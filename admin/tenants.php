<?php
require_once __DIR__ . '/../includes/crud.php';
require_once __DIR__ . '/../includes/finance.php';

const TENANT_STATUSES = ['Active', 'Inactive', 'Evicted', 'Blacklisted'];

crud_run([
    'module' => 'tenants',
    'table' => 'tenants',
    'singular' => 'Tenant',
    'plural' => 'Tenants',
    'description' => 'Tenant profiles, contacts and balances. Tenants who self-register with the same national ID and email are linked automatically.',
    'fields' => [
        'full_name' => ['label' => 'Full name', 'required' => true, 'max' => 100],
        'national_id' => ['label' => 'National ID / passport', 'required' => true, 'max' => 50],
        'phone' => ['label' => 'Phone', 'type' => 'tel', 'required' => true, 'max' => 20],
        'email' => ['label' => 'Email', 'type' => 'email', 'required' => true, 'max' => 150],
        'gender' => ['label' => 'Gender', 'type' => 'select', 'options' => ['Female', 'Male', 'Other'], 'required' => true],
        'date_of_birth' => ['label' => 'Date of birth', 'type' => 'date'],
        'occupation' => ['label' => 'Occupation', 'max' => 100],
        'employer' => ['label' => 'Employer', 'max' => 100],
        'emergency_contact_name' => ['label' => 'Emergency contact name', 'max' => 100],
        'emergency_contact_phone' => ['label' => 'Emergency contact phone', 'type' => 'tel', 'max' => 20],
        'user_id' => ['label' => 'Portal login', 'type' => 'select', 'help' => 'Optional. Accounts with the Tenant role.',
            'options_sql' => "SELECT u.id, CONCAT(u.full_name, ' (', u.email, ')') FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name = 'Tenant' ORDER BY u.full_name"],
        'status' => ['label' => 'Status', 'type' => 'select', 'options' => TENANT_STATUSES, 'required' => true, 'default' => 'Active'],
        'current_address' => ['label' => 'Previous / current address', 'type' => 'textarea'],
    ],
    'list' => [
        'sql' => 'SELECT t.id, t.full_name, t.national_id, t.phone, t.email, t.status,
                (SELECT CONCAT(p.name, \' / \', u.unit_number) FROM leases l JOIN units u ON u.id = l.unit_id JOIN properties p ON p.id = l.property_id WHERE l.tenant_id = t.id AND l.status = \'Active\' LIMIT 1) AS current_unit,
                COALESCE((SELECT SUM(rc.total_due) FROM rent_charges rc WHERE rc.tenant_id = t.id), 0)
                  - COALESCE((SELECT SUM(pa.allocated_amount) FROM payment_allocations pa JOIN rent_charges rc ON rc.id = pa.rent_charge_id WHERE rc.tenant_id = t.id), 0) AS balance
            FROM tenants t',
        'columns' => [
            'full_name' => ['label' => 'Tenant', 'format' => fn($row) => '<strong>' . e($row['full_name']) . '</strong><div class="text-xs text-gray-500">ID ' . e($row['national_id']) . '</div>'],
            'phone' => ['label' => 'Phone', 'format' => fn($row) => e($row['phone']) . '<div class="text-xs text-gray-500">' . e($row['email']) . '</div>'],
            'current_unit' => ['label' => 'Current unit'],
            'balance' => ['label' => 'Balance', 'format' => fn($row) => '<span class="font-semibold ' . ($row['balance'] > 0 ? 'text-red-700' : 'text-green-800') . '">' . format_currency($row['balance']) . '</span>'],
            'status' => ['label' => 'Status', 'format' => 'badge'],
        ],
        'search' => ['t.full_name', 't.national_id', 't.phone', 't.email'],
        'filters' => ['status' => ['label' => 'Status', 'column' => 't.status', 'options' => TENANT_STATUSES]],
        'order' => 't.full_name',
        'row_actions' => function ($row) {
            $html = '<a class="btn btn-small btn-secondary" href="' . e(module_url('reports', ['report' => 'statement', 'tenant_id' => $row['id']])) . '">Statement</a>';
            if (!$row['current_unit']) $html .= '<a class="btn btn-small btn-accent" href="' . e(module_url('leases', ['action' => 'create', 'tenant_id' => $row['id']])) . '">New lease</a>';
            return $html;
        },
    ],
    'messages' => [
        'duplicate' => 'A tenant with that national ID already exists.',
        'in_use' => 'This tenant has leases or payments on record. Set the status to Inactive instead of deleting.',
    ],
    'hooks' => [
        'prepare' => function (array $data, ?array $old, PDO $db) {
            if (!empty($data['user_id'])) {
                $stmt = $db->prepare('SELECT COUNT(*) FROM tenants WHERE user_id = ? AND id <> ?');
                $stmt->execute([$data['user_id'], $old['id'] ?? 0]);
                if ((int)$stmt->fetchColumn() > 0) throw new UserFacingException('That portal login is already linked to another tenant.');
            }
            return $data;
        },
    ],
]);
