<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('Caretaker');
$db = db();
$user = getCurrentUser();
const CARETAKER_STATUSES = ['Assigned', 'In Progress', 'Waiting', 'Completed'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $requestId = filter_var($_POST['request_id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $status = $_POST['status'] ?? '';
    $cost = $_POST['actual_cost'] ?? '';
    if (!csrf_validate()) {
        set_flash('error', 'Your session expired. Please try again.');
    } elseif (!$requestId || !in_array($status, CARETAKER_STATUSES, true) || ($cost !== '' && (!is_numeric($cost) || $cost < 0))) {
        set_flash('error', 'Choose a valid status and a cost of zero or more.');
    } else {
        // The assigned_staff condition limits caretakers to their own requests.
        $current = $db->prepare("SELECT mr.status, mr.category, t.user_id FROM maintenance_requests mr JOIN tenants t ON t.id = mr.tenant_id
            WHERE mr.id = ? AND mr.assigned_staff = ? AND mr.status NOT IN ('Completed', 'Cancelled')");
        $current->execute([$requestId, $user['id']]);
        $request = $current->fetch();
        if (!$request) {
            set_flash('error', 'That request is not open and assigned to you.');
        } else {
            $db->prepare("UPDATE maintenance_requests SET status = ?, actual_cost = COALESCE(?, actual_cost), completed_date = IF(? = 'Completed', NOW(), NULL) WHERE id = ? AND assigned_staff = ?")
                ->execute([$status, $cost === '' ? null : round((float)$cost, 2), $status, $requestId, $user['id']]);
            if ($request['status'] !== $status) notify_user($db, $request['user_id'], 'Maintenance request update', "Your {$request['category']} request #$requestId is now: $status.");
            logAuditAction('UPDATE', 'maintenance_requests', $requestId, "Caretaker set status to $status");
            set_flash('success', "Request #$requestId updated to $status.");
        }
    }
    redirect('caretaker/dashboard.php#assigned-requests');
}

$base = 'FROM maintenance_requests mr JOIN properties p ON p.id = mr.property_id JOIN units u ON u.id = mr.unit_id JOIN tenants t ON t.id = mr.tenant_id WHERE mr.assigned_staff = ?';
$count = $db->prepare("SELECT COUNT(DISTINCT mr.property_id) properties, COUNT(DISTINCT mr.unit_id) units,
    COALESCE(SUM(mr.status NOT IN ('Completed','Cancelled')), 0) open_count,
    COALESCE(SUM(mr.priority = 'Emergency' AND mr.status NOT IN ('Completed','Cancelled')), 0) urgent,
    COALESCE(SUM(mr.status = 'Completed' AND mr.completed_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')), 0) completed_month $base");
$count->execute([$user['id']]);
$stats = $count->fetch();
$recent = $db->prepare("SELECT mr.id, mr.category, mr.description, mr.priority, mr.status, mr.actual_cost, mr.created_at, p.name property_name, u.unit_number, t.full_name tenant_name, t.phone tenant_phone
    $base ORDER BY mr.status IN ('Completed','Cancelled'), FIELD(mr.priority, 'Emergency', 'High', 'Medium', 'Low'), mr.created_at DESC LIMIT 20");
$recent->execute([$user['id']]);
$requests = $recent->fetchAll();

$page_title = 'Caretaker Dashboard';
include __DIR__ . '/../includes/layout_top.php';
?>
<div><p class="page-eyebrow"><?php echo e(date('l, d F Y')); ?></p><h1 class="text-2xl font-bold text-gray-900">Welcome, <?php echo e($user['full_name']); ?></h1><p class="text-sm text-gray-500">Maintenance requests assigned to you. Update the status as you work so tenants and managers stay informed.</p></div>
<div class="grid grid-cols-2 xl:grid-cols-4 gap-5">
<?php foreach ([['Open requests', $stats['open_count'], 'fa-screwdriver-wrench'], ['Emergencies', $stats['urgent'], 'fa-triangle-exclamation'], ['Completed this month', $stats['completed_month'], 'fa-circle-check'], ['Properties with assigned work', $stats['properties'], 'fa-building']] as [$label, $value, $icon]): ?>
    <article class="stat-tile bg-white p-5 rounded-xl border border-gray-100 shadow-sm"><div class="flex items-start justify-between gap-3"><div><p class="stat-label"><?php echo e($label); ?></p><p class="stat-value"><?php echo (int)$value; ?></p></div><span class="stat-icon" aria-hidden="true"><i class="fa-solid <?php echo e($icon); ?>"></i></span></div></article>
<?php endforeach; ?>
</div>
<section id="assigned-requests" class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
    <div class="p-5 border-b border-gray-100"><h2 class="font-bold text-gray-900">Assigned requests</h2><p class="text-xs text-gray-500">Open work first, most urgent at the top.</p></div>
    <div class="overflow-x-auto"><table class="w-full text-left">
        <thead><tr><th scope="col" class="p-4">Issue</th><th scope="col" class="p-4">Property / unit</th><th scope="col" class="p-4">Tenant</th><th scope="col" class="p-4">Priority</th><th scope="col" class="p-4">Status</th><th scope="col" class="p-4">Update</th></tr></thead>
        <tbody class="divide-y divide-gray-100">
        <?php if (!$requests): ?><tr><td colspan="6" class="p-5 text-center text-gray-500">No maintenance requests have been assigned to you.</td></tr><?php endif; ?>
        <?php foreach ($requests as $request): $closed = in_array($request['status'], ['Completed', 'Cancelled'], true); ?>
            <tr>
                <td class="p-4 align-top"><strong><?php echo e($request['category']); ?></strong> <span class="text-xs text-gray-500">#<?php echo (int)$request['id']; ?> · <?php echo e(format_date($request['created_at'])); ?></span><div class="text-xs text-gray-600 max-w-xs mt-1"><?php echo e($request['description']); ?></div></td>
                <td class="p-4 align-top"><?php echo e($request['property_name'] . ' / ' . $request['unit_number']); ?></td>
                <td class="p-4 align-top"><?php echo e($request['tenant_name']); ?><div class="text-xs text-gray-500"><a href="tel:<?php echo e($request['tenant_phone']); ?>"><?php echo e($request['tenant_phone']); ?></a></div></td>
                <td class="p-4 align-top"><?php echo render_status_badge($request['priority']); ?></td>
                <td class="p-4 align-top"><?php echo render_status_badge($request['status']); ?></td>
                <td class="p-4 align-top">
                    <?php if ($closed): ?><span class="text-xs text-gray-500">Closed<?php echo $request['actual_cost'] > 0 ? ' · ' . format_currency($request['actual_cost']) : ''; ?></span>
                    <?php else: ?>
                    <form method="post" class="flex flex-wrap items-end gap-2">
                        <?php echo csrf_field(); ?><input type="hidden" name="request_id" value="<?php echo (int)$request['id']; ?>">
                        <label class="sr-only" for="status-<?php echo (int)$request['id']; ?>">Status for request <?php echo (int)$request['id']; ?></label>
                        <select id="status-<?php echo (int)$request['id']; ?>" name="status" class="px-2 py-1.5 border border-gray-300 rounded-lg text-sm">
                            <?php foreach (CARETAKER_STATUSES as $option): ?><option <?php echo $option === $request['status'] ? 'selected' : ''; ?>><?php echo e($option); ?></option><?php endforeach; ?>
                        </select>
                        <label class="sr-only" for="cost-<?php echo (int)$request['id']; ?>">Actual cost in KSh for request <?php echo (int)$request['id']; ?></label>
                        <input id="cost-<?php echo (int)$request['id']; ?>" name="actual_cost" type="number" min="0" step="0.01" inputmode="decimal" placeholder="Cost KSh" value="<?php echo $request['actual_cost'] > 0 ? e($request['actual_cost']) : ''; ?>" class="w-28 px-2 py-1.5 border border-gray-300 rounded-lg text-sm">
                        <button type="submit" class="btn btn-small btn-primary">Save</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</section>
<?php include __DIR__ . '/../includes/layout_bottom.php'; ?>
