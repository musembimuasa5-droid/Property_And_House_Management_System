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

$payments = [];
if ($tenant) {
    $stmt = $db->prepare("SELECT p.*, u.unit_number FROM payments p JOIN units u ON p.unit_id = u.id WHERE p.tenant_id = ? ORDER BY p.payment_date DESC");
    $stmt->execute([$tenant['id']]);
    $payments = $stmt->fetchAll();
}

$page_title = 'Payment History';
include __DIR__ . '/../includes/header.php';
?>

<div class="flex h-screen bg-gray-50 overflow-hidden">
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>
    <div class="flex-1 flex flex-col overflow-y-auto">
        <?php include __DIR__ . '/../includes/navbar.php'; ?>
        <main class="flex-1 p-6 space-y-6">
            <h1 class="text-2xl font-bold text-gray-900">Payment Records & Receipts</h1>
            <div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-gray-50 text-xs font-semibold text-gray-500 uppercase">
                            <th class="p-4">Receipt #</th>
                            <th class="p-4">Unit</th>
                            <th class="p-4">Amount</th>
                            <th class="p-4">Method</th>
                            <th class="p-4">Date</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 text-sm">
                        <?php if (empty($payments)): ?>
                            <tr><td colspan="5" class="p-6 text-center text-gray-500">No payment history found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($payments as $p): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="p-4 font-bold text-gray-900"><?php echo e($p['receipt_number']); ?></td>
                                    <td class="p-4"><?php echo e($p['unit_number']); ?></td>
                                    <td class="p-4 font-semibold text-success"><?php echo format_currency($p['amount']); ?></td>
                                    <td class="p-4"><?php echo render_status_badge($p['payment_method']); ?></td>
                                    <td class="p-4 text-gray-500"><?php echo e($p['payment_date']); ?></td>
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