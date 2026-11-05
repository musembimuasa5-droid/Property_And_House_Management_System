<?php
/**
 * Dashboard metrics and widgets shared by the staff and landlord dashboards.
 * Pass a landlord id to scope every figure to that landlord's properties.
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/reports.php';

function dashboard_metrics(PDO $db, ?int $landlordId): array {
    [$scope, $scopeValues] = $landlordId ? [' AND p.landlord_id = ?', [$landlordId]] : ['', []];
    $row = function (string $sql, array $values = []) use ($db, $scopeValues) {
        $stmt = $db->prepare($sql);
        $stmt->execute([...$values, ...$scopeValues]);
        return $stmt->fetch(PDO::FETCH_NUM);
    };
    $monthStart = date('Y-m-01');
    $monthEnd = date('Y-m-t');
    $lastStart = date('Y-m-01', strtotime('first day of last month'));
    $lastEnd = date('Y-m-t', strtotime($lastStart));

    $collectedSql = "SELECT COALESCE(SUM(pay.amount), 0) FROM payments pay JOIN properties p ON p.id = pay.property_id WHERE pay.payment_date BETWEEN ? AND ? $scope";
    [$collected] = $row($collectedSql, [$monthStart, $monthEnd]);
    [$collectedLast] = $row($collectedSql, [$lastStart, $lastEnd]);
    [$billed] = $row("SELECT COALESCE(SUM(rc.total_due), 0) FROM rent_charges rc JOIN leases l ON l.id = rc.lease_id JOIN properties p ON p.id = l.property_id WHERE rc.billing_period = ? $scope", [date('Y-m')]);
    [$expenses] = $row("SELECT COALESCE(SUM(e.amount), 0) FROM expenses e JOIN properties p ON p.id = e.property_id WHERE e.expense_date BETWEEN ? AND ? $scope", [$monthStart, $monthEnd]);
    [$outstanding, $overdue, $overdueTenants] = $row("SELECT COALESCE(SUM(rc.total_due - COALESCE(pa.paid, 0)), 0),
            COALESCE(SUM(CASE WHEN rc.due_date < CURDATE() THEN rc.total_due - COALESCE(pa.paid, 0) END), 0),
            COUNT(DISTINCT CASE WHEN rc.due_date < CURDATE() THEN rc.tenant_id END)
        FROM rent_charges rc JOIN leases l ON l.id = rc.lease_id JOIN properties p ON p.id = l.property_id
        LEFT JOIN " . CHARGE_PAID_SUBQUERY . " pa ON pa.rent_charge_id = rc.id WHERE rc.total_due - COALESCE(pa.paid, 0) > 0 $scope");
    [$properties, $units, $occupied, $vacant] = $row("SELECT COUNT(DISTINCT p.id), COUNT(u.id), COALESCE(SUM(u.status = 'Occupied'), 0), COALESCE(SUM(u.status = 'Vacant'), 0)
        FROM properties p LEFT JOIN units u ON u.property_id = p.id WHERE p.status <> 'Archived' $scope");
    [$tenants] = $row("SELECT COUNT(DISTINCT l.tenant_id) FROM leases l JOIN properties p ON p.id = l.property_id WHERE l.status = 'Active' $scope");
    [$openMaintenance, $emergencies] = $row("SELECT COUNT(*), COALESCE(SUM(mr.priority = 'Emergency'), 0) FROM maintenance_requests mr JOIN properties p ON p.id = mr.property_id
        WHERE mr.status NOT IN ('Completed', 'Cancelled') $scope");
    [$viewings] = $row("SELECT COUNT(*) FROM viewing_requests v JOIN properties p ON p.id = v.property_id WHERE v.status IN ('Pending', 'Rescheduled') $scope");

    return [
        'collected' => (float)$collected, 'collected_last' => (float)$collectedLast, 'billed' => (float)$billed, 'expenses' => (float)$expenses,
        'outstanding' => (float)$outstanding, 'overdue' => (float)$overdue, 'overdue_tenants' => (int)$overdueTenants,
        'properties' => (int)$properties, 'units' => (int)$units, 'occupied' => (int)$occupied, 'vacant' => (int)$vacant,
        'tenants' => (int)$tenants, 'open_maintenance' => (int)$openMaintenance, 'emergencies' => (int)$emergencies, 'viewings' => (int)$viewings,
    ];
}

/** Collections and expenses per month for the last $months months, oldest first. */
function dashboard_monthly_finance(PDO $db, ?int $landlordId, int $months = 6): array {
    [$scope, $scopeValues] = $landlordId ? [' AND p.landlord_id = ?', [$landlordId]] : ['', []];
    $start = date('Y-m-01', strtotime('-' . ($months - 1) . ' months', strtotime(date('Y-m-01'))));
    $series = function (string $sql) use ($db, $start, $scopeValues) {
        $stmt = $db->prepare($sql);
        $stmt->execute([$start, ...$scopeValues]);
        return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    };
    $collected = $series("SELECT DATE_FORMAT(pay.payment_date, '%Y-%m'), SUM(pay.amount) FROM payments pay JOIN properties p ON p.id = pay.property_id WHERE pay.payment_date >= ? $scope GROUP BY 1");
    $expenses = $series("SELECT DATE_FORMAT(e.expense_date, '%Y-%m'), SUM(e.amount) FROM expenses e JOIN properties p ON p.id = e.property_id WHERE e.expense_date >= ? $scope GROUP BY 1");

    $periods = [];
    for ($i = 0; $i < $months; $i++) $periods[] = date('Y-m', strtotime("+$i months", strtotime($start)));
    return [
        'periods' => $periods,
        'collected' => array_map(fn($period) => (float)($collected[$period] ?? 0), $periods),
        'expenses' => array_map(fn($period) => (float)($expenses[$period] ?? 0), $periods),
    ];
}

