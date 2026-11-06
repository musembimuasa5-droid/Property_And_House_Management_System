<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/modules.php';
require_once __DIR__ . '/../includes/dashboard.php';

$landlord = requireLandlordProfile();
$db = db();
$ownerId = (int)$landlord['id'];

$metrics = dashboard_metrics($db, $ownerId);
$finance = dashboard_monthly_finance($db, $ownerId);
$scope = ['property_id' => null, 'landlord_id' => $ownerId];
$properties = landlord_property_cards($db, $ownerId);
$arrears = array_slice(report_arrears($db, $scope + ['from' => date('Y-m-d'), 'to' => date('Y-m-d')])['rows'], 0, 5);
$expiring = report_leases($db, $scope + ['from' => date('Y-m-d'), 'to' => date('Y-m-d', strtotime('+60 days'))])['rows'];
$payments = dashboard_recent_payments($db, $ownerId);
$maintenance = dashboard_open_maintenance($db, $ownerId);
$reportUrl = fn(string $report) => module_url('my_reports', ['report' => $report]);
$occupancyRate = $metrics['units'] > 0 ? $metrics['occupied'] / $metrics['units'] : 0;
$collectionRatio = $metrics['billed'] > 0 ? $metrics['collected'] / $metrics['billed'] : 0;
$net = $metrics['collected'] - $metrics['expenses'];

$page_title = 'Landlord Dashboard';
include __DIR__ . '/../includes/layout_top.php';
?>
<div class="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-4">
    <div>
        <p class="page-eyebrow"><?php echo e(date('l, d F Y')); ?></p>
        <h1 class="text-2xl font-bold text-gray-900">Welcome, <?php echo e($landlord['full_name']); ?></h1>
        <p class="text-sm text-gray-500">Your <?php echo count($properties); ?> properties, occupancy and rental income at a glance.</p>
    </div>
    <div class="flex flex-wrap gap-2">
        <a class="btn btn-primary" href="<?php echo e(module_url('my_properties', ['action' => 'create'])); ?>"><i class="fa-solid fa-plus" aria-hidden="true"></i> Add property</a>
        <a class="btn btn-secondary" href="<?php echo e(module_url('my_units', ['action' => 'create'])); ?>"><i class="fa-solid fa-door-open" aria-hidden="true"></i> Add unit</a>
        <a class="btn btn-secondary" href="<?php echo e($reportUrl('income')); ?>"><i class="fa-solid fa-scale-balanced" aria-hidden="true"></i> Income statement</a>
    </div>
</div>

<div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-5">
    <?php
    render_stat_tile('Collected this month', format_currency_short($metrics['collected']), 'fa-money-bill-wave',
        render_meter($collectionRatio, 'Share of this month\'s billing collected')
        . '<p class="mt-2">' . round($collectionRatio * 100) . '% of ' . e(format_currency_short($metrics['billed'])) . ' billed</p>'
        . '<p class="mt-1">' . render_delta(percent_change($metrics['collected'], $metrics['collected_last']), date('M', strtotime('first day of last month'))) . '</p>',
        $reportUrl('collections'));
    render_stat_tile('Net income this month', format_currency_short($net), 'fa-scale-balanced',
        '<p>' . e(format_currency_short($metrics['expenses'])) . ' in expenses</p>', $reportUrl('income'));
    render_stat_tile('Outstanding rent', format_currency_short($metrics['outstanding']), 'fa-file-invoice-dollar',
        '<p>' . e(format_currency_short($metrics['overdue'])) . ' overdue · ' . $metrics['overdue_tenants'] . ' tenant(s)</p>', $reportUrl('arrears'));
    render_stat_tile('Occupancy', round($occupancyRate * 100) . '%', 'fa-house-circle-check',
        render_meter($occupancyRate, 'Occupied units') . '<p class="mt-2">' . $metrics['occupied'] . ' of ' . $metrics['units'] . ' units · ' . $metrics['vacant'] . ' vacant</p>',
        $reportUrl('occupancy'));
    ?>
</div>

<section aria-labelledby="my-properties-heading" class="space-y-4">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div><h2 id="my-properties-heading" class="text-lg font-bold text-gray-900">My properties</h2><p class="text-sm text-gray-500">Active properties with vacant units are listed on the public website.</p></div>
        <a class="text-sm font-semibold text-primary" href="<?php echo e(module_url('my_properties')); ?>">Manage all <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a>
    </div>
    <?php if (!$properties): ?>
        <div class="bg-white p-8 rounded-xl border border-gray-100 text-center"><p class="text-gray-500 text-sm mb-3">You have no properties yet.</p><a class="btn btn-primary" href="<?php echo e(module_url('my_properties', ['action' => 'create'])); ?>">Add your first property</a></div>
    <?php else: ?>
        <div class="property-tile-grid"><?php foreach ($properties as $property) render_property_card($property); ?></div>
    <?php endif; ?>
</section>

<section class="bg-white p-5 rounded-xl border border-gray-100 shadow-sm min-w-0">
    <?php render_column_chart('landlord-finance-chart', 'Collections vs expenses, last 6 months', $finance['periods'], [
        ['name' => 'Collected', 'slot' => 1, 'values' => $finance['collected']],
        ['name' => 'Expenses', 'slot' => 2, 'values' => $finance['expenses']],
    ]); ?>
</section>

<div class="grid grid-cols-1 xl:grid-cols-2 gap-6 items-start">
    <?php render_arrears_panel($arrears, $reportUrl('arrears')); ?>
    <?php render_expiring_leases_panel($expiring, $reportUrl('leases')); ?>
    <?php render_payments_panel($payments, $reportUrl('collections'), false); ?>
    <?php render_maintenance_panel($maintenance, $reportUrl('maintenance')); ?>
</div>
<?php include __DIR__ . '/../includes/layout_bottom.php'; ?>
