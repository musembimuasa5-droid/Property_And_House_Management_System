<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/admin_people.php';

requireRole('Super Admin');

$categories = adminPeopleCategories();
$category = $_GET['type'] ?? 'tenants';
if (!is_string($category) || !isset($categories[$category])) $category = 'tenants';
$searchValue = $_GET['q'] ?? '';
$search = is_string($searchValue) ? trim($searchValue) : '';
$pageValue = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$page = $pageValue ?: 1;
$directory = fetchAdminPeople(db(), $category, $search, $page);

$page_title = 'People Directory';
include __DIR__ . '/../includes/header.php';
?>

<div class="flex h-screen bg-gray-50 overflow-hidden">
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>
    <div class="flex-1 flex flex-col overflow-y-auto">
        <?php include __DIR__ . '/../includes/navbar.php'; ?>
        <main id="main-content" class="flex-1 p-6 space-y-6">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">People Directory</h1>
                <p class="text-sm text-gray-500">Browse tenant and property-team contact records.</p>
            </div>

            <nav class="flex flex-wrap gap-2 border-b border-gray-200" aria-label="People categories">
                <?php foreach ($categories as $key => $item): ?>
                    <a href="<?php echo BASE_URL; ?>/admin/people.php?type=<?php echo e($key); ?>"
                       class="px-4 py-2 text-sm font-medium <?php echo $category === $key ? 'border-b-2 border-primary text-primary' : 'text-gray-600 hover:text-gray-900'; ?>"
                       <?php echo $category === $key ? 'aria-current="page"' : ''; ?>><?php echo e($item['label']); ?></a>
                <?php endforeach; ?>
            </nav>

            <section class="bg-white border border-gray-200 rounded-lg">
                <div class="p-4 border-b border-gray-200 flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4">
                    <div>
                        <h2 class="font-semibold text-gray-900"><?php echo e($categories[$category]['label']); ?></h2>
                        <p class="text-sm text-gray-500"><?php echo number_format($directory['total']); ?> records</p>
                    </div>
                    <form method="get" action="<?php echo BASE_URL; ?>/admin/people.php" class="flex gap-2">
                        <input type="hidden" name="type" value="<?php echo e($category); ?>">
                        <label class="sr-only" for="people-search">Search records</label>
                        <input id="people-search" type="search" name="q" value="<?php echo e($search); ?>" maxlength="100" placeholder="Name, email or phone" class="min-w-0 w-64 max-w-full px-3 py-2 border border-gray-300 rounded text-sm">
                        <button type="submit" class="bg-primary text-white px-4 py-2 rounded text-sm font-medium">Search</button>
                    </form>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left">
                        <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                            <tr>
                                <th scope="col" class="p-4">Name</th>
                                <th scope="col" class="p-4">Email</th>
                                <th scope="col" class="p-4">Phone</th>
                                <th scope="col" class="p-4"><?php echo $category === 'landlords' ? 'Organization' : 'Role'; ?></th>
                                <th scope="col" class="p-4">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 text-sm">
                            <?php if (!$directory['rows']): ?>
                                <tr><td colspan="5" class="p-6 text-center text-gray-500">No matching records found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($directory['rows'] as $person): ?>
                                    <tr>
                                        <td class="p-4 font-medium text-gray-900"><?php echo e($person['name']); ?></td>
                                        <td class="p-4"><?php echo e($person['email']); ?></td>
                                        <td class="p-4"><?php echo e($person['phone']); ?></td>
                                        <td class="p-4"><?php echo e($person['organization'] ?? '-'); ?></td>
                                        <td class="p-4"><?php echo render_status_badge($person['status']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($directory['total_pages'] > 1): ?>
                    <div class="p-4 border-t border-gray-200 flex items-center justify-between text-sm">
                        <span class="text-gray-500">Page <?php echo $directory['page']; ?> of <?php echo $directory['total_pages']; ?></span>
                        <div class="flex gap-2">
                            <?php if ($directory['page'] > 1): ?>
                                <a class="px-3 py-2 border border-gray-300 rounded" href="?type=<?php echo e($category); ?>&amp;q=<?php echo rawurlencode($search); ?>&amp;page=<?php echo $directory['page'] - 1; ?>">Previous</a>
                            <?php endif; ?>
                            <?php if ($directory['page'] < $directory['total_pages']): ?>
                                <a class="px-3 py-2 border border-gray-300 rounded" href="?type=<?php echo e($category); ?>&amp;q=<?php echo rawurlencode($search); ?>&amp;page=<?php echo $directory['page'] + 1; ?>">Next</a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </section>
        </main>
        <?php include __DIR__ . '/../includes/footer.php'; ?>
    </div>
</div>
