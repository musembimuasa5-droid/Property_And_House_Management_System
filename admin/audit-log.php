<?php
require_once __DIR__ . '/../includes/crud.php';

/** Show an entry's before/after values as "field: old → new" lines. */
function audit_changes_html(array $row): string {
    $before = json_decode((string)$row['old_values'], true) ?: [];
    $after = json_decode((string)$row['new_values'], true) ?: [];
    $fields = array_unique(array_merge(array_keys($before), array_keys($after)));
    if (!$fields) return '<span class="text-gray-400">—</span>';
    $lines = [];
    foreach (array_slice($fields, 0, 8) as $field) {
        $old = array_key_exists($field, $before) ? (string)$before[$field] : '';
        $new = array_key_exists($field, $after) ? (string)$after[$field] : '';
        $lines[] = '<div><span class="font-semibold">' . e($field) . ':</span> '
            . ($old !== '' ? e(mb_strimwidth($old, 0, 40, '…')) . ' → ' : '') . e(mb_strimwidth($new, 0, 40, '…')) . '</div>';
    }
    if (count($fields) > 8) $lines[] = '<div class="text-gray-500">+' . (count($fields) - 8) . ' more</div>';
    return '<div class="text-xs text-gray-700 max-w-sm">' . implode('', $lines) . '</div>';
}

crud_run([
    'module' => 'audit_log',
    'table' => 'audit_logs',
    'singular' => 'Audit entry',
    'plural' => 'Audit Log',
    'description' => 'Who changed what and when: sign-ins, record changes with before and after values, exports and notices. Entries cannot be edited or deleted.',
    'allow_create' => false,
    'allow_edit' => false,
    'allow_delete' => false,
    'fields' => [],
    'list' => [
        'sql' => 'SELECT a.id, a.created_at, a.action, a.module, a.record_id, a.description, a.old_values, a.new_values, a.ip_address, a.user_id, u.full_name AS user_name
            FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id',
        'columns' => [
            'created_at' => ['label' => 'When', 'format' => fn($row) => e(date('d M Y, H:i', strtotime($row['created_at'])))],
            'user_name' => ['label' => 'User', 'format' => fn($row) => $row['user_name'] ? e($row['user_name']) : '<span class="text-gray-400">System / visitor</span>'],
            'action' => ['label' => 'Action', 'format' => fn($row) => '<strong>' . e($row['action']) . '</strong><div class="text-xs text-gray-500">' . e($row['module']) . ($row['record_id'] ? ' #' . (int)$row['record_id'] : '') . '</div>'],
            'description' => ['label' => 'Description'],
            'old_values' => ['label' => 'Changes', 'format' => fn($row) => audit_changes_html($row)],
            'ip_address' => ['label' => 'IP address'],
        ],
        'search' => ['a.description', 'a.module', 'a.action', 'u.full_name', 'a.ip_address'],
        'filters' => [
            'action' => ['label' => 'Action', 'column' => 'a.action', 'options_sql' => 'SELECT DISTINCT action, action FROM audit_logs ORDER BY action'],
            'module' => ['label' => 'Area', 'column' => 'a.module', 'options_sql' => 'SELECT DISTINCT module, module FROM audit_logs ORDER BY module'],
            'user_id' => ['label' => 'User', 'column' => 'a.user_id', 'options_sql' => 'SELECT id, full_name FROM users ORDER BY full_name'],
        ],
        'order' => 'a.id DESC',
    ],
]);
