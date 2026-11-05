<?php
/**
 * Reports page shared by the staff and landlord portals.
 * Set $reportLandlordId (null for staff) and $reportPageUrl before including.
 */

// Only run when included by a page that has already checked access.
if (!isset($reportPageUrl) || !array_key_exists('reportLandlordId', get_defined_vars())) {
    http_response_code(404);
    exit();
}

require_once __DIR__ . '/reports.php';

$db = db();
$catalog = array_filter(report_catalog(), fn($definition) => $reportLandlordId === null || $definition['landlord']);
$reportKey = is_string($_GET['report'] ?? null) && isset($catalog[$_GET['report']]) ? $_GET['report'] : array_key_first($catalog);
$definition = $catalog[$reportKey];
$params = report_params($reportKey, $reportLandlordId);

$propertySql = 'SELECT id, name FROM properties' . ($reportLandlordId ? ' WHERE landlord_id = ?' : '') . ' ORDER BY name';
$propertyStmt = $db->prepare($propertySql);
$propertyStmt->execute($reportLandlordId ? [$reportLandlordId] : []);
$properties = $propertyStmt->fetchAll(PDO::FETCH_KEY_PAIR);
if ($params['property_id'] && !isset($properties[$params['property_id']])) $params['property_id'] = null;
$tenants = $reportKey === 'statement' ? $db->query("SELECT id, CONCAT(full_name, ' — ID ', national_id) FROM tenants ORDER BY full_name")->fetchAll(PDO::FETCH_KEY_PAIR) : [];

$report = run_report($db, $reportKey, $params);
$query = array_filter(['report' => $reportKey, 'from' => $params['from'], 'to' => $params['to'], 'property_id' => $params['property_id'], 'tenant_id' => $params['tenant_id']]);
if ($definition['range'] === 'none') unset($query['from'], $query['to']);

if (($_GET['format'] ?? '') === 'csv') {
    logAuditAction('EXPORT', 'reports', null, "Exported {$definition['title']} report");
    stream_report_csv($report, $reportKey . '-report-' . date('Y-m-d') . '.csv');
    exit();
}

$page_title = $definition['title'] . ' report';
include __DIR__ . '/layout_top.php';
?>
<div class="no-print">
    <h1 class="text-2xl font-bold text-gray-900">Reports</h1>
    <p class="text-sm text-gray-500 mt-1">Choose a report, set the filters, then print it or download it as CSV for Excel.</p>
</div>

<nav class="report-picker no-print" aria-label="Report types">
    <?php foreach ($catalog as $key => $item): ?>
        <a href="<?php echo e($reportPageUrl . '?' . http_build_query(['report' => $key] + ($params['property_id'] ? ['property_id' => $params['property_id']] : []))); ?>" <?php echo $key === $reportKey ? 'aria-current="page"' : ''; ?>>
            <i class="fa-solid <?php echo e($item['icon']); ?>" aria-hidden="true"></i><span><?php echo e($item['title']); ?></span>
        </a>
    <?php endforeach; ?>
</nav>

<form method="get" class="filter-bar bg-white p-4 rounded-xl border border-gray-100 shadow-sm no-print">
    <input type="hidden" name="report" value="<?php echo e($reportKey); ?>">
    <?php if ($definition['range'] !== 'none'): ?>
    <div><label for="report-from" class="block text-xs font-semibold text-gray-500 uppercase mb-1">From</label><input id="report-from" type="date" name="from" value="<?php echo e($params['from']); ?>" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"></div>
    <div><label for="report-to" class="block text-xs font-semibold text-gray-500 uppercase mb-1">To</label><input id="report-to" type="date" name="to" value="<?php echo e($params['to']); ?>" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"></div>
    <?php endif; ?>
    <?php if ($reportKey === 'statement'): ?>
    <div class="filter-search"><label for="report-tenant" class="block text-xs font-semibold text-gray-500 uppercase mb-1">Tenant</label>
        <select id="report-tenant" name="tenant_id" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"><option value="">Select a tenant…</option>
        <?php foreach ($tenants as $id => $name): ?><option value="<?php echo (int)$id; ?>" <?php echo (int)$id === $params['tenant_id'] ? 'selected' : ''; ?>><?php echo e($name); ?></option><?php endforeach; ?></select></div>
    <?php else: ?>
    <div><label for="report-property" class="block text-xs font-semibold text-gray-500 uppercase mb-1">Property</label>
        <select id="report-property" name="property_id" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"><option value="">All properties</option>
        <?php foreach ($properties as $id => $name): ?><option value="<?php echo (int)$id; ?>" <?php echo (int)$id === $params['property_id'] ? 'selected' : ''; ?>><?php echo e($name); ?></option><?php endforeach; ?></select></div>
    <?php endif; ?>
    <div class="flex items-end gap-2 flex-wrap">
        <button type="submit" class="btn btn-primary">Run report</button>
        <a class="btn btn-secondary" href="<?php echo e($reportPageUrl . '?' . http_build_query($query + ['format' => 'csv'])); ?>"><i class="fa-solid fa-file-csv" aria-hidden="true"></i> CSV</a>
        <button type="button" class="btn btn-secondary" data-print><i class="fa-solid fa-print" aria-hidden="true"></i> Print</button>
    </div>
</form>

<article class="report-sheet print-container bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden" aria-labelledby="report-title">
    <div class="p-5 border-b border-gray-100 flex flex-wrap justify-between gap-3">
        <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500"><?php echo e(APP_NAME); ?></p>
            <h2 id="report-title" class="text-xl font-bold text-gray-900"><?php echo e($report['title']); ?> report</h2>
            <p class="text-sm text-gray-500"><?php echo e($report['subtitle']); ?><?php echo $params['property_id'] ? ' · ' . e($properties[$params['property_id']]) : ''; ?></p>
        </div>
        <p class="text-xs text-gray-500 self-end">Generated <?php echo e(date('d M Y, H:i')); ?> by <?php echo e($_SESSION['full_name'] ?? ''); ?></p>
    </div>
    <?php if ($report['summary']): ?>
    <dl class="report-summary">
        <?php foreach ($report['summary'] as [$label, $value]): ?><div><dt><?php echo e($label); ?></dt><dd><?php echo e((string)$value); ?></dd></div><?php endforeach; ?>
    </dl>
    <?php endif; ?>
    <?php if ($report['notice']): ?><p class="notice notice-warning mx-5 text-sm"><?php echo e($report['notice']); ?></p><?php endif; ?>
    <?php render_report_table($report['columns'], $report['rows'], $report['totals'], $report['title']); ?>
    <?php if ($report['breakdown']): ?>
        <h3 class="font-bold text-gray-900 px-5 pt-6 pb-3"><?php echo e($report['breakdown']['title']); ?></h3>
        <?php render_report_table($report['breakdown']['columns'], $report['breakdown']['rows'], null, $report['breakdown']['title']); ?>
    <?php endif; ?>
</article>
<?php include __DIR__ . '/layout_bottom.php'; ?>
