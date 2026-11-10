<?php
require_once __DIR__ . '/../includes/crud.php';
require_once __DIR__ . '/../includes/finance.php';

const DEPOSIT_TYPES = ['Received', 'Deduction', 'Refund'];
$propertyOptions = 'SELECT id, name FROM properties ORDER BY name';

crud_run([
    'module' => 'deposits',
    'table' => 'deposit_transactions',
    'singular' => 'Deposit entry',
    'plural' => 'Deposit Ledger',
    'description' => 'Deposits received, itemised deductions with reasons, and refunds. Tenants see their own ledger in the portal.',
    'delete_roles' => ['Super Admin', 'Accountant'],
    'fields' => [
        'lease_id' => ['label' => 'Lease', 'type' => 'select', 'required' => true, 'wide' => true,
            'options_sql' => "SELECT l.id, CONCAT(t.full_name, ' — ', p.name, ' / ', u.unit_number, ' (', l.status, ', deposit KSh ', FORMAT(l.deposit, 2), ')')
                FROM leases l JOIN tenants t ON t.id = l.tenant_id JOIN units u ON u.id = l.unit_id JOIN properties p ON p.id = l.property_id
                ORDER BY l.status = 'Active' DESC, t.full_name"],
        'transaction_type' => ['label' => 'Entry type', 'type' => 'select', 'options' => DEPOSIT_TYPES, 'required' => true, 'default' => 'Received'],
        'amount' => ['label' => 'Amount (KSh)', 'type' => 'decimal', 'min' => 0.01, 'max' => 100000000, 'required' => true],
        'transaction_date' => ['label' => 'Date', 'type' => 'date', 'required' => true, 'default' => fn() => date('Y-m-d')],
        'reason' => ['label' => 'Reason', 'max' => 255, 'wide' => true, 'help' => 'Required for a deduction, e.g. "Replace broken kitchen window pane".'],
        'condition_report_id' => ['label' => 'Supporting condition report', 'type' => 'select', 'wide' => true,
            'help' => 'Link a deduction to the move-out report that shows the damage.',
            'options_sql' => "SELECT cr.id, CONCAT(cr.report_type, ' report ', DATE_FORMAT(cr.inspection_date, '%d %b %Y'), ' — ', t.full_name, ' / ', u.unit_number)
                FROM condition_reports cr JOIN leases l ON l.id = cr.lease_id JOIN tenants t ON t.id = l.tenant_id JOIN units u ON u.id = l.unit_id
                ORDER BY cr.inspection_date DESC"],
        'reference' => ['label' => 'Reference', 'max' => 100, 'help' => 'M-Pesa code, bank slip or cash book number, if any.'],
    ],
    'list' => [
        'sql' => "SELECT d.id, d.transaction_date, d.transaction_type, d.amount, d.reason, d.reference, d.condition_report_id, l.property_id,
                t.full_name AS tenant_name, p.name AS property_name, u.unit_number, rb.full_name AS recorded_by_name
            FROM deposit_transactions d JOIN leases l ON l.id = d.lease_id JOIN tenants t ON t.id = l.tenant_id JOIN units u ON u.id = l.unit_id
            JOIN properties p ON p.id = l.property_id JOIN users rb ON rb.id = d.recorded_by",
        'columns' => [
            'transaction_date' => ['label' => 'Date', 'format' => 'date'],
            'tenant_name' => ['label' => 'Tenant', 'format' => fn($row) => e($row['tenant_name']) . '<div class="text-xs text-gray-500">' . e($row['property_name'] . ' / ' . $row['unit_number']) . '</div>'],
            'transaction_type' => ['label' => 'Entry', 'format' => 'badge'],
            'amount' => ['label' => 'Amount', 'format' => fn($row) => '<strong>' . ($row['transaction_type'] === 'Received' ? '' : '− ') . format_currency($row['amount']) . '</strong>'],
            'reason' => ['label' => 'Reason', 'format' => fn($row) => e($row['reason'] ?? '')
                . ($row['condition_report_id'] ? '<div class="text-xs"><a class="text-primary" href="' . e(module_url('condition_reports', ['action' => 'view', 'id' => $row['condition_report_id']])) . '">See condition report</a></div>' : '')],
            'reference' => ['label' => 'Reference'],
            'recorded_by_name' => ['label' => 'Recorded by'],
        ],
        'search' => ['t.full_name', 'u.unit_number', 'd.reason', 'd.reference'],
        'filters' => [
            'transaction_type' => ['label' => 'Entry', 'column' => 'd.transaction_type', 'options' => DEPOSIT_TYPES],
            'property_id' => ['label' => 'Property', 'column' => 'l.property_id', 'options_sql' => $propertyOptions],
        ],
        'order' => 'd.transaction_date DESC, d.id DESC',
        'panel' => function (PDO $db) {
            $totals = $db->query("SELECT
                    COALESCE(SUM(CASE d.transaction_type WHEN 'Received' THEN d.amount ELSE -d.amount END), 0) AS held,
                    COALESCE(SUM(CASE WHEN d.transaction_type = 'Deduction' AND YEAR(d.transaction_date) = YEAR(CURDATE()) THEN d.amount END), 0) AS deducted_year
                FROM deposit_transactions d")->fetch();
            $due = $db->query("SELECT COUNT(*) AS leases, COALESCE(SUM(held), 0) AS amount FROM (
                    SELECT SUM(CASE d.transaction_type WHEN 'Received' THEN d.amount ELSE -d.amount END) AS held
                    FROM leases l JOIN deposit_transactions d ON d.lease_id = l.id
                    WHERE l.status IN ('Terminated', 'Expired') GROUP BY l.id HAVING held > 0.004) AS ended")->fetch();
            $tiles = [['Deposits held', format_currency($totals['held'])], ['Due for refund', (int)$due['leases'] . ' lease(s) · ' . format_currency($due['amount'])], ['Deducted this year', format_currency($totals['deducted_year'])]];
            echo '<div class="grid grid-cols-1 sm:grid-cols-3 gap-4">';
            foreach ($tiles as [$label, $value]) echo '<div class="bg-white p-4 rounded-xl border border-gray-100 shadow-sm"><p class="text-xs font-semibold uppercase text-gray-500">' . e($label) . '</p><p class="text-lg font-bold text-gray-900 mt-1">' . e($value) . '</p></div>';
            echo '</div>';
        },
    ],
    'hooks' => [
        'prepare' => function (array $data, ?array $old, PDO $db) {
            if ($data['transaction_date'] > date('Y-m-d')) throw new UserFacingException('The date cannot be in the future.');
            if ($data['transaction_type'] === 'Deduction' && ($data['reason'] ?? null) === null) {
                throw new UserFacingException('Give the reason for the deduction, so the tenant can see what it covers.');
            }
            if (!empty($data['condition_report_id'])) {
                $report = $db->prepare('SELECT lease_id FROM condition_reports WHERE id = ?');
                $report->execute([$data['condition_report_id']]);
                if ((int)$report->fetchColumn() !== (int)$data['lease_id']) throw new UserFacingException('That condition report belongs to a different lease.');
            }
            $error = deposit_entry_error($db, (int)$data['lease_id'], $data['transaction_type'], (float)$data['amount'], (int)($old['id'] ?? 0));
            if ($error) throw new UserFacingException($error);
            if (!$old) $data['recorded_by'] = $_SESSION['user_id'];
            return $data;
        },
        'after_save' => function (PDO $db, int $id, array $data, ?array $old) {
            if ($old || $data['transaction_type'] === 'Received') return;
            $tenant = $db->prepare('SELECT t.user_id FROM leases l JOIN tenants t ON t.id = l.tenant_id WHERE l.id = ?');
            $tenant->execute([$data['lease_id']]);
            $what = $data['transaction_type'] === 'Refund' ? 'refunded to you' : 'deducted for: ' . $data['reason'];
            notify_user($db, $tenant->fetchColumn(), 'Deposit update', format_currency($data['amount']) . " of your deposit was $what. See Deposit & Condition for details.");
        },
        // Removing money received must not leave deductions or refunds larger than the deposit held.
        'before_delete' => function (PDO $db, array $row) {
            if ($row['transaction_type'] !== 'Received') return;
            $summary = deposit_summary($db, (int)$row['lease_id'], (int)$row['id']);
            if ($summary['held'] < -0.004) throw new UserFacingException('Deductions or refunds depend on this receipt. Delete them first.');
        },
    ],
]);
