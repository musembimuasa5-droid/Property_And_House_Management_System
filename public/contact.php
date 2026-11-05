<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

$errors = [];
$sent = false;
$form = ['name' => '', 'email' => '', 'phone' => '', 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($form as $key => $default) $form[$key] = is_string($_POST[$key] ?? null) ? trim($_POST[$key]) : '';
    if (!csrf_validate()) {
        $errors[] = 'Your session expired. Please reload the page and try again.';
    } else {
        if ($form['name'] === '' || mb_strlen($form['name']) > 100) $errors[] = 'Enter your name (up to 100 characters).';
        if (!filter_var($form['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($form['email']) > 150) $errors[] = 'Enter a valid email address.';
        if ($form['phone'] !== '' && !preg_match('/^\+?[0-9][0-9 ()-]{6,19}$/', $form['phone'])) $errors[] = 'Enter a valid phone number, e.g. +254712345678, or leave it blank.';
        if ($form['message'] === '' || mb_strlen($form['message']) > 3000) $errors[] = 'Enter a message of up to 3,000 characters.';
    }

    // Bots fill the hidden "website" field; accept quietly but store nothing.
    if (!$errors && trim((string)($_POST['website'] ?? '')) === '') {
        try {
            $db = db();
            $db->prepare('INSERT INTO enquiries (full_name, email, phone, message) VALUES (?, ?, ?, ?)')
                ->execute([$form['name'], $form['email'], $form['phone'] ?: null, $form['message']]);
            notify_roles($db, MANAGEMENT_ROLES, 'New enquiry from ' . $form['name'], mb_strimwidth($form['message'], 0, 160, '…') . ' (' . $form['email'] . ')');
        } catch (PDOException $e) {
            error_log('Contact enquiry could not be saved: ' . $e->getMessage());
            $errors[] = 'Your message could not be sent right now. Please call or email the management office instead.';
        }
    }
    if (!$errors) {
        $sent = true;
        $form = ['name' => '', 'email' => '', 'phone' => '', 'message' => ''];
    }
}

$page_title = 'Contact Us';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/navbar.php';
?>

<div class="flex-1 flex flex-col bg-gray-50 py-12 px-4">
    <div class="max-w-3xl mx-auto w-full bg-white rounded-xl shadow-sm border border-gray-100 p-8">
        <h1 class="text-3xl font-bold text-gray-900 mb-2">Contact Management</h1>
        <p class="text-sm text-gray-500 mb-6">Questions about a property, a viewing or your tenancy? Send us a message and the management team will get back to you.</p>

        <?php if ($sent): ?>
            <div class="bg-green-50 text-green-800 p-4 rounded-lg text-sm mb-6 border-l-4 border-success" role="status">Thank you. Your message has been sent to the management team, who will reply by email or phone.</div>
        <?php endif; ?>
        <?php if ($errors): ?>
            <div class="bg-red-50 text-red-800 p-4 rounded-lg text-sm mb-6 border-l-4 border-danger" role="alert"><ul class="list-disc pl-4"><?php foreach ($errors as $error): ?><li><?php echo e($error); ?></li><?php endforeach; ?></ul></div>
        <?php endif; ?>

        <form method="POST" class="space-y-4">
            <?php echo csrf_field(); ?>
            <div class="hidden" aria-hidden="true"><label for="website">Website</label><input id="website" type="text" name="website" tabindex="-1" autocomplete="off"></div>
            <div>
                <label for="name" class="block text-xs font-semibold text-gray-500 uppercase mb-1">Full Name</label>
                <input id="name" type="text" name="name" required maxlength="100" value="<?php echo e($form['name']); ?>" autocomplete="name" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label for="email" class="block text-xs font-semibold text-gray-500 uppercase mb-1">Email Address</label>
                    <input id="email" type="email" name="email" required maxlength="150" value="<?php echo e($form['email']); ?>" autocomplete="email" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
                </div>
                <div>
                    <label for="phone" class="block text-xs font-semibold text-gray-500 uppercase mb-1">Phone Number <span class="normal-case font-normal">(optional)</span></label>
                    <input id="phone" type="tel" name="phone" placeholder="+2547..." value="<?php echo e($form['phone']); ?>" autocomplete="tel" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
                </div>
            </div>
            <div>
                <label for="message" class="block text-xs font-semibold text-gray-500 uppercase mb-1">Message</label>
                <textarea id="message" name="message" rows="5" required maxlength="3000" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"><?php echo e($form['message']); ?></textarea>
            </div>
            <button type="submit" class="bg-primary hover:bg-blue-800 text-white font-semibold px-6 py-2.5 rounded-lg text-sm transition">
                Send Message
            </button>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
