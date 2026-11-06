<?php
require_once __DIR__ . '/../includes/crud.php';

const MAINTENANCE_STATUSES = ['Submitted', 'Reviewed', 'Assigned', 'In Progress', 'Waiting', 'Completed', 'Cancelled'];
const MAINTENANCE_PRIORITIES = ['Low', 'Medium', 'High', 'Emergency'];
const MAINTENANCE_CATEGORIES = ['Plumbing', 'Electrical', 'Water', 'Security', 'Structural', 'Appliance', 'Cleaning', 'Other'];
$staffOptions = "SELECT u.id, CONCAT(u.full_name, ' — ', r.name) FROM users u JOIN roles r ON r.id = u.role_id
    WHERE r.name IN ('Caretaker', 'Property Manager', 'Super Admin') AND u.status = 'Active' ORDER BY r.name = 'Caretaker' DESC, u.full_name";

/** Point a request at the tenant's current unit; requests must belong to an active tenancy. */
function attach_active_lease(array $data, ?array $old, PDO $db, string $subject): array {
    if ($old && (int)$old['tenant_id'] === (int)$data['tenant_id']) return $data;
    $lease = $db->prepare("SELECT property_id, unit_id FROM leases WHERE tenant_id = ? AND status = 'Active' LIMIT 1");
    $lease->execute([$data['tenant_id']]);
    $lease = $lease->fetch();
    if (!$lease) throw new UserFacingException("That tenant has no active lease, so the $subject cannot be linked to a unit.");
    return array_merge($data, $lease);
}

crud_run([
    'module' => 'maintenance',
    'table' => 'maintenance_requests',
    'singular' => 'Maintenance request',
    'plural' => 'Maintenance',
    'description' => 'Repair requests from tenants. Assign a caretaker, track progress and record costs.',
    'fields' => [
        'tenant_id' => ['label' => 'Tenant', 'type' => 'select', 'required' => true, 'help' => 'The request is linked to the unit on their active lease.',
            'options_sql' => 'SELECT id, full_name FROM tenants ORDER BY full_name'],
        'category' => ['label' => 'Category', 'type' => 'select', 'options' => MAINTENANCE_CATEGORIES, 'required' => true],
        'priority' => ['label' => 'Priority', 'type' => 'select', 'options' => MAINTENANCE_PRIORITIES, 'required' => true, 'default' => 'Medium'],
        'status' => ['label' => 'Status', 'type' => 'select', 'options' => MAINTENANCE_STATUSES, 'required' => true, 'default' => 'Submitted'],
        'assigned_staff' => ['label' => 'Assigned to', 'type' => 'select', 'options_sql' => $staffOptions, 'help' => 'Caretakers see assigned requests on their dashboard.'],
        'estimated_cost' => ['label' => 'Estimated cost (KSh)', 'type' => 'decimal', 'min' => 0, 'required' => true, 'default' => 0],
        'actual_cost' => ['label' => 'Actual cost (KSh)', 'type' => 'decimal', 'min' => 0, 'required' => true, 'default' => 0],
        'description' => ['label' => 'Description', 'type' => 'textarea', 'required' => true],
    ],
    'list' => [
        'sql' => 'SELECT mr.id, mr.category, mr.description, mr.priority, mr.status, mr.created_at, mr.assigned_staff, mr.property_id, mr.actual_cost,
                t.full_name AS tenant_name, p.name AS property_name, u.unit_number, s.full_name AS staff_name
            FROM maintenance_requests mr JOIN tenants t ON t.id = mr.tenant_id JOIN properties p ON p.id = mr.property_id JOIN units u ON u.id = mr.unit_id
            LEFT JOIN users s ON s.id = mr.assigned_staff',
        'columns' => [
            'created_at' => ['label' => 'Reported', 'format' => 'date'],
            'category' => ['label' => 'Issue', 'format' => fn($row) => '<strong>' . e($row['category']) . '</strong><div class="text-xs text-gray-500 max-w-xs">' . e(mb_strimwidth($row['description'], 0, 90, '…')) . '</div>'],
            'unit_number' => ['label' => 'Property / unit', 'format' => fn($row) => e($row['property_name'] . ' / ' . $row['unit_number']) . '<div class="text-xs text-gray-500">' . e($row['tenant_name']) . '</div>'],
            'priority' => ['label' => 'Priority', 'format' => 'badge'],
            'status' => ['label' => 'Status', 'format' => 'badge'],
            'staff_name' => ['label' => 'Assigned to'],
        ],
        'search' => ['mr.description', 't.full_name', 'p.name', 'u.unit_number'],
        'filters' => [
            'status' => ['label' => 'Status', 'column' => 'mr.status', 'options' => MAINTENANCE_STATUSES],
            'priority' => ['label' => 'Priority', 'column' => 'mr.priority', 'options' => MAINTENANCE_PRIORITIES],
            'property_id' => ['label' => 'Property', 'column' => 'mr.property_id', 'options_sql' => 'SELECT id, name FROM properties ORDER BY name'],
            'assigned_staff' => ['label' => 'Assigned to', 'column' => 'mr.assigned_staff', 'options_sql' => $staffOptions],
        ],
        'order' => "mr.status IN ('Completed', 'Cancelled'), FIELD(mr.priority, 'Emergency', 'High', 'Medium', 'Low'), mr.created_at DESC",
    ],
    'hooks' => [
        'prepare' => function (array $data, ?array $old, PDO $db) {
            $data = attach_active_lease($data, $old, $db, 'request');
            if ($data['status'] === 'Completed') {
                $data['completed_date'] = ($old && $old['status'] === 'Completed') ? $old['completed_date'] : date('Y-m-d H:i:s');
            } else {
                $data['completed_date'] = null;
            }
            if (!empty($data['assigned_staff']) && $data['status'] === 'Submitted') $data['status'] = 'Assigned';
            return $data;
        },
        'after_save' => function (PDO $db, int $id, array $data, ?array $old) {
            if (!empty($data['assigned_staff']) && (int)($old['assigned_staff'] ?? 0) !== (int)$data['assigned_staff']) {
                notify_user($db, (int)$data['assigned_staff'], 'New maintenance assignment', "Request #$id ({$data['category']}, {$data['priority']} priority) has been assigned to you.");
            }
            if ($old && $old['status'] !== $data['status']) {
                $tenant = $db->prepare('SELECT user_id FROM tenants WHERE id = ?');
                $tenant->execute([$data['tenant_id']]);
                notify_user($db, $tenant->fetchColumn(), 'Maintenance request update', "Your {$data['category']} request #$id is now: {$data['status']}.");
            }
        },
    ],
]);
