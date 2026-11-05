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
    // Only readings within this tenant's own lease periods, not earlier occupants' readings for the same unit.
    $stmt = $db->prepare("SELECT r.*, ty.name AS utility_type, ty.unit_of_measure FROM utility_readings r
        JOIN utility_types ty ON ty.id = r.utility_type_id
        JOIN leases l ON l.unit_id = r.unit_id AND r.billing_period BETWEEN DATE_FORMAT(l.start_date, '%Y-%m') AND DATE_FORMAT(l.end_date, '%Y-%m')
        WHERE l.tenant_id = ? ORDER BY r.billing_period DESC, ty.name");
    $stmt->execute([$tenant['id']]);
    $bills = $stmt->fetchAll();
}

$page_title = 'Utility Bills';
include __DIR__ . '/../includes/layout_top.php';
?>
<div>
    <h1 class="text-2xl font-bold text-gray-900">Utility Bills</h1>
    <p class="text-sm text-gray-500">Metered usage for your unit. Each month's total is added to that month's rent charge.</p>
</div>
<div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
    <div class="overflow-x-auto"><table class="w-full text-left border-collapse">
        <thead>
            <tr class="bg-gray-50 text-xs font-semibold text-gray-500 uppercase">
                <th scope="col" class="p-4">Month</th>
                <th scope="col" class="p-4">Utility</th>
                <th scope="col" class="p-4">Reading (previous → current)</th>
                <th scope="col" class="p-4 text-right">Usage</th>
                <th scope="col" class="p-4 text-right">Rate</th>
                <th scope="col" class="p-4 text-right">Amount</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100 text-sm">
            <?php if (empty($bills)): ?>
                <tr><td colspan="6" class="p-6 text-center text-gray-500">No utility bills found.</td></tr>
            <?php else: ?>
                <?php foreach ($bills as $b): ?>
                    <tr>
                        <td class="p-4 font-semibold text-gray-900"><?php echo e(format_period($b['billing_period'])); ?></td>
                        <td class="p-4"><?php echo e($b['utility_type']); ?></td>
                        <td class="p-4 tabular-nums"><?php echo e($b['previous_reading'] . ' → ' . $b['current_reading']); ?></td>
                        <td class="p-4 text-right tabular-nums"><?php echo e($b['consumption'] . ' ' . $b['unit_of_measure']); ?></td>
                        <td class="p-4 text-right tabular-nums"><?php echo format_currency($b['rate']); ?></td>
                        <td class="p-4 text-right tabular-nums font-bold text-primary"><?php echo format_currency($b['total_amount']); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table></div>
</div>
<?php include __DIR__ . '/../includes/layout_bottom.php'; ?>
