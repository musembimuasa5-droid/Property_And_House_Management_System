<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/upload_helper.php';

requireRole('Tenant');
$db = db();
$user = getCurrentUser();
const TENANT_MAINTENANCE_CATEGORIES = ['Plumbing', 'Electrical', 'Water', 'Security', 'Structural', 'Appliance', 'Cleaning', 'Other'];
const TENANT_CANCELLABLE = ['Submitted', 'Reviewed'];

$error = '';
$success = $_SESSION['maintenance_success'] ?? '';
unset($_SESSION['maintenance_success']);
$tenantProfileQuery = $db->prepare('SELECT id FROM tenants WHERE user_id = ? ORDER BY id LIMIT 1');
$tenantProfileQuery->execute([$user['id']]);
$tenantId = (int)$tenantProfileQuery->fetchColumn();
$tenantQuery = $db->prepare("SELECT t.id AS tenant_id, l.property_id, l.unit_id, p.name AS property_name, u.unit_number
    FROM tenants t JOIN leases l ON t.id = l.tenant_id
    JOIN properties p ON p.id = l.property_id JOIN units u ON u.id = l.unit_id
    WHERE t.user_id = ? AND t.id = ? AND l.status = 'Active' AND l.end_date >= CURRENT_DATE()
    ORDER BY p.name, u.unit_number");
$tenantQuery->execute([$user['id'], $tenantId]);
$tenantLeases = $tenantQuery->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postText = static function ($key) {
        return isset($_POST[$key]) && is_string($_POST[$key]) ? trim($_POST[$key]) : '';
    };
    if (!csrf_validate()) {
        http_response_code(400);
        $error = 'Your session expired. Please reload the page and try again.';
    } elseif ($postText('_action') === 'cancel') {
        $requestId = filter_var($postText('request_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
        $cancel = $db->prepare("UPDATE maintenance_requests SET status = 'Cancelled' WHERE id = ? AND tenant_id = ? AND status IN ('Submitted', 'Reviewed')");
<<<<<<< HEAD
        $cancel->execute([$requestId, $tenantId]);
        $_SESSION['maintenance_success'] = $cancel->rowCount() === 1
            ? 'Maintenance request cancelled.'
            : 'That request can no longer be cancelled.';
=======
        $cancel->execute([$requestId ?: 0, $tenantId]);
        if ($cancel->rowCount()) record_maintenance_update($db, (int)$requestId, 'Cancelled', 'Cancelled by the tenant');
        set_flash($cancel->rowCount() ? 'success' : 'error', $cancel->rowCount() ? 'Request cancelled.' : 'That request can no longer be cancelled.');
>>>>>>> 821dfc5081008f5deeee9e752f0a38a164e17c2d
        redirect('tenant/maintenance.php');
    } elseif (!$tenantId) {
        $error = 'A maintenance request can only be submitted for an active tenancy linked to your account.';
    } else {
        $category = $postText('category');
        $description = $postText('description');
        $priority = $postText('priority');
        $unitId = filter_var($postText('unit_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
        if (!in_array($category, ['Plumbing', 'Electrical', 'Water', 'Security', 'Structural', 'Appliance', 'Cleaning', 'Other'], true)) {
            $error = 'Select a valid maintenance category.';
        } elseif (!in_array($priority, ['Low', 'Medium', 'High', 'Emergency'], true)) {
            $error = 'Select a valid priority.';
        } elseif ($description === '' || strlen($description) > 2000) {
            $error = 'Describe the issue in 1 to 2,000 characters.';
        } else {
<<<<<<< HEAD
            $allowedUnitIds = array_map(static function ($lease) {
                return (int)$lease['unit_id'];
            }, $tenantLeases);
            if (!$unitId || !in_array($unitId, $allowedUnitIds, true)) {
                $error = 'Choose a unit assigned to your active tenancy.';
            } else {
                $leaseCheck = $db->prepare("SELECT t.id AS tenant_id, l.property_id, l.unit_id
                    FROM tenants t JOIN leases l ON l.tenant_id = t.id
                    WHERE t.user_id = ? AND t.id = ? AND l.unit_id = ? AND l.status = 'Active' AND l.end_date >= CURRENT_DATE()");
                $leaseCheck->execute([$user['id'], $tenantId, $unitId]);
                $lease = $leaseCheck->fetch();
                if (!$lease) {
                    $error = 'That unit is no longer assigned to your active tenancy.';
                } else {
                    $imagePath = null;
                    if (isset($_FILES['maintenance_image']) && $_FILES['maintenance_image']['error'] !== UPLOAD_ERR_NO_FILE) {
                        $upload = uploadApplicationFile($_FILES['maintenance_image'], 'maintenance', ['jpg', 'jpeg', 'png']);
                        if (!$upload['success']) {
                            $error = $upload['error'];
                        } else {
                            $imagePath = $upload['path'];
                        }
                    }
                    if ($error === '') {
                        try {
                            $db->beginTransaction();
                            $stmt = $db->prepare("INSERT INTO maintenance_requests (tenant_id, property_id, unit_id, category, description, priority, image_path) VALUES (?, ?, ?, ?, ?, ?, ?)");
                            $stmt->execute([(int)$lease['tenant_id'], (int)$lease['property_id'], (int)$lease['unit_id'], $category, $description, $priority, $imagePath]);
                            $requestId = (int)$db->lastInsertId();
                            $audit = $db->prepare("INSERT INTO audit_logs (user_id, action, module, record_id, description, ip_address) VALUES (?, 'Submit', 'Tenant Maintenance', ?, ?, ?)");
                            $audit->execute([(int)$user['id'], $requestId, 'Submitted a maintenance request for unit #' . $unitId, $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN']);
                            notify_roles($db, MANAGEMENT_ROLES, ($priority === 'Emergency' ? 'EMERGENCY: ' : '') . 'New maintenance request',
                                "{$user['full_name']} reported a {$category} issue ({$priority} priority).");
                            $db->commit();
                            $_SESSION['maintenance_success'] = 'Maintenance request submitted successfully.';
                            redirect('tenant/maintenance.php');
                        } catch (Throwable $exception) {
                            if ($db->inTransaction()) {
                                $db->rollBack();
                            }
                            if ($imagePath !== null) {
                                $file = realpath(__DIR__ . '/../' . $imagePath);
                                $root = realpath(__DIR__ . '/../uploads/maintenance');
                                if ($file && $root && strpos($file, $root . DIRECTORY_SEPARATOR) === 0 && !unlink($file)) {
                                    error_log('Unable to remove unreferenced maintenance image: ' . $imagePath);
                                }
                            }
                            error_log('Tenant maintenance request failed: ' . $exception->getMessage());
                            $error = 'The maintenance request could not be saved. Please try again.';
                        }
                    }
                }
            }
=======
            $photo = null;
            if (($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $upload = uploadApplicationFile($_FILES['photo'], 'maintenance', ['jpg', 'jpeg', 'png']);
                if ($upload['success']) $photo = $upload['path'];
                else $error = 'Photo: ' . $upload['error'];
            }
        }
        if (!$error) {
            $stmt = $db->prepare('INSERT INTO maintenance_requests (tenant_id, property_id, unit_id, category, description, priority, image_path) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$tenantId, $lease['property_id'], $lease['unit_id'], $form['category'], $form['description'], $form['priority'], $photo]);
            $newId = (int)$db->lastInsertId();
            record_maintenance_update($db, $newId, 'Submitted', 'Reported by the tenant' . ($photo ? ' with a photo' : ''));
            logAuditAction('CREATE', 'maintenance_requests', $newId, "Tenant reported a {$form['category']} issue", null, ['category' => $form['category'], 'priority' => $form['priority'], 'photo' => (bool)$photo]);
            notify_roles($db, MANAGEMENT_ROLES, ($form['priority'] === 'Emergency' ? 'EMERGENCY: ' : '') . 'New maintenance request',
                "{$user['full_name']} reported a {$form['category']} issue ({$form['priority']} priority): " . mb_strimwidth($form['description'], 0, 120, '…'));
            set_flash('success', 'Maintenance request submitted. You will get a notice when its status changes.');
            redirect('tenant/maintenance.php');
>>>>>>> 821dfc5081008f5deeee9e752f0a38a164e17c2d
        }
    }
}

$requests = [];
$requestQuery = $db->prepare("SELECT mr.id, mr.category, mr.description, mr.priority, mr.image_path, mr.status, mr.created_at,
        mr.completed_date, p.name AS property_name, u.unit_number, s.full_name AS staff_name
    FROM maintenance_requests mr JOIN properties p ON p.id = mr.property_id JOIN units u ON u.id = mr.unit_id
    LEFT JOIN users s ON s.id = mr.assigned_staff
    WHERE mr.tenant_id = ? ORDER BY mr.created_at DESC, mr.id DESC");
$requestQuery->execute([$tenantId]);
$requests = $requestQuery->fetchAll();

$page_title = 'Maintenance Requests';
include __DIR__ . '/../includes/layout_top.php';
?>
<h1 class="text-2xl font-bold text-gray-900">Maintenance Requests</h1>
<?php if (!empty($success)): ?><div class="bg-green-50 text-green-800 p-3 rounded-lg text-sm" role="status"><?php echo e($success); ?></div><?php endif; ?>
<?php if ($error): ?><div class="bg-red-50 text-red-800 p-3 rounded-lg text-sm" role="alert"><?php echo e($error); ?></div><?php endif; ?>

<div class="grid grid-cols-1 xl:grid-cols-5 gap-6 items-start">
    <div class="bg-white p-6 rounded-xl border border-gray-100 shadow-sm xl:col-span-2">
<<<<<<< HEAD
                <h3 class="font-bold text-lg text-gray-900 mb-4">Submit New Request</h3>
                <?php if (!$tenantLeases): ?><p class="mb-4 text-sm text-amber-800">No active tenancy is linked to your account, so requests cannot be submitted.</p><?php endif; ?>
                <form method="POST" enctype="multipart/form-data" class="space-y-4">
                    <?php echo csrf_field(); ?>
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Property / unit</label>
                        <select name="unit_id" required <?php echo !$tenantLeases ? 'disabled' : ''; ?> class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
                            <?php foreach ($tenantLeases as $lease): ?><option value="<?php echo (int)$lease['unit_id']; ?>"><?php echo e($lease['property_name'] . ' · Unit ' . $lease['unit_number']); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Category</label>
                        <select name="category" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm outline-none">
                            <option value="Plumbing">Plumbing</option>
                            <option value="Electrical">Electrical</option>
                            <option value="Water">Water</option>
                            <option value="Security">Security</option>
                            <option value="Structural">Structural</option>
                            <option value="Appliance">Appliance</option>
                            <option value="Cleaning">Cleaning</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Priority</label>
                        <select name="priority" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm outline-none">
                            <option value="Low">Low</option>
                            <option value="Medium" selected>Medium</option>
                            <option value="High">High</option>
                            <option value="Emergency">Emergency</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Description</label>
                        <textarea name="description" rows="3" maxlength="2000" required <?php echo !$tenantLeases ? 'disabled' : ''; ?> class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm outline-none" placeholder="Describe the issue..."></textarea>
                    </div>
                    <div><label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Optional photo (JPG/PNG, up to 5 MB)</label><input type="file" name="maintenance_image" accept=".jpg,.jpeg,.png,image/jpeg,image/png" <?php echo !$tenantLeases ? 'disabled' : ''; ?> class="block w-full text-sm"></div>
                    <button type="submit" <?php echo !$tenantLeases ? 'disabled' : ''; ?> class="bg-primary text-white font-semibold px-4 py-2 rounded-lg text-sm transition hover:bg-blue-800 disabled:cursor-not-allowed disabled:opacity-50">Submit Request</button>
                </form>
=======
        <h2 class="font-bold text-lg text-gray-900 mb-4">Submit a new request</h2>
        <?php if (!$lease): ?><p class="text-sm text-gray-500">You need an active lease to submit requests. Contact management if this looks wrong.</p><?php else: ?>
        <form method="POST" enctype="multipart/form-data" class="space-y-4">
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
            <div>
                <label for="photo" class="block text-xs font-semibold text-gray-500 uppercase mb-1">Photo <span class="normal-case font-normal">(optional)</span></label>
                <input id="photo" type="file" name="photo" accept="image/jpeg,image/png" aria-describedby="photo-help" class="block w-full text-sm">
                <p id="photo-help" class="text-xs text-gray-500 mt-1">A JPG or PNG up to 5 MB helps the caretaker see the problem before visiting.</p>
            </div>
            <button type="submit" class="btn btn-primary">Submit request</button>
        </form>
        <?php endif; ?>
>>>>>>> 821dfc5081008f5deeee9e752f0a38a164e17c2d
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
                        <?php if ($request['staff_name']): ?><div class="text-xs text-gray-500 mt-1">Assigned to <?php echo e($request['staff_name']); ?></div><?php endif; ?>
                        <?php if ($request['image_path']): ?><a class="text-xs text-primary font-semibold" href="<?php echo e(attachment_url('maintenance', (int)$request['id'])); ?>" target="_blank" rel="noopener">View photo<span class="sr-only"> (opens in a new tab)</span></a><?php endif; ?>
                        <?php $history = maintenance_history($db, (int)$request['id']); if ($history): ?>
                        <details class="text-xs text-gray-600 mt-1"><summary class="cursor-pointer text-primary font-semibold">History (<?php echo count($history); ?>)</summary>
                            <ol class="mt-1 space-y-0.5"><?php foreach ($history as $step): ?><li><?php echo e(date('d M Y, H:i', strtotime($step['created_at']))); ?> · <strong><?php echo e($step['status']); ?></strong><?php echo $step['note'] ? ' · ' . e($step['note']) : ''; ?></li><?php endforeach; ?></ol>
                        </details>
                        <?php endif; ?></td>
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
