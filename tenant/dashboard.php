<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('Tenant');

$db = db();
$user = getCurrentUser();

// Fetch tenant details linked to user
$stmt = $db->prepare("SELECT * FROM tenants WHERE user_id = ?");
$stmt->execute([$user['id']]);
$tenant = $stmt->fetch();

if (!$tenant) {
    die("Tenant profile not linked. Please contact administrator.");
}

// Fetch active lease and unit
$lease_stmt = $db->prepare("
    l.*, u.unit_number, u.monthly_rent, p.name as property_name 
    FROM leases l 
    JOIN units u ON l.unit_id = u.id 
    JOIN properties p ON l.property_id = p.id 
    WHERE l.tenant_id = ? AND l.status = 'Active'
");
$lease_stmt->execute([$tenant['id']]);
$lease = $lease_stmt->fetch();

// Fetch latest rent balance/charges
$balance = 0;
if ($lease) {
    $charge_stmt = $db->prepare("SELECT SUM(total_due) as total FROM rent_charges WHERE lease_id = ? AND status != 'Paid'");
    $charge_stmt->execute([$lease['id']]);
    $balance = $charge_stmt->fetch()['total'] ?? 0;
}

$page_title = 'Tenant Dashboard';
include __DIR__ . '/../includes/header.php';
?>

<div class="flex h-screen bg-gray-50 overflow-hidden">
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>

    <div class="flex-1 flex flex-col overflow-y-auto">
        <?php include __DIR__ . '/../includes/navbar.php'; ?>

        <main class="flex-1 p-6 space-y-6">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">Welcome, <?php echo e($tenant['full_name']); ?></h1>
                <p class="text-sm text-gray-500">Manage your lease, view rent balances, and submit requests.</p>
            </div>

            <!-- Portal Summary Cards -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-5">
                <div class="bg-white p-5 rounded-xl border border-gray-100 shadow-sm">
                    <p class="text-xs font-medium text-gray-500 uppercase">Current Unit</p>
                    <h3 class="text-xl font-bold text-gray-900 mt-1"><?php echo $lease ? e($lease['property_name'] . ' - ' . $lease['unit_number']) : 'No Active Lease'; ?></h3>
                </div>
                <div class="bg-white p-5 rounded-xl border border-gray-100 shadow-sm">
                    <p class="text-xs font-medium text-gray-500 uppercase">Outstanding Balance</p>
                    <h3 class="text-xl font-bold text-red-600 mt-1"><?php echo format_currency($balance); ?></h3>
                </div>
                <div class="bg-white p-5 rounded-xl border border-gray-100 shadow-sm">
                    <p class="text-xs font-medium text-gray-500 uppercase">Lease Status</p>
                    <div class="mt-1"><?php echo render_status_badge($lease['status'] ?? 'None'); ?></div>
                </div>
            </div>
        </main>
        <?php include __DIR__ . '/../includes/footer.php'; ?>
    </div>
</div>