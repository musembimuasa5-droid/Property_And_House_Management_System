<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole(['Super Admin', 'Property Manager', 'Accountant']);

$db = db();
$error = '';
$success = $_SESSION['contact_message_success'] ?? '';
unset($_SESSION['contact_message_success']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';
    $id = isset($_POST['id']) && is_scalar($_POST['id']) ? filter_var($_POST['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
    if (!csrf_validate()) {
        http_response_code(400);
        $error = 'Your session expired. Reload the page and try again.';
    } elseif (!$id || !in_array($action, ['mark_read', 'mark_replied'], true)) {
        http_response_code(400);
        $error = 'Choose a valid contact message action.';
    } else {
        $status = $action === 'mark_replied' ? 'Replied' : 'Read';
        try {
            $db->beginTransaction();
            $stmt = $db->prepare('UPDATE contact_messages SET status = ? WHERE id = ?');
            $stmt->execute([$status, $id]);
            if ($stmt->rowCount() === 0) {
                $exists = $db->prepare('SELECT id FROM contact_messages WHERE id = ?');
                $exists->execute([$id]);
                if (!$exists->fetchColumn()) {
                    throw new InvalidArgumentException('That contact message no longer exists.');
                }
            }
            $audit = $db->prepare("INSERT INTO audit_logs (user_id, action, module, record_id, description, ip_address) VALUES (?, ?, 'Contact Messages', ?, ?, ?)");
            $audit->execute([(int)$_SESSION['user_id'], $status, $id, 'Marked contact message as ' . strtolower($status), $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN']);
            $db->commit();
            $_SESSION['contact_message_success'] = 'Contact message marked ' . strtolower($status) . '.';
            redirect('admin/contact_messages.php');
        } catch (InvalidArgumentException $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $error = $exception->getMessage();
        } catch (PDOException $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('Contact message update failed: ' . $exception->getMessage());
            $error = 'The contact message status could not be updated. Please try again.';
        }
    }
}

$messages = $db->query('SELECT id, name, email, phone, subject, message, status, created_at FROM contact_messages ORDER BY created_at DESC LIMIT 100')->fetchAll();

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
                <?php if ($error !== ''): ?><div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert"><?php echo e($error); ?></div><?php endif; ?>
                <?php if ($success !== ''): ?><div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800" role="status"><?php echo e($success); ?></div><?php endif; ?>
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
                                <th class="p-4">Message</th>
                                <th class="p-4">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 text-sm">
                            <?php if (!$messages): ?>
                                <tr><td colspan="8" class="p-6 text-center text-gray-500">No contact messages yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($messages as $message): ?>
                                    <tr>
                                        <td class="p-4 font-medium text-gray-900"><?php echo e($message['name']); ?></td>
                                        <td class="p-4"><?php echo e($message['email']); ?></td>
                                        <td class="p-4"><?php echo e($message['phone'] ?? '-'); ?></td>
                                        <td class="p-4"><?php echo e($message['subject']); ?></td>
                                        <td class="p-4"><?php echo render_status_badge($message['status']); ?></td>
                                        <td class="p-4 text-gray-500"><?php echo e(date('d M Y, H:i', strtotime($message['created_at']))); ?></td>
                                        <td class="p-4"><details><summary class="cursor-pointer font-medium text-primary">Read message</summary><p class="mt-2 max-w-xl whitespace-pre-wrap text-sm text-gray-700"><?php echo e($message['message']); ?></p></details></td>
                                        <td class="p-4">
                                            <div class="flex gap-2 flex-wrap">
                                                <form method="post"><?php echo csrf_field(); ?><input type="hidden" name="id" value="<?php echo (int)$message['id']; ?>"><input type="hidden" name="action" value="mark_read"><button class="px-2.5 py-1.5 border border-gray-300 rounded text-xs font-medium">Mark read</button></form>
                                                <form method="post"><?php echo csrf_field(); ?><input type="hidden" name="id" value="<?php echo (int)$message['id']; ?>"><input type="hidden" name="action" value="mark_replied"><button class="px-2.5 py-1.5 bg-primary text-white rounded text-xs font-medium">Mark replied</button></form>
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
