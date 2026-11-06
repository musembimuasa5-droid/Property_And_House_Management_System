<?php
require_once __DIR__ . '/../includes/crud.php';

crud_run([
    'module' => 'utility_types',
    'table' => 'utility_types',
    'singular' => 'Utility type',
    'plural' => 'Utility Types',
    'description' => 'Billable utilities and their default rates, used when recording meter readings.',
    'fields' => [
        'name' => ['label' => 'Name', 'required' => true, 'max' => 50],
        'unit_of_measure' => ['label' => 'Unit of measure', 'required' => true, 'max' => 20, 'default' => 'Units', 'help' => 'For example M3, kWh or Month.'],
        'default_rate' => ['label' => 'Default rate (KSh)', 'type' => 'decimal', 'min' => 0, 'required' => true],
        'description' => ['label' => 'Description', 'max' => 255, 'wide' => true],
    ],
    'list' => [
        'sql' => 'SELECT ty.id, ty.name, ty.unit_of_measure, ty.default_rate, ty.description, (SELECT COUNT(*) FROM utility_readings r WHERE r.utility_type_id = ty.id) AS reading_count FROM utility_types ty',
        'columns' => [
            'name' => ['label' => 'Utility'],
            'default_rate' => ['label' => 'Default rate', 'format' => fn($row) => format_currency($row['default_rate']) . ' / ' . e($row['unit_of_measure'])],
            'description' => ['label' => 'Description'],
            'reading_count' => ['label' => 'Readings'],
        ],
        'search' => ['ty.name', 'ty.description'],
        'order' => 'ty.name',
    ],
    'messages' => ['in_use' => 'Readings have been recorded for this utility, so it cannot be deleted.'],
]);
