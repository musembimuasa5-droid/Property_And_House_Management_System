<?php
require_once __DIR__ . '/../includes/crud.php';

const EXPENSE_CATEGORIES = ['Maintenance', 'Security', 'Utilities', 'Cleaning', 'Staff', 'Repairs', 'Management', 'Other'];
$propertyOptions = 'SELECT id, name FROM properties ORDER BY name';

crud_run([
    'module' => 'expenses',
    'table' => 'expenses',
    'singular' => 'Expense',
    'plural' => 'Expenses',
    'description' => 'Property running costs. These are subtracted from collections in the income statement report.',
    'fields' => [
        'property_id' => ['label' => 'Property', 'type' => 'select', 'options_sql' => $propertyOptions, 'required' => true],
        'category' => ['label' => 'Category', 'type' => 'select', 'options' => EXPENSE_CATEGORIES, 'required' => true],
        'description' => ['label' => 'Description', 'required' => true, 'max' => 255, 'wide' => true],
        'amount' => ['label' => 'Amount (KSh)', 'type' => 'decimal', 'min' => 0.01, 'max' => 100000000, 'required' => true],
        'expense_date' => ['label' => 'Date', 'type' => 'date', 'required' => true, 'default' => fn() => date('Y-m-d')],
        'vendor' => ['label' => 'Vendor / payee', 'max' => 150],
    ],
    'list' => [
        'sql' => 'SELECT e.id, e.expense_date, e.category, e.description, e.vendor, e.amount, e.property_id, p.name AS property_name, rb.full_name AS recorded_by_name
            FROM expenses e JOIN properties p ON p.id = e.property_id JOIN users rb ON rb.id = e.recorded_by',
        'columns' => [
            'expense_date' => ['label' => 'Date', 'format' => 'date'],
            'property_name' => ['label' => 'Property'],
            'category' => ['label' => 'Category'],
            'description' => ['label' => 'Description', 'format' => fn($row) => e($row['description']) . ($row['vendor'] ? '<div class="text-xs text-gray-500">' . e($row['vendor']) . '</div>' : '')],
            'amount' => ['label' => 'Amount', 'format' => fn($row) => '<strong>' . format_currency($row['amount']) . '</strong>'],
            'recorded_by_name' => ['label' => 'Recorded by'],
        ],
        'search' => ['e.description', 'e.vendor', 'p.name'],
        'filters' => [
            'property_id' => ['label' => 'Property', 'column' => 'e.property_id', 'options_sql' => $propertyOptions],
            'category' => ['label' => 'Category', 'column' => 'e.category', 'options' => EXPENSE_CATEGORIES],
        ],
        'order' => 'e.expense_date DESC, e.id DESC',
    ],
    'hooks' => [
        'prepare' => function (array $data, ?array $old) {
            if (!$old) $data['recorded_by'] = $_SESSION['user_id'];
            return $data;
        },
    ],
]);
