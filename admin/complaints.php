<?php
require_once __DIR__ . '/../includes/crud.php';

const COMPLAINT_STATUSES = ['Open', 'Under Review', 'Resolved', 'Dismissed'];
const COMPLAINT_CATEGORIES = ['Noise', 'Security', 'Water', 'Neighbors', 'Maintenance', 'Billing', 'Staff', 'Other'];
$staffOptions = "SELECT u.id, CONCAT(u.full_name, ' — ', r.name) FROM users u JOIN roles r ON r.id = u.role_id
    WHERE r.name IN ('Super Admin', 'Property Manager', 'Caretaker', 'Accountant') AND u.status = 'Active' ORDER BY u.full_name";

crud_run([
    'module' => 'complaints',
    'table' => 'complaints',
    'singular' => 'Complaint',
    'plural' => 'Complaints',
    'description' => 'Tenant complaints. Record the resolution so the tenant can see the outcome in their portal.',
    'fields' => [
        'tenant_id' => ['label' => 'Tenant', 'type' => 'select', 'required' => true, 'options_sql' => 'SELECT id, full_name FROM tenants ORDER BY full_name'],
        'category' => ['label' => 'Category', 'type' => 'select', 'options' => COMPLAINT_CATEGORIES, 'required' => true, 'default' => 'Other'],
        'subject' => ['label' => 'Subject', 'required' => true, 'max' => 150, 'wide' => true],
        'description' => ['label' => 'Details', 'type' => 'textarea', 'required' => true],
        'status' => ['label' => 'Status', 'type' => 'select', 'options' => COMPLAINT_STATUSES, 'required' => true, 'default' => 'Open'],
        'assigned_to' => ['label' => 'Handled by', 'type' => 'select', 'options_sql' => $staffOptions],
        'resolution_notes' => ['label' => 'Resolution notes', 'type' => 'textarea', 'help' => 'Visible to the tenant.'],
    ],
    'list' => [
        'sql' => 'SELECT c.id, c.category, c.subject, c.status, c.created_at, c.property_id, t.full_name AS tenant_name, p.name AS property_name, s.full_name AS handler_name
            FROM complaints c JOIN tenants t ON t.id = c.tenant_id JOIN properties p ON p.id = c.property_id LEFT JOIN users s ON s.id = c.assigned_to',
        'columns' => [
            'created_at' => ['label' => 'Received', 'format' => 'date'],
            'subject' => ['label' => 'Subject', 'format' => fn($row) => '<strong>' . e($row['subject']) . '</strong><div class="text-xs text-gray-500">' . e($row['category']) . '</div>'],
            'tenant_name' => ['label' => 'Tenant', 'format' => fn($row) => e($row['tenant_name']) . '<div class="text-xs text-gray-500">' . e($row['property_name']) . '</div>'],
            'handler_name' => ['label' => 'Handled by'],
            'status' => ['label' => 'Status', 'format' => 'badge'],
        ],
        'search' => ['c.subject', 'c.description', 't.full_name'],
        'filters' => [
            'status' => ['label' => 'Status', 'column' => 'c.status', 'options' => COMPLAINT_STATUSES],
            'category' => ['label' => 'Category', 'column' => 'c.category', 'options' => COMPLAINT_CATEGORIES],
            'property_id' => ['label' => 'Property', 'column' => 'c.property_id', 'options_sql' => 'SELECT id, name FROM properties ORDER BY name'],
        ],
        'order' => "FIELD(c.status, 'Open', 'Under Review', 'Resolved', 'Dismissed'), c.created_at DESC",
    ],
    'hooks' => [
        'prepare' => function (array $data, ?array $old, PDO $db) {
            if ($old && (int)$old['tenant_id'] === (int)$data['tenant_id']) return $data;
            $lease = $db->prepare("SELECT property_id FROM leases WHERE tenant_id = ? AND status = 'Active' LIMIT 1");
            $lease->execute([$data['tenant_id']]);
            $data['property_id'] = $lease->fetchColumn();
            if (!$data['property_id']) throw new UserFacingException('That tenant has no active lease, so the complaint cannot be linked to a property.');
            return $data;
        },
        'after_save' => function (PDO $db, int $id, array $data, ?array $old) {
            if ($old && $old['status'] !== $data['status']) {
                $tenant = $db->prepare('SELECT user_id FROM tenants WHERE id = ?');
                $tenant->execute([$data['tenant_id']]);
                notify_user($db, $tenant->fetchColumn(), 'Complaint update', "Your complaint \"{$data['subject']}\" is now: {$data['status']}.");
            }
        },
    ],
]);
