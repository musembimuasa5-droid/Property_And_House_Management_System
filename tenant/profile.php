<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('Tenant');
$db = db();
$user = getCurrentUser();

$tenant_stmt = $db->prepare("SELECT * FROM tenants WHERE user_id = ? LIMIT 1");
$tenant_stmt->execute([$user['id']]);
$tenant = $tenant_stmt->fetch();

if (!$tenant) {
    http_response_code(403);
    exit('Tenant profile is not linked to this account. Please contact an administrator.');
}

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate()) {
        http_response_code(400);
        $error = 'Your session expired. Please reload the page and try again.';
    } else {
        $full_name = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone_number'] ?? '');
        $emergency_name = trim($_POST['emergency_contact_name'] ?? '');
        $emergency_phone = trim($_POST['emergency_contact_phone'] ?? '');

        if ($full_name === '') {
            $error = 'Please provide your full name.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please provide a valid email address.';
        } elseif (!is_valid_phone_number($phone)) {
            $error = 'Please provide a valid phone number such as 0712345678 or +254712345678.';
        } elseif ($emergency_phone !== '' && !is_valid_phone_number($emergency_phone)) {
            $error = 'Please provide a valid emergency contact phone number.';
        } else {
            $duplicateUser = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1");
            $duplicateUser->execute([$email, $user['id']]);
            if ($duplicateUser->fetch()) {
                $error = 'This email address is already in use by another account.';
            } else {
                try {
                    $db->beginTransaction();

                    $userUpdate = $db->prepare("UPDATE users SET full_name = ?, email = ?, phone = ? WHERE id = ?");
                    $userUpdate->execute([$full_name, $email, $phone, $user['id']]);

                    $tenantUpdate = $db->prepare("UPDATE tenants SET full_name = ?, email = ?, phone = ?, emergency_contact_name = ?, emergency_contact_phone = ? WHERE id = ?");
                    $tenantUpdate->execute([$full_name, $email, $phone, $emergency_name, $emergency_phone, $tenant['id']]);

                    $db->commit();

                    $_SESSION['full_name'] = $full_name;
                    $_SESSION['email'] = $email;
                    $success = 'Profile updated successfully.';

                    $tenant_stmt->execute([$user['id']]);
                    $tenant = $tenant_stmt->fetch();
                } catch (Exception $e) {
                    $db->rollBack();
                    error_log('Profile update failed: ' . $e->getMessage());
                    $error = 'The profile could not be updated right now. Please try again.';
                }
            }
        }
    }
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
            <?php if ($error): ?><div class="bg-red-50 text-red-800 p-3 rounded-lg text-sm"><?php echo e($error); ?></div><?php endif; ?>
            <?php if (!empty($success)): ?><div class="bg-green-50 text-green-800 p-3 rounded-lg text-sm"><?php echo e($success); ?></div><?php endif; ?>
            <div class="bg-white p-6 rounded-xl border border-gray-100 shadow-sm max-w-xl">
                <form method="POST" class="space-y-4">
                    <?php echo csrf_field(); ?>
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Full Name</label>
                        <input type="text" name="full_name" value="<?php echo e($tenant['full_name']); ?>" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm outline-none" required>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Email Address</label>
                        <input type="email" name="email" value="<?php echo e($tenant['email']); ?>" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm outline-none" required>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Phone Number</label>
                        <input type="tel" name="phone_number" value="<?php echo e($tenant['phone']); ?>" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm outline-none" required>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Emergency Contact</label>
                        <input type="text" name="emergency_contact_name" value="<?php echo e($tenant['emergency_contact_name'] ?? ''); ?>" placeholder="Contact name" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm outline-none">
                        <input type="tel" name="emergency_contact_phone" value="<?php echo e($tenant['emergency_contact_phone'] ?? ''); ?>" placeholder="Contact phone" class="w-full mt-2 px-3 py-2 border border-gray-300 rounded-lg text-sm outline-none">
                    </div>
                    <button type="submit" class="bg-primary text-white font-semibold px-4 py-2 rounded-lg text-sm">Save Changes</button>
                </form>
            </div>
        </main>
        <?php include __DIR__ . '/../includes/footer.php'; ?>
    </div>
</div>
