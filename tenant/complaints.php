<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('Tenant');
$db = db();
$user = getCurrentUser();

$tenant_stmt = $db->prepare("SELECT t.*, l.property_id, l.unit_id FROM tenants t JOIN leases l ON t.id = l.tenant_id WHERE t.user_id = ? AND l.status = 'Active'");
$tenant_stmt->execute([$user['id']]);
$tenant = $tenant_stmt->fetch();

$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tenant) {
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');

    if (!empty($subject) && !empty($message)) {
        $stmt = $db->prepare("INSERT INTO complaints (tenant_id, property_id, unit_id, subject, message) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$tenant['id'], $tenant['property_id'], $tenant['unit_id'], $subject, $message]);
        $success = 'Complaint submitted successfully.';
    }
}

$complaints = [];
if ($tenant) {
    $c_stmt = $db->prepare("SELECT * FROM complaints WHERE tenant_id = ? ORDER BY created_at DESC");
    $c_stmt->execute([$tenant['id']]);
    $complaints = $c_stmt->fetchAll();
}

$page_title = 'Complaints';
include __DIR__ . '/../includes/header.php';
?>

<div class="flex h-screen bg-gray-50 overflow-hidden">
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>
    <div class="flex-1 flex flex-col overflow-y-auto">
        <?php include __DIR__ . '/../includes/navbar.php'; ?>
        <main class="flex-1 p-6 space-y-6">
            <h1 class="text-2xl font-bold text-gray-900">Tenant Complaints</h1>
            <?php if (!empty($success)): ?><div class="bg-green-50 text-green-800 p-3 rounded-lg text-sm"><?php echo e($success); ?></div><?php endif; ?>
            <div class="bg-white p-6 rounded-xl border border-gray-100 shadow-sm max-w-2xl">
                <form method="POST" class="space-y-4">
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Subject</label>
                        <input type="text" name="subject" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm outline-none">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Message</label>
                        <textarea name="message" rows="3" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm outline-none"></textarea>
                    </div>
                    <button type="submit" class="bg-primary text-white font-semibold px-4 py-2 rounded-lg text-sm">Submit Complaint</button>
                </form>
            </div>
        </main>
        <?php include __DIR__ . '/../includes/footer.php'; ?>
    </div>
</div>