<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('Tenant');
$db = db();

$notices = $db->query("SELECT * FROM notifications ORDER BY created_at DESC LIMIT 10")->fetchAll();

$page_title = 'Property Notices';
include __DIR__ . '/../includes/header.php';
?>

<div class="flex h-screen bg-gray-50 overflow-hidden">
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>
    <div class="flex-1 flex flex-col overflow-y-auto">
        <?php include __DIR__ . '/../includes/navbar.php'; ?>
        <main class="flex-1 p-6 space-y-6">
            <h1 class="text-2xl font-bold text-gray-900">Management Notices</h1>
            <div class="space-y-4 max-w-3xl">
                <?php if (empty($notices)): ?>
                    <div class="bg-white p-6 rounded-xl border border-gray-100"><p class="text-gray-500 text-sm">No active notices.</p></div>
                <?php else: ?>
                    <?php foreach ($notices as $n): ?>
                        <div class="bg-white p-5 rounded-xl border border-gray-100 shadow-sm">
                            <h3 class="font-bold text-gray-900"><?php echo e($n['title']); ?></h3>
                            <p class="text-sm text-gray-600 mt-2"><?php echo nl2br(e($n['message'])); ?></p>
                            <p class="text-xs text-gray-400 mt-3"><?php echo e($n['created_at']); ?></p>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </main>
        <?php include __DIR__ . '/../includes/footer.php'; ?>
    </div>
</div>