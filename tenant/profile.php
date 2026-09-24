<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('Tenant');
$db = db();
$user = getCurrentUser();

$tenant_stmt = $db->prepare("SELECT * FROM tenants WHERE user_id = ?");
$tenant_stmt->execute([$user['id']]);
$tenant = $tenant_stmt->fetch();

$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tenant) {
    $phone = trim($_POST['phone_number'] ?? '');
    $emergency = trim($_POST['emergency_contact'] ?? '');

    $upd = $db->prepare("UPDATE tenants SET phone_number = ?, emergency_contact = ? WHERE id = ?");
    $upd->execute([$phone, $emergency, $tenant['id']]);
    $success = 'Profile updated successfully.';
    
    // Refresh tenant data
    $tenant_stmt->execute([$user['id']]);
    $tenant = $tenant_stmt->fetch();
}

$page_title = 'My Profile';
include __DIR__ . '/../includes/header.php';
?>

<div class="flex h-screen bg-gray-50 overflow-hidden">
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>
    <div class="flex-1 flex flex-col overflow-y-auto">
        <?php include __DIR__ . '/../includes/navbar.php'; ?>
        <main class="flex-1 p-6 space-y-6">
            <h1 class="text-2xl font-bold text-gray-900">Tenant Profile Settings</h1>
            <?php if (!empty($success)): ?><div class="bg-green-50 text-green-800 p-3 rounded-lg text-sm"><?php echo e($success); ?></div><?php endif; ?>
            <div class="bg-white p-6 rounded-xl border border-gray-100 shadow-sm max-w-xl">
                <form method="POST" class="space-y-4">
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Full Name</label>
                        <input type="text" disabled value="<?php echo e($tenant['full_name']); ?>" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm bg-gray-50 text-gray-500">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Email Address</label>
                        <input type="email" disabled value="<?php echo e($tenant['email']); ?>" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm bg-gray-50 text-gray-500">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Phone Number</label>
                        <input type="text" name="phone_number" value="<?php echo e($tenant['phone_number']); ?>" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm outline-none">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Emergency Contact</label>
                        <input type="text" name="emergency_contact" value="<?php echo e($tenant['emergency_contact'] ?? ''); ?>" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm outline-none">
                    </div>
                    <button type="submit" class="bg-primary text-white font-semibold px-4 py-2 rounded-lg text-sm">Save Changes</button>
                </form>
            </div>
        </main>
        <?php include __DIR__ . '/../includes/footer.php'; ?>
    </div>
</div>