function dashboard_recent_payments(PDO $db, ?int $landlordId, int $limit = 5): array {
    [$scope, $values] = $landlordId ? [' AND p.landlord_id = ?', [$landlordId]] : ['', []];
    $stmt = $db->prepare("SELECT pay.id, pay.receipt_number, pay.amount, pay.payment_date, pay.payment_method, t.full_name AS tenant_name, p.name AS property_name, u.unit_number
        FROM payments pay JOIN properties p ON p.id = pay.property_id JOIN tenants t ON t.id = pay.tenant_id JOIN units u ON u.id = pay.unit_id
        WHERE 1 = 1 $scope ORDER BY pay.payment_date DESC, pay.id DESC LIMIT " . (int)$limit);
    $stmt->execute($values);
    return $stmt->fetchAll();
}

function dashboard_open_maintenance(PDO $db, ?int $landlordId, int $limit = 5): array {
    [$scope, $values] = $landlordId ? [' AND p.landlord_id = ?', [$landlordId]] : ['', []];
    $stmt = $db->prepare("SELECT mr.id, mr.category, mr.priority, mr.status, mr.created_at, p.name AS property_name, u.unit_number, s.full_name AS staff_name
        FROM maintenance_requests mr JOIN properties p ON p.id = mr.property_id JOIN units u ON u.id = mr.unit_id LEFT JOIN users s ON s.id = mr.assigned_staff
        WHERE mr.status NOT IN ('Completed', 'Cancelled') $scope
        ORDER BY FIELD(mr.priority, 'Emergency', 'High', 'Medium', 'Low'), mr.created_at LIMIT " . (int)$limit);
    $stmt->execute($values);
    return $stmt->fetchAll();
}

/** Whole-shilling amount, compacted above a million so stat tiles stay on one line. */
function format_currency_short($amount): string {
    $amount = (float)$amount;
    return abs($amount) >= 1000000 ? 'KSh ' . number_format($amount / 1000000, 2) . 'M' : 'KSh ' . number_format($amount);
}

function percent_change(float $current, float $previous): ?float {
    return $previous > 0 ? ($current - $previous) / $previous * 100 : null;
}

// ---------------------------------------------------------------------------
// Widgets
// ---------------------------------------------------------------------------

/** Stat tile: label, value, optional detail line (trusted HTML) and link. */
function render_stat_tile(string $label, string $value, string $icon, string $detailHtml = '', ?string $href = null): void {
    $tag = $href ? 'a' : 'article';
    ?>
    <<?php echo $tag; ?> class="stat-tile bg-white p-5 rounded-xl border border-gray-100 shadow-sm" <?php echo $href ? 'href="' . e($href) . '"' : ''; ?>>
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <p class="stat-label"><?php echo e($label); ?></p>
                <p class="stat-value"><?php echo e($value); ?></p>
            </div>
            <span class="stat-icon" aria-hidden="true"><i class="fa-solid <?php echo e($icon); ?>"></i></span>
        </div>
        <?php if ($detailHtml !== ''): ?><div class="stat-detail"><?php echo $detailHtml; ?></div><?php endif; ?>
    </<?php echo $tag; ?>>
    <?php
}

/** Signed change versus a named period; the arrow and words carry direction, not colour alone. */
function render_delta(?float $change, string $versus, bool $upIsGood = true): string {
    if ($change === null) return '<span class="text-gray-500">Nothing in ' . e($versus) . ' to compare</span>';
    $up = $change >= 0;
    $class = $up === $upIsGood ? 'delta-good' : 'delta-bad';
    return '<span class="' . $class . '"><i class="fa-solid fa-arrow-' . ($up ? 'up' : 'down') . '" aria-hidden="true"></i> '
        . ($up ? '+' : '−') . number_format(abs($change), 0) . '%</span> <span class="text-gray-500">vs ' . e($versus) . '</span>';
}

/** Meter: one ratio against a limit, with the figures in text beside it. */
function render_meter(float $ratio, string $label): string {
    $percent = max(0, min(100, $ratio * 100));
    return '<div class="meter" role="meter" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . round($percent) . '" aria-label="' . e($label) . '">'
        . '<span style="width:' . number_format($percent, 1, '.', '') . '%"></span></div>';
}

/** Round an axis maximum up to a clean 1/2/2.5/5 × 10^n step so ticks read cleanly. */
function chart_nice_max(float $max, int $ticks = 4): float {
    if ($max <= 0) return $ticks;
    $rawStep = $max / $ticks;
    $magnitude = 10 ** floor(log10($rawStep));
    foreach ([1, 2, 2.5, 5, 10] as $multiple) {
        if ($multiple * $magnitude >= $rawStep) return $multiple * $magnitude * $ticks;
    }
    return $max;
}

function chart_tick_label(float $value, float $axisMax): string {
    if ($value == 0) return '0';
    if ($axisMax >= 10000) return $value >= 1000000 ? rtrim(rtrim(number_format($value / 1000000, 1), '0'), '.') . 'M' : rtrim(rtrim(number_format($value / 1000, 1), '0'), '.') . 'K';
    return number_format($value);
}

/**
 * Grouped column chart (one shared KSh axis). Each bar has a full-height hover
 * and keyboard target; every value is also in the table view below the chart.
 * $series: [['name' => 'Collected', 'slot' => 1, 'values' => [...]], ...]
 */
function render_column_chart(string $id, string $title, array $periods, array $series): void {
    $axisMax = chart_nice_max(max(1, ...array_merge(...array_column($series, 'values'))));
    $ticks = 4;
    ?>
    <figure class="viz" id="<?php echo e($id); ?>" aria-labelledby="<?php echo e($id); ?>-title">
        <figcaption class="flex flex-wrap items-baseline justify-between gap-2 mb-3">
            <span id="<?php echo e($id); ?>-title" class="font-bold text-gray-900"><?php echo e($title); ?></span>
            <span class="viz-legend">
                <?php foreach ($series as $item): ?><span><i class="viz-key viz-slot-<?php echo (int)$item['slot']; ?>" aria-hidden="true"></i><?php echo e($item['name']); ?></span><?php endforeach; ?>
            </span>
        </figcaption>
        <div class="viz-plot">
            <div class="viz-axis" aria-hidden="true">
                <?php for ($i = $ticks; $i >= 0; $i--): ?><span style="bottom:<?php echo $i / $ticks * 100; ?>%"><?php echo e(chart_tick_label($axisMax * $i / $ticks, $axisMax)); ?></span><?php endfor; ?>
            </div>
            <div class="viz-area">
                <?php for ($i = 0; $i <= $ticks; $i++): ?><span class="viz-gridline<?php echo $i === 0 ? ' viz-baseline' : ''; ?>" style="bottom:<?php echo $i / $ticks * 100; ?>%" aria-hidden="true"></span><?php endfor; ?>
                <?php foreach ($periods as $index => $period): ?>
                <div class="viz-group">
                    <?php foreach ($series as $item):
                        $value = $item['values'][$index];
                        $label = $item['name'] . ' · ' . format_period($period); ?>
                    <span class="viz-hit" tabindex="0" role="img" aria-label="<?php echo e($label . ': ' . format_currency($value)); ?>" data-value="<?php echo e(format_currency($value)); ?>" data-label="<?php echo e($label); ?>" data-slot="<?php echo (int)$item['slot']; ?>">
                        <span class="viz-bar viz-slot-<?php echo (int)$item['slot']; ?>" style="height:<?php echo number_format($value / $axisMax * 100, 2, '.', ''); ?>%"></span>
                    </span>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>
                <div class="viz-tooltip" hidden aria-hidden="true"></div>
            </div>
            <div></div>
            <div class="viz-x" aria-hidden="true">
                <?php foreach ($periods as $period): ?><span><?php echo e(date('M', strtotime($period . '-01'))); ?></span><?php endforeach; ?>
            </div>
        </div>
        <details class="viz-table mt-3">
            <summary>Show as table</summary>
            <div class="overflow-x-auto"><table class="w-full text-left text-sm table-no-scroll">
                <caption class="sr-only"><?php echo e($title); ?></caption>
                <thead><tr><th scope="col" class="p-2">Month</th><?php foreach ($series as $item): ?><th scope="col" class="p-2 text-right"><?php echo e($item['name']); ?></th><?php endforeach; ?></tr></thead>
                <tbody><?php foreach ($periods as $index => $period): ?><tr class="border-t"><th scope="row" class="p-2 font-medium"><?php echo e(format_period($period)); ?></th>
                    <?php foreach ($series as $item): ?><td class="p-2 text-right tabular-nums"><?php echo format_currency($item['values'][$index]); ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody>
            </table></div>
        </details>
    </figure>
    <?php
}

/** A landlord's properties with unit counts and the lowest vacant rent, for the photo cards. */
function landlord_property_cards(PDO $db, int $landlordId): array {
    $stmt = $db->prepare("SELECT p.id, p.name, p.property_type, p.status, p.property_image, l.estate, l.sub_county,
            COUNT(u.id) AS units, COALESCE(SUM(u.status = 'Occupied'), 0) AS occupied, COALESCE(SUM(u.status = 'Vacant'), 0) AS vacant,
            MIN(CASE WHEN u.status = 'Vacant' THEN u.monthly_rent END) AS from_rent
        FROM properties p JOIN locations l ON l.id = p.location_id LEFT JOIN units u ON u.property_id = p.id
        WHERE p.landlord_id = ? GROUP BY p.id, p.name, p.property_type, p.status, p.property_image, l.estate, l.sub_county
        ORDER BY p.status = 'Active' DESC, p.name");
    $stmt->execute([$landlordId]);
    return $stmt->fetchAll();
}

function render_property_card(array $property): void {
    [$image, $isSample] = property_image($property);
    $ratio = $property['units'] > 0 ? $property['occupied'] / $property['units'] : 0;
    ?>
    <article class="property-tile">
        <div class="property-tile-media">
            <img src="<?php echo e(BASE_URL . '/' . $image); ?>" alt="<?php echo $isSample ? 'Sample home photo; no photo uploaded for this property' : 'Photo of ' . e($property['name']); ?>" loading="lazy">
            <span class="property-tile-badge"><?php echo render_status_badge($property['status']); ?></span>
            <?php if ($isSample): ?><span class="property-tile-sample">Sample photo</span><?php endif; ?>
        </div>
        <div class="p-4 flex flex-col gap-3 flex-1">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-accent"><?php echo e($property['property_type']); ?></p>
                <h3 class="font-bold text-gray-900 text-lg leading-snug"><?php echo e($property['name']); ?></h3>
                <p class="text-sm text-gray-500"><i class="fa-solid fa-location-dot mr-1" aria-hidden="true"></i><?php echo e($property['estate'] . ', ' . $property['sub_county']); ?></p>
            </div>
            <div>
                <div class="flex justify-between text-sm mb-1.5"><span class="text-gray-600"><?php echo (int)$property['occupied']; ?> of <?php echo (int)$property['units']; ?> units occupied</span><span class="font-semibold text-gray-900"><?php echo round($ratio * 100); ?>%</span></div>
                <?php echo render_meter($ratio, $property['name'] . ' occupancy'); ?>
                <p class="text-xs text-gray-500 mt-2"><?php echo (int)$property['vacant'] ? (int)$property['vacant'] . ' vacant · from ' . e(format_currency_short($property['from_rent'])) . ' / month' : 'No vacant units'; ?></p>
            </div>
            <div class="flex flex-wrap gap-2 mt-auto pt-1">
                <a class="btn btn-small btn-secondary" href="<?php echo e(module_url('my_properties', ['action' => 'edit', 'id' => $property['id']])); ?>"><i class="fa-solid fa-pen" aria-hidden="true"></i> Edit</a>
                <a class="btn btn-small btn-secondary" href="<?php echo e(module_url('my_units', ['property_id' => $property['id']])); ?>"><i class="fa-solid fa-door-open" aria-hidden="true"></i> Units</a>
                <?php if ($property['status'] === 'Active'): ?><a class="btn btn-small btn-secondary" href="<?php echo e(BASE_URL . '/public/property-details.php?id=' . (int)$property['id']); ?>" target="_blank" rel="noopener"><i class="fa-solid fa-globe" aria-hidden="true"></i> Listing<span class="sr-only"> (opens in a new tab)</span></a><?php endif; ?>
            </div>
        </div>
    </article>
    <?php
}

function render_panel_header(string $title, ?string $linkUrl = null, string $linkLabel = 'View all'): void {
    echo '<div class="p-5 border-b border-gray-100 flex items-center justify-between gap-3"><h2 class="font-bold text-gray-900">' . e($title) . '</h2>';
    if ($linkUrl) echo '<a class="text-sm font-semibold text-primary whitespace-nowrap" href="' . e($linkUrl) . '">' . e($linkLabel) . ' <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a>';
    echo '</div>';
}

/** Occupancy per property as meters, reusing the occupancy report query. */
function render_occupancy_panel(array $rows, ?string $linkUrl): void {
    ?>
    <section class="bg-white rounded-xl border border-gray-100 shadow-sm" aria-labelledby="occupancy-heading">
        <div class="p-5 border-b border-gray-100 flex items-center justify-between gap-3"><h2 id="occupancy-heading" class="font-bold text-gray-900">Occupancy by property</h2>
            <?php if ($linkUrl): ?><a class="text-sm font-semibold text-primary" href="<?php echo e($linkUrl); ?>">Report <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a><?php endif; ?></div>
        <ul class="p-5 space-y-4">
            <?php if (!$rows): ?><li class="text-sm text-gray-500">No properties yet.</li><?php endif; ?>
            <?php foreach ($rows as $row):
                $ratio = $row['units'] > 0 ? $row['occupied'] / $row['units'] : 0; ?>
            <li>
                <div class="flex justify-between gap-3 text-sm mb-1.5"><span class="font-semibold text-gray-900 truncate"><?php echo e($row['property']); ?></span>
                    <span class="text-gray-600 whitespace-nowrap"><?php echo (int)$row['occupied']; ?> of <?php echo (int)$row['units']; ?> · <?php echo round($ratio * 100); ?>%</span></div>
                <?php echo render_meter($ratio, $row['property'] . ' occupancy'); ?>
            </li>
            <?php endforeach; ?>
        </ul>
    </section>
    <?php
}

function render_arrears_panel(array $rows, ?string $linkUrl): void {
    ?>
    <section class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
        <?php render_panel_header('Largest arrears', $linkUrl, 'Arrears report'); ?>
        <div class="overflow-x-auto"><table class="w-full text-left">
            <thead><tr><th scope="col" class="p-4">Tenant</th><th scope="col" class="p-4">Unit</th><th scope="col" class="p-4 text-right">Balance</th><th scope="col" class="p-4 text-right">Days overdue</th></tr></thead>
            <tbody class="divide-y divide-gray-100">
            <?php if (!$rows): ?><tr><td colspan="4" class="p-5 text-center text-gray-500">No tenant owes rent right now.</td></tr><?php endif; ?>
            <?php foreach ($rows as $row): ?><tr>
                <td class="p-4"><strong><?php echo e($row['tenant']); ?></strong><div class="text-xs text-gray-500"><?php echo e($row['phone']); ?></div></td>
                <td class="p-4"><?php echo e($row['unit']); ?></td>
                <td class="p-4 text-right font-semibold tabular-nums whitespace-nowrap"><?php echo format_currency($row['balance']); ?></td>
                <td class="p-4 text-right tabular-nums"><?php echo $row['days_overdue'] > 0 ? (int)$row['days_overdue'] : '<span class="text-gray-500">Not yet due</span>'; ?></td>
            </tr><?php endforeach; ?>
            </tbody>
        </table></div>
    </section>
    <?php
}

function render_expiring_leases_panel(array $rows, ?string $linkUrl): void {
    ?>
    <section class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
        <?php render_panel_header('Leases ending in 60 days', $linkUrl, 'Expiry report'); ?>
        <div class="overflow-x-auto"><table class="w-full text-left">
            <thead><tr><th scope="col" class="p-4">Tenant</th><th scope="col" class="p-4">Unit</th><th scope="col" class="p-4">Ends</th><th scope="col" class="p-4 text-right">Days left</th></tr></thead>
            <tbody class="divide-y divide-gray-100">
            <?php if (!$rows): ?><tr><td colspan="4" class="p-5 text-center text-gray-500">No leases end in the next 60 days.</td></tr><?php endif; ?>
            <?php foreach ($rows as $row): ?><tr>
                <td class="p-4"><strong><?php echo e($row['tenant']); ?></strong><div class="text-xs text-gray-500"><?php echo e($row['phone']); ?></div></td>
                <td class="p-4"><?php echo e($row['unit']); ?></td>
                <td class="p-4 whitespace-nowrap"><?php echo e(format_date($row['end_date'])); ?></td>
                <td class="p-4 text-right tabular-nums"><?php echo (int)$row['days_left']; ?></td>
            </tr><?php endforeach; ?>
            </tbody>
        </table></div>
    </section>
    <?php
}

function render_payments_panel(array $rows, ?string $linkUrl, bool $withReceipts): void {
    ?>
    <section class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
        <?php render_panel_header('Recent payments', $linkUrl); ?>
        <div class="overflow-x-auto"><table class="w-full text-left">
            <thead><tr><th scope="col" class="p-4">Date</th><th scope="col" class="p-4">Tenant</th><th scope="col" class="p-4">Method</th><th scope="col" class="p-4 text-right">Amount</th></tr></thead>
            <tbody class="divide-y divide-gray-100">
            <?php if (!$rows): ?><tr><td colspan="4" class="p-5 text-center text-gray-500">No payments recorded yet.</td></tr><?php endif; ?>
            <?php foreach ($rows as $row): ?><tr>
                <td class="p-4 whitespace-nowrap"><?php echo e(format_date($row['payment_date'])); ?></td>
                <td class="p-4"><strong><?php echo e($row['tenant_name']); ?></strong><div class="text-xs text-gray-500"><?php echo e($row['property_name'] . ' / ' . $row['unit_number']); ?></div></td>
                <td class="p-4"><?php echo render_status_badge($row['payment_method']); ?></td>
                <td class="p-4 text-right font-semibold tabular-nums whitespace-nowrap"><?php if ($withReceipts): ?><a class="text-primary" href="<?php echo e(BASE_URL . '/admin/receipt.php?id=' . (int)$row['id']); ?>"><?php echo format_currency($row['amount']); ?></a><?php else: ?><?php echo format_currency($row['amount']); ?><?php endif; ?></td>
            </tr><?php endforeach; ?>
            </tbody>
        </table></div>
    </section>
    <?php
}

function render_maintenance_panel(array $rows, ?string $linkUrl): void {
    ?>
    <section class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
        <?php render_panel_header('Open maintenance', $linkUrl); ?>
        <div class="overflow-x-auto"><table class="w-full text-left">
            <thead><tr><th scope="col" class="p-4">Issue</th><th scope="col" class="p-4">Unit</th><th scope="col" class="p-4">Priority</th><th scope="col" class="p-4">Status</th></tr></thead>
            <tbody class="divide-y divide-gray-100">
            <?php if (!$rows): ?><tr><td colspan="4" class="p-5 text-center text-gray-500">No open maintenance requests.</td></tr><?php endif; ?>
            <?php foreach ($rows as $row): ?><tr>
                <td class="p-4"><strong><?php echo e($row['category']); ?></strong><div class="text-xs text-gray-500"><?php echo e(format_date($row['created_at'])); ?><?php echo $row['staff_name'] ? ' · ' . e($row['staff_name']) : ' · Unassigned'; ?></div></td>
                <td class="p-4"><?php echo e($row['property_name'] . ' / ' . $row['unit_number']); ?></td>
                <td class="p-4"><?php echo render_status_badge($row['priority']); ?></td>
                <td class="p-4"><?php echo render_status_badge($row['status']); ?></td>
            </tr><?php endforeach; ?>
            </tbody>
        </table></div>
    </section>
    <?php
}
