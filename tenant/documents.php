<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('Tenant');
$db = db();
$user = getCurrentUser();

$tenant_stmt = $db->prepare("SELECT id FROM tenants WHERE user_id = ? ORDER BY id LIMIT 1");
$tenant_stmt->execute([$user['id']]);
$tenant = $tenant_stmt->fetch();

$leases = [];
$documents = [];
if ($tenant) {
    $stmt = $db->prepare("SELECT l.id, l.start_date, l.end_date, l.status, l.document_path, p.name AS property_name, u.unit_number
        FROM leases l JOIN properties p ON l.property_id = p.id JOIN units u ON l.unit_id = u.id
        WHERE l.tenant_id = ? ORDER BY l.start_date DESC, l.id DESC");
    $stmt->execute([$tenant['id']]);
    $leases = $stmt->fetchAll();

    $documentStmt = $db->prepare('SELECT id, title, document_type, created_at FROM documents WHERE tenant_id = ? ORDER BY created_at DESC, id DESC');
    $documentStmt->execute([$tenant['id']]);
    $documents = $documentStmt->fetchAll();
}

$page_title = 'Lease & Documents';
include __DIR__ . '/../includes/layout_top.php';
?>
            <h1 class="text-2xl font-bold text-gray-900">Lease Agreements & Documents</h1>
            <section class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden" aria-labelledby="lease-documents-heading">
                <div class="p-5 border-b border-gray-100"><h2 id="lease-documents-heading" class="font-bold text-gray-900">Lease agreements</h2><p class="mt-1 text-sm text-gray-500">Download a copy when a file has been attached by management.</p></div>
                <div class="p-5">
                <?php if (!$leases): ?>
                    <p class="text-gray-500 text-sm">No lease records are linked to your tenant profile.</p>
                <?php else: ?>
                    <ul class="divide-y divide-gray-100">
                        <?php foreach ($leases as $l): ?>
                            <li class="py-3 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                                <div>
                                    <p class="font-bold text-gray-900"><?php echo e($l['property_name'] . ' - Unit ' . $l['unit_number']); ?></p>
                                    <p class="text-xs text-gray-500">Period: <?php echo e($l['start_date'] . ' to ' . $l['end_date']); ?></p>
                                </div>
                                <div class="flex items-center gap-3"><?php echo render_status_badge($l['status']); ?>
                                    <?php if (!empty($l['document_path'])): ?><a class="rounded-lg border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-primary" href="<?php echo BASE_URL; ?>/tenant/download_document.php?type=lease&amp;id=<?php echo (int)$l['id']; ?>">Download lease</a><?php else: ?><span class="text-xs text-gray-500">No file attached</span><?php endif; ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                </div>
            </section>
            <section class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden" aria-labelledby="shared-documents-heading">
                <div class="p-5 border-b border-gray-100"><h2 id="shared-documents-heading" class="font-bold text-gray-900">Documents shared with you</h2></div>
                <ul class="divide-y divide-gray-100 p-5">
                    <?php if (!$documents): ?><li class="text-sm text-gray-500">No documents have been shared with your account.</li>
                    <?php else: foreach ($documents as $document): ?><li class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 py-3"><div><p class="font-semibold text-gray-900"><?php echo e($document['title']); ?></p><p class="text-xs text-gray-500"><?php echo e($document['document_type']); ?> · Added <?php echo e(date('d M Y', strtotime($document['created_at']))); ?></p></div><a class="self-start rounded-lg border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-primary" href="<?php echo BASE_URL; ?>/tenant/download_document.php?type=document&amp;id=<?php echo (int)$document['id']; ?>">Download</a></li><?php endforeach; endif; ?>
                </ul>
            </section>
<?php include __DIR__ . '/../includes/layout_bottom.php'; ?>
