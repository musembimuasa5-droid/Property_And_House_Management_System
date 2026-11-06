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
include __DIR__ . '/../includes/layout_top.php';
?>
            <h1 class="text-2xl font-bold text-gray-900">Payment Records & Receipts</h1>
            <div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
                <div class="overflow-x-auto"><table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-gray-50 text-xs font-semibold text-gray-500 uppercase">
                            <th class="p-4">Receipt #</th>
                            <th class="p-4">Unit</th>
                            <th class="p-4">Amount</th>
                            <th class="p-4">Method</th>
                            <th class="p-4">Date</th>
                            <th class="p-4"><span class="sr-only">Receipt</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 text-sm">
                        <?php if (empty($payments)): ?>
                            <tr><td colspan="6" class="p-6 text-center text-gray-500">No payment history found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($payments as $p): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="p-4 font-bold text-gray-900"><?php echo e($p['receipt_number']); ?></td>
                                    <td class="p-4"><?php echo e($p['unit_number']); ?></td>
                                    <td class="p-4 font-semibold text-success"><?php echo format_currency($p['amount']); ?></td>
                                    <td class="p-4"><?php echo render_status_badge($p['payment_method']); ?></td>
                                    <td class="p-4 text-gray-500"><?php echo e(format_date($p['payment_date'])); ?></td>
                                    <td class="p-4 text-right"><a class="btn btn-small btn-secondary" href="<?php echo BASE_URL; ?>/tenant/receipt.php?id=<?php echo (int)$p['id']; ?>"><i class="fa-solid fa-print" aria-hidden="true"></i> Receipt</a></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table></div>
            </div>
<?php include __DIR__ . '/../includes/layout_bottom.php'; ?>
