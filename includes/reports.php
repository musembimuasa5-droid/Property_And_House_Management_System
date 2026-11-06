<?php
/**
 * Management reports.
 *
 * Each report_<key>() function returns a plain array (title, columns, rows,
 * totals, summary tiles and an optional breakdown table) that one renderer
 * shows as HTML and one exporter writes as CSV. Passing a landlord_id limits
 * every query to that landlord's properties, so the same code serves the
 * landlord portal safely.
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/finance.php';

function report_catalog(): array {
    return [
        'collections' => ['title' => 'Rent collection', 'icon' => 'fa-money-bill-wave', 'range' => 'year_to_date', 'landlord' => true,
            'description' => 'Payments received in the period, with totals by payment method.'],
        'arrears' => ['title' => 'Arrears', 'icon' => 'fa-file-invoice-dollar', 'range' => 'none', 'landlord' => true,
            'description' => 'Tenants with unpaid rent as of today, largest overdue balance first.'],
        'income' => ['title' => 'Income statement', 'icon' => 'fa-scale-balanced', 'range' => 'year_to_date', 'landlord' => true,
            'description' => 'Rent billed, collected, expenses and net income per property.'],
        'occupancy' => ['title' => 'Occupancy', 'icon' => 'fa-house-circle-check', 'range' => 'none', 'landlord' => true,
            'description' => 'Occupied and vacant units, occupancy rate and rent potential per property.'],
        'expenses' => ['title' => 'Expenses', 'icon' => 'fa-receipt', 'range' => 'year_to_date', 'landlord' => true,
            'description' => 'Property running costs in the period, grouped by category.'],
        'maintenance' => ['title' => 'Maintenance', 'icon' => 'fa-screwdriver-wrench', 'range' => 'year_to_date', 'landlord' => true,
            'description' => 'Requests raised in the period with status, cost and time to resolve.'],
        'leases' => ['title' => 'Lease expiry', 'icon' => 'fa-file-signature', 'range' => 'next_90_days', 'landlord' => true,
            'description' => 'Active leases ending in the period, so renewals can be planned.'],
        'statement' => ['title' => 'Tenant statement', 'icon' => 'fa-user-clock', 'range' => 'year_to_date', 'landlord' => false,
            'description' => 'Charges and payments for one tenant with a running balance.'],
    ];
}

/** Read and validate report parameters from the query string. */
function report_params(string $key, ?int $landlordId): array {
    $range = report_catalog()[$key]['range'];
    $defaults = $range === 'next_90_days'
        ? [date('Y-m-d'), date('Y-m-d', strtotime('+90 days'))]
        : [date('Y-01-01'), date('Y-m-d')];
    $date = function ($name, $fallback) {
        $value = is_string($_GET[$name] ?? null) ? $_GET[$name] : '';
        $parsed = DateTime::createFromFormat('!Y-m-d', $value);
        return $parsed && $parsed->format('Y-m-d') === $value ? $value : $fallback;
    };
    [$from, $to] = [$date('from', $defaults[0]), $date('to', $defaults[1])];
    if ($from > $to) [$from, $to] = [$to, $from];
    $int = fn($name) => filter_var($_GET[$name] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
    return ['from' => $from, 'to' => $to, 'property_id' => $int('property_id'), 'tenant_id' => $int('tenant_id'), 'landlord_id' => $landlordId];
}

/** WHERE fragment limiting properties ("p") to the landlord scope and the chosen property. */
function report_scope(array $params): array {
    $sql = '';
    $values = [];
    if ($params['landlord_id']) { $sql .= ' AND p.landlord_id = ?'; $values[] = $params['landlord_id']; }
    if ($params['property_id']) { $sql .= ' AND p.id = ?'; $values[] = $params['property_id']; }
    return [$sql, $values];
}

function report_query(PDO $db, string $sql, array $values): array {
    $stmt = $db->prepare($sql);
    $stmt->execute($values);
    return $stmt->fetchAll();
}

function report_sum(array $rows, array $columns): array {
    $totals = [];
    foreach ($columns as $column) $totals[$column] = array_sum(array_map(fn($row) => (float)$row[$column], $rows));
    return $totals;
}

function report_period_label(array $params): string {
    return format_date($params['from']) . ' – ' . format_date($params['to']);
}

function run_report(PDO $db, string $key, array $params): array {
    $report = ('report_' . $key)($db, $params);
    return $report + ['totals' => null, 'summary' => [], 'breakdown' => null, 'notice' => null];
}

function report_collections(PDO $db, array $params): array {
    [$scope, $values] = report_scope($params);
    $rows = report_query($db, "SELECT pay.payment_date, pay.receipt_number, t.full_name AS tenant, CONCAT(p.name, ' / ', u.unit_number) AS unit,
            pay.payment_method, pay.transaction_reference, pay.payment_period, pay.amount
        FROM payments pay JOIN tenants t ON t.id = pay.tenant_id JOIN properties p ON p.id = pay.property_id JOIN units u ON u.id = pay.unit_id
        WHERE pay.payment_date BETWEEN ? AND ? $scope ORDER BY pay.payment_date, pay.id", [$params['from'], $params['to'], ...$values]);

    $byMethod = [];
    foreach ($rows as $row) {
        $byMethod[$row['payment_method']] ??= ['method' => $row['payment_method'], 'count' => 0, 'amount' => 0];
        $byMethod[$row['payment_method']]['count']++;
        $byMethod[$row['payment_method']]['amount'] += $row['amount'];
    }
    $total = array_sum(array_column($rows, 'amount'));
    return [
        'title' => 'Rent collection',
        'subtitle' => 'Payments received ' . report_period_label($params),
        'columns' => [
            'payment_date' => ['label' => 'Date', 'format' => 'date'], 'receipt_number' => ['label' => 'Receipt'], 'tenant' => ['label' => 'Tenant'],
            'unit' => ['label' => 'Property / unit'], 'payment_method' => ['label' => 'Method'], 'transaction_reference' => ['label' => 'Reference'],
            'payment_period' => ['label' => 'For period', 'format' => 'period'], 'amount' => ['label' => 'Amount', 'format' => 'currency'],
        ],
        'rows' => $rows,
        'totals' => ['amount' => $total],
        'summary' => [['Total collected', format_currency($total)], ['Payments', count($rows)], ['Average payment', format_currency($rows ? $total / count($rows) : 0)]],
        'breakdown' => ['title' => 'By payment method', 'columns' => ['method' => ['label' => 'Method'], 'count' => ['label' => 'Payments', 'format' => 'number'], 'amount' => ['label' => 'Amount', 'format' => 'currency']],
            'rows' => array_values($byMethod)],
    ];
}

function report_arrears(PDO $db, array $params): array {
    [$scope, $values] = report_scope($params);
    $rows = report_query($db, "SELECT t.full_name AS tenant, t.phone, CONCAT(p.name, ' / ', u.unit_number) AS unit, l.status AS lease_status,
            SUM(rc.total_due) AS billed, SUM(COALESCE(pa.paid, 0)) AS paid, SUM(rc.total_due - COALESCE(pa.paid, 0)) AS balance,
            SUM(CASE WHEN rc.due_date < CURDATE() THEN rc.total_due - COALESCE(pa.paid, 0) ELSE 0 END) AS overdue,
            MIN(CASE WHEN rc.total_due - COALESCE(pa.paid, 0) > 0 THEN rc.due_date END) AS oldest_due
        FROM rent_charges rc JOIN leases l ON l.id = rc.lease_id JOIN tenants t ON t.id = rc.tenant_id JOIN units u ON u.id = rc.unit_id
        JOIN properties p ON p.id = l.property_id LEFT JOIN " . CHARGE_PAID_SUBQUERY . " pa ON pa.rent_charge_id = rc.id
        WHERE 1 = 1 $scope
        GROUP BY rc.lease_id, t.full_name, t.phone, p.name, u.unit_number, l.status
        HAVING balance > 0.004 ORDER BY overdue DESC, balance DESC", $values);
    $today = strtotime(date('Y-m-d'));
    foreach ($rows as &$row) $row['days_overdue'] = $row['oldest_due'] && strtotime($row['oldest_due']) < $today ? (int)(($today - strtotime($row['oldest_due'])) / 86400) : 0;
    unset($row);
    $totals = report_sum($rows, ['billed', 'paid', 'balance', 'overdue']);
    return [
        'title' => 'Arrears',
        'subtitle' => 'Unpaid rent as of ' . format_date(date('Y-m-d')),
        'columns' => [
            'tenant' => ['label' => 'Tenant'], 'phone' => ['label' => 'Phone'], 'unit' => ['label' => 'Property / unit'], 'lease_status' => ['label' => 'Lease', 'format' => 'badge'],
            'billed' => ['label' => 'Billed', 'format' => 'currency'], 'paid' => ['label' => 'Paid', 'format' => 'currency'],
            'balance' => ['label' => 'Balance', 'format' => 'currency'], 'overdue' => ['label' => 'Overdue', 'format' => 'currency'],
            'oldest_due' => ['label' => 'Oldest unpaid', 'format' => 'date'], 'days_overdue' => ['label' => 'Days overdue', 'format' => 'number'],
        ],
        'rows' => $rows,
        'totals' => $totals,
        'summary' => [['Total outstanding', format_currency($totals['balance'])], ['Overdue', format_currency($totals['overdue'])], ['Tenants in arrears', count(array_filter($rows, fn($row) => $row['overdue'] > 0))]],
    ];
}

function report_income(PDO $db, array $params): array {
    [$scope, $values] = report_scope($params);
    $range = [$params['from'], $params['to']];
    $rows = report_query($db, "SELECT p.name AS property,
            COALESCE((SELECT SUM(rc.total_due) FROM rent_charges rc JOIN leases l ON l.id = rc.lease_id WHERE l.property_id = p.id AND rc.due_date BETWEEN ? AND ?), 0) AS billed,
            COALESCE((SELECT SUM(pay.amount) FROM payments pay WHERE pay.property_id = p.id AND pay.payment_date BETWEEN ? AND ?), 0) AS collected,
            COALESCE((SELECT SUM(e.amount) FROM expenses e WHERE e.property_id = p.id AND e.expense_date BETWEEN ? AND ?), 0) AS expenses
        FROM properties p WHERE 1 = 1 $scope ORDER BY p.name", [...$range, ...$range, ...$range, ...$values]);
    foreach ($rows as &$row) {
        $row['net_income'] = $row['collected'] - $row['expenses'];
        $row['collection_rate'] = $row['billed'] > 0 ? $row['collected'] / $row['billed'] * 100 : null;
    }
    unset($row);
    $totals = report_sum($rows, ['billed', 'collected', 'expenses', 'net_income']);
    $totals['collection_rate'] = $totals['billed'] > 0 ? $totals['collected'] / $totals['billed'] * 100 : null;
    $categories = report_query($db, "SELECT e.category, COUNT(*) AS entries, SUM(e.amount) AS amount FROM expenses e JOIN properties p ON p.id = e.property_id
        WHERE e.expense_date BETWEEN ? AND ? $scope GROUP BY e.category ORDER BY amount DESC", [...$range, ...$values]);
    return [
        'title' => 'Income statement',
        'subtitle' => report_period_label($params),
        'columns' => [
            'property' => ['label' => 'Property'], 'billed' => ['label' => 'Rent billed', 'format' => 'currency'], 'collected' => ['label' => 'Collected', 'format' => 'currency'],
            'collection_rate' => ['label' => 'Collection rate', 'format' => 'percent'], 'expenses' => ['label' => 'Expenses', 'format' => 'currency'],
            'net_income' => ['label' => 'Net income', 'format' => 'currency'],
        ],
        'rows' => $rows,
        'totals' => $totals,
        'summary' => [['Collected', format_currency($totals['collected'])], ['Expenses', format_currency($totals['expenses'])], ['Net income', format_currency($totals['net_income'])],
            ['Collection rate', $totals['collection_rate'] === null ? '—' : number_format($totals['collection_rate'], 1) . '%']],
        'breakdown' => ['title' => 'Expenses by category', 'columns' => ['category' => ['label' => 'Category'], 'entries' => ['label' => 'Entries', 'format' => 'number'], 'amount' => ['label' => 'Amount', 'format' => 'currency']],
            'rows' => $categories],
        'notice' => 'Collected is cash received in the period. Rent billed counts charges falling due in the period. Maintenance costs are included only when recorded as expenses.',
    ];
}

function report_occupancy(PDO $db, array $params): array {
    [$scope, $values] = report_scope($params);
    $rows = report_query($db, "SELECT p.name AS property, CONCAT(loc.estate, ', ', loc.sub_county) AS location, COUNT(u.id) AS units,
            COALESCE(SUM(u.status = 'Occupied'), 0) AS occupied, COALESCE(SUM(u.status = 'Vacant'), 0) AS vacant,
            COALESCE(SUM(u.status NOT IN ('Occupied', 'Vacant')), 0) AS unavailable, COALESCE(SUM(u.monthly_rent), 0) AS potential_rent,
            COALESCE((SELECT SUM(l.monthly_rent) FROM leases l WHERE l.property_id = p.id AND l.status = 'Active'), 0) AS leased_rent
        FROM properties p JOIN locations loc ON loc.id = p.location_id LEFT JOIN units u ON u.property_id = p.id
        WHERE p.status <> 'Archived' $scope GROUP BY p.id, p.name, loc.estate, loc.sub_county ORDER BY p.name", $values);
    foreach ($rows as &$row) $row['occupancy_rate'] = $row['units'] > 0 ? $row['occupied'] / $row['units'] * 100 : null;
    unset($row);
    $totals = report_sum($rows, ['units', 'occupied', 'vacant', 'unavailable', 'potential_rent', 'leased_rent']);
    $totals['occupancy_rate'] = $totals['units'] > 0 ? $totals['occupied'] / $totals['units'] * 100 : null;
    return [
        'title' => 'Occupancy',
        'subtitle' => 'Unit status as of ' . format_date(date('Y-m-d')) . ' (archived properties excluded)',
        'columns' => [
            'property' => ['label' => 'Property'], 'location' => ['label' => 'Location'], 'units' => ['label' => 'Units', 'format' => 'number'],
            'occupied' => ['label' => 'Occupied', 'format' => 'number'], 'vacant' => ['label' => 'Vacant', 'format' => 'number'],
            'unavailable' => ['label' => 'Unavailable', 'format' => 'number'], 'occupancy_rate' => ['label' => 'Occupancy', 'format' => 'percent'],
            'potential_rent' => ['label' => 'Rent if full', 'format' => 'currency'], 'leased_rent' => ['label' => 'Leased rent', 'format' => 'currency'],
        ],
        'rows' => $rows,
        'totals' => $totals,
        'summary' => [['Occupancy rate', $totals['occupancy_rate'] === null ? '—' : number_format($totals['occupancy_rate'], 1) . '%'], ['Vacant units', (int)$totals['vacant']],
            ['Monthly rent at risk', format_currency(max(0, $totals['potential_rent'] - $totals['leased_rent']))]],
    ];
}

function report_expenses(PDO $db, array $params): array {
    [$scope, $values] = report_scope($params);
    $range = [$params['from'], $params['to']];
    $rows = report_query($db, "SELECT e.expense_date, p.name AS property, e.category, e.description, e.vendor, e.amount
        FROM expenses e JOIN properties p ON p.id = e.property_id WHERE e.expense_date BETWEEN ? AND ? $scope ORDER BY e.expense_date, e.id", [...$range, ...$values]);
    $categories = report_query($db, "SELECT e.category, COUNT(*) AS entries, SUM(e.amount) AS amount FROM expenses e JOIN properties p ON p.id = e.property_id
        WHERE e.expense_date BETWEEN ? AND ? $scope GROUP BY e.category ORDER BY amount DESC", [...$range, ...$values]);
    $total = array_sum(array_column($rows, 'amount'));
    return [
        'title' => 'Expenses',
        'subtitle' => report_period_label($params),
        'columns' => [
            'expense_date' => ['label' => 'Date', 'format' => 'date'], 'property' => ['label' => 'Property'], 'category' => ['label' => 'Category'],
            'description' => ['label' => 'Description'], 'vendor' => ['label' => 'Vendor'], 'amount' => ['label' => 'Amount', 'format' => 'currency'],
        ],
        'rows' => $rows,
        'totals' => ['amount' => $total],
        'summary' => [['Total expenses', format_currency($total)], ['Entries', count($rows)], ['Largest category', $categories[0]['category'] ?? '—']],
        'breakdown' => ['title' => 'By category', 'columns' => ['category' => ['label' => 'Category'], 'entries' => ['label' => 'Entries', 'format' => 'number'], 'amount' => ['label' => 'Amount', 'format' => 'currency']],
            'rows' => $categories],
    ];
}

function report_maintenance(PDO $db, array $params): array {
    [$scope, $values] = report_scope($params);
    $rows = report_query($db, "SELECT mr.created_at, CONCAT(p.name, ' / ', u.unit_number) AS unit, t.full_name AS tenant, mr.category, mr.priority, mr.status,
            s.full_name AS assigned, mr.estimated_cost, mr.actual_cost, mr.completed_date
        FROM maintenance_requests mr JOIN properties p ON p.id = mr.property_id JOIN units u ON u.id = mr.unit_id JOIN tenants t ON t.id = mr.tenant_id
        LEFT JOIN users s ON s.id = mr.assigned_staff
        WHERE DATE(mr.created_at) BETWEEN ? AND ? $scope ORDER BY mr.created_at", [$params['from'], $params['to'], ...$values]);
    $byCategory = [];
    $resolutionDays = [];
    foreach ($rows as &$row) {
        $row['days_to_resolve'] = $row['completed_date'] ? round((strtotime($row['completed_date']) - strtotime($row['created_at'])) / 86400, 1) : null;
        if ($row['days_to_resolve'] !== null) $resolutionDays[] = $row['days_to_resolve'];
        $byCategory[$row['category']] ??= ['category' => $row['category'], 'requests' => 0, 'open' => 0, 'actual_cost' => 0];
        $byCategory[$row['category']]['requests']++;
        $byCategory[$row['category']]['open'] += in_array($row['status'], ['Completed', 'Cancelled'], true) ? 0 : 1;
        $byCategory[$row['category']]['actual_cost'] += $row['actual_cost'];
    }
    unset($row);
    $totals = report_sum($rows, ['estimated_cost', 'actual_cost']);
    $open = count(array_filter($rows, fn($row) => !in_array($row['status'], ['Completed', 'Cancelled'], true)));
    return [
        'title' => 'Maintenance',
        'subtitle' => 'Requests raised ' . report_period_label($params),
        'columns' => [
            'created_at' => ['label' => 'Reported', 'format' => 'date'], 'unit' => ['label' => 'Property / unit'], 'tenant' => ['label' => 'Tenant'],
            'category' => ['label' => 'Category'], 'priority' => ['label' => 'Priority', 'format' => 'badge'], 'status' => ['label' => 'Status', 'format' => 'badge'],
            'assigned' => ['label' => 'Assigned to'], 'estimated_cost' => ['label' => 'Estimate', 'format' => 'currency'],
            'actual_cost' => ['label' => 'Actual cost', 'format' => 'currency'], 'days_to_resolve' => ['label' => 'Days to resolve', 'format' => 'number'],
        ],
        'rows' => $rows,
        'totals' => $totals,
        'summary' => [['Requests', count($rows)], ['Still open', $open], ['Average days to resolve', $resolutionDays ? number_format(array_sum($resolutionDays) / count($resolutionDays), 1) : '—'],
            ['Actual cost', format_currency($totals['actual_cost'])]],
        'breakdown' => ['title' => 'By category', 'columns' => ['category' => ['label' => 'Category'], 'requests' => ['label' => 'Requests', 'format' => 'number'], 'open' => ['label' => 'Open', 'format' => 'number'], 'actual_cost' => ['label' => 'Actual cost', 'format' => 'currency']],
            'rows' => array_values($byCategory)],
    ];
}

function report_leases(PDO $db, array $params): array {
    [$scope, $values] = report_scope($params);
    $rows = report_query($db, "SELECT t.full_name AS tenant, t.phone, CONCAT(p.name, ' / ', u.unit_number) AS unit, l.start_date, l.end_date, l.monthly_rent, l.notice_period_days
        FROM leases l JOIN tenants t ON t.id = l.tenant_id JOIN units u ON u.id = l.unit_id JOIN properties p ON p.id = l.property_id
        WHERE l.status = 'Active' AND l.end_date BETWEEN ? AND ? $scope ORDER BY l.end_date", [$params['from'], $params['to'], ...$values]);
    $today = strtotime(date('Y-m-d'));
    foreach ($rows as &$row) $row['days_left'] = (int)round((strtotime($row['end_date']) - $today) / 86400);
    unset($row);
    $rent = array_sum(array_column($rows, 'monthly_rent'));
    return [
        'title' => 'Lease expiry',
        'subtitle' => 'Active leases ending ' . report_period_label($params),
        'columns' => [
            'tenant' => ['label' => 'Tenant'], 'phone' => ['label' => 'Phone'], 'unit' => ['label' => 'Property / unit'], 'start_date' => ['label' => 'Started', 'format' => 'date'],
            'end_date' => ['label' => 'Ends', 'format' => 'date'], 'days_left' => ['label' => 'Days left', 'format' => 'number'],
            'notice_period_days' => ['label' => 'Notice (days)', 'format' => 'number'], 'monthly_rent' => ['label' => 'Monthly rent', 'format' => 'currency'],
        ],
        'rows' => $rows,
        'totals' => ['monthly_rent' => $rent],
        'summary' => [['Leases ending', count($rows)], ['Monthly rent affected', format_currency($rent)]],
    ];
}

function report_statement(PDO $db, array $params): array {
    $base = ['title' => 'Tenant statement', 'subtitle' => report_period_label($params), 'rows' => [],
        'columns' => ['entry_date' => ['label' => 'Date', 'format' => 'date'], 'description' => ['label' => 'Description'], 'debit' => ['label' => 'Charged', 'format' => 'currency'],
            'credit' => ['label' => 'Paid', 'format' => 'currency'], 'balance' => ['label' => 'Balance', 'format' => 'currency']]];
    $tenant = null;
    if ($params['tenant_id']) {
        $stmt = $db->prepare('SELECT id, full_name, phone FROM tenants WHERE id = ?');
        $stmt->execute([$params['tenant_id']]);
        $tenant = $stmt->fetch();
    }
    if (!$tenant) return $base + ['notice' => 'Choose a tenant to see their statement.'];

    $opening = report_query($db, 'SELECT
            COALESCE((SELECT SUM(total_due) FROM rent_charges WHERE tenant_id = ? AND due_date < ?), 0)
          - COALESCE((SELECT SUM(amount) FROM payments WHERE tenant_id = ? AND payment_date < ?), 0) AS opening',
        [$tenant['id'], $params['from'], $tenant['id'], $params['from']])[0]['opening'];
    $entries = report_query($db, "SELECT rc.due_date AS entry_date, CONCAT('Rent charge for ', DATE_FORMAT(STR_TO_DATE(CONCAT(rc.billing_period, '-01'), '%Y-%m-%d'), '%b %Y')) AS description, rc.total_due AS debit, 0 AS credit, 0 AS sort_order
            FROM rent_charges rc WHERE rc.tenant_id = ? AND rc.due_date BETWEEN ? AND ?
        UNION ALL
        SELECT pay.payment_date, CONCAT('Payment ', pay.receipt_number, ' (', pay.payment_method, ' ', pay.transaction_reference, ')'), 0, pay.amount, 1
            FROM payments pay WHERE pay.tenant_id = ? AND pay.payment_date BETWEEN ? AND ?
        ORDER BY entry_date, sort_order", [$tenant['id'], $params['from'], $params['to'], $tenant['id'], $params['from'], $params['to']]);

    $balance = (float)$opening;
    $rows = [['entry_date' => $params['from'], 'description' => 'Opening balance', 'debit' => null, 'credit' => null, 'balance' => $balance]];
    foreach ($entries as $entry) {
        $balance += $entry['debit'] - $entry['credit'];
        $rows[] = ['entry_date' => $entry['entry_date'], 'description' => $entry['description'], 'debit' => $entry['debit'] ?: null, 'credit' => $entry['credit'] ?: null, 'balance' => $balance];
    }
    $charged = array_sum(array_column($entries, 'debit'));
    $paid = array_sum(array_column($entries, 'credit'));
    return array_merge($base, [
        'subtitle' => $tenant['full_name'] . ' · ' . $tenant['phone'] . ' · ' . report_period_label($params),
        'rows' => $rows,
        'totals' => ['debit' => $charged, 'credit' => $paid, 'balance' => $balance],
        'summary' => [['Opening balance', format_currency($opening)], ['Charged', format_currency($charged)], ['Paid', format_currency($paid)],
            [$balance < 0 ? 'Credit balance' : 'Closing balance', format_currency(abs($balance))]],
    ]);
}

// ---------------------------------------------------------------------------
// Output
// ---------------------------------------------------------------------------

function report_cell($value, ?string $format): string {
    if ($value === null || $value === '') return '—';
    return match ($format) {
        'currency' => format_currency($value),
        'date' => format_date($value),
        'period' => format_period($value),
        'percent' => number_format((float)$value, 1) . '%',
        'number' => is_float($value + 0) && floor((float)$value) != (float)$value ? number_format((float)$value, 1) : number_format((float)$value),
        default => (string)$value,
    };
}

function render_report_table(array $columns, array $rows, ?array $totals, string $caption): void {
    $numeric = ['currency', 'number', 'percent'];
    ?>
    <div class="overflow-x-auto"><table class="w-full text-left border-collapse report-table">
        <caption class="sr-only"><?php echo e($caption); ?></caption>
        <thead><tr class="bg-gray-50 text-xs font-semibold text-gray-500 uppercase">
            <?php foreach ($columns as $column): ?><th scope="col" class="p-3 <?php echo in_array($column['format'] ?? '', $numeric, true) ? 'text-right' : ''; ?>"><?php echo e($column['label']); ?></th><?php endforeach; ?>
        </tr></thead>
        <tbody class="divide-y divide-gray-100 text-sm">
        <?php if (!$rows): ?><tr><td colspan="<?php echo count($columns); ?>" class="p-6 text-center text-gray-500">No records for these filters.</td></tr><?php endif; ?>
        <?php foreach ($rows as $row): ?><tr>
            <?php foreach ($columns as $key => $column):
                $format = $column['format'] ?? null; ?>
                <td class="p-3 <?php echo in_array($format, $numeric, true) ? 'text-right tabular-nums whitespace-nowrap' : ''; ?>"><?php echo $format === 'badge' && $row[$key] !== null ? render_status_badge($row[$key]) : e(report_cell($row[$key] ?? null, $format)); ?></td>
            <?php endforeach; ?>
        </tr><?php endforeach; ?>
        </tbody>
        <?php if ($totals && $rows): ?><tfoot><tr class="font-bold bg-gray-50">
            <?php $first = true; foreach ($columns as $key => $column): ?>
                <td class="p-3 <?php echo in_array($column['format'] ?? '', $numeric, true) ? 'text-right tabular-nums whitespace-nowrap' : ''; ?>"><?php echo array_key_exists($key, $totals) ? e(report_cell($totals[$key], $column['format'] ?? null)) : ($first ? 'Total' : ''); ?></td>
            <?php $first = false; endforeach; ?>
        </tr></tfoot><?php endif; ?>
    </table></div>
    <?php
}

function report_csv_value($value, ?string $format) {
    if ($value === null) return '';
    if (in_array($format, ['currency', 'percent'], true)) return number_format((float)$value, 2, '.', '');
    return csv_safe($value);
}

function stream_report_csv(array $report, string $filename): void {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, [csv_safe($report['title'])]);
    fputcsv($out, [csv_safe($report['subtitle'])]);
    fputcsv($out, ['Generated ' . date('Y-m-d H:i') . ' by ' . csv_safe($_SESSION['full_name'] ?? '')]);
    fputcsv($out, []);
    $writeTable = function (array $columns, array $rows, ?array $totals) use ($out) {
        fputcsv($out, array_column($columns, 'label'));
        foreach ($rows as $row) fputcsv($out, array_map(fn($key) => report_csv_value($row[$key] ?? null, $columns[$key]['format'] ?? null), array_keys($columns)));
        if ($totals) {
            $first = true;
            fputcsv($out, array_map(function ($key) use ($totals, $columns, &$first) {
                $cell = array_key_exists($key, $totals) ? report_csv_value($totals[$key], $columns[$key]['format'] ?? null) : ($first ? 'Total' : '');
                $first = false;
                return $cell;
            }, array_keys($columns)));
        }
    };
    $writeTable($report['columns'], $report['rows'], $report['totals']);
    if ($report['breakdown']) {
        fputcsv($out, []);
        fputcsv($out, [csv_safe($report['breakdown']['title'])]);
        $writeTable($report['breakdown']['columns'], $report['breakdown']['rows'], null);
    }
    fclose($out);
}
