<?php
require_once __DIR__ . '/../includes/crud.php';
require_once __DIR__ . '/../includes/finance.php';

const LEASE_STATUSES = ['Pending', 'Active', 'Renewed', 'Expired', 'Terminated'];
$propertyOptions = 'SELECT id, name FROM properties ORDER BY name';

crud_run([
    'module' => 'leases',
    'table' => 'leases',
    'singular' => 'Lease',
    'plural' => 'Leases',
    'description' => 'Tenancy agreements. Activating a lease marks the unit Occupied; ending it frees the unit.',
    'fields' => [
        'tenant_id' => ['label' => 'Tenant', 'type' => 'select', 'required' => true,
            'options_sql' => "SELECT id, CONCAT(full_name, ' — ID ', national_id) FROM tenants ORDER BY full_name"],
        'unit_id' => ['label' => 'Unit', 'type' => 'select', 'required' => true,
            'options_sql' => "SELECT u.id, CONCAT(p.name, ' / ', u.unit_number, ' (', u.status, ')') FROM units u JOIN properties p ON p.id = u.property_id ORDER BY p.name, u.unit_number"],
        'start_date' => ['label' => 'Start date', 'type' => 'date', 'required' => true, 'default' => fn() => date('Y-m-d')],
        'end_date' => ['label' => 'End date', 'type' => 'date', 'required' => true, 'default' => fn() => date('Y-m-d', strtotime('+1 year -1 day'))],
        'monthly_rent' => ['label' => 'Monthly rent (KSh)', 'type' => 'decimal', 'min' => 0, 'help' => "Leave blank to use the unit's rent."],
        'deposit' => ['label' => 'Deposit (KSh)', 'type' => 'decimal', 'min' => 0, 'help' => "Leave blank to use the unit's deposit."],
        'payment_due_day' => ['label' => 'Rent due day of month', 'type' => 'number', 'min' => 1, 'max' => 28, 'required' => true, 'default' => 5],
        'notice_period_days' => ['label' => 'Notice period (days)', 'type' => 'number', 'min' => 0, 'max' => 365, 'required' => true, 'default' => 30],
        'status' => ['label' => 'Status', 'type' => 'select', 'options' => LEASE_STATUSES, 'required' => true, 'default' => 'Active'],
        'special_terms' => ['label' => 'Special terms', 'type' => 'textarea'],
    ],
    'list' => [
        'sql' => 'SELECT l.id, l.tenant_id, l.start_date, l.end_date, l.monthly_rent, l.status, l.property_id, t.full_name AS tenant_name, p.name AS property_name, u.unit_number
            FROM leases l JOIN tenants t ON t.id = l.tenant_id JOIN units u ON u.id = l.unit_id JOIN properties p ON p.id = l.property_id',
        'columns' => [
            'tenant_name' => ['label' => 'Tenant'],
            'unit_number' => ['label' => 'Property / unit', 'format' => fn($row) => e($row['property_name'] . ' / ' . $row['unit_number'])],
            'start_date' => ['label' => 'Starts', 'format' => 'date'],
            'end_date' => ['label' => 'Ends', 'format' => function ($row) {
                $days = (int)floor((strtotime($row['end_date']) - strtotime(date('Y-m-d'))) / 86400);
                $soon = $row['status'] === 'Active' && $days >= 0 && $days <= 60 ? ' <span class="text-xs text-orange-700 font-semibold">(' . $days . ' days)</span>' : '';
                return e(format_date($row['end_date'])) . $soon;
            }],
            'monthly_rent' => ['label' => 'Rent', 'format' => 'currency'],
            'status' => ['label' => 'Status', 'format' => 'badge'],
        ],
        'search' => ['t.full_name', 'p.name', 'u.unit_number'],
        'filters' => [
            'status' => ['label' => 'Status', 'column' => 'l.status', 'options' => LEASE_STATUSES],
            'property_id' => ['label' => 'Property', 'column' => 'l.property_id', 'options_sql' => $propertyOptions],
        ],
        'order' => "FIELD(l.status, 'Active', 'Pending', 'Renewed', 'Expired', 'Terminated'), l.end_date",
        'row_actions' => function ($row) {
            $html = '';
            if ($row['status'] === 'Active' && user_can_access_module('payments')) {
                $html .= '<a class="btn btn-small btn-accent" href="' . e(module_url('payments', ['action' => 'create', 'lease_id' => $row['id']])) . '">Record payment</a>';
            }
            if (user_can_access_module('reports')) {
                $html .= '<a class="btn btn-small btn-secondary" href="' . e(module_url('reports', ['report' => 'statement', 'tenant_id' => $row['tenant_id']])) . '">Statement</a>';
            }
            return $html;
        },
    ],
    'hooks' => [
        'prepare' => function (array $data, ?array $old, PDO $db) {
            if ($data['end_date'] <= $data['start_date']) throw new UserFacingException('The end date must be after the start date.');

            $unit = $db->prepare('SELECT property_id, monthly_rent, deposit, status FROM units WHERE id = ?');
            $unit->execute([$data['unit_id']]);
            $unit = $unit->fetch();
            $data['property_id'] = $unit['property_id'];
            $data['monthly_rent'] ??= $unit['monthly_rent'];
            $data['deposit'] ??= $unit['deposit'];

            if ($data['status'] === 'Active') {
                $selfId = $old['id'] ?? 0;
                $clash = $db->prepare("SELECT
                        (SELECT COUNT(*) FROM leases WHERE unit_id = ? AND status = 'Active' AND id <> ?),
                        (SELECT COUNT(*) FROM leases WHERE tenant_id = ? AND status = 'Active' AND id <> ?)");
                $clash->execute([$data['unit_id'], $selfId, $data['tenant_id'], $selfId]);
                [$unitTaken, $tenantBusy] = $clash->fetch(PDO::FETCH_NUM);
                if ($unitTaken) throw new UserFacingException('That unit already has an active lease. End it before activating a new one.');
                if ($tenantBusy) throw new UserFacingException('This tenant already has an active lease. End it first, or mark this one Pending.');
                if (in_array($unit['status'], ['Under Maintenance', 'Unavailable'], true)) throw new UserFacingException("That unit is marked {$unit['status']}. Update the unit before activating a lease.");
            }
            return $data;
        },
        'after_save' => function (PDO $db, int $id, array $data, ?array $old) {
            sync_unit_occupancy($db, (int)$data['unit_id']);
            if ($old && (int)$old['unit_id'] !== (int)$data['unit_id']) sync_unit_occupancy($db, (int)$old['unit_id']);
        },
        // Charges would cascade away with the lease, so keep billing history intact.
        'before_delete' => function (PDO $db, array $row) {
            $stmt = $db->prepare('SELECT (SELECT COUNT(*) FROM rent_charges WHERE lease_id = ?) + (SELECT COUNT(*) FROM payments WHERE lease_id = ?)');
            $stmt->execute([$row['id'], $row['id']]);
            if ((int)$stmt->fetchColumn() > 0) throw new UserFacingException('This lease has charges or payments. Set it to Terminated or Expired instead of deleting it.');
        },
        'after_delete' => fn(PDO $db, array $row) => sync_unit_occupancy($db, (int)$row['unit_id']),
    ],
]);
