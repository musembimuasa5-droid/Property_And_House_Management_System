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

$leases = [];
if ($tenant) {
    $stmt = $db->prepare("SELECT l.*, p.name as property_name, u.unit_number FROM leases l JOIN properties p ON l.property_id = p.id JOIN units u ON l.unit_id = u.id WHERE l.tenant_id = ?");
    $stmt->execute([$tenant['id']]);
    $leases = $stmt->fetchAll();
}

$page_title = 'Lease & Documents';
include __DIR__ . '/../includes/header.php';
?>

<div class="flex h-screen bg-gray-50 overflow-hidden">
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>
    <div class="flex-1 flex flex-col overflow-y-auto">
        <?php include __DIR__ . '/../includes/navbar.php'; ?>
        <main class="flex-1 p-6 space-y-6">
            <h1 class="text-2xl font-bold text-gray-900">Lease Agreements & Documents</h1>
            <div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden p-6">
                <?php if (empty($leases)): ?>
                    <p class="text-gray-500 text-sm">No active lease documents found.</p>
                <?php else: ?>
                    <ul class="divide-y divide-gray-100">
                        <?php foreach ($leases as $l): ?>
                            <li class="py-3 flex items-center justify-between">
                                <div>
                                    <p class="font-bold text-gray-900"><?php echo e($l['property_name'] . ' - Unit ' . $l['unit_number']); ?></p>
                                    <p class="text-xs text-gray-500">Period: <?php echo e($l['start_date'] . ' to ' . ($l['end_date'] ?? 'Indefinite')); ?></p>
                                </div>
                                <?php echo render_status_badge($l['status']); ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </main>
        <?php include __DIR__ . '/../includes/footer.php'; ?>
    </div>
</div>