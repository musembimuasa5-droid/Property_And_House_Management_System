<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('Tenant');
$db = db();
$user = getCurrentUser();
const TENANT_MAINTENANCE_CATEGORIES = ['Plumbing', 'Electrical', 'Water', 'Security', 'Structural', 'Appliance', 'Cleaning', 'Other'];
const TENANT_CANCELLABLE = ['Submitted', 'Reviewed'];

$profile = $db->prepare('SELECT id FROM tenants WHERE user_id = ?');
$profile->execute([$user['id']]);
$tenantId = (int)$profile->fetchColumn();
$lease = $db->prepare("SELECT property_id, unit_id FROM leases WHERE tenant_id = ? AND status = 'Active' LIMIT 1");
$lease->execute([$tenantId]);
$lease = $lease->fetch();

$error = '';
$form = ['category' => 'Plumbing', 'priority' => 'Medium', 'description' => ''];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tenantId) {
    if (!csrf_validate()) {
        $error = 'Your session expired. Please reload the page and try again.';
    } elseif (($_POST['_action'] ?? '') === 'cancel') {
        // Tenants may withdraw only their own requests that nobody has started on.
        $requestId = filter_var($_POST['request_id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $cancel = $db->prepare("UPDATE maintenance_requests SET status = 'Cancelled' WHERE id = ? AND tenant_id = ? AND status IN ('Submitted', 'Reviewed')");
        $cancel->execute([$requestId ?: 0, $tenantId]);
        set_flash($cancel->rowCount() ? 'success' : 'error', $cancel->rowCount() ? 'Request cancelled.' : 'That request can no longer be cancelled.');
        redirect('tenant/maintenance.php');
    } elseif (!$lease) {
        $error = 'You need an active lease to submit a maintenance request.';
    } else {
        foreach ($form as $key => $default) $form[$key] = is_string($_POST[$key] ?? null) ? trim($_POST[$key]) : $default;
        if (!in_array($form['category'], TENANT_MAINTENANCE_CATEGORIES, true)) {
            $error = 'Select a valid maintenance category.';
        } elseif (!in_array($form['priority'], ['Low', 'Medium', 'High', 'Emergency'], true)) {
            $error = 'Select a valid priority.';
        } elseif ($form['description'] === '' || mb_strlen($form['description']) > 2000) {
            $error = 'Describe the issue in up to 2,000 characters.';
        } else {
            $stmt = $db->prepare('INSERT INTO maintenance_requests (tenant_id, property_id, unit_id, category, description, priority) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->execute([$tenantId, $lease['property_id'], $lease['unit_id'], $form['category'], $form['description'], $form['priority']]);
            notify_roles($db, MANAGEMENT_ROLES, ($form['priority'] === 'Emergency' ? 'EMERGENCY: ' : '') . 'New maintenance request',
                "{$user['full_name']} reported a {$form['category']} issue ({$form['priority']} priority): " . mb_strimwidth($form['description'], 0, 120, '…'));
            set_flash('success', 'Maintenance request submitted. You will get a notice when its status changes.');
            redirect('tenant/maintenance.php');
        }
    }
}

$requests = [];
if ($tenantId) {
    $req_stmt = $db->prepare('SELECT mr.*, s.full_name AS staff_name FROM maintenance_requests mr LEFT JOIN users s ON s.id = mr.assigned_staff WHERE mr.tenant_id = ? ORDER BY mr.created_at DESC');
    $req_stmt->execute([$tenantId]);
    $requests = $req_stmt->fetchAll();
}

$page_title = 'Maintenance Requests';
include __DIR__ . '/../includes/layout_top.php';
?>
<h1 class="text-2xl font-bold text-gray-900">Maintenance Requests</h1>
<?php if ($error): ?><div class="bg-red-50 text-red-800 p-3 rounded-lg text-sm" role="alert"><?php echo e($error); ?></div><?php endif; ?>

<div class="grid grid-cols-1 xl:grid-cols-5 gap-6 items-start">
    <div class="bg-white p-6 rounded-xl border border-gray-100 shadow-sm xl:col-span-2">
        <h2 class="font-bold text-lg text-gray-900 mb-4">Submit a new request</h2>
        <?php if (!$lease): ?><p class="text-sm text-gray-500">You need an active lease to submit requests. Contact management if this looks wrong.</p><?php else: ?>
        <form method="POST" class="space-y-4">
            <?php echo csrf_field(); ?>
            <div>
                <label for="category" class="block text-xs font-semibold text-gray-500 uppercase mb-1">Category</label>
                <select id="category" name="category" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
                    <?php foreach (TENANT_MAINTENANCE_CATEGORIES as $category): ?><option <?php echo $form['category'] === $category ? 'selected' : ''; ?>><?php echo e($category); ?></option><?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="priority" class="block text-xs font-semibold text-gray-500 uppercase mb-1">Priority</label>
                <select id="priority" name="priority" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
                    <?php foreach (['Low', 'Medium', 'High', 'Emergency'] as $priority): ?><option <?php echo $form['priority'] === $priority ? 'selected' : ''; ?>><?php echo e($priority); ?></option><?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="description" class="block text-xs font-semibold text-gray-500 uppercase mb-1">Description</label>
                <textarea id="description" name="description" rows="4" required maxlength="2000" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm" placeholder="Describe the issue and where it is"><?php echo e($form['description']); ?></textarea>
            </div>
            <button type="submit" class="btn btn-primary">Submit request</button>
        </form>
        <?php endif; ?>
    </div>

    <section class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden xl:col-span-3" aria-labelledby="my-requests">
        <div class="p-5 border-b border-gray-100"><h2 id="my-requests" class="font-bold text-gray-900">My requests</h2></div>
        <div class="overflow-x-auto"><table class="w-full text-left">
            <thead><tr><th scope="col" class="p-4">Reported</th><th scope="col" class="p-4">Issue</th><th scope="col" class="p-4">Priority</th><th scope="col" class="p-4">Status</th><th scope="col" class="p-4 text-right">Action</th></tr></thead>
            <tbody class="divide-y divide-gray-100 text-sm">
            <?php if (!$requests): ?><tr><td colspan="5" class="p-6 text-center text-gray-500">You have not submitted any requests.</td></tr><?php endif; ?>
            <?php foreach ($requests as $request): ?>
                <tr>
                    <td class="p-4 whitespace-nowrap align-top"><?php echo e(format_date($request['created_at'])); ?></td>
                    <td class="p-4 align-top"><strong><?php echo e($request['category']); ?></strong><div class="text-xs text-gray-600 max-w-xs"><?php echo e($request['description']); ?></div>
                        <?php if ($request['staff_name']): ?><div class="text-xs text-gray-500 mt-1">Assigned to <?php echo e($request['staff_name']); ?></div><?php endif; ?></td>
                    <td class="p-4 align-top"><?php echo render_status_badge($request['priority']); ?></td>
                    <td class="p-4 align-top"><?php echo render_status_badge($request['status']); ?><?php if ($request['completed_date']): ?><div class="text-xs text-gray-500 mt-1"><?php echo e(format_date($request['completed_date'])); ?></div><?php endif; ?></td>
                    <td class="p-4 align-top text-right">
                        <?php if (in_array($request['status'], TENANT_CANCELLABLE, true)): ?>
                        <form method="post" data-confirm="Cancel this maintenance request?"><?php echo csrf_field(); ?><input type="hidden" name="_action" value="cancel"><input type="hidden" name="request_id" value="<?php echo (int)$request['id']; ?>"><button type="submit" class="btn btn-small btn-danger">Cancel</button></form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </section>
</div>
<?php include __DIR__ . '/../includes/layout_bottom.php'; ?>
