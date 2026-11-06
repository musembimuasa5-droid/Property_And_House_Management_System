<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/modules.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole(STAFF_ROLES);
$db = db();
$query = trim(is_string($_GET['q'] ?? null) ? $_GET['q'] : '');
$results = [];

// Each source: label, SQL returning id/title/detail, number of LIKE placeholders, and the link for a hit.
// Only modules the signed-in role can open are searched.
$sources = [
    'tenants' => ['Tenants', "SELECT t.id, t.full_name AS title, CONCAT_WS(' · ', t.phone, t.email, CONCAT('ID ', t.national_id)) AS detail
        FROM tenants t WHERE t.full_name LIKE ? OR t.phone LIKE ? OR t.email LIKE ? OR t.national_id LIKE ? ORDER BY t.full_name", 4,
        fn($row) => module_url('tenants', ['action' => 'edit', 'id' => $row['id']])],
    'properties' => ['Properties', "SELECT p.id, p.name AS title, CONCAT_WS(' · ', p.property_type, l.estate, p.status) AS detail
        FROM properties p JOIN locations l ON l.id = p.location_id WHERE p.name LIKE ? OR p.physical_address LIKE ? OR l.estate LIKE ? ORDER BY p.name", 3,
        fn($row) => module_url('properties', ['action' => 'edit', 'id' => $row['id']])],
    'units' => ['Units', "SELECT u.id, CONCAT(p.name, ' / ', u.unit_number) AS title, CONCAT_WS(' · ', u.house_type, u.status, CONCAT('KSh ', FORMAT(u.monthly_rent, 0), ' / month')) AS detail
        FROM units u JOIN properties p ON p.id = u.property_id WHERE u.unit_number LIKE ? OR CONCAT(p.name, ' ', u.unit_number) LIKE ? ORDER BY p.name, u.unit_number", 2,
        fn($row) => module_url('units', ['action' => 'edit', 'id' => $row['id']])],
    'payments' => ['Payments', "SELECT pay.id, CONCAT(pay.receipt_number, ' — ', t.full_name) AS title,
            CONCAT_WS(' · ', CONCAT('KSh ', FORMAT(pay.amount, 2)), pay.payment_method, pay.transaction_reference, DATE_FORMAT(pay.payment_date, '%d %b %Y')) AS detail
        FROM payments pay JOIN tenants t ON t.id = pay.tenant_id WHERE pay.receipt_number LIKE ? OR pay.transaction_reference LIKE ? OR t.full_name LIKE ? ORDER BY pay.payment_date DESC", 3,
        fn($row) => BASE_URL . '/admin/receipt.php?id=' . (int)$row['id']],
    'landlords' => ['Landlords', "SELECT ld.id, ld.full_name AS title, CONCAT_WS(' · ', ld.company_name, ld.phone, ld.email) AS detail
        FROM landlords ld WHERE ld.full_name LIKE ? OR ld.company_name LIKE ? OR ld.phone LIKE ? OR ld.email LIKE ? ORDER BY ld.full_name", 4,
        fn($row) => module_url('landlords', ['action' => 'edit', 'id' => $row['id']])],
    'enquiries' => ['Enquiries', "SELECT q.id, q.full_name AS title, CONCAT_WS(' · ', q.email, q.status, DATE_FORMAT(q.created_at, '%d %b %Y')) AS detail
        FROM enquiries q WHERE q.full_name LIKE ? OR q.email LIKE ? OR q.message LIKE ? ORDER BY q.created_at DESC", 3,
        fn($row) => module_url('enquiries', ['action' => 'edit', 'id' => $row['id']])],
];

if (mb_strlen($query) >= 2) {
    $like = '%' . addcslashes($query, '\\%_') . '%';
    foreach ($sources as $key => [$label, $sql, $placeholders, $link]) {
        if (!user_can_access_module($key)) continue;
        try {
            $stmt = $db->prepare($sql . ' LIMIT 6');
            $stmt->execute(array_fill(0, $placeholders, $like));
            $rows = $stmt->fetchAll();
        } catch (PDOException $e) {
            continue; // an optional table (e.g. enquiries before its migration) simply yields no results
        }
        if ($rows) $results[$key] = ['label' => $label, 'rows' => $rows, 'link' => $link];
    }
}

$page_title = 'Search';
include __DIR__ . '/../includes/layout_top.php';
?>
<div>
    <h1 class="text-2xl font-bold text-gray-900">Search</h1>
    <p class="text-sm text-gray-500 mt-1">Find tenants, properties, units, receipts, landlords and enquiries.</p>
</div>
<form method="get" class="bg-white p-4 rounded-xl border border-gray-100 shadow-sm flex flex-col sm:flex-row gap-3" role="search">
    <label for="search-page-q" class="sr-only">Search term</label>
    <input id="search-page-q" type="search" name="q" value="<?php echo e($query); ?>" minlength="2" required placeholder="Name, phone, ID number, unit, receipt or M-Pesa code" class="flex-1 px-3 py-2 border border-gray-300 rounded-lg text-sm">
    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i> Search</button>
</form>

<?php if ($query !== '' && mb_strlen($query) < 2): ?>
    <p class="text-sm text-gray-500">Type at least two characters.</p>
<?php elseif ($query !== '' && !$results): ?>
    <div class="bg-white p-8 rounded-xl border border-gray-100 text-center text-sm text-gray-500">Nothing matches “<?php echo e($query); ?>”. Try a phone number, unit number or receipt number.</div>
<?php endif; ?>

<div class="grid grid-cols-1 xl:grid-cols-2 gap-6 items-start">
<?php foreach ($results as $key => $group): ?>
    <section class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden" aria-labelledby="results-<?php echo e($key); ?>">
        <div class="p-4 border-b border-gray-100 flex items-center justify-between gap-3">
            <h2 id="results-<?php echo e($key); ?>" class="font-bold text-gray-900"><i class="fa-solid <?php echo e(app_modules()[$key]['icon']); ?> text-accent mr-1" aria-hidden="true"></i> <?php echo e($group['label']); ?></h2>
            <a class="text-sm font-semibold text-primary" href="<?php echo e(module_url($key, ['q' => $query])); ?>">Open in <?php echo e($group['label']); ?> <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a>
        </div>
        <ul class="divide-y divide-gray-100">
            <?php foreach ($group['rows'] as $row): ?>
                <li><a class="block px-4 py-3 hover:bg-gray-50" href="<?php echo e($group['link']($row)); ?>"><strong class="text-sm text-gray-900"><?php echo e($row['title']); ?></strong><span class="block text-xs text-gray-500"><?php echo e($row['detail']); ?></span></a></li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endforeach; ?>
</div>
<?php include __DIR__ . '/../includes/layout_bottom.php'; ?>
