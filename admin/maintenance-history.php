<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/modules.php';
require_once __DIR__ . '/../includes/functions.php';

require_module('maintenance');
$db = db();
$id = (int)filter_var($_GET['id'] ?? 0, FILTER_VALIDATE_INT);
$stmt = $db->prepare('SELECT mr.*, t.full_name AS tenant_name, t.phone AS tenant_phone, p.name AS property_name, u.unit_number, s.full_name AS staff_name
    FROM maintenance_requests mr JOIN tenants t ON t.id = mr.tenant_id JOIN properties p ON p.id = mr.property_id JOIN units u ON u.id = mr.unit_id
    LEFT JOIN users s ON s.id = mr.assigned_staff WHERE mr.id = ?');
$stmt->execute([$id]);
$request = $stmt->fetch();
if (!$request) {
    set_flash('error', 'Maintenance request not found.');
    redirect('admin/maintenance.php');
}
$history = maintenance_history($db, $id);

$page_title = 'Maintenance request #' . $id;
include __DIR__ . '/../includes/layout_top.php';
?>
<div>
    <a href="<?php echo e(module_url('maintenance')); ?>" class="text-sm text-primary font-medium"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Back to maintenance</a>
    <h1 class="text-2xl font-bold text-gray-900 mt-2"><?php echo e($request['category']); ?> request #<?php echo $id; ?></h1>
    <p class="text-sm text-gray-500"><?php echo e($request['property_name'] . ' / ' . $request['unit_number'] . ' · ' . $request['tenant_name'] . ' · ' . $request['tenant_phone']); ?></p>
</div>
<div class="grid grid-cols-1 xl:grid-cols-5 gap-6 items-start">
    <section class="bg-white p-6 rounded-xl border border-gray-100 shadow-sm space-y-3 xl:col-span-2" aria-label="Request details">
        <p><?php echo render_status_badge($request['status']); ?> <?php echo render_status_badge($request['priority']); ?></p>
        <p class="text-sm text-gray-700"><?php echo nl2br(e($request['description'])); ?></p>
        <dl class="text-sm space-y-1">
            <div><dt class="inline font-semibold">Assigned to:</dt> <dd class="inline"><?php echo e($request['staff_name'] ?? 'Not assigned'); ?></dd></div>
            <div><dt class="inline font-semibold">Estimated / actual cost:</dt> <dd class="inline"><?php echo format_currency($request['estimated_cost']) . ' / ' . format_currency($request['actual_cost']); ?></dd></div>
        </dl>
        <?php if ($request['image_path']): ?>
            <a href="<?php echo e(attachment_url('maintenance', $id)); ?>" target="_blank" rel="noopener"><img src="<?php echo e(attachment_url('maintenance', $id)); ?>" alt="Photo of the reported problem" class="rounded-lg border border-gray-200 max-h-64"></a>
        <?php else: ?><p class="text-xs text-gray-500">No photo was attached.</p><?php endif; ?>
        <a class="btn btn-primary" href="<?php echo e(module_url('maintenance', ['action' => 'edit', 'id' => $id])); ?>">Update request</a>
    </section>
    <section class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden xl:col-span-3" aria-labelledby="history-heading">
        <div class="p-5 border-b border-gray-100"><h2 id="history-heading" class="font-bold text-gray-900">Status history</h2><p class="text-xs text-gray-500">Every change, oldest first, with who made it.</p></div>
        <div class="overflow-x-auto"><table class="w-full text-left text-sm">
            <thead><tr><th scope="col" class="p-4">When</th><th scope="col" class="p-4">Status</th><th scope="col" class="p-4">By</th><th scope="col" class="p-4">Note</th></tr></thead>
            <tbody class="divide-y divide-gray-100">
            <?php if (!$history): ?><tr><td colspan="4" class="p-6 text-center text-gray-500">No history was recorded for this request (it was logged before history tracking began).</td></tr><?php endif; ?>
            <?php foreach ($history as $step): ?>
                <tr><td class="p-4 whitespace-nowrap"><?php echo e(date('d M Y, H:i', strtotime($step['created_at']))); ?></td><td class="p-4"><?php echo render_status_badge($step['status']); ?></td><td class="p-4"><?php echo e($step['full_name'] ?? '—'); ?></td><td class="p-4"><?php echo e($step['note'] ?? ''); ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </section>
</div>
<?php include __DIR__ . '/../includes/layout_bottom.php'; ?>
