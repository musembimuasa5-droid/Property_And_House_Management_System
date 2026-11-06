<?php
require_once __DIR__ . '/../includes/crud.php';
require_once __DIR__ . '/../includes/finance.php';

$typeOptions = "SELECT id, CONCAT(name, ' (KSh ', FORMAT(default_rate, 2), ' / ', unit_of_measure, ')') FROM utility_types ORDER BY name";

crud_run([
    'module' => 'utility_readings',
    'table' => 'utility_readings',
    'singular' => 'Utility reading',
    'plural' => 'Utility Readings',
    'description' => "Meter readings per unit. Totals flow into that period's rent charge automatically.",
    'fields' => [
        'unit_id' => ['label' => 'Unit', 'type' => 'select', 'required' => true,
            'options_sql' => "SELECT u.id, CONCAT(p.name, ' / ', u.unit_number) FROM units u JOIN properties p ON p.id = u.property_id ORDER BY p.name, u.unit_number"],
        'utility_type_id' => ['label' => 'Utility', 'type' => 'select', 'options_sql' => $typeOptions, 'required' => true],
        'billing_period' => ['label' => 'Billing period', 'type' => 'month', 'required' => true, 'default' => fn() => date('Y-m')],
        'reading_date' => ['label' => 'Reading date', 'type' => 'date', 'required' => true, 'default' => fn() => date('Y-m-d')],
        'previous_reading' => ['label' => 'Previous reading', 'type' => 'decimal', 'min' => 0, 'help' => 'Leave blank to use the last recorded reading for this unit and utility.'],
        'current_reading' => ['label' => 'Current reading', 'type' => 'decimal', 'min' => 0, 'required' => true],
        'rate' => ['label' => 'Rate per unit (KSh)', 'type' => 'decimal', 'min' => 0, 'help' => "Leave blank to use the utility's default rate."],
    ],
    'list' => [
        'sql' => 'SELECT r.id, r.billing_period, r.previous_reading, r.current_reading, r.consumption, r.rate, r.total_amount, r.reading_date, r.utility_type_id, u.property_id,
                ty.name AS utility_name, ty.unit_of_measure, p.name AS property_name, u.unit_number
            FROM utility_readings r JOIN units u ON u.id = r.unit_id JOIN properties p ON p.id = u.property_id JOIN utility_types ty ON ty.id = r.utility_type_id',
        'columns' => [
            'billing_period' => ['label' => 'Period', 'format' => 'period'],
            'unit_number' => ['label' => 'Property / unit', 'format' => fn($row) => e($row['property_name'] . ' / ' . $row['unit_number'])],
            'utility_name' => ['label' => 'Utility'],
            'consumption' => ['label' => 'Usage', 'format' => fn($row) => e(rtrim(rtrim($row['consumption'], '0'), '.') . ' ' . $row['unit_of_measure']) . '<div class="text-xs text-gray-500">' . e($row['previous_reading'] . ' → ' . $row['current_reading']) . '</div>'],
            'rate' => ['label' => 'Rate', 'format' => 'currency'],
            'total_amount' => ['label' => 'Amount', 'format' => fn($row) => '<strong>' . format_currency($row['total_amount']) . '</strong>'],
        ],
        'search' => ['p.name', 'u.unit_number'],
        'filters' => [
            'billing_period' => ['label' => 'Period', 'column' => 'r.billing_period', 'options_sql' => 'SELECT DISTINCT billing_period, billing_period FROM utility_readings ORDER BY billing_period DESC'],
            'utility_type_id' => ['label' => 'Utility', 'column' => 'r.utility_type_id', 'options_sql' => 'SELECT id, name FROM utility_types ORDER BY name'],
            'property_id' => ['label' => 'Property', 'column' => 'u.property_id', 'options_sql' => 'SELECT id, name FROM properties ORDER BY name'],
        ],
        'order' => 'r.billing_period DESC, p.name, u.unit_number',
    ],
    'hooks' => [
        'prepare' => function (array $data, ?array $old, PDO $db) {
            if ($data['previous_reading'] === null) {
                $last = $db->prepare('SELECT current_reading FROM utility_readings WHERE unit_id = ? AND utility_type_id = ? AND id <> ? ORDER BY billing_period DESC, reading_date DESC, id DESC LIMIT 1');
                $last->execute([$data['unit_id'], $data['utility_type_id'], $old['id'] ?? 0]);
                $data['previous_reading'] = (float)($last->fetchColumn() ?: 0);
            }
            if ($data['rate'] === null) {
                $rate = $db->prepare('SELECT default_rate FROM utility_types WHERE id = ?');
                $rate->execute([$data['utility_type_id']]);
                $data['rate'] = (float)$rate->fetchColumn();
            }
            if ($data['current_reading'] < $data['previous_reading']) {
                throw new UserFacingException('The current reading (' . $data['current_reading'] . ') is lower than the previous reading (' . $data['previous_reading'] . ').');
            }
            $data['consumption'] = round($data['current_reading'] - $data['previous_reading'], 2);
            $data['total_amount'] = round($data['consumption'] * $data['rate'], 2);
            if (!$old) $data['recorded_by'] = $_SESSION['user_id'];
            return $data;
        },
        'after_save' => function (PDO $db, int $id, array $data, ?array $old) {
            sync_charge_utilities($db, (int)$data['unit_id'], $data['billing_period']);
            if ($old && ((int)$old['unit_id'] !== (int)$data['unit_id'] || $old['billing_period'] !== $data['billing_period'])) {
                sync_charge_utilities($db, (int)$old['unit_id'], $old['billing_period']);
            }
        },
        'after_delete' => fn(PDO $db, array $row) => sync_charge_utilities($db, (int)$row['unit_id'], $row['billing_period']),
    ],
]);
