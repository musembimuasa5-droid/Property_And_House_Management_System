<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('Tenant');
$db = db();
$user = getCurrentUser();

$tenant_stmt = $db->prepare("SELECT id FROM tenants WHERE user_id = ?");
$tenant_stmt->execute([$user['id']]);
$tenant = $tenant_stmt->fetch();

$bills = [];
if ($tenant) {
    $stmt = $db->prepare("SELECT * FROM utility_bills WHERE tenant_id = ? ORDER BY billing_month DESC");
    $stmt->execute([$tenant['id']]);
    $bills = $stmt->fetchAll();
}

$page_title = 'Utility Bills';
include __DIR__ . '/../includes/header.php';
?>

<div class="flex h-screen bg-gray-50 overflow-hidden">
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>
    <div class="flex-1 flex flex-col overflow-y-auto">
        <?php include __DIR__ . '/../includes/navbar.php'; ?>
        <main class="flex-1 p-6 space-y-6">
            <h1 class="text-2xl font-bold text-gray-900">Utility Bills (Water & Electricity)</h1>
            <div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-gray-50 text-xs font-semibold text-gray-500 uppercase">
                            <th class="p-4">Month</th>
                            <th class="p-4">Utility Type</th>
                            <th class="p-4">Reading (Prev - Curr)</th>
                            <th class="p-4">Amount Due</th>
                            <th class="p-4">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 text-sm">
                        <?php if (empty($bills)): ?>
                            <tr><td colspan="5" class="p-6 text-center text-gray-500">No utility bills found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($bills as $b): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="p-4 font-semibold text-gray-900"><?php echo e($b['billing_month']); ?></td>
                                    <td class="p-4"><?php echo e($b['utility_type']); ?></td>
                                    <td class="p-4"><?php echo e($b['previous_reading'] . ' - ' . $b['current_reading']); ?></td>
                                    <td class="p-4 font-bold text-primary"><?php echo format_currency($b['total_amount']); ?></td>
                                    <td class="p-4"><?php echo render_status_badge($b['status']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </main>
        <?php include __DIR__ . '/../includes/footer.php'; ?>
    </div>
</div>