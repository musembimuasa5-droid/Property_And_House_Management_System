<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/modules.php';
require_once __DIR__ . '/../includes/dashboard.php';

// Enforce admin/manager authorization server-side
requireRole(STAFF_ROLES);
$db = db();

$metrics = dashboard_metrics($db, null);
$finance = dashboard_monthly_finance($db, null);
$occupancy = report_occupancy($db, report_params('occupancy', null))['rows'];
$arrears = array_slice(run_report($db, 'arrears', report_params('arrears', null))['rows'], 0, 5);
$expiring = report_leases($db, ['from' => date('Y-m-d'), 'to' => date('Y-m-d', strtotime('+60 days')), 'property_id' => null, 'landlord_id' => null])['rows'];
$payments = dashboard_recent_payments($db, null);
$maintenance = dashboard_open_maintenance($db, null);
$viewings = user_can_access_module('viewings')
    ? $db->query("SELECT v.id, v.visitor_name, v.visitor_phone, v.preferred_date, v.preferred_time, v.status, p.name AS property_name FROM viewing_requests v JOIN properties p ON p.id = v.property_id
        WHERE v.status IN ('Pending', 'Rescheduled') ORDER BY v.preferred_date LIMIT 5")->fetchAll()
    : [];

try {
    $newEnquiries = (int)$db->query("SELECT COUNT(*) FROM enquiries WHERE status = 'New'")->fetchColumn();
} catch (PDOException $e) {
    $newEnquiries = 0; // enquiries migration not applied yet
}

$link = fn(string $module, array $query = []) => user_can_access_module($module) ? module_url($module, $query) : null;
$reportLink = fn(string $report) => $link('reports', ['report' => $report]);
$occupancyRate = $metrics['units'] > 0 ? $metrics['occupied'] / $metrics['units'] : 0;
$collectionRatio = $metrics['billed'] > 0 ? $metrics['collected'] / $metrics['billed'] : 0;
$lastMonth = date('M', strtotime('first day of last month'));

$quickActions = array_filter([
    ['payments', ['action' => 'create'], 'fa-money-bill-wave', 'Record payment'],
    ['rent_charges', [], 'fa-bolt', 'Generate charges'],
    ['leases', ['action' => 'create'], 'fa-file-signature', 'New lease'],
    ['tenants', ['action' => 'create'], 'fa-user-plus', 'Add tenant'],
    ['expenses', ['action' => 'create'], 'fa-receipt', 'Record expense'],
    ['reports', [], 'fa-chart-line', 'Reports'],
], fn($action) => user_can_access_module($action[0]));

$page_title = 'Dashboard';
include __DIR__ . '/../includes/layout_top.php';
?>
<div class="flex flex-col 2xl:flex-row 2xl:items-end 2xl:justify-between gap-4">
    <div>
        <p class="page-eyebrow"><?php echo e(date('l, d F Y')); ?></p>
        <h1 class="text-2xl font-bold text-gray-900">Good <?php echo (int)date('G') < 12 ? 'morning' : ((int)date('G') < 17 ? 'afternoon' : 'evening'); ?>, <?php echo e(strtok(getCurrentUser()['full_name'], ' ')); ?></h1>
        <p class="text-sm text-gray-500">Live figures across <?php echo $metrics['properties']; ?> properties and <?php echo $metrics['units']; ?> units.</p>
    </div>
    <div class="flex flex-wrap gap-2">
        <?php foreach ($quickActions as [$module, $query, $icon, $label]): ?>
            <a class="btn <?php echo $module === 'payments' ? 'btn-primary' : 'btn-secondary'; ?>" href="<?php echo e(module_url($module, $query)); ?>"><i class="fa-solid <?php echo e($icon); ?>" aria-hidden="true"></i> <?php echo e($label); ?></a>
        <?php endforeach; ?>
    </div>
</div>

<div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-5">
    <?php
    render_stat_tile('Collected this month', format_currency_short($metrics['collected']), 'fa-money-bill-wave',
        render_meter($collectionRatio, 'Share of this month\'s billing collected')
        . '<p class="mt-2">' . round($collectionRatio * 100) . '% of ' . e(format_currency_short($metrics['billed'])) . ' billed</p>'
        . '<p class="mt-1">' . render_delta(percent_change($metrics['collected'], $metrics['collected_last']), $lastMonth) . '</p>',
        $link('payments'));
    render_stat_tile('Outstanding rent', format_currency_short($metrics['outstanding']), 'fa-file-invoice-dollar',
        '<p>' . e(format_currency_short($metrics['overdue'])) . ' overdue · ' . $metrics['overdue_tenants'] . ' tenant(s)</p>', $reportLink('arrears'));
    render_stat_tile('Occupancy', round($occupancyRate * 100) . '%', 'fa-house-circle-check',
        render_meter($occupancyRate, 'Occupied units') . '<p class="mt-2">' . $metrics['occupied'] . ' of ' . $metrics['units'] . ' units occupied · ' . $metrics['vacant'] . ' vacant</p>',
        $link('units', ['status' => 'Vacant']));
    render_stat_tile('Open maintenance', (string)$metrics['open_maintenance'], 'fa-screwdriver-wrench',
        '<p>' . ($metrics['emergencies'] ? '<span class="delta-bad"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i> ' . $metrics['emergencies'] . ' emergency</span>' : 'No emergencies') . '</p>',
        $link('maintenance'));
    ?>
</div>

<div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-5">
    <?php
    render_stat_tile('Active tenants', (string)$metrics['tenants'], 'fa-users', '', $link('tenants', ['status' => 'Active']));
    render_stat_tile('Net this month', format_currency_short($metrics['collected'] - $metrics['expenses']), 'fa-scale-balanced',
        '<p>After ' . e(format_currency_short($metrics['expenses'])) . ' in expenses</p>', $reportLink('income'));
    render_stat_tile('Pending viewings', (string)$metrics['viewings'], 'fa-calendar-check', '<p>Requested from the public website</p>', $link('viewings', ['status' => 'Pending']));
    render_stat_tile('New enquiries', (string)$newEnquiries, 'fa-envelope-open-text', '<p>From the Contact page</p>', $link('enquiries', ['status' => 'New']));
    ?>
</div>

<div class="grid grid-cols-1 xl:grid-cols-3 gap-6 items-start">
    <section class="bg-white p-5 rounded-xl border border-gray-100 shadow-sm xl:col-span-2 min-w-0">
        <?php render_column_chart('finance-chart', 'Collections vs expenses, last 6 months', $finance['periods'], [
            ['name' => 'Collected', 'slot' => 1, 'values' => $finance['collected']],
            ['name' => 'Expenses', 'slot' => 2, 'values' => $finance['expenses']],
        ]); ?>
    </section>
    <?php render_occupancy_panel($occupancy, $reportLink('occupancy')); ?>
</div>

<div class="grid grid-cols-1 xl:grid-cols-2 gap-6 items-start">
    <?php render_arrears_panel($arrears, $reportLink('arrears')); ?>
    <?php render_expiring_leases_panel($expiring, $reportLink('leases')); ?>
    <?php render_payments_panel($payments, $link('payments'), user_can_access_module('payments')); ?>
    <?php render_maintenance_panel($maintenance, $link('maintenance')); ?>
</div>

<?php if ($viewings): ?>
<section class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
    <?php render_panel_header('Viewing requests awaiting a reply', $link('viewings')); ?>
    <div class="overflow-x-auto"><table class="w-full text-left">
        <thead><tr><th scope="col" class="p-4">Visitor</th><th scope="col" class="p-4">Property</th><th scope="col" class="p-4">Preferred slot</th><th scope="col" class="p-4">Status</th><th scope="col" class="p-4 text-right">Action</th></tr></thead>
        <tbody class="divide-y divide-gray-100">
        <?php foreach ($viewings as $viewing): ?><tr>
            <td class="p-4"><strong><?php echo e($viewing['visitor_name']); ?></strong><div class="text-xs text-gray-500"><?php echo e($viewing['visitor_phone']); ?></div></td>
            <td class="p-4"><?php echo e($viewing['property_name']); ?></td>
            <td class="p-4 whitespace-nowrap"><?php echo e(format_date($viewing['preferred_date']) . ' ' . substr($viewing['preferred_time'], 0, 5)); ?></td>
            <td class="p-4"><?php echo render_status_badge($viewing['status']); ?></td>
            <td class="p-4 text-right"><a class="btn btn-small btn-secondary" href="<?php echo e(module_url('viewings', ['action' => 'edit', 'id' => $viewing['id']])); ?>">Respond</a></td>
        </tr><?php endforeach; ?>
        </tbody>
    </table></div>
</section>
<?php endif; ?>
<?php include __DIR__ . '/../includes/layout_bottom.php'; ?>
