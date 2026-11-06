<?php
require_once __DIR__ . '/../includes/crud.php';

const ENQUIRY_STATUSES = ['New', 'In Progress', 'Resolved', 'Closed'];
$staffOptions = "SELECT u.id, CONCAT(u.full_name, ' — ', r.name) FROM users u JOIN roles r ON r.id = u.role_id
    WHERE r.name IN ('Super Admin', 'Property Manager', 'Accountant') AND u.status = 'Active' ORDER BY u.full_name";

require_module('enquiries');
try {
    db()->query('SELECT 1 FROM enquiries LIMIT 1');
} catch (PDOException $e) {
    $page_title = 'Enquiries';
    include __DIR__ . '/../includes/layout_top.php';
    echo '<h1 class="text-2xl font-bold text-gray-900">Enquiries</h1><p class="notice notice-warning">The enquiries table is missing. Apply <code>database/migrations/001_create_enquiries.sql</code> to this database, then reload.</p>';
    include __DIR__ . '/../includes/layout_bottom.php';
    exit();
}

crud_run([
    'module' => 'enquiries',
    'table' => 'enquiries',
    'singular' => 'Enquiry',
    'plural' => 'Enquiries',
    'description' => 'Messages sent from the public Contact page. Record your reply and close them when done.',
    'allow_create' => false,
    'fields' => [
        'full_name' => ['label' => 'Name', 'required' => true, 'max' => 100],
        'email' => ['label' => 'Email', 'type' => 'email', 'required' => true, 'max' => 150],
        'phone' => ['label' => 'Phone', 'type' => 'tel', 'max' => 20],
        'status' => ['label' => 'Status', 'type' => 'select', 'options' => ENQUIRY_STATUSES, 'required' => true],
        'handled_by' => ['label' => 'Handled by', 'type' => 'select', 'options_sql' => $staffOptions],
        'message' => ['label' => 'Message', 'type' => 'textarea', 'required' => true],
        'response_notes' => ['label' => 'Response notes', 'type' => 'textarea', 'help' => 'How you replied (call, email, viewing booked). Internal only.'],
    ],
    'list' => [
        'sql' => 'SELECT q.id, q.full_name, q.email, q.phone, q.message, q.status, q.created_at, s.full_name AS handler_name FROM enquiries q LEFT JOIN users s ON s.id = q.handled_by',
        'columns' => [
            'created_at' => ['label' => 'Received', 'format' => 'date'],
            'full_name' => ['label' => 'From', 'format' => fn($row) => '<strong>' . e($row['full_name']) . '</strong><div class="text-xs text-gray-500"><a class="text-primary" href="mailto:' . e($row['email']) . '">' . e($row['email']) . '</a>' . ($row['phone'] ? ' · ' . e($row['phone']) : '') . '</div>'],
            'message' => ['label' => 'Message', 'format' => fn($row) => '<span class="text-gray-700">' . e(mb_strimwidth($row['message'], 0, 120, '…')) . '</span>'],
            'handler_name' => ['label' => 'Handled by'],
            'status' => ['label' => 'Status', 'format' => 'badge'],
        ],
        'search' => ['q.full_name', 'q.email', 'q.phone', 'q.message'],
        'filters' => ['status' => ['label' => 'Status', 'column' => 'q.status', 'options' => ENQUIRY_STATUSES]],
        'order' => "FIELD(q.status, 'New', 'In Progress', 'Resolved', 'Closed'), q.created_at DESC",
    ],
    'hooks' => [
        'prepare' => function (array $data, ?array $old) {
            // Picking up a new enquiry assigns it to whoever saves it.
            if ($data['status'] !== 'New' && empty($data['handled_by'])) $data['handled_by'] = $_SESSION['user_id'];
            return $data;
        },
    ],
]);
