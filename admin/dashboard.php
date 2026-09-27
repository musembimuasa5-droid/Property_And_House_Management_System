<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/functions.php';

// Enforce admin/manager authorization server-side
requireRole(['Super Admin', 'Property Manager', 'Accountant']);

$db = db();

// Fetch live database metrics
$stats = [
    'properties' => $db->query("SELECT COUNT(*) FROM properties WHERE status = 'Active'")->fetchColumn(),
    'units' => $db->query("SELECT COUNT(*) FROM units")->fetchColumn(),
    'occupied' => $db->query("SELECT COUNT(*) FROM units WHERE status = 'Occupied'")->fetchColumn(),
    'vacant' => $db->query("SELECT COUNT(*) FROM units WHERE status = 'Vacant'")->fetchColumn(),
    'tenants' => $db->query("SELECT COUNT(*) FROM tenants WHERE status = 'Active'")->fetchColumn(),
    'landlords' => $db->query("SELECT COUNT(*) FROM landlords WHERE status = 'Active'")->fetchColumn(),
    'caretakers' => $db->query("SELECT COUNT(*) FROM users u JOIN roles r ON r.id=u.role_id WHERE r.name='Caretaker' AND u.status='Active'")->fetchColumn(),
    'payments' => $db->query("SELECT COUNT(*) FROM payments")->fetchColumn(),
    'outstanding' => $db->query("SELECT COALESCE(SUM(GREATEST(rc.total_due-COALESCE(pa.paid,0),0)),0) FROM rent_charges rc LEFT JOIN (SELECT rent_charge_id,SUM(allocated_amount) paid FROM payment_allocations GROUP BY rent_charge_id) pa ON pa.rent_charge_id=rc.id WHERE rc.status<>'Paid'")->fetchColumn(),
    'collected' => $db->query("SELECT SUM(amount) FROM payments WHERE YEAR(payment_date) = YEAR(CURRENT_DATE()) AND MONTH(payment_date) = MONTH(CURRENT_DATE())")->fetchColumn() ?: 0,
    'maintenance' => $db->query("SELECT COUNT(*) FROM maintenance_requests WHERE status NOT IN ('Completed', 'Cancelled')")->fetchColumn()
];

// Fetch recent payments
$recent_payments = $db->query("
    SELECT p.*, t.full_name as tenant_name, u.unit_number
    FROM payments p
    JOIN tenants t ON p.tenant_id = t.id
    JOIN units u ON p.unit_id = u.id
    ORDER BY p.payment_date DESC LIMIT 5
")->fetchAll();

$page_title = 'Admin Dashboard';
include __DIR__ . '/../includes/header.php';
?>

<div class="flex h-screen bg-gray-50 overflow-hidden">
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>

    <div class="flex-1 flex flex-col overflow-y-auto">
        <?php include __DIR__ . '/../includes/navbar.php'; ?>

        <main class="flex-1 p-6 space-y-6">
            <!-- Page Header -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">Dashboard Overview</h1>
                    <p class="text-sm text-gray-500">Real-time property management statistics for Nairobi portfolios.</p>
                </div>
                <div class="flex items-center space-x-3">
                    <a href="<?php echo BASE_URL; ?>/public/properties.php" class="bg-primary hover:bg-blue-800 text-white px-4 py-2 rounded-lg text-sm font-semibold transition shadow-sm">
                        <i class="fa-solid fa-building mr-1"></i> Browse Properties
                    </a>
                </div>
            </div>

            <!-- System-wide live statistics -->
            <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-5">
                <?php foreach ([['Properties',$stats['properties'],'fa-building'],['Total Units',$stats['units'],'fa-door-open'],['Occupied Units',$stats['occupied'],'fa-key'],['Vacant Units',$stats['vacant'],'fa-house-circle-check'],['Active Landlords',$stats['landlords'],'fa-user-tie'],['Active Caretakers',$stats['caretakers'],'fa-person-circle-check'],['Active Tenants',$stats['tenants'],'fa-users'],['Payments Recorded',$stats['payments'],'fa-receipt'],['Outstanding Rent',format_currency($stats['outstanding']),'fa-file-invoice-dollar'],['Monthly Collections',format_currency($stats['collected']),'fa-money-bill-wave'],['Open Maintenance',$stats['maintenance'],'fa-screwdriver-wrench']] as $card): ?>
                    <article class="bg-white p-5 rounded-xl border border-gray-100 shadow-sm"><div class="flex items-center justify-between"><div><p class="text-xs font-medium text-gray-500 uppercase"><?php echo e($card[0]); ?></p><h2 class="text-xl font-bold text-gray-900 mt-2"><?php echo e((string)$card[1]); ?></h2></div><span class="p-3 rounded-xl bg-blue-50 text-primary"><i class="fa-solid <?php echo e($card[2]); ?>"></i></span></div></article>
                <?php endforeach; ?>
            </div>
            <!-- Recent Transactions Table -->
            <div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
                <div class="p-5 border-b border-gray-100 flex items-center justify-between">
                    <h3 class="font-bold text-gray-900">Recent Rent Payments</h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="bg-gray-50 text-xs font-semibold text-gray-500 uppercase">
                                <th class="p-4">Receipt</th>
                                <th class="p-4">Tenant</th>
                                <th class="p-4">Unit</th>
                                <th class="p-4">Amount</th>
                                <th class="p-4">Method</th>
                                <th class="p-4">Date</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 text-sm">
                            <?php if (empty($recent_payments)): ?>
                                <tr>
                                    <td colspan="6" class="p-6 text-center text-gray-500">No payment records found.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($recent_payments as $p): ?>
                                    <tr class="hover:bg-gray-50 transition">
                                        <td class="p-4 font-medium text-gray-900"><?php echo e($p['receipt_number']); ?></td>
                                        <td class="p-4"><?php echo e($p['tenant_name']); ?></td>
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
            </div>
        </main>
        <?php include __DIR__ . '/../includes/footer.php'; ?>
    </div>
</div>
