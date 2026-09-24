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
    'properties' => $db->query("SELECT COUNT(*) FROM properties")->fetchColumn(),
    'units' => $db->query("SELECT COUNT(*) FROM units")->fetchColumn(),
    'occupied' => $db->query("SELECT COUNT(*) FROM units WHERE status = 'Occupied'")->fetchColumn(),
    'vacant' => $db->query("SELECT COUNT(*) FROM units WHERE status = 'Vacant'")->fetchColumn(),
    'tenants' => $db->query("SELECT COUNT(*) FROM tenants WHERE status = 'Active'")->fetchColumn(),
    'collected' => $db->query("SELECT SUM(amount) FROM payments WHERE MONTH(payment_date) = MONTH(CURRENT_DATE())")->fetchColumn() ?: 0,
    'maintenance' => $db->query("SELECT COUNT(*) FROM maintenance_requests WHERE status NOT IN ('Completed', 'Cancelled')")->fetchColumn()
];

// Fetch recent payments
$recent_payments = $db->query("
    p.*, t.full_name as tenant_name, u.unit_number 
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
                    <a href="<?php echo BASE_URL; ?>/admin/properties/" class="bg-primary hover:bg-blue-800 text-white px-4 py-2 rounded-lg text-sm font-semibold transition shadow-sm">
                        <i class="fa-solid fa-plus mr-1"></i> Add Property
                    </a>
                </div>
            </div>

            <!-- Stat Cards Grid -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
                <div class="bg-white p-5 rounded-xl border border-gray-100 shadow-sm">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-medium text-gray-500 uppercase">Total Properties</p>
                            <h3 class="text-2xl font-bold text-gray-900 mt-1"><?php echo $stats['properties']; ?></h3>
                        </div>
                        <div class="p-3 bg-blue-50 text-primary rounded-xl"><i class="fa-solid fa-building text-lg"></i></div>
                    </div>
                </div>

                <div class="bg-white p-5 rounded-xl border border-gray-100 shadow-sm">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-medium text-gray-500 uppercase">Occupancy Rate</p>
                            <h3 class="text-2xl font-bold text-gray-900 mt-1">
                                <?php echo $stats['units'] > 0 ? round(($stats['occupied'] / $stats['units']) * 100) . '%' : '0%'; ?>
                            </h3>
                        </div>
                        <div class="p-3 bg-green-50 text-success rounded-xl"><i class="fa-solid fa-door-open text-lg"></i></div>
                    </div>
                    <p class="text-xs text-gray-400 mt-2"><?php echo $stats['vacant']; ?> vacant units available</p>
                </div>

                <div class="bg-white p-5 rounded-xl border border-gray-100 shadow-sm">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-medium text-gray-500 uppercase">Monthly Collections</p>
                            <h3 class="text-xl font-bold text-gray-900 mt-1"><?php echo format_currency($stats['collected']); ?></h3>
                        </div>
                        <div class="p-3 bg-indigo-50 text-indigo-600 rounded-xl"><i class="fa-solid fa-money-bill-trend-up text-lg"></i></div>
                    </div>
                </div>

                <div class="bg-white p-5 rounded-xl border border-gray-100 shadow-sm">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-medium text-gray-500 uppercase">Open Maintenance</p>
                            <h3 class="text-2xl font-bold text-gray-900 mt-1"><?php echo $stats['maintenance']; ?></h3>
                        </div>
                        <div class="p-3 bg-yellow-50 text-warning rounded-xl"><i class="fa-solid fa-screwdriver-wrench text-lg"></i></div>
                    </div>
                </div>
            </div>

            <!-- Recent Transactions Table -->
            <div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
                <div class="p-5 border-b border-gray-100 flex items-center justify-between">
                    <h3 class="font-bold text-gray-900">Recent Rent Payments</h3>
                    <a href="<?php echo BASE_URL; ?>/admin/payments/" class="text-sm text-primary font-medium hover:underline">View All</a>
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