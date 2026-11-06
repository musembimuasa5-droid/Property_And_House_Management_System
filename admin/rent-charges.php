<?php
require_once __DIR__ . '/../includes/crud.php';
require_once __DIR__ . '/../includes/finance.php';

$propertyOptions = 'SELECT id, name FROM properties ORDER BY name';
$leaseOptions = "SELECT l.id, CONCAT(t.full_name, ' — ', p.name, ' / ', u.unit_number, ' (', l.status, ')')
    FROM leases l JOIN tenants t ON t.id = l.tenant_id JOIN units u ON u.id = l.unit_id JOIN properties p ON p.id = l.property_id
    ORDER BY l.status = 'Active' DESC, t.full_name";

crud_run([
    'module' => 'rent_charges',
    'table' => 'rent_charges',
    'singular' => 'Rent charge',
    'plural' => 'Rent Charges',
    'description' => 'Monthly invoices per lease. Payments are applied to the oldest unpaid charge automatically.',
    'fields' => [
        'lease_id' => ['label' => 'Lease', 'type' => 'select', 'options_sql' => $leaseOptions, 'required' => true, 'wide' => true],
        'billing_period' => ['label' => 'Billing period', 'type' => 'month', 'required' => true, 'default' => fn() => date('Y-m')],
        'due_date' => ['label' => 'Due date', 'type' => 'date', 'help' => "Leave blank to use the lease's rent due day."],
        'rent_amount' => ['label' => 'Rent (KSh)', 'type' => 'decimal', 'min' => 0, 'help' => "Leave blank to use the lease's monthly rent."],
        'service_charge' => ['label' => 'Service charge (KSh)', 'type' => 'decimal', 'min' => 0, 'required' => true, 'default' => 0],
        'water_bill' => ['label' => 'Utilities (KSh)', 'type' => 'decimal', 'min' => 0, 'required' => true, 'default' => 0, 'help' => 'Filled in automatically from utility readings for this unit and period.'],
        'previous_balance' => ['label' => 'Balance brought forward (KSh)', 'type' => 'decimal', 'min' => 0, 'required' => true, 'default' => 0,
            'help' => 'Only for arrears carried over from before this system. Unpaid charges here are already tracked.'],
    ],
    'list' => [
        'sql' => 'SELECT rc.id, rc.billing_period, rc.due_date, rc.total_due, l.property_id, t.full_name AS tenant_name, p.name AS property_name, u.unit_number,
                COALESCE(pa.paid, 0) AS paid, rc.total_due - COALESCE(pa.paid, 0) AS balance, ' . CHARGE_LIVE_STATUS_SQL . ' AS live_status
            FROM rent_charges rc JOIN leases l ON l.id = rc.lease_id JOIN tenants t ON t.id = rc.tenant_id JOIN units u ON u.id = rc.unit_id JOIN properties p ON p.id = l.property_id
            LEFT JOIN ' . CHARGE_PAID_SUBQUERY . ' pa ON pa.rent_charge_id = rc.id',
        'columns' => [
            'billing_period' => ['label' => 'Period', 'format' => 'period'],
            'tenant_name' => ['label' => 'Tenant'],
            'unit_number' => ['label' => 'Property / unit', 'format' => fn($row) => e($row['property_name'] . ' / ' . $row['unit_number'])],
            'due_date' => ['label' => 'Due', 'format' => 'date'],
            'total_due' => ['label' => 'Total due', 'format' => 'currency'],
            'paid' => ['label' => 'Paid', 'format' => 'currency'],
            'balance' => ['label' => 'Balance', 'format' => fn($row) => '<strong>' . format_currency($row['balance']) . '</strong>'],
            'live_status' => ['label' => 'Status', 'format' => 'badge'],
        ],
        'search' => ['t.full_name', 'p.name', 'u.unit_number'],
        'filters' => [
            'billing_period' => ['label' => 'Period', 'column' => 'rc.billing_period', 'options_sql' => 'SELECT DISTINCT billing_period, billing_period FROM rent_charges ORDER BY billing_period DESC'],
            'status' => ['label' => 'Status', 'column' => CHARGE_LIVE_STATUS_SQL, 'options' => ['Unpaid', 'Partial', 'Overdue', 'Paid']],
            'property_id' => ['label' => 'Property', 'column' => 'l.property_id', 'options_sql' => $propertyOptions],
        ],
        'order' => 'rc.billing_period DESC, t.full_name',
        'panel' => function () { ?>
            <form method="post" class="bg-white p-5 rounded-xl border border-gray-100 shadow-sm flex flex-col sm:flex-row sm:items-end gap-4">
                <?php echo csrf_field(); ?><input type="hidden" name="_action" value="generate">
                <div class="flex-1">
                    <h2 class="font-bold text-gray-900">Generate monthly charges</h2>
                    <p class="text-sm text-gray-500">Creates one charge for every active lease in the period (rent + service charge + recorded utilities). Leases already charged are skipped.</p>
                </div>
                <div><label for="generate-period" class="block text-xs font-semibold text-gray-500 uppercase mb-1">Billing period</label>
                    <input id="generate-period" type="month" name="period" required value="<?php echo e(date('Y-m')); ?>" pattern="\d{4}-\d{2}" class="px-3 py-2 border border-gray-300 rounded-lg text-sm"></div>
                <button type="submit" class="btn btn-accent"><i class="fa-solid fa-bolt" aria-hidden="true"></i> Generate</button>
            </form>
        <?php },
    ],
    'actions' => [
        'generate' => function (PDO $db) {
            $period = $_POST['period'] ?? '';
            if (!is_string($period) || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) throw new UserFacingException('Choose a valid billing period.');
            $db->beginTransaction();
            [$created, $skipped] = generate_rent_charges($db, $period);
            $db->commit();
            logAuditAction('GENERATE', 'rent_charges', null, "Generated $created rent charges for $period");
            return "Created $created charge(s) for " . format_period($period) . ($skipped ? "; $skipped lease(s) were already charged." : '.');
        },
    ],
    'hooks' => [
        'prepare' => function (array $data, ?array $old, PDO $db) {
            $lease = $db->prepare('SELECT tenant_id, unit_id, monthly_rent, payment_due_day FROM leases WHERE id = ?');
            $lease->execute([$data['lease_id']]);
            $lease = $lease->fetch();
            $duplicate = $db->prepare('SELECT COUNT(*) FROM rent_charges WHERE lease_id = ? AND billing_period = ? AND id <> ?');
            $duplicate->execute([$data['lease_id'], $data['billing_period'], $old['id'] ?? 0]);
            if ((int)$duplicate->fetchColumn() > 0) throw new UserFacingException('This lease already has a charge for ' . format_period($data['billing_period']) . '. Edit that charge instead.');

            $data['tenant_id'] = $lease['tenant_id'];
            $data['unit_id'] = $lease['unit_id'];
            $data['rent_amount'] ??= $lease['monthly_rent'];
            $data['due_date'] ??= charge_due_date($data['billing_period'], (int)$lease['payment_due_day']);
            $data['total_due'] = round($data['rent_amount'] + $data['service_charge'] + $data['water_bill'] + $data['previous_balance'], 2);
            return $data;
        },
        'after_save' => function (PDO $db, int $id, array $data, ?array $old) {
            recalculate_lease_balances($db, (int)$data['lease_id']);
            if ($old && (int)$old['lease_id'] !== (int)$data['lease_id']) recalculate_lease_balances($db, (int)$old['lease_id']);
        },
        'after_delete' => fn(PDO $db, array $row) => recalculate_lease_balances($db, (int)$row['lease_id']),
    ],
]);
