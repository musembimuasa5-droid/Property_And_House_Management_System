<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole(['Super Admin', 'Property Manager', 'Accountant']);

$db = db();

if (isset($_GET['action'], $_GET['id'])) {
    $id = filter_var($_GET['id'], FILTER_VALIDATE_INT);
    $action = $_GET['action'];
    $allowed = ['mark_read', 'mark_replied'];
    if ($id && in_array($action, $allowed, true)) {
        $status = $action === 'mark_replied' ? 'Replied' : 'Read';
        $stmt = $db->prepare('UPDATE contact_messages SET status = ? WHERE id = ?');
        $stmt->execute([$status, $id]);
        header('Location: ' . BASE_URL . '/admin/contact_messages.php');
        exit;
    }
}

$messages = $db->query('SELECT * FROM contact_messages ORDER BY created_at DESC LIMIT 100')->fetchAll();

$page_title = 'Contact Messages';
include __DIR__ . '/../includes/header.php';
?>

<div class="flex h-screen bg-gray-50 overflow-hidden">
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>
    <div class="flex-1 flex flex-col overflow-y-auto">
        <?php include __DIR__ . '/../includes/navbar.php'; ?>
        <main id="main-content" class="flex-1 p-6 space-y-6">
            <div class="flex items-center justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">Contact Messages</h1>
                    <p class="text-sm text-gray-500">Manage property and tenancy enquiries submitted through the public contact form.</p>
                </div>
            </div>

            <section class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-left">
                        <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                            <tr>
                                <th class="p-4">Name</th>
                                <th class="p-4">Email</th>
                                <th class="p-4">Phone</th>
                                <th class="p-4">Subject</th>
                                <th class="p-4">Status</th>
                                <th class="p-4">Submitted</th>
                                <th class="p-4">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 text-sm">
                            <?php if (!$messages): ?>
                                <tr><td colspan="7" class="p-6 text-center text-gray-500">No contact messages yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($messages as $message): ?>
                                    <tr>
                                        <td class="p-4 font-medium text-gray-900"><?php echo e($message['name']); ?></td>
                                        <td class="p-4"><?php echo e($message['email']); ?></td>
                                        <td class="p-4"><?php echo e($message['phone'] ?? '-'); ?></td>
                                        <td class="p-4"><?php echo e($message['subject']); ?></td>
                                        <td class="p-4"><?php echo render_status_badge($message['status']); ?></td>
                                        <td class="p-4 text-gray-500"><?php echo e(date('d M Y, H:i', strtotime($message['created_at']))); ?></td>
                                        <td class="p-4">
                                            <div class="flex gap-2 flex-wrap">
                                                <a href="<?php echo BASE_URL; ?>/admin/contact_messages.php?action=mark_read&id=<?php echo (int)$message['id']; ?>" class="px-2.5 py-1.5 border border-gray-300 rounded text-xs font-medium">Mark read</a>
                                                <a href="<?php echo BASE_URL; ?>/admin/contact_messages.php?action=mark_replied&id=<?php echo (int)$message['id']; ?>" class="px-2.5 py-1.5 bg-primary text-white rounded text-xs font-medium">Mark replied</a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </main>
        <?php include __DIR__ . '/../includes/footer.php'; ?>
    </div>
</div>
