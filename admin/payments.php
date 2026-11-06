<?php
require_once __DIR__ . '/../includes/crud.php';
require_once __DIR__ . '/../includes/finance.php';

const PAYMENT_METHODS = ['M-Pesa', 'Bank', 'Cash', 'Card', 'Other'];
$propertyOptions = 'SELECT id, name FROM properties ORDER BY name';

crud_run([
    'module' => 'payments',
    'table' => 'payments',
    'singular' => 'Payment',
    'plural' => 'Payments',
    'description' => 'Rent received. Each payment is applied to the oldest unpaid charge on its lease; overpayments carry forward.',
    'delete_roles' => ['Super Admin', 'Accountant'],
    'fields' => [
        'lease_id' => ['label' => 'Lease', 'type' => 'select', 'required' => true, 'wide' => true,
            'options_sql' => "SELECT l.id, CONCAT(t.full_name, ' — ', p.name, ' / ', u.unit_number, ' (', l.status, ')')
                FROM leases l JOIN tenants t ON t.id = l.tenant_id JOIN units u ON u.id = l.unit_id JOIN properties p ON p.id = l.property_id
                ORDER BY l.status = 'Active' DESC, t.full_name"],
        'amount' => ['label' => 'Amount (KSh)', 'type' => 'decimal', 'min' => 1, 'max' => 100000000, 'required' => true],
        'payment_method' => ['label' => 'Method', 'type' => 'select', 'options' => PAYMENT_METHODS, 'required' => true, 'default' => 'M-Pesa'],
        'transaction_reference' => ['label' => 'Transaction reference', 'required' => true, 'max' => 100, 'help' => 'M-Pesa code, bank slip or cash book number. Must be unique.'],
        'payment_date' => ['label' => 'Date received', 'type' => 'date', 'required' => true, 'default' => fn() => date('Y-m-d')],
        'payment_period' => ['label' => 'For period', 'type' => 'month', 'required' => true, 'default' => fn() => date('Y-m')],
        'notes' => ['label' => 'Notes', 'type' => 'textarea'],
    ],
    'list' => [
        'sql' => 'SELECT pay.id, pay.receipt_number, pay.payment_date, pay.amount, pay.payment_method, pay.transaction_reference, pay.payment_period, pay.property_id,
                t.full_name AS tenant_name, p.name AS property_name, u.unit_number, rb.full_name AS recorded_by_name
            FROM payments pay JOIN tenants t ON t.id = pay.tenant_id JOIN properties p ON p.id = pay.property_id JOIN units u ON u.id = pay.unit_id JOIN users rb ON rb.id = pay.recorded_by',
        'columns' => [
            'receipt_number' => ['label' => 'Receipt', 'format' => fn($row) => '<strong>' . e($row['receipt_number']) . '</strong><div class="text-xs text-gray-500">Ref ' . e($row['transaction_reference']) . '</div>'],
            'payment_date' => ['label' => 'Date', 'format' => 'date'],
            'tenant_name' => ['label' => 'Tenant'],
            'unit_number' => ['label' => 'Property / unit', 'format' => fn($row) => e($row['property_name'] . ' / ' . $row['unit_number'])],
            'payment_method' => ['label' => 'Method', 'format' => 'badge'],
            'amount' => ['label' => 'Amount', 'format' => fn($row) => '<strong class="text-green-800">' . format_currency($row['amount']) . '</strong>'],
            'recorded_by_name' => ['label' => 'Recorded by'],
        ],
        'search' => ['pay.receipt_number', 'pay.transaction_reference', 't.full_name', 'u.unit_number'],
        'filters' => [
            'payment_method' => ['label' => 'Method', 'column' => 'pay.payment_method', 'options' => PAYMENT_METHODS],
            'payment_period' => ['label' => 'Period', 'column' => 'pay.payment_period', 'options_sql' => 'SELECT DISTINCT payment_period, payment_period FROM payments ORDER BY payment_period DESC'],
            'property_id' => ['label' => 'Property', 'column' => 'pay.property_id', 'options_sql' => $propertyOptions],
        ],
        'order' => 'pay.payment_date DESC, pay.id DESC',
        'row_actions' => fn($row) => '<a class="btn btn-small btn-secondary" href="' . e(BASE_URL . '/admin/receipt.php?id=' . (int)$row['id']) . '"><i class="fa-solid fa-print" aria-hidden="true"></i> Receipt</a>',
    ],
    'messages' => ['duplicate' => 'That transaction reference has already been recorded. Check for a duplicate payment.'],
    'hooks' => [
        'prepare' => function (array $data, ?array $old, PDO $db) {
            if ($data['payment_date'] > date('Y-m-d')) throw new UserFacingException('The payment date cannot be in the future.');
            $lease = $db->prepare('SELECT tenant_id, property_id, unit_id FROM leases WHERE id = ?');
            $lease->execute([$data['lease_id']]);
            $data += $lease->fetch();
            if (!$old) {
                $data['receipt_number'] = generate_receipt_number();
                $data['recorded_by'] = $_SESSION['user_id'];
            }
            return $data;
        },
        'after_save' => function (PDO $db, int $id, array $data, ?array $old) {
            recalculate_lease_balances($db, (int)$data['lease_id']);
            if ($old && (int)$old['lease_id'] !== (int)$data['lease_id']) recalculate_lease_balances($db, (int)$old['lease_id']);
        },
        'after_delete' => fn(PDO $db, array $row) => recalculate_lease_balances($db, (int)$row['lease_id']),
    ],
]);
