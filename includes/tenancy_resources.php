<?php
/**
 * Tenancy and finance module definitions shared by the staff, landlord and caretaker portals.
 *
 * Each function returns the staff version when called with no owner. Passing an owner builds
 * a version limited to the properties that owner is responsible for: every lookup, list,
 * export, select option, edit and delete is then scoped in SQL, so changing an id in the URL
 * or the form cannot reach another landlord's records.
 *
 * An owner is ['landlord_id', landlords.id] or ['caretaker_id', users.id].
 */

require_once __DIR__ . '/crud.php';
require_once __DIR__ . '/finance.php';

const TENANT_STATUSES = ['Active', 'Inactive', 'Evicted', 'Blacklisted'];
const LEASE_STATUSES = ['Pending', 'Active', 'Renewed', 'Expired', 'Terminated'];
const PAYMENT_METHODS = ['M-Pesa', 'Bank', 'Cash', 'Card', 'Other'];
const DEPOSIT_TYPES = ['Received', 'Deduction', 'Refund'];

/**
 * Subquery returning the ids of the properties an owner is responsible for, or null for staff.
 * The column comes from this whitelist and the id is cast to an integer, so it is safe in SQL.
 */
function owner_properties_sql(?array $owner): ?string {
    if ($owner === null) return null;
    [$column, $id] = $owner;
    if (!in_array($column, ['landlord_id', 'caretaker_id'], true)) throw new InvalidArgumentException('Unknown owner column.');
    return "SELECT id FROM properties WHERE $column = " . (int)$id;
}

/** Registry key of a module in the portal of the owner (staff, landlord or caretaker). */
function owner_module(?array $owner, string $staff, ?string $landlord = null, ?string $caretaker = null): ?string {
    if ($owner === null) return $staff;
    return $owner[0] === 'landlord_id' ? $landlord : $caretaker;
}

/** "WHERE ..." for an options query, limited to the owner's properties through $column. */
function owner_where(?string $properties, string $column): string {
    return $properties ? " WHERE $column IN ($properties)" : '';
}

/** Link to a module page, or an empty string when that portal has no such module. */
function owner_link(?string $module, array $query, string $label, string $class = 'btn-secondary'): string {
    if ($module === null || !user_can_access_module($module)) return '';
    return '<a class="btn btn-small ' . $class . '" href="' . e(module_url($module, $query)) . '">' . e($label) . '</a>';
}

/** Leases a payment, charge or deposit entry can be recorded against, newest tenancies first. */
function lease_options_sql(?string $properties, bool $withDeposit = false): string {
    $label = $withDeposit
        ? "CONCAT(t.full_name, ' — ', p.name, ' / ', u.unit_number, ' (', l.status, ', deposit KSh ', FORMAT(l.deposit, 2), ')')"
        : "CONCAT(t.full_name, ' — ', p.name, ' / ', u.unit_number, ' (', l.status, ')')";
    return "SELECT l.id, $label FROM leases l JOIN tenants t ON t.id = l.tenant_id JOIN units u ON u.id = l.unit_id JOIN properties p ON p.id = l.property_id"
        . owner_where($properties, 'l.property_id') . " ORDER BY l.status = 'Active' DESC, t.full_name";
}

function property_options_sql(?string $properties): string {
    return 'SELECT id, name FROM properties' . owner_where($properties, 'id') . ' ORDER BY name';
}

// ---------------------------------------------------------------------------
// Tenants
// ---------------------------------------------------------------------------

/**
 * A landlord sees the tenants they registered and anyone who has leased one of their units.
 * Portal logins stay a staff decision: tenants link their own login by registering.
 */
