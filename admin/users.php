<?php
require_once __DIR__ . '/../includes/crud.php';

const USER_STATUSES = ['Active', 'Inactive', 'Suspended'];
$roleOptions = 'SELECT id, name FROM roles ORDER BY id';

crud_run([
    'module' => 'users',
    'table' => 'users',
    'singular' => 'User account',
    'plural' => 'User Accounts',
    'description' => 'Sign-in accounts and roles. Link Landlord and Tenant accounts to their profiles from the Landlords and Tenants pages.',
    'fields' => [
        'full_name' => ['label' => 'Full name', 'required' => true, 'max' => 100],
        'email' => ['label' => 'Email (sign-in)', 'type' => 'email', 'required' => true, 'max' => 150],
        'phone' => ['label' => 'Phone', 'type' => 'tel', 'required' => true, 'max' => 20],
        'role_id' => ['label' => 'Role', 'type' => 'select', 'options_sql' => $roleOptions, 'required' => true],
        'status' => ['label' => 'Status', 'type' => 'select', 'options' => USER_STATUSES, 'required' => true, 'default' => 'Active', 'help' => 'Only Active accounts can sign in.'],
        'password' => ['label' => 'Password', 'type' => 'password', 'required' => true, 'virtual' => true, 'help' => 'At least 10 characters. Leave blank when editing to keep the current password.'],
    ],
    'list' => [
        'sql' => 'SELECT u.id, u.full_name, u.email, u.phone, u.status, u.last_login, u.role_id, r.name AS role_name FROM users u JOIN roles r ON r.id = u.role_id',
        'columns' => [
            'full_name' => ['label' => 'Name', 'format' => fn($row) => '<strong>' . e($row['full_name']) . '</strong><div class="text-xs text-gray-500">' . e($row['email']) . '</div>'],
            'phone' => ['label' => 'Phone'],
            'role_name' => ['label' => 'Role'],
            'last_login' => ['label' => 'Last sign-in', 'format' => fn($row) => $row['last_login'] ? e(format_date($row['last_login'])) : '<span class="text-gray-400">Never</span>'],
            'status' => ['label' => 'Status', 'format' => 'badge'],
        ],
        'search' => ['u.full_name', 'u.email', 'u.phone'],
        'filters' => [
            'role_id' => ['label' => 'Role', 'column' => 'u.role_id', 'options_sql' => $roleOptions],
            'status' => ['label' => 'Status', 'column' => 'u.status', 'options' => USER_STATUSES],
        ],
        'order' => 'u.role_id, u.full_name',
    ],
    'messages' => [
        'duplicate' => 'That email address already has an account.',
        'in_use' => 'This account has recorded payments, readings or expenses. Set it to Inactive instead of deleting it.',
    ],
    'hooks' => [
        'prepare' => function (array $data, ?array $old) {
            if ($old && (int)$old['id'] === (int)$_SESSION['user_id'] && ((int)$data['role_id'] !== (int)$old['role_id'] || $data['status'] !== 'Active')) {
                throw new UserFacingException('You cannot change your own role or deactivate your own account.');
            }
            if (isset($data['password'])) $data['password_hash'] = password_hash($data['password'], PASSWORD_DEFAULT);
            return $data;
        },
        'after_save' => function (PDO $db, int $id, array $data) {
            if ($id === (int)$_SESSION['user_id']) {
                $_SESSION['full_name'] = $data['full_name'];
                $_SESSION['email'] = $data['email'];
            }
        },
        'before_delete' => function (PDO $db, array $row) {
            if ((int)$row['id'] === (int)$_SESSION['user_id']) throw new UserFacingException('You cannot delete your own account.');
        },
    ],
]);
