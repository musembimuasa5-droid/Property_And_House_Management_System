<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('Tenant');
$db = db();
$user = getCurrentUser();
const TENANT_COMPLAINT_CATEGORIES = ['Noise', 'Security', 'Water', 'Neighbors', 'Maintenance', 'Billing', 'Staff', 'Other'];

$tenant_stmt = $db->prepare("SELECT t.id, l.property_id FROM tenants t JOIN leases l ON t.id = l.tenant_id WHERE t.user_id = ? AND l.status = 'Active'");
$tenant_stmt->execute([$user['id']]);
$tenant = $tenant_stmt->fetch();
$profile = $db->prepare('SELECT id FROM tenants WHERE user_id = ?');
$profile->execute([$user['id']]);
$tenantId = (int)$profile->fetchColumn();

$error = '';
$form = ['category' => 'Other', 'subject' => '', 'message' => ''];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tenant) {
    foreach ($form as $key => $default) $form[$key] = is_string($_POST[$key] ?? null) ? trim($_POST[$key]) : $default;
    if (!csrf_validate()) {
        $error = 'Your session expired. Please reload the page and try again.';
    } elseif (!in_array($form['category'], TENANT_COMPLAINT_CATEGORIES, true)) {
        $error = 'Select a valid category.';
    } elseif ($form['subject'] === '' || mb_strlen($form['subject']) > 150) {
        $error = 'Enter a subject of up to 150 characters.';
    } elseif ($form['message'] === '' || mb_strlen($form['message']) > 2000) {
        $error = 'Enter a message of up to 2,000 characters.';
    } else {
        $stmt = $db->prepare('INSERT INTO complaints (tenant_id, property_id, category, subject, description) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$tenant['id'], $tenant['property_id'], $form['category'], $form['subject'], $form['message']]);
        notify_roles($db, MANAGEMENT_ROLES, 'New complaint: ' . $form['subject'], "{$user['full_name']} raised a {$form['category']} complaint.");
        set_flash('success', 'Complaint submitted. Management will review it and you will get a notice when it is updated.');
        redirect('tenant/complaints.php');
    }
}

$complaints = [];
if ($tenantId) {
    $c_stmt = $db->prepare('SELECT * FROM complaints WHERE tenant_id = ? ORDER BY created_at DESC');
    $c_stmt->execute([$tenantId]);
    $complaints = $c_stmt->fetchAll();
}

$page_title = 'Complaints';
include __DIR__ . '/../includes/layout_top.php';
?>
<h1 class="text-2xl font-bold text-gray-900">Complaints</h1>
<?php if ($error): ?><div class="bg-red-50 text-red-800 p-3 rounded-lg text-sm" role="alert"><?php echo e($error); ?></div><?php endif; ?>
<div class="grid grid-cols-1 xl:grid-cols-5 gap-6 items-start">
    <div class="bg-white p-6 rounded-xl border border-gray-100 shadow-sm xl:col-span-2">
        <h2 class="font-bold text-lg text-gray-900 mb-4">Raise a complaint</h2>
        <?php if (!$tenant): ?><p class="text-sm text-gray-500">You need an active lease to raise a complaint.</p><?php else: ?>
        <form method="POST" class="space-y-4">
            <?php echo csrf_field(); ?>
            <div>
                <label for="category" class="block text-xs font-semibold text-gray-500 uppercase mb-1">Category</label>
                <select id="category" name="category" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
                    <?php foreach (TENANT_COMPLAINT_CATEGORIES as $category): ?><option <?php echo $form['category'] === $category ? 'selected' : ''; ?>><?php echo e($category); ?></option><?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="subject" class="block text-xs font-semibold text-gray-500 uppercase mb-1">Subject</label>
                <input id="subject" type="text" name="subject" required maxlength="150" value="<?php echo e($form['subject']); ?>" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
            </div>
            <div>
                <label for="message" class="block text-xs font-semibold text-gray-500 uppercase mb-1">Message</label>
                <textarea id="message" name="message" rows="4" required maxlength="2000" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"><?php echo e($form['message']); ?></textarea>
            </div>
            <button type="submit" class="btn btn-primary">Submit complaint</button>
        </form>
        <?php endif; ?>
    </div>
    <section class="space-y-3 xl:col-span-3" aria-labelledby="my-complaints">
        <h2 id="my-complaints" class="font-bold text-gray-900">My complaints</h2>
        <?php if (!$complaints): ?><div class="bg-white p-6 rounded-xl border border-gray-100 text-sm text-gray-500">You have not raised any complaints.</div><?php endif; ?>
        <?php foreach ($complaints as $complaint): ?>
            <article class="bg-white p-5 rounded-xl border border-gray-100 shadow-sm">
                <div class="flex flex-wrap justify-between gap-2"><h3 class="font-bold text-gray-900"><?php echo e($complaint['subject']); ?></h3><?php echo render_status_badge($complaint['status']); ?></div>
                <p class="text-xs text-gray-500 mt-1"><?php echo e($complaint['category'] . ' · ' . format_date($complaint['created_at'])); ?></p>
                <p class="text-sm text-gray-700 mt-2"><?php echo nl2br(e($complaint['description'])); ?></p>
                <?php if ($complaint['resolution_notes']): ?><div class="mt-3 p-3 rounded-lg bg-gray-50 text-sm"><strong>Management response:</strong> <?php echo nl2br(e($complaint['resolution_notes'])); ?></div><?php endif; ?>
            </article>
        <?php endforeach; ?>
    </section>
</div>
<?php include __DIR__ . '/../includes/layout_bottom.php'; ?>
