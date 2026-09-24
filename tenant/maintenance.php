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

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tenant) {
    $category = $_POST['category'] ?? 'Plumbing';
    $description = trim($_POST['description'] ?? '');
    $priority = $_POST['priority'] ?? 'Medium';

    if (!empty($description)) {
        $stmt = $db->prepare("INSERT INTO maintenance_requests (tenant_id, property_id, unit_id, category, description, priority) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$tenant['id'], $tenant['property_id'], $tenant['unit_id'], $category, $description, $priority]);
        $success = 'Maintenance request submitted successfully.';
    } else {
        $error = 'Please provide a description of the issue.';
    }
}

$requests = [];
if ($tenant) {
    $req_stmt = $db->prepare("SELECT * FROM maintenance_requests WHERE tenant_id = ? ORDER BY created_at DESC");
    $req_stmt->execute([$tenant['id']]);
    $requests = $req_stmt->fetchAll();
}

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

            <div class="bg-white p-6 rounded-xl border border-gray-100 shadow-sm max-w-2xl">
                <h3 class="font-bold text-lg text-gray-900 mb-4">Submit New Request</h3>
                <form method="POST" class="space-y-4">
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
                        <textarea name="description" rows="3" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm outline-none" placeholder="Describe the issue..."></textarea>
                    </div>
                    <button type="submit" class="bg-primary text-white font-semibold px-4 py-2 rounded-lg text-sm transition hover:bg-blue-800">Submit Request</button>
                </form>
            </div>
        </main>
        <?php include __DIR__ . '/../includes/footer.php'; ?>
    </div>
</div>