<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/finance.php';

requireRole('Tenant');
$db = db();
$user = getCurrentUser();

$tenant_stmt = $db->prepare("SELECT id FROM tenants WHERE user_id = ?");
$tenant_stmt->execute([$user['id']]);
$tenant = $tenant_stmt->fetch();

$charges = [];
if ($tenant) {
    $stmt = $db->prepare('SELECT rc.*, COALESCE(pa.paid, 0) AS paid, rc.total_due - COALESCE(pa.paid, 0) AS balance, ' . CHARGE_LIVE_STATUS_SQL . ' AS live_status
        FROM rent_charges rc LEFT JOIN ' . CHARGE_PAID_SUBQUERY . ' pa ON pa.rent_charge_id = rc.id WHERE rc.tenant_id = ? ORDER BY rc.billing_period DESC');
    $stmt->execute([$tenant['id']]);
    $charges = $stmt->fetchAll();
}
$totalBalance = array_sum(array_column($charges, 'balance'));

$page_title = 'My Rent & Statements';
include __DIR__ . '/../includes/layout_top.php';
?>
<div class="flex flex-wrap items-end justify-between gap-3">
    <h1 class="text-2xl font-bold text-gray-900">Rent Charges & Statement</h1>
    <p class="text-sm">Outstanding balance: <strong class="<?php echo $totalBalance > 0 ? 'text-red-700' : 'text-green-800'; ?>"><?php echo format_currency($totalBalance); ?></strong></p>
</div>
<div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
    <div class="overflow-x-auto"><table class="w-full text-left border-collapse">
        <thead>
            <tr class="bg-gray-50 text-xs font-semibold text-gray-500 uppercase">
                <th scope="col" class="p-4">Period</th>
                <th scope="col" class="p-4">Due</th>
                <th scope="col" class="p-4 text-right">Rent</th>
                <th scope="col" class="p-4 text-right">Service</th>
                <th scope="col" class="p-4 text-right">Utilities</th>
                <th scope="col" class="p-4 text-right">Total due</th>
                <th scope="col" class="p-4 text-right">Paid</th>
                <th scope="col" class="p-4 text-right">Balance</th>
                <th scope="col" class="p-4">Status</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100 text-sm">
            <?php if (empty($charges)): ?>
                <tr><td colspan="9" class="p-6 text-center text-gray-500">No rent charges found.</td></tr>
            <?php else: ?>
                <?php foreach ($charges as $c): ?>
                    <tr>
                        <td class="p-4 font-semibold text-gray-900"><?php echo e(format_period($c['billing_period'])); ?></td>
                        <td class="p-4 whitespace-nowrap"><?php echo e(format_date($c['due_date'])); ?></td>
                        <td class="p-4 text-right tabular-nums"><?php echo format_currency($c['rent_amount']); ?></td>
                        <td class="p-4 text-right tabular-nums"><?php echo format_currency($c['service_charge']); ?></td>
                        <td class="p-4 text-right tabular-nums"><?php echo format_currency($c['water_bill']); ?></td>
                        <td class="p-4 text-right tabular-nums font-semibold"><?php echo format_currency($c['total_due']); ?></td>
                        <td class="p-4 text-right tabular-nums"><?php echo format_currency($c['paid']); ?></td>
                        <td class="p-4 text-right tabular-nums font-bold text-primary"><?php echo format_currency($c['balance']); ?></td>
                        <td class="p-4"><?php echo render_status_badge($c['live_status']); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table></div>
</div>
<?php include __DIR__ . '/../includes/layout_bottom.php'; ?>
