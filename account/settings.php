<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/shell.php';

requireLogin();
$db = db();
$user = getCurrentUser();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current = $_POST['current_password'] ?? '';
    $new = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    $stored = $db->prepare('SELECT password_hash FROM users WHERE id = ?');
    $stored->execute([$user['id']]);
    $hash = (string)$stored->fetchColumn();

    if (!csrf_validate()) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (!is_string($current) || !password_verify($current, $hash)) {
        $errors[] = 'Your current password is incorrect.';
    } elseif (!is_string($new) || mb_strlen($new) < 10) {
        $errors[] = 'Choose a new password of at least 10 characters.';
    } elseif ($new !== $confirm) {
        $errors[] = 'The new passwords do not match.';
    } elseif (password_verify($new, $hash)) {
        $errors[] = 'Choose a password different from your current one.';
    } else {
        $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);
        session_regenerate_id(true);
        logAuditAction('PASSWORD_CHANGE', 'users', $user['id'], 'Changed own password');
        set_flash('success', 'Password changed. Use your new password next time you sign in.');
        redirect('account/settings.php');
    }
}

$account = $db->prepare('SELECT u.full_name, u.email, u.phone, u.last_login, u.created_at, r.name AS role_name FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?');
$account->execute([$user['id']]);
$account = $account->fetch();

$page_title = 'Account Settings';
include __DIR__ . '/../includes/layout_top.php';
?>
<div>
    <h1 class="text-2xl font-bold text-gray-900">Account settings</h1>
    <p class="text-sm text-gray-500 mt-1">Your sign-in details and password.</p>
</div>
<div class="grid grid-cols-1 xl:grid-cols-2 gap-6 items-start max-w-5xl">
    <section class="bg-white p-6 rounded-xl border border-gray-100 shadow-sm" aria-labelledby="account-heading">
        <div class="flex items-center gap-4 mb-5">
            <span class="avatar avatar-lg" aria-hidden="true"><?php echo e(user_initials($account['full_name'])); ?></span>
            <div><h2 id="account-heading" class="font-bold text-gray-900 text-lg"><?php echo e($account['full_name']); ?></h2><p class="text-sm text-gray-500"><?php echo e($account['role_name']); ?></p></div>
        </div>
        <dl class="receipt-details my-0">
            <div><dt>Email (sign-in)</dt><dd><?php echo e($account['email']); ?></dd></div>
            <div><dt>Phone</dt><dd><?php echo e($account['phone']); ?></dd></div>
            <div><dt>Last sign-in</dt><dd><?php echo e($account['last_login'] ? date('d M Y, H:i', strtotime($account['last_login'])) : '—'); ?></dd></div>
            <div><dt>Member since</dt><dd><?php echo e(format_date($account['created_at'])); ?></dd></div>
        </dl>
        <p class="text-xs text-gray-500 mt-5"><?php echo $account['role_name'] === 'Tenant' ? 'Update your phone and emergency contact on <a class="text-primary font-semibold" href="' . BASE_URL . '/tenant/profile.php">My profile</a>.' : 'To change your name, email or role, ask a Super Admin.'; ?></p>
    </section>

    <section class="bg-white p-6 rounded-xl border border-gray-100 shadow-sm" aria-labelledby="password-heading">
        <h2 id="password-heading" class="font-bold text-gray-900 text-lg">Change password</h2>
        <p class="text-sm text-gray-500 mb-4">Use at least 10 characters. A short phrase is easier to remember and harder to guess.</p>
        <?php if ($errors): ?><div class="border-l-4 p-3 mb-4 rounded-r-lg bg-red-50 border-red-500 text-red-800 text-sm" role="alert"><?php echo e($errors[0]); ?></div><?php endif; ?>
        <form method="post" class="space-y-4">
            <?php echo csrf_field(); ?>
            <div><label for="current_password" class="block text-xs font-semibold text-gray-600 uppercase mb-1">Current password</label>
                <input id="current_password" type="password" name="current_password" required autocomplete="current-password" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"></div>
            <div><label for="new_password" class="block text-xs font-semibold text-gray-600 uppercase mb-1">New password</label>
                <input id="new_password" type="password" name="new_password" required minlength="10" autocomplete="new-password" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"></div>
            <div><label for="confirm_password" class="block text-xs font-semibold text-gray-600 uppercase mb-1">Confirm new password</label>
                <input id="confirm_password" type="password" name="confirm_password" required minlength="10" autocomplete="new-password" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"></div>
            <button type="submit" class="btn btn-primary">Update password</button>
        </form>
    </section>
</div>
<?php include __DIR__ . '/../includes/layout_bottom.php'; ?>
