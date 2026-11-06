<?php
require_once __DIR__ . '/../includes/crud.php';

const VIEWING_STATUSES = ['Pending', 'Approved', 'Rescheduled', 'Completed', 'Rejected'];
$propertyOptions = 'SELECT id, name FROM properties ORDER BY name';

crud_run([
    'module' => 'viewings',
    'table' => 'viewing_requests',
    'singular' => 'Viewing request',
    'plural' => 'Viewing Requests',
    'description' => 'Visits requested from the public property pages. Approve, reschedule or close them here.',
    'allow_create' => false,
    'fields' => [
        'visitor_name' => ['label' => 'Visitor name', 'required' => true, 'max' => 100],
        'visitor_phone' => ['label' => 'Visitor phone', 'type' => 'tel', 'required' => true, 'max' => 20],
        'visitor_email' => ['label' => 'Visitor email', 'type' => 'email', 'required' => true, 'max' => 150],
        'property_id' => ['label' => 'Property', 'type' => 'select', 'options_sql' => $propertyOptions, 'required' => true],
        'unit_id' => ['label' => 'Unit', 'type' => 'select',
            'options_sql' => "SELECT u.id, CONCAT(p.name, ' / ', u.unit_number) FROM units u JOIN properties p ON p.id = u.property_id ORDER BY p.name, u.unit_number"],
        'preferred_date' => ['label' => 'Preferred date', 'type' => 'date', 'required' => true],
        'preferred_time' => ['label' => 'Preferred time', 'type' => 'time', 'required' => true],
        'status' => ['label' => 'Status', 'type' => 'select', 'options' => VIEWING_STATUSES, 'required' => true],
        'message' => ['label' => 'Visitor message', 'type' => 'textarea'],
    ],
    'list' => [
        'sql' => 'SELECT v.id, v.visitor_name, v.visitor_phone, v.visitor_email, v.preferred_date, v.preferred_time, v.status, v.created_at, v.property_id, p.name AS property_name
            FROM viewing_requests v JOIN properties p ON p.id = v.property_id',
        'columns' => [
            'visitor_name' => ['label' => 'Visitor', 'format' => fn($row) => '<strong>' . e($row['visitor_name']) . '</strong><div class="text-xs text-gray-500">' . e($row['visitor_phone'] . ' · ' . $row['visitor_email']) . '</div>'],
            'property_name' => ['label' => 'Property'],
            'preferred_date' => ['label' => 'Preferred slot', 'format' => fn($row) => e(format_date($row['preferred_date']) . ' ' . substr($row['preferred_time'], 0, 5))],
            'created_at' => ['label' => 'Requested', 'format' => 'date'],
            'status' => ['label' => 'Status', 'format' => 'badge'],
        ],
        'search' => ['v.visitor_name', 'v.visitor_phone', 'v.visitor_email', 'p.name'],
        'filters' => [
            'status' => ['label' => 'Status', 'column' => 'v.status', 'options' => VIEWING_STATUSES],
            'property_id' => ['label' => 'Property', 'column' => 'v.property_id', 'options_sql' => $propertyOptions],
        ],
        'order' => "FIELD(v.status, 'Pending', 'Rescheduled', 'Approved', 'Completed', 'Rejected'), v.preferred_date",
    ],
]);