function tenant_resource(?int $landlordId = null): array {
    $owner = $landlordId ? ['landlord_id', $landlordId] : null;
    $props = owner_properties_sql($owner);
    $leaseFilter = $props ? " AND l.property_id IN ($props)" : '';
    $chargeFilter = $props ? " AND rc.lease_id IN (SELECT id FROM leases WHERE property_id IN ($props))" : '';
    $reportsModule = owner_module($owner, 'reports', 'my_reports');
    $leasesModule = owner_module($owner, 'leases', 'my_leases');

    $fields = [
        'full_name' => ['label' => 'Full name', 'required' => true, 'max' => 100],
        'national_id' => ['label' => 'National ID / passport', 'required' => true, 'max' => 50],
        'phone' => ['label' => 'Phone', 'type' => 'tel', 'required' => true, 'max' => 20],
        'email' => ['label' => 'Email', 'type' => 'email', 'required' => true, 'max' => 150,
            'help' => $owner ? 'The tenant can create a portal login with this email and national ID on the Register page.' : null],
        'gender' => ['label' => 'Gender', 'type' => 'select', 'options' => ['Female', 'Male', 'Other'], 'required' => true],
        'date_of_birth' => ['label' => 'Date of birth', 'type' => 'date'],
        'occupation' => ['label' => 'Occupation', 'max' => 100],
        'employer' => ['label' => 'Employer', 'max' => 100],
        'emergency_contact_name' => ['label' => 'Emergency contact name', 'max' => 100],
        'emergency_contact_phone' => ['label' => 'Emergency contact phone', 'type' => 'tel', 'max' => 20],
        'user_id' => ['label' => 'Portal login', 'type' => 'select', 'help' => 'Optional. Accounts with the Tenant role.',
            'options_sql' => "SELECT u.id, CONCAT(u.full_name, ' (', u.email, ')') FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name = 'Tenant' ORDER BY u.full_name"],
        'status' => ['label' => 'Status', 'type' => 'select', 'options' => TENANT_STATUSES, 'required' => true, 'default' => 'Active'],
        'current_address' => ['label' => 'Previous / current address', 'type' => 'textarea'],
    ];
    if ($owner) unset($fields['user_id']);

    $resource = [
        'module' => $owner ? 'my_tenants' : 'tenants',
        'table' => 'tenants',
        'singular' => 'Tenant',
        'plural' => $owner ? 'My Tenants' : 'Tenants',
        'description' => $owner
            ? 'Tenants you have registered and tenants who rent in your properties. Balances cover your properties only.'
            : 'Tenant profiles, contacts and balances. Tenants who self-register with the same national ID and email are linked automatically.',
        'fields' => $fields,
        'list' => [
            'sql' => "SELECT t.id, t.full_name, t.national_id, t.phone, t.email, t.status,
                    (SELECT CONCAT(p.name, ' / ', u.unit_number) FROM leases l JOIN units u ON u.id = l.unit_id JOIN properties p ON p.id = l.property_id WHERE l.tenant_id = t.id AND l.status = 'Active'$leaseFilter LIMIT 1) AS current_unit,
                    COALESCE((SELECT SUM(rc.total_due) FROM rent_charges rc WHERE rc.tenant_id = t.id$chargeFilter), 0)
                      - COALESCE((SELECT SUM(pa.allocated_amount) FROM payment_allocations pa JOIN rent_charges rc ON rc.id = pa.rent_charge_id WHERE rc.tenant_id = t.id$chargeFilter), 0) AS balance
                FROM tenants t",
            'columns' => [
                'full_name' => ['label' => 'Tenant', 'format' => fn($row) => '<strong>' . e($row['full_name']) . '</strong><div class="text-xs text-gray-500">ID ' . e($row['national_id']) . '</div>'],
                'phone' => ['label' => 'Phone', 'format' => fn($row) => e($row['phone']) . '<div class="text-xs text-gray-500">' . e($row['email']) . '</div>'],
                'current_unit' => ['label' => 'Current unit'],
                'balance' => ['label' => 'Balance', 'format' => fn($row) => '<span class="font-semibold ' . ($row['balance'] > 0 ? 'text-red-700' : 'text-green-800') . '">' . format_currency($row['balance']) . '</span>'],
                'status' => ['label' => 'Status', 'format' => 'badge'],
            ],
            'search' => ['t.full_name', 't.national_id', 't.phone', 't.email'],
            'filters' => ['status' => ['label' => 'Status', 'column' => 't.status', 'options' => TENANT_STATUSES]],
            'order' => 't.full_name',
            'row_actions' => function ($row) use ($reportsModule, $leasesModule) {
                $html = owner_link($reportsModule, ['report' => 'statement', 'tenant_id' => $row['id']], 'Statement');
                if (!$row['current_unit']) $html .= owner_link($leasesModule, ['action' => 'create', 'tenant_id' => $row['id']], 'New lease', 'btn-accent');
                return $html;
            },
        ],
        'messages' => [
            'duplicate' => $owner
                ? 'A tenant with that national ID is already registered. Ask management to create the lease on your unit; the tenant then appears in your list.'
                : 'A tenant with that national ID already exists.',
            'in_use' => 'This tenant has leases or payments on record. Set the status to Inactive instead of deleting.',
        ],
        'hooks' => [
            'prepare' => function (array $data, ?array $old, PDO $db) use ($landlordId) {
                if ($landlordId) {
                    if (!$old) $data['landlord_id'] = $landlordId;
                    return $data;
                }
                if (!empty($data['user_id'])) {
                    $stmt = $db->prepare('SELECT COUNT(*) FROM tenants WHERE user_id = ? AND id <> ?');
                    $stmt->execute([$data['user_id'], $old['id'] ?? 0]);
                    if ((int)$stmt->fetchColumn() > 0) throw new UserFacingException('That portal login is already linked to another tenant.');
                }
                return $data;
            },
        ],
    ];
    if ($owner) {
        $mine = "landlord_id = $landlordId OR id IN (SELECT tenant_id FROM leases WHERE property_id IN ($props))";
        $resource['scope'] = ['find' => $mine, 'list' => "t.landlord_id = $landlordId OR t.id IN (SELECT tenant_id FROM leases WHERE property_id IN ($props))", 'params' => []];
    }
    return $resource;
}

