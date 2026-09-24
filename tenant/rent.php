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

$charges = [];
if ($tenant) {
    $stmt = $db->prepare("SELECT * FROM rent_charges WHERE tenant_id = ? ORDER BY created_at DESC");
    $stmt->execute([$tenant['id']]);
    $charges = $stmt->fetchAll();
}

$page_title = 'My Rent & Statements';
include __DIR__ . '/../includes/header.php';
?>

<div class="flex h-screen bg-gray-50 overflow-hidden">
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>
    <div class="flex-1 flex flex-col overflow-y-auto">
        <?php include __DIR__ . '/../includes/navbar.php'; ?>
        <main class="flex-1 p-6 space-y-6">
            <h1 class="text-2xl font-bold text-gray-900">Rent Charges & Statement</h1>
            <div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-gray-50 text-xs font-semibold text-gray-500 uppercase">
                            <th class="p-4">Period</th>
                            <th class="p-4">Rent</th>
                            <th class="p-4">Service Charge</th>
                            <th class="p-4">Water</th>
                            <th class="p-4">Total Due</th>
                            <th class="p-4">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 text-sm">
                        <?php if (empty($charges)): ?>
                            <tr><td colspan="6" class="p-6 text-center text-gray-500">No rent charges found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($charges as $c): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="p-4 font-semibold text-gray-900"><?php echo e($c['billing_period']); ?></td>
                                    <td class="p-4"><?php echo format_currency($c['rent_amount']); ?></td>
                                    <td class="p-4"><?php echo format_currency($c['service_charge']); ?></td>
                                    <td class="p-4"><?php echo format_currency($c['water_bill']); ?></td>
                                    <td class="p-4 font-bold text-primary"><?php echo format_currency($c['total_due']); ?></td>
                                    <td class="p-4"><?php echo render_status_badge($c['status']); ?></td>
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