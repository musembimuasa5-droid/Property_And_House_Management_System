<?php
require_once __DIR__ . '/../includes/crud.php';

crud_run([
    'module' => 'landlords',
    'table' => 'landlords',
    'singular' => 'Landlord',
    'plural' => 'Landlords',
    'description' => 'Property owners. Link a Landlord login so they can see their own dashboard and reports.',
    'fields' => [
        'full_name' => ['label' => 'Full name', 'required' => true, 'max' => 100],
        'company_name' => ['label' => 'Company name', 'max' => 150],
        'phone' => ['label' => 'Phone', 'type' => 'tel', 'required' => true, 'max' => 20],
        'email' => ['label' => 'Email', 'type' => 'email', 'required' => true, 'max' => 150],
        'id_number' => ['label' => 'National ID / company reg. no.', 'required' => true, 'max' => 50],
        'mpesa_number' => ['label' => 'M-Pesa number', 'type' => 'tel', 'max' => 20],
        'user_id' => ['label' => 'Login account', 'type' => 'select', 'help' => 'Optional. Accounts with the Landlord role, created under User Accounts.',
            'options_sql' => "SELECT u.id, CONCAT(u.full_name, ' (', u.email, ')') FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name = 'Landlord' ORDER BY u.full_name"],
        'status' => ['label' => 'Status', 'type' => 'select', 'options' => ['Active', 'Inactive'], 'required' => true, 'default' => 'Active'],
        'address' => ['label' => 'Postal / physical address', 'type' => 'textarea'],
        'bank_details' => ['label' => 'Bank details', 'type' => 'textarea'],
    ],
    'list' => [
        'sql' => 'SELECT ld.id, ld.full_name, ld.company_name, ld.phone, ld.email, ld.status, u.email AS login_email,
                (SELECT COUNT(*) FROM properties p WHERE p.landlord_id = ld.id) AS property_count
            FROM landlords ld LEFT JOIN users u ON u.id = ld.user_id',
        'columns' => [
            'full_name' => ['label' => 'Landlord', 'format' => fn($row) => '<strong>' . e($row['full_name']) . '</strong><div class="text-xs text-gray-500">' . e($row['company_name'] ?? '') . '</div>'],
            'phone' => ['label' => 'Phone'],
            'email' => ['label' => 'Email'],
            'property_count' => ['label' => 'Properties'],
            'login_email' => ['label' => 'Login', 'format' => fn($row) => $row['login_email'] ? render_status_badge('Active') : '<span class="text-gray-400">Not linked</span>'],
            'status' => ['label' => 'Status', 'format' => 'badge'],
        ],
        'search' => ['ld.full_name', 'ld.company_name', 'ld.phone', 'ld.email'],
        'filters' => ['status' => ['label' => 'Status', 'column' => 'ld.status', 'options' => ['Active', 'Inactive']]],
        'order' => 'ld.full_name',
        'row_actions' => fn($row) => '<a class="btn btn-small btn-secondary" href="' . e(module_url('properties', ['landlord_id' => $row['id']])) . '">Properties</a>',
    ],
    'messages' => ['in_use' => 'This landlord still owns properties. Reassign them or set the landlord to Inactive.'],
    'hooks' => [
        'prepare' => function (array $data, ?array $old, PDO $db) {
            if (!empty($data['user_id'])) {
                $stmt = $db->prepare('SELECT COUNT(*) FROM landlords WHERE user_id = ? AND id <> ?');
                $stmt->execute([$data['user_id'], $old['id'] ?? 0]);
                if ((int)$stmt->fetchColumn() > 0) throw new UserFacingException('That login account is already linked to another landlord.');
            }
            return $data;
        },
    ],
]);
