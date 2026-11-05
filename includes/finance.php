<?php
/**
 * Rent, payment and utility billing rules shared by the admin modules and the API.
 */

/**
 * Rebuild payment allocations for one lease, paying the oldest charge first, and
 * refresh each charge's status.
 *
 * Recomputing from scratch keeps balances right after any payment or charge is
 * added, edited or deleted. A lease has about one charge a month, so it is cheap.
 * Amounts are handled in cents to avoid floating-point drift.
 */
function recalculate_lease_balances(PDO $db, int $lease_id): void {
    $db->prepare('DELETE pa FROM payment_allocations pa JOIN payments p ON p.id = pa.payment_id WHERE p.lease_id = ?')->execute([$lease_id]);
    $db->prepare('DELETE pa FROM payment_allocations pa JOIN rent_charges rc ON rc.id = pa.rent_charge_id WHERE rc.lease_id = ?')->execute([$lease_id]);

    $charges = $db->prepare('SELECT id, total_due, due_date FROM rent_charges WHERE lease_id = ? ORDER BY billing_period, due_date, id');
    $charges->execute([$lease_id]);
    $payments = $db->prepare('SELECT id, amount FROM payments WHERE lease_id = ? ORDER BY payment_date, id');
    $payments->execute([$lease_id]);

    $credits = array_map(fn($p) => ['id' => (int)$p['id'], 'left' => (int)round($p['amount'] * 100)], $payments->fetchAll());
    $allocate = $db->prepare('INSERT INTO payment_allocations (payment_id, rent_charge_id, allocated_amount) VALUES (?, ?, ?)');
    $setStatus = $db->prepare('UPDATE rent_charges SET status = ? WHERE id = ?');
    $today = date('Y-m-d');
    $next = 0;

    foreach ($charges->fetchAll() as $charge) {
        $due = (int)round($charge['total_due'] * 100);
        $paid = 0;
        while ($paid < $due && $next < count($credits)) {
            $take = min($credits[$next]['left'], $due - $paid);
            if ($take > 0) {
                $allocate->execute([$credits[$next]['id'], $charge['id'], $take / 100]);
                $paid += $take;
                $credits[$next]['left'] -= $take;
            }
            if ($credits[$next]['left'] <= 0) $next++;
        }
        $status = $paid >= $due ? 'Paid' : ($charge['due_date'] < $today ? 'Overdue' : ($paid > 0 ? 'Partial' : 'Unpaid'));
        $setStatus->execute([$status, $charge['id']]);
    }
}

/** Total recorded utility usage for a unit in a billing period. */
function unit_utilities_total(PDO $db, int $unit_id, string $period): float {
    $stmt = $db->prepare('SELECT COALESCE(SUM(total_amount), 0) FROM utility_readings WHERE unit_id = ? AND billing_period = ?');
    $stmt->execute([$unit_id, $period]);
    return (float)$stmt->fetchColumn();
}

/** Copy a unit's utility readings into any rent charge already raised for that period. */
function sync_charge_utilities(PDO $db, int $unit_id, string $period): void {
    $stmt = $db->prepare('SELECT id, lease_id FROM rent_charges WHERE unit_id = ? AND billing_period = ?');
    $stmt->execute([$unit_id, $period]);
    $utilities = unit_utilities_total($db, $unit_id, $period);
    $update = $db->prepare('UPDATE rent_charges SET water_bill = ?, total_due = rent_amount + service_charge + ? + previous_balance WHERE id = ?');
    foreach ($stmt->fetchAll() as $charge) {
        $update->execute([$utilities, $utilities, $charge['id']]);
        recalculate_lease_balances($db, (int)$charge['lease_id']);
    }
}

/** Due date for a period given the lease's payment day (capped at 28 so it exists in every month). */
function charge_due_date(string $period, int $due_day): string {
    $day = max(1, min(28, $due_day));
    return $period . '-' . str_pad((string)$day, 2, '0', STR_PAD_LEFT);
}

/**
 * Raise one rent charge per active lease for a billing period, skipping leases
 * already charged. Returns [created, skipped].
 */
function generate_rent_charges(PDO $db, string $period): array {
    $start = $period . '-01';
    $end = date('Y-m-t', strtotime($start));
    $leases = $db->prepare("SELECT l.id, l.tenant_id, l.unit_id, l.monthly_rent, l.payment_due_day, u.service_charge,
            EXISTS(SELECT 1 FROM rent_charges rc WHERE rc.lease_id = l.id AND rc.billing_period = ?) AS already_charged
        FROM leases l JOIN units u ON u.id = l.unit_id
        WHERE l.status = 'Active' AND l.start_date <= ? AND l.end_date >= ?");
    $leases->execute([$period, $end, $start]);
    $insert = $db->prepare("INSERT INTO rent_charges (lease_id, tenant_id, unit_id, billing_period, rent_amount, service_charge, water_bill, previous_balance, total_due, due_date, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?, 'Unpaid')");

    $created = 0;
    $skipped = 0;
    foreach ($leases->fetchAll() as $lease) {
        if ($lease['already_charged']) { $skipped++; continue; }
        $utilities = unit_utilities_total($db, (int)$lease['unit_id'], $period);
        $total = (float)$lease['monthly_rent'] + (float)$lease['service_charge'] + $utilities;
        $insert->execute([$lease['id'], $lease['tenant_id'], $lease['unit_id'], $period, $lease['monthly_rent'], $lease['service_charge'], $utilities, $total, charge_due_date($period, (int)$lease['payment_due_day'])]);
        recalculate_lease_balances($db, (int)$lease['id']);
        $created++;
    }
    return [$created, $skipped];
}

/** Mark a unit Occupied while it has an active lease, and Vacant once it has none. */
function sync_unit_occupancy(PDO $db, int $unit_id): void {
    $stmt = $db->prepare("SELECT COUNT(*) FROM leases WHERE unit_id = ? AND status = 'Active'");
    $stmt->execute([$unit_id]);
    if ((int)$stmt->fetchColumn() > 0) {
        $db->prepare("UPDATE units SET status = 'Occupied' WHERE id = ?")->execute([$unit_id]);
    } else {
        $db->prepare("UPDATE units SET status = 'Vacant' WHERE id = ? AND status = 'Occupied'")->execute([$unit_id]);
    }
}

/** SQL fragment: amount allocated to each rent charge. Join as "pa" on pa.rent_charge_id = rc.id. */
const CHARGE_PAID_SUBQUERY = '(SELECT rent_charge_id, SUM(allocated_amount) AS paid FROM payment_allocations GROUP BY rent_charge_id)';

/** SQL expression: live status of rent charge "rc" with allocations "pa", so overdue shows without a nightly job. */
const CHARGE_LIVE_STATUS_SQL = "(CASE WHEN rc.total_due - COALESCE(pa.paid, 0) <= 0 THEN 'Paid' WHEN rc.due_date < CURDATE() THEN 'Overdue' WHEN COALESCE(pa.paid, 0) > 0 THEN 'Partial' ELSE 'Unpaid' END)";
