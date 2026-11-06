<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/shell.php';

requireLogin();
$db = db();
$user = getCurrentUser();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (csrf_validate() && ($_POST['_action'] ?? '') === 'mark_read') {
        $db->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0')->execute([$user['id']]);
        set_flash('success', 'All notifications marked as read.');
    }
    // Only return to a same-site path, never an external URL.
    $return = is_string($_POST['return'] ?? null) ? $_POST['return'] : '';
    if (preg_match('#^/(?![/\\\\])#', $return)) {
        header('Location: ' . $return);
        exit();
    }
    redirect('account/notifications.php');
}

$stmt = $db->prepare('SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 50');
$stmt->execute([$user['id']]);
$notices = $stmt->fetchAll();
// Opening the full list counts as reading; this visit still shows which ones were new.
$db->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0')->execute([$user['id']]);

$page_title = $user['role_name'] === 'Tenant' ? 'Notices' : 'Notifications';
include __DIR__ . '/../includes/layout_top.php';
?>
<div>
    <h1 class="text-2xl font-bold text-gray-900"><?php echo e($page_title); ?></h1>
    <p class="text-sm text-gray-500 mt-1"><?php echo $user['role_name'] === 'Tenant' ? 'Announcements from management and updates on your requests.' : 'Alerts about new requests, assignments and updates. Latest 50.'; ?></p>
</div>
<section class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden max-w-3xl">
    <ul class="divide-y divide-gray-100">
        <?php if (!$notices): ?><li class="p-6 text-sm text-gray-500">No notifications yet.</li><?php endif; ?>
        <?php foreach ($notices as $notice): ?>
            <li class="notification-item<?php echo $notice['is_read'] ? '' : ' is-unread'; ?> p-5">
                <span class="notification-dot" aria-hidden="true"></span>
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 class="font-bold text-gray-900 text-sm"><?php echo e($notice['title']); ?></h2>
                        <?php if (!$notice['is_read']) echo render_status_badge('New'); ?>
                    </div>
                    <p class="text-sm text-gray-600 mt-1"><?php echo nl2br(e($notice['message'])); ?></p>
                    <time class="text-xs text-gray-500" datetime="<?php echo e(date('c', strtotime($notice['created_at']))); ?>"><?php echo e(time_ago($notice['created_at'])); ?></time>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
</section>
<?php include __DIR__ . '/../includes/layout_bottom.php'; ?>
