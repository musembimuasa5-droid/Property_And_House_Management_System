<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/upload_helper.php';

requireRole('Tenant');
$db = db();
$user = getCurrentUser();

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
        }
    }
}

$requests = [];
$requestQuery = $db->prepare("SELECT mr.id, mr.category, mr.description, mr.priority, mr.image_path, mr.status, mr.created_at,
        mr.completed_date, p.name AS property_name, u.unit_number
    FROM maintenance_requests mr JOIN properties p ON p.id = mr.property_id JOIN units u ON u.id = mr.unit_id
    WHERE mr.tenant_id = ? ORDER BY mr.created_at DESC, mr.id DESC");
$requestQuery->execute([$tenantId]);
$requests = $requestQuery->fetchAll();

$page_title = 'Maintenance Requests';
include __DIR__ . '/../includes/header.php';
?>

<div class="flex h-screen bg-gray-50 overflow-hidden">
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>
    <div class="flex-1 flex flex-col overflow-y-auto">
        <?php include __DIR__ . '/../includes/navbar.php'; ?>
        <main class="flex-1 p-6 space-y-6">
            <h1 class="text-2xl font-bold text-gray-900">Maintenance Requests</h1>

            <?php if (!empty($success)): ?>
                <div class="bg-green-50 text-green-800 p-3 rounded-lg text-sm"><?php echo e($success); ?></div>
            <?php endif; ?>
            <?php if (!empty($error)): ?><div class="bg-red-50 text-red-800 p-3 rounded-lg text-sm"><?php echo e($error); ?></div><?php endif; ?>

            <div class="bg-white p-6 rounded-xl border border-gray-100 shadow-sm max-w-2xl">
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
            </div>
            <section class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden" aria-labelledby="tenant-maintenance-list">
                <div class="p-5 border-b border-gray-100"><h2 id="tenant-maintenance-list" class="font-bold text-gray-900">Your maintenance requests</h2><p class="mt-1 text-sm text-gray-500">Only requests linked to your tenant profile are shown.</p></div>
                <div class="divide-y divide-gray-100">
                    <?php if (!$requests): ?><p class="p-5 text-sm text-gray-500">No maintenance requests have been submitted.</p>
                    <?php else: foreach ($requests as $request): ?><article class="p-5"><div class="flex flex-wrap items-center justify-between gap-2"><h3 class="font-semibold text-gray-900"><?php echo e($request['category'] . ' · ' . $request['property_name'] . ' / Unit ' . $request['unit_number']); ?></h3><div class="flex gap-2"><?php echo render_status_badge($request['priority']); ?><?php echo render_status_badge($request['status']); ?></div></div><p class="mt-2 whitespace-pre-wrap text-sm text-gray-700"><?php echo e($request['description']); ?></p><p class="mt-2 text-xs text-gray-500">Submitted <?php echo e(date('d M Y, H:i', strtotime($request['created_at']))); ?><?php if ($request['completed_date']): ?> · Completed <?php echo e(date('d M Y', strtotime($request['completed_date']))); ?><?php endif; ?></p></article><?php endforeach; endif; ?>
                </div>
            </section>
        </main>
        <?php include __DIR__ . '/../includes/footer.php'; ?>
    </div>
</div>