/** Tenants a lease can be written for: all for staff, the landlord's own tenants otherwise. */
function tenant_options_sql(?int $landlordId): string {
    $where = '';
    if ($landlordId) {
        $props = owner_properties_sql(['landlord_id', $landlordId]);
        $where = " WHERE landlord_id = $landlordId OR id IN (SELECT tenant_id FROM leases WHERE property_id IN ($props))";
    }
    return "SELECT id, CONCAT(full_name, ' — ID ', national_id) FROM tenants$where ORDER BY full_name";
}

// ---------------------------------------------------------------------------
// Leases
// ---------------------------------------------------------------------------

function lease_resource(?int $landlordId = null): array {
    $owner = $landlordId ? ['landlord_id', $landlordId] : null;
    $props = owner_properties_sql($owner);
    $paymentsModule = owner_module($owner, 'payments', 'my_payments');
    $reportsModule = owner_module($owner, 'reports', 'my_reports');
    $conditionModule = owner_module($owner, 'condition_reports', 'my_condition_reports');

    $resource = [
        'module' => $owner ? 'my_leases' : 'leases',
        'table' => 'leases',
        'singular' => 'Lease',
        'plural' => $owner ? 'My Leases' : 'Leases',
        'description' => 'Tenancy agreements. Activating a lease marks the unit Occupied; ending it frees the unit.',
        'fields' => [
            'tenant_id' => ['label' => 'Tenant', 'type' => 'select', 'required' => true, 'options_sql' => tenant_options_sql($landlordId),
                'help' => $owner ? 'Missing someone? Add them under My Tenants first.' : null],
            'unit_id' => ['label' => 'Unit', 'type' => 'select', 'required' => true,
                'options_sql' => "SELECT u.id, CONCAT(p.name, ' / ', u.unit_number, ' (', u.status, ')') FROM units u JOIN properties p ON p.id = u.property_id"
                    . owner_where($props, 'u.property_id') . ' ORDER BY p.name, u.unit_number'],
            'start_date' => ['label' => 'Start date', 'type' => 'date', 'required' => true, 'default' => fn() => date('Y-m-d')],
            'end_date' => ['label' => 'End date', 'type' => 'date', 'required' => true, 'default' => fn() => date('Y-m-d', strtotime('+1 year -1 day'))],
            'monthly_rent' => ['label' => 'Monthly rent (KSh)', 'type' => 'decimal', 'min' => 0, 'help' => "Leave blank to use the unit's rent."],
            'deposit' => ['label' => 'Deposit (KSh)', 'type' => 'decimal', 'min' => 0, 'help' => "Leave blank to use the unit's deposit."],
            'payment_due_day' => ['label' => 'Rent due day of month', 'type' => 'number', 'min' => 1, 'max' => 28, 'required' => true, 'default' => 5],
            'notice_period_days' => ['label' => 'Notice period (days)', 'type' => 'number', 'min' => 0, 'max' => 365, 'required' => true, 'default' => 30],
            'rent_review_date' => ['label' => 'Rent review date', 'type' => 'date', 'help' => 'Optional. The date from which rent may next be reviewed, as agreed in the tenancy.'],
            'status' => ['label' => 'Status', 'type' => 'select', 'options' => LEASE_STATUSES, 'required' => true, 'default' => 'Active'],
            'special_terms' => ['label' => 'Special terms', 'type' => 'textarea'],
        ],
        'list' => [
            'sql' => 'SELECT l.id, l.tenant_id, l.start_date, l.end_date, l.monthly_rent, l.status, l.property_id, t.full_name AS tenant_name, p.name AS property_name, u.unit_number,
                    (SELECT COUNT(*) FROM condition_reports cr WHERE cr.lease_id = l.id AND cr.report_type = \'Move-in\') AS has_move_in
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
                'property_id' => ['label' => 'Property', 'column' => 'l.property_id', 'options_sql' => property_options_sql($props)],
            ],
            'order' => "FIELD(l.status, 'Active', 'Pending', 'Renewed', 'Expired', 'Terminated'), l.end_date",
            'row_actions' => function ($row) use ($paymentsModule, $reportsModule, $conditionModule) {
                $html = '';
                if ($row['status'] === 'Active') $html .= owner_link($paymentsModule, ['action' => 'create', 'lease_id' => $row['id']], 'Record payment', 'btn-accent');
                if (!$row['has_move_in'] && in_array($row['status'], ['Pending', 'Active'], true)) {
                    $html .= owner_link($conditionModule, ['action' => 'create', 'lease_id' => $row['id'], 'report_type' => 'Move-in'], 'Move-in report');
                }
                return $html . owner_link($reportsModule, ['report' => 'statement', 'tenant_id' => $row['tenant_id']], 'Statement');
            },
        ],
        'hooks' => [
            'prepare' => function (array $data, ?array $old, PDO $db) {
                if ($data['end_date'] <= $data['start_date']) throw new UserFacingException('The end date must be after the start date.');
                if (!empty($data['rent_review_date']) && $data['rent_review_date'] <= $data['start_date']) throw new UserFacingException('The rent review date must be after the start date.');

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
    ];
    if ($props) $resource['scope'] = ['find' => "property_id IN ($props)", 'list' => "l.property_id IN ($props)", 'params' => []];
    return $resource;
}

// ---------------------------------------------------------------------------
// Rent charges
// ---------------------------------------------------------------------------

function rent_charge_resource(?int $landlordId = null): array {
    $owner = $landlordId ? ['landlord_id', $landlordId] : null;
    $props = owner_properties_sql($owner);
    $leasesWord = $owner ? 'every active lease in your properties' : 'every active lease';

    $resource = [
        'module' => $owner ? 'my_rent_charges' : 'rent_charges',
        'table' => 'rent_charges',
        'singular' => 'Rent charge',
        'plural' => 'Rent Charges',
        'description' => 'Monthly invoices per lease. Payments are applied to the oldest unpaid charge automatically.',
        'fields' => [
            'lease_id' => ['label' => 'Lease', 'type' => 'select', 'options_sql' => lease_options_sql($props), 'required' => true, 'wide' => true],
            'billing_period' => ['label' => 'Billing period', 'type' => 'month', 'required' => true, 'default' => fn() => date('Y-m')],
            'due_date' => ['label' => 'Due date', 'type' => 'date', 'help' => "Leave blank to use the lease's rent due day."],
            'rent_amount' => ['label' => 'Rent (KSh)', 'type' => 'decimal', 'min' => 0, 'help' => "Leave blank to use the lease's monthly rent."],
            'service_charge' => ['label' => 'Service charge (KSh)', 'type' => 'decimal', 'min' => 0, 'required' => true, 'default' => 0],
            'water_bill' => ['label' => 'Utilities (KSh)', 'type' => 'decimal', 'min' => 0, 'required' => true, 'default' => 0, 'help' => 'Filled in automatically from utility readings for this unit and period.'],
            'previous_balance' => ['label' => 'Balance brought forward (KSh)', 'type' => 'decimal', 'min' => 0, 'required' => true, 'default' => 0,
                'help' => 'Only for arrears carried over from before this system. Unpaid charges here are already tracked.'],
        ],
        'list' => [
            'sql' => 'SELECT rc.id, rc.billing_period, rc.due_date, rc.total_due, l.property_id, t.full_name AS tenant_name, p.name AS property_name, u.unit_number,
                    COALESCE(pa.paid, 0) AS paid, rc.total_due - COALESCE(pa.paid, 0) AS balance, ' . CHARGE_LIVE_STATUS_SQL . ' AS live_status
                FROM rent_charges rc JOIN leases l ON l.id = rc.lease_id JOIN tenants t ON t.id = rc.tenant_id JOIN units u ON u.id = rc.unit_id JOIN properties p ON p.id = l.property_id
                LEFT JOIN ' . CHARGE_PAID_SUBQUERY . ' pa ON pa.rent_charge_id = rc.id',
            'columns' => [
                'billing_period' => ['label' => 'Period', 'format' => 'period'],
                'tenant_name' => ['label' => 'Tenant'],
                'unit_number' => ['label' => 'Property / unit', 'format' => fn($row) => e($row['property_name'] . ' / ' . $row['unit_number'])],
                'due_date' => ['label' => 'Due', 'format' => 'date'],
                'total_due' => ['label' => 'Total due', 'format' => 'currency'],
                'paid' => ['label' => 'Paid', 'format' => 'currency'],
                'balance' => ['label' => 'Balance', 'format' => fn($row) => '<strong>' . format_currency($row['balance']) . '</strong>'],
                'live_status' => ['label' => 'Status', 'format' => 'badge'],
            ],
            'search' => ['t.full_name', 'p.name', 'u.unit_number'],
            'filters' => [
                'billing_period' => ['label' => 'Period', 'column' => 'rc.billing_period', 'options_sql' => 'SELECT DISTINCT billing_period, billing_period FROM rent_charges ORDER BY billing_period DESC'],
                'status' => ['label' => 'Status', 'column' => CHARGE_LIVE_STATUS_SQL, 'options' => ['Unpaid', 'Partial', 'Overdue', 'Paid']],
                'property_id' => ['label' => 'Property', 'column' => 'l.property_id', 'options_sql' => property_options_sql($props)],
            ],
            'order' => 'rc.billing_period DESC, t.full_name',
            'panel' => function () use ($leasesWord) { ?>
                <form method="post" class="bg-white p-5 rounded-xl border border-gray-100 shadow-sm flex flex-col sm:flex-row sm:items-end gap-4">
                    <?php echo csrf_field(); ?><input type="hidden" name="_action" value="generate">
                    <div class="flex-1">
                        <h2 class="font-bold text-gray-900">Generate monthly charges</h2>
                        <p class="text-sm text-gray-500">Creates one charge for <?php echo e($leasesWord); ?> in the period (rent + service charge + recorded utilities). Leases already charged are skipped.</p>
                    </div>
                    <div><label for="generate-period" class="block text-xs font-semibold text-gray-500 uppercase mb-1">Billing period</label>
                        <input id="generate-period" type="month" name="period" required value="<?php echo e(date('Y-m')); ?>" pattern="\d{4}-\d{2}" class="px-3 py-2 border border-gray-300 rounded-lg text-sm"></div>
                    <button type="submit" class="btn btn-accent"><i class="fa-solid fa-bolt" aria-hidden="true"></i> Generate</button>
                </form>
                <form method="post" class="bg-white p-5 rounded-xl border border-gray-100 shadow-sm flex flex-col sm:flex-row sm:items-end gap-4" data-confirm="Send an arrears reminder to every tenant with an overdue balance?">
                    <?php echo csrf_field(); ?><input type="hidden" name="_action" value="remind">
                    <div class="flex-1">
                        <h2 class="font-bold text-gray-900">Arrears reminders</h2>
                        <p class="text-sm text-gray-500">Sends an in-system notice to each tenant with an overdue balance, stating the amount and the oldest unpaid due date. Tenants reminded in the last 24 hours are skipped.</p>
                    </div>
                    <button type="submit" class="btn btn-secondary"><i class="fa-solid fa-bell" aria-hidden="true"></i> Send reminders</button>
                </form>
            <?php },
        ],
        'actions' => [
            'generate' => function (PDO $db) use ($landlordId) {
                $period = $_POST['period'] ?? '';
                if (!is_string($period) || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) throw new UserFacingException('Choose a valid billing period.');
                $db->beginTransaction();
                [$created, $skipped] = generate_rent_charges($db, $period, $landlordId);
                $db->commit();
                logAuditAction('GENERATE', 'rent_charges', null, "Generated $created rent charges for $period" . ($landlordId ? " (landlord #$landlordId)" : ''));
                return "Created $created charge(s) for " . format_period($period) . ($skipped ? "; $skipped lease(s) were already charged." : '.');
            },
            'remind' => function (PDO $db) use ($landlordId) {
                [$sent, $skipped] = send_arrears_reminders($db, $landlordId);
                logAuditAction('REMIND', 'rent_charges', null, "Sent $sent arrears reminder(s); skipped $skipped" . ($landlordId ? " (landlord #$landlordId)" : ''));
                return "Sent $sent arrears reminder(s)." . ($skipped ? " $skipped tenant(s) were skipped: reminded in the last 24 hours or no portal login." : '');
            },
        ],
        'hooks' => [
            'prepare' => function (array $data, ?array $old, PDO $db) {
                $lease = $db->prepare('SELECT tenant_id, unit_id, monthly_rent, payment_due_day FROM leases WHERE id = ?');
                $lease->execute([$data['lease_id']]);
                $lease = $lease->fetch();
                $duplicate = $db->prepare('SELECT COUNT(*) FROM rent_charges WHERE lease_id = ? AND billing_period = ? AND id <> ?');
                $duplicate->execute([$data['lease_id'], $data['billing_period'], $old['id'] ?? 0]);
                if ((int)$duplicate->fetchColumn() > 0) throw new UserFacingException('This lease already has a charge for ' . format_period($data['billing_period']) . '. Edit that charge instead.');

                $data['tenant_id'] = $lease['tenant_id'];
                $data['unit_id'] = $lease['unit_id'];
                $data['rent_amount'] ??= $lease['monthly_rent'];
                $data['due_date'] ??= charge_due_date($data['billing_period'], (int)$lease['payment_due_day']);
                $data['total_due'] = round($data['rent_amount'] + $data['service_charge'] + $data['water_bill'] + $data['previous_balance'], 2);
                return $data;
            },
            'after_save' => function (PDO $db, int $id, array $data, ?array $old) {
                recalculate_lease_balances($db, (int)$data['lease_id']);
                if ($old && (int)$old['lease_id'] !== (int)$data['lease_id']) recalculate_lease_balances($db, (int)$old['lease_id']);
            },
            'after_delete' => fn(PDO $db, array $row) => recalculate_lease_balances($db, (int)$row['lease_id']),
        ],
    ];
    if ($props) $resource['scope'] = ['find' => "lease_id IN (SELECT id FROM leases WHERE property_id IN ($props))", 'list' => "l.property_id IN ($props)", 'params' => []];
    return $resource;
}

// ---------------------------------------------------------------------------
// Payments
// ---------------------------------------------------------------------------

function payment_resource(?int $landlordId = null): array {
    $owner = $landlordId ? ['landlord_id', $landlordId] : null;
    $props = owner_properties_sql($owner);
    $receiptPage = $owner ? '/landlord/receipt.php' : '/admin/receipt.php';

    $resource = [
        'module' => $owner ? 'my_payments' : 'payments',
        'table' => 'payments',
        'singular' => 'Payment',
        'plural' => 'Payments',
        'description' => 'Rent received. Each payment is applied to the oldest unpaid charge on its lease; overpayments carry forward.',
        // Landlords record and correct payments; deleting a receipted payment stays with Super Admin and Accountant.
        'delete_roles' => ['Super Admin', 'Accountant'],
        'fields' => [
            'lease_id' => ['label' => 'Lease', 'type' => 'select', 'required' => true, 'wide' => true, 'options_sql' => lease_options_sql($props)],
            'amount' => ['label' => 'Amount (KSh)', 'type' => 'decimal', 'min' => 1, 'max' => 100000000, 'required' => true],
            'payment_method' => ['label' => 'Method', 'type' => 'select', 'options' => PAYMENT_METHODS, 'required' => true, 'default' => 'M-Pesa'],
            'transaction_reference' => ['label' => 'Transaction reference', 'required' => true, 'max' => 100, 'help' => 'M-Pesa code, bank slip or cash book number. Must be unique.'],
            'payment_date' => ['label' => 'Date received', 'type' => 'date', 'required' => true, 'default' => fn() => date('Y-m-d')],
            'payment_period' => ['label' => 'For period', 'type' => 'month', 'required' => true, 'default' => fn() => date('Y-m')],
            'notes' => ['label' => 'Notes', 'type' => 'textarea'],
        ],
        'list' => [
            'sql' => 'SELECT pay.id, pay.receipt_number, pay.payment_date, pay.amount, pay.payment_method, pay.transaction_reference, pay.payment_period, pay.property_id,
                    t.full_name AS tenant_name, p.name AS property_name, u.unit_number, rb.full_name AS recorded_by_name
                FROM payments pay JOIN tenants t ON t.id = pay.tenant_id JOIN properties p ON p.id = pay.property_id JOIN units u ON u.id = pay.unit_id JOIN users rb ON rb.id = pay.recorded_by',
            'columns' => [
                'receipt_number' => ['label' => 'Receipt', 'format' => fn($row) => '<strong>' . e($row['receipt_number']) . '</strong><div class="text-xs text-gray-500">Ref ' . e($row['transaction_reference']) . '</div>'],
                'payment_date' => ['label' => 'Date', 'format' => 'date'],
                'tenant_name' => ['label' => 'Tenant'],
                'unit_number' => ['label' => 'Property / unit', 'format' => fn($row) => e($row['property_name'] . ' / ' . $row['unit_number'])],
                'payment_method' => ['label' => 'Method', 'format' => 'badge'],
                'amount' => ['label' => 'Amount', 'format' => fn($row) => '<strong class="text-green-800">' . format_currency($row['amount']) . '</strong>'],
                'recorded_by_name' => ['label' => 'Recorded by'],
            ],
            'search' => ['pay.receipt_number', 'pay.transaction_reference', 't.full_name', 'u.unit_number'],
            'filters' => [
                'payment_method' => ['label' => 'Method', 'column' => 'pay.payment_method', 'options' => PAYMENT_METHODS],
                'payment_period' => ['label' => 'Period', 'column' => 'pay.payment_period', 'options_sql' => 'SELECT DISTINCT payment_period, payment_period FROM payments ORDER BY payment_period DESC'],
                'property_id' => ['label' => 'Property', 'column' => 'pay.property_id', 'options_sql' => property_options_sql($props)],
            ],
            'order' => 'pay.payment_date DESC, pay.id DESC',
            'row_actions' => fn($row) => '<a class="btn btn-small btn-secondary" href="' . e(BASE_URL . $receiptPage . '?id=' . (int)$row['id']) . '"><i class="fa-solid fa-print" aria-hidden="true"></i> Receipt</a>',
        ],
        'messages' => ['duplicate' => 'That transaction reference has already been recorded. Check for a duplicate payment.'],
        'hooks' => [
            'prepare' => function (array $data, ?array $old, PDO $db) {
                if ($data['payment_date'] > date('Y-m-d')) throw new UserFacingException('The payment date cannot be in the future.');
                $lease = $db->prepare('SELECT tenant_id, property_id, unit_id FROM leases WHERE id = ?');
                $lease->execute([$data['lease_id']]);
                $data += $lease->fetch();
                if (!$old) {
                    $data['receipt_number'] = generate_receipt_number();
                    $data['recorded_by'] = $_SESSION['user_id'];
                }
                return $data;
            },
            'after_save' => function (PDO $db, int $id, array $data, ?array $old) {
                recalculate_lease_balances($db, (int)$data['lease_id']);
                if ($old && (int)$old['lease_id'] !== (int)$data['lease_id']) recalculate_lease_balances($db, (int)$old['lease_id']);
            },
            'after_delete' => fn(PDO $db, array $row) => recalculate_lease_balances($db, (int)$row['lease_id']),
        ],
    ];
    if ($props) $resource['scope'] = ['find' => "property_id IN ($props)", 'list' => "pay.property_id IN ($props)", 'params' => []];
    return $resource;
}

// ---------------------------------------------------------------------------
// Deposit ledger
// ---------------------------------------------------------------------------

function deposit_resource(?int $landlordId = null): array {
    $owner = $landlordId ? ['landlord_id', $landlordId] : null;
    $props = owner_properties_sql($owner);
    $conditionModule = owner_module($owner, 'condition_reports', 'my_condition_reports');
    $panelFilter = $props ? " AND l.property_id IN ($props)" : '';

    $resource = [
        'module' => $owner ? 'my_deposits' : 'deposits',
        'table' => 'deposit_transactions',
        'singular' => 'Deposit entry',
        'plural' => 'Deposit Ledger',
        'description' => 'Deposits received, itemised deductions with reasons, and refunds. Tenants see their own ledger in the portal.',
        'delete_roles' => ['Super Admin', 'Accountant'],
        'fields' => [
            'lease_id' => ['label' => 'Lease', 'type' => 'select', 'required' => true, 'wide' => true, 'options_sql' => lease_options_sql($props, true)],
            'transaction_type' => ['label' => 'Entry type', 'type' => 'select', 'options' => DEPOSIT_TYPES, 'required' => true, 'default' => 'Received'],
            'amount' => ['label' => 'Amount (KSh)', 'type' => 'decimal', 'min' => 0.01, 'max' => 100000000, 'required' => true],
            'transaction_date' => ['label' => 'Date', 'type' => 'date', 'required' => true, 'default' => fn() => date('Y-m-d')],
            'reason' => ['label' => 'Reason', 'max' => 255, 'wide' => true, 'help' => 'Required for a deduction, e.g. "Replace broken kitchen window pane".'],
            'condition_report_id' => ['label' => 'Supporting condition report', 'type' => 'select', 'wide' => true,
                'help' => 'Link a deduction to the move-out report that shows the damage.',
                'options_sql' => "SELECT cr.id, CONCAT(cr.report_type, ' report ', DATE_FORMAT(cr.inspection_date, '%d %b %Y'), ' — ', t.full_name, ' / ', u.unit_number)
                    FROM condition_reports cr JOIN leases l ON l.id = cr.lease_id JOIN tenants t ON t.id = l.tenant_id JOIN units u ON u.id = l.unit_id"
                    . owner_where($props, 'l.property_id') . ' ORDER BY cr.inspection_date DESC'],
            'reference' => ['label' => 'Reference', 'max' => 100, 'help' => 'M-Pesa code, bank slip or cash book number, if any.'],
        ],
        'list' => [
            'sql' => "SELECT d.id, d.transaction_date, d.transaction_type, d.amount, d.reason, d.reference, d.condition_report_id, l.property_id,
                    t.full_name AS tenant_name, p.name AS property_name, u.unit_number, rb.full_name AS recorded_by_name
                FROM deposit_transactions d JOIN leases l ON l.id = d.lease_id JOIN tenants t ON t.id = l.tenant_id JOIN units u ON u.id = l.unit_id
                JOIN properties p ON p.id = l.property_id JOIN users rb ON rb.id = d.recorded_by",
            'columns' => [
                'transaction_date' => ['label' => 'Date', 'format' => 'date'],
                'tenant_name' => ['label' => 'Tenant', 'format' => fn($row) => e($row['tenant_name']) . '<div class="text-xs text-gray-500">' . e($row['property_name'] . ' / ' . $row['unit_number']) . '</div>'],
                'transaction_type' => ['label' => 'Entry', 'format' => 'badge'],
                'amount' => ['label' => 'Amount', 'format' => fn($row) => '<strong>' . ($row['transaction_type'] === 'Received' ? '' : '− ') . format_currency($row['amount']) . '</strong>'],
                'reason' => ['label' => 'Reason', 'format' => fn($row) => e($row['reason'] ?? '')
                    . ($row['condition_report_id'] ? '<div class="text-xs"><a class="text-primary" href="' . e(module_url($conditionModule, ['action' => 'view', 'id' => $row['condition_report_id']])) . '">See condition report</a></div>' : '')],
                'reference' => ['label' => 'Reference'],
                'recorded_by_name' => ['label' => 'Recorded by'],
            ],
            'search' => ['t.full_name', 'u.unit_number', 'd.reason', 'd.reference'],
            'filters' => [
                'transaction_type' => ['label' => 'Entry', 'column' => 'd.transaction_type', 'options' => DEPOSIT_TYPES],
                'property_id' => ['label' => 'Property', 'column' => 'l.property_id', 'options_sql' => property_options_sql($props)],
            ],
            'order' => 'd.transaction_date DESC, d.id DESC',
            'panel' => function (PDO $db) use ($panelFilter) {
                $totals = $db->query("SELECT
                        COALESCE(SUM(CASE d.transaction_type WHEN 'Received' THEN d.amount ELSE -d.amount END), 0) AS held,
                        COALESCE(SUM(CASE WHEN d.transaction_type = 'Deduction' AND YEAR(d.transaction_date) = YEAR(CURDATE()) THEN d.amount END), 0) AS deducted_year
                    FROM deposit_transactions d JOIN leases l ON l.id = d.lease_id WHERE 1 = 1$panelFilter")->fetch();
                $due = $db->query("SELECT COUNT(*) AS leases, COALESCE(SUM(held), 0) AS amount FROM (
                        SELECT SUM(CASE d.transaction_type WHEN 'Received' THEN d.amount ELSE -d.amount END) AS held
                        FROM leases l JOIN deposit_transactions d ON d.lease_id = l.id
                        WHERE l.status IN ('Terminated', 'Expired')$panelFilter GROUP BY l.id HAVING held > 0.004) AS ended")->fetch();
                $tiles = [['Deposits held', format_currency($totals['held'])], ['Due for refund', (int)$due['leases'] . ' lease(s) · ' . format_currency($due['amount'])], ['Deducted this year', format_currency($totals['deducted_year'])]];
                echo '<div class="grid grid-cols-1 sm:grid-cols-3 gap-4">';
                foreach ($tiles as [$label, $value]) echo '<div class="bg-white p-4 rounded-xl border border-gray-100 shadow-sm"><p class="text-xs font-semibold uppercase text-gray-500">' . e($label) . '</p><p class="text-lg font-bold text-gray-900 mt-1">' . e($value) . '</p></div>';
                echo '</div>';
            },
        ],
        'hooks' => [
            'prepare' => function (array $data, ?array $old, PDO $db) {
                if ($data['transaction_date'] > date('Y-m-d')) throw new UserFacingException('The date cannot be in the future.');
                if ($data['transaction_type'] === 'Deduction' && ($data['reason'] ?? null) === null) {
                    throw new UserFacingException('Give the reason for the deduction, so the tenant can see what it covers.');
                }
                if (!empty($data['condition_report_id'])) {
                    $report = $db->prepare('SELECT lease_id FROM condition_reports WHERE id = ?');
                    $report->execute([$data['condition_report_id']]);
                    if ((int)$report->fetchColumn() !== (int)$data['lease_id']) throw new UserFacingException('That condition report belongs to a different lease.');
                }
                $error = deposit_entry_error($db, (int)$data['lease_id'], $data['transaction_type'], (float)$data['amount'], (int)($old['id'] ?? 0));
                if ($error) throw new UserFacingException($error);
                if (!$old) $data['recorded_by'] = $_SESSION['user_id'];
                return $data;
            },
            'after_save' => function (PDO $db, int $id, array $data, ?array $old) {
                if ($old || $data['transaction_type'] === 'Received') return;
                $tenant = $db->prepare('SELECT t.user_id FROM leases l JOIN tenants t ON t.id = l.tenant_id WHERE l.id = ?');
                $tenant->execute([$data['lease_id']]);
                $what = $data['transaction_type'] === 'Refund' ? 'refunded to you' : 'deducted for: ' . $data['reason'];
                notify_user($db, $tenant->fetchColumn(), 'Deposit update', format_currency($data['amount']) . " of your deposit was $what. See Deposit & Condition for details.");
            },
            // Removing money received must not leave deductions or refunds larger than the deposit held.
            'before_delete' => function (PDO $db, array $row) {
                if ($row['transaction_type'] !== 'Received') return;
                $summary = deposit_summary($db, (int)$row['lease_id'], (int)$row['id']);
                if ($summary['held'] < -0.004) throw new UserFacingException('Deductions or refunds depend on this receipt. Delete them first.');
            },
        ],
    ];
    if ($props) $resource['scope'] = ['find' => "lease_id IN (SELECT id FROM leases WHERE property_id IN ($props))", 'list' => "l.property_id IN ($props)", 'params' => []];
    return $resource;
}

// ---------------------------------------------------------------------------
// Utility (meter) readings
// ---------------------------------------------------------------------------

/** Readings for staff, a landlord's own units, or the units a caretaker looks after. Caretakers cannot delete readings. */
function utility_reading_resource(?array $owner = null): array {
    $props = owner_properties_sql($owner);
    $typeOptions = "SELECT id, CONCAT(name, ' (KSh ', FORMAT(default_rate, 2), ' / ', unit_of_measure, ')') FROM utility_types ORDER BY name";
    $isCaretaker = ($owner[0] ?? null) === 'caretaker_id';

    $resource = [
        'module' => owner_module($owner, 'utility_readings', 'my_readings', 'care_readings'),
        'table' => 'utility_readings',
        'singular' => 'Utility reading',
        'plural' => $isCaretaker ? 'Meter Readings' : 'Utility Readings',
        'description' => $isCaretaker
            ? 'Water and other meter readings for the units you look after. Totals flow into that month\'s rent charge automatically.'
            : "Meter readings per unit. Totals flow into that period's rent charge automatically.",
        'allow_delete' => !$isCaretaker,
        'fields' => [
            'unit_id' => ['label' => 'Unit', 'type' => 'select', 'required' => true,
                'options_sql' => "SELECT u.id, CONCAT(p.name, ' / ', u.unit_number) FROM units u JOIN properties p ON p.id = u.property_id"
                    . owner_where($props, 'u.property_id') . ' ORDER BY p.name, u.unit_number'],
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
                'property_id' => ['label' => 'Property', 'column' => 'u.property_id', 'options_sql' => property_options_sql($props)],
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
    ];
    if ($props) $resource['scope'] = ['find' => "unit_id IN (SELECT id FROM units WHERE property_id IN ($props))", 'list' => "u.property_id IN ($props)", 'params' => []];
    return $resource;
}
