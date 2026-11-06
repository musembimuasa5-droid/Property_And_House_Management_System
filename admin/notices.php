<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/modules.php';
require_once __DIR__ . '/../includes/functions.php';

require_module('notices');
$db = db();
$errors = [];
$form = ['audience' => 'all_tenants', 'property_id' => '', 'user_id' => '', 'title' => '', 'message' => ''];

/** User ids for a notice audience; only tenants and accounts with a login can receive notices. */
function notice_recipients(PDO $db, string $audience, ?int $propertyId, ?int $userId): array {
    if ($audience === 'all_tenants') {
        return $db->query("SELECT user_id FROM tenants WHERE status = 'Active' AND user_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
    }
    if ($audience === 'property' && $propertyId) {
        $stmt = $db->prepare("SELECT DISTINCT t.user_id FROM leases l JOIN tenants t ON t.id = l.tenant_id WHERE l.property_id = ? AND l.status = 'Active' AND t.user_id IS NOT NULL");
        $stmt->execute([$propertyId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    return ($audience === 'user' && $userId) ? [$userId] : [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate()) {
        set_flash('error', 'Your session expired. Please try again.');
        redirect('admin/notices.php');
    }
    if (($_POST['_action'] ?? '') === 'delete') {
        $id = filter_var($_POST['id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $db->prepare('DELETE FROM notifications WHERE id = ?')->execute([$id ?: 0]);
        logAuditAction('DELETE', 'notifications', $id ?: null, 'Deleted notice');
        set_flash('success', 'Notice deleted.');
        redirect('admin/notices.php');
    }

    foreach ($form as $key => $default) $form[$key] = is_string($_POST[$key] ?? null) ? trim($_POST[$key]) : '';
    $propertyId = filter_var($form['property_id'], FILTER_VALIDATE_INT) ?: null;
    $userId = filter_var($form['user_id'], FILTER_VALIDATE_INT) ?: null;
    if (!in_array($form['audience'], ['all_tenants', 'property', 'user'], true)) $errors[] = 'Choose who should receive the notice.';
    if ($form['audience'] === 'property' && !$propertyId) $errors[] = 'Choose a property.';
    if ($form['audience'] === 'user' && !$userId) $errors[] = 'Choose a recipient.';
    if ($form['title'] === '' || mb_strlen($form['title']) > 150) $errors[] = 'Enter a title of up to 150 characters.';
    if ($form['message'] === '' || mb_strlen($form['message']) > 5000) $errors[] = 'Enter a message of up to 5,000 characters.';

    if (!$errors) {
        $recipients = notice_recipients($db, $form['audience'], $propertyId, $userId);
        if (!$recipients) {
            $errors[] = 'Nobody matches that audience yet. Tenants need a linked portal login to receive notices.';
        } else {
            $db->beginTransaction();
            foreach ($recipients as $recipient) notify_user($db, (int)$recipient, $form['title'], $form['message']);
            $db->commit();
            logAuditAction('SEND', 'notifications', null, 'Sent "' . $form['title'] . '" to ' . count($recipients) . ' recipient(s)');
            set_flash('success', 'Notice sent to ' . count($recipients) . ' recipient(s).');
            redirect('admin/notices.php');
        }
    }
}

$properties = $db->query('SELECT id, name FROM properties ORDER BY name')->fetchAll(PDO::FETCH_KEY_PAIR);
$users = $db->query("SELECT u.id, CONCAT(u.full_name, ' — ', r.name) FROM users u JOIN roles r ON r.id = u.role_id WHERE u.status = 'Active' ORDER BY u.full_name")->fetchAll(PDO::FETCH_KEY_PAIR);
$notices = $db->query('SELECT n.id, n.title, n.message, n.is_read, n.created_at, u.full_name, r.name AS role_name FROM notifications n JOIN users u ON u.id = n.user_id JOIN roles r ON r.id = u.role_id ORDER BY n.created_at DESC, n.id DESC LIMIT 50')->fetchAll();

$page_title = 'Notices';
include __DIR__ . '/../includes/layout_top.php';
?>
<div>
    <h1 class="text-2xl font-bold text-gray-900">Notices</h1>
    <p class="text-sm text-gray-500 mt-1">Send announcements to tenants. They appear on each tenant's Notices page.</p>
</div>
<?php if ($errors): ?><div class="border-l-4 p-4 rounded-r-lg bg-red-50 border-red-500 text-red-800" role="alert"><ul class="text-sm font-medium list-disc pl-4"><?php foreach ($errors as $error): ?><li><?php echo e($error); ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<div class="grid grid-cols-1 xl:grid-cols-5 gap-6 items-start">
    <form method="post" class="bg-white p-6 rounded-xl border border-gray-100 shadow-sm space-y-4 xl:col-span-2">
        <?php echo csrf_field(); ?><input type="hidden" name="_action" value="send">
        <h2 class="font-bold text-gray-900">Send a notice</h2>
        <fieldset><legend class="block text-xs font-semibold text-gray-600 uppercase mb-2">Audience</legend>
            <?php foreach (['all_tenants' => 'All active tenants', 'property' => 'Tenants of one property', 'user' => 'One person'] as $value => $label): ?>
            <label class="flex items-center gap-2 text-sm mb-1"><input type="radio" name="audience" value="<?php echo e($value); ?>" <?php echo $form['audience'] === $value ? 'checked' : ''; ?>> <?php echo e($label); ?></label>
            <?php endforeach; ?>
        </fieldset>
        <div><label for="notice-property" class="block text-xs font-semibold text-gray-600 uppercase mb-1">Property</label>
            <select id="notice-property" name="property_id" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"><option value="">Select…</option><?php foreach ($properties as $id => $name): ?><option value="<?php echo (int)$id; ?>" <?php echo (string)$id === $form['property_id'] ? 'selected' : ''; ?>><?php echo e($name); ?></option><?php endforeach; ?></select></div>
        <div><label for="notice-user" class="block text-xs font-semibold text-gray-600 uppercase mb-1">Recipient</label>
            <select id="notice-user" name="user_id" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"><option value="">Select…</option><?php foreach ($users as $id => $name): ?><option value="<?php echo (int)$id; ?>" <?php echo (string)$id === $form['user_id'] ? 'selected' : ''; ?>><?php echo e($name); ?></option><?php endforeach; ?></select></div>
        <div><label for="notice-title" class="block text-xs font-semibold text-gray-600 uppercase mb-1">Title <span class="text-red-600" aria-hidden="true">*</span></label>
            <input id="notice-title" name="title" required maxlength="150" value="<?php echo e($form['title']); ?>" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"></div>
        <div><label for="notice-message" class="block text-xs font-semibold text-gray-600 uppercase mb-1">Message <span class="text-red-600" aria-hidden="true">*</span></label>
            <textarea id="notice-message" name="message" required rows="5" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"><?php echo e($form['message']); ?></textarea></div>
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-paper-plane" aria-hidden="true"></i> Send notice</button>
    </form>
    <section class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden xl:col-span-3" aria-labelledby="sent-heading">
        <div class="p-5 border-b border-gray-100"><h2 id="sent-heading" class="font-bold text-gray-900">Recent notices</h2><p class="text-xs text-gray-500">Latest 50, one row per recipient.</p></div>
        <div class="overflow-x-auto"><table class="w-full text-left">
            <thead><tr><th scope="col" class="p-4">Sent</th><th scope="col" class="p-4">Notice</th><th scope="col" class="p-4">Recipient</th><th scope="col" class="p-4">Read</th><th scope="col" class="p-4 text-right">Actions</th></tr></thead>
            <tbody class="divide-y divide-gray-100">
            <?php if (!$notices): ?><tr><td colspan="5" class="p-6 text-center text-gray-500">No notices sent yet.</td></tr><?php endif; ?>
            <?php foreach ($notices as $notice): ?>
                <tr>
                    <td class="p-4 whitespace-nowrap"><?php echo e(format_date($notice['created_at'])); ?></td>
                    <td class="p-4"><strong><?php echo e($notice['title']); ?></strong><div class="text-xs text-gray-500 max-w-sm"><?php echo e(mb_strimwidth($notice['message'], 0, 100, '…')); ?></div></td>
                    <td class="p-4"><?php echo e($notice['full_name']); ?><div class="text-xs text-gray-500"><?php echo e($notice['role_name']); ?></div></td>
                    <td class="p-4"><?php echo $notice['is_read'] ? render_status_badge('Read') : render_status_badge('New'); ?></td>
                    <td class="p-4"><div class="row-actions"><form method="post" data-confirm="Delete this notice?"><?php echo csrf_field(); ?><input type="hidden" name="_action" value="delete"><input type="hidden" name="id" value="<?php echo (int)$notice['id']; ?>"><button type="submit" class="btn btn-small btn-danger">Delete</button></form></div></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </section>
</div>
<?php include __DIR__ . '/../includes/layout_bottom.php'; ?>
