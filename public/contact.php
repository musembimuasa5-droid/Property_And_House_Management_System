<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate()) { $success = 'Your session expired. Please reload the page and try again.'; }
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $message = trim($_POST['message'] ?? '');

    if (!$success && !empty($name) && filter_var($email, FILTER_VALIDATE_EMAIL) && !empty($message)) $success = 'Contact submissions are not configured yet. Your message has not been sent.';
}

$page_title = 'Contact Us';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/navbar.php';
?>

<div class="flex-1 flex flex-col bg-gray-50 py-12 px-4">
    <div class="max-w-3xl mx-auto w-full bg-white rounded-xl shadow-sm border border-gray-100 p-8">
        <h1 class="text-3xl font-bold text-gray-900 mb-2">Contact Management</h1>
        <p class="text-sm text-gray-500 mb-6">Use this page for property and tenancy enquiries. Message delivery is not configured yet.</p>

        <?php if (!empty($success)): ?>
            <div class="bg-green-50 text-green-800 p-4 rounded-lg text-sm mb-6 border-l-4 border-success">
                <?php echo e($success); ?>
            </div>
        <?php endif; ?>

        <form method="POST" class="space-y-4">
            <?php echo csrf_field(); ?>
            <div>
                <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Full Name</label>
                <input type="text" name="name" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary outline-none">
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Email Address</label>
                    <input type="email" name="email" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary outline-none">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Phone Number</label>
                    <input type="text" name="phone" placeholder="+2547..." class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary outline-none">
                </div>
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Message</label>
                <textarea name="message" rows="4" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary outline-none"></textarea>
            </div>
            <button type="submit" class="bg-primary hover:bg-blue-800 text-white font-semibold px-6 py-2.5 rounded-lg text-sm transition">
                Send Message
            </button>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
