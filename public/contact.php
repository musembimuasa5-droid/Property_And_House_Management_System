<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

$success = '';
$error = '';
$form = [
    'name' => '',
    'email' => '',
    'phone' => '',
    'subject' => '',
    'message' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['name'] = trim($_POST['name'] ?? '');
    $form['email'] = trim($_POST['email'] ?? '');
    $form['phone'] = trim($_POST['phone'] ?? '');
    $form['subject'] = trim($_POST['subject'] ?? '');
    $form['message'] = trim($_POST['message'] ?? '');

    if (!csrf_validate()) {
        $error = 'Your session expired. Please reload the page and try again.';
    } elseif ($form['name'] === '') {
        $error = 'Please enter your full name.';
    } elseif (!filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
        $error = 'Please provide a valid email address.';
    } elseif ($form['phone'] !== '' && !is_valid_phone_number($form['phone'])) {
        $error = 'Please enter a valid phone number in the format 0712345678 or +254712345678.';
    } elseif ($form['subject'] === '') {
        $error = 'Please choose a subject for your enquiry.';
    } elseif (mb_strlen($form['message']) < 10) {
        $error = 'Your message must include at least 10 characters so we can understand your enquiry.';
    } else {
        try {
            $db = db();
            $stmt = $db->prepare("INSERT INTO contact_messages (name, email, phone, subject, message, status, created_at) VALUES (?, ?, ?, ?, ?, 'New', NOW())");
            $stmt->execute([
                $form['name'],
                $form['email'],
                $form['phone'] !== '' ? $form['phone'] : null,
                $form['subject'],
                $form['message']
            ]);
            $success = 'Thank you for contacting Urban Escape. Your message has been saved and our team will respond as soon as possible.';
            $form = ['name' => '', 'email' => '', 'phone' => '', 'subject' => '', 'message' => ''];
        } catch (Exception $e) {
            error_log('Contact message insert failed: ' . $e->getMessage());
            $error = 'We could not send your message right now. Please try again later.';
        }
    }
}

$page_title = 'Contact Us';
$page_description = 'Contact Urban Escape about property enquiries, tenancy support, maintenance, and general assistance.';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/navbar.php';
?>

<div class="flex-1 flex flex-col bg-gray-50 py-12 px-4">
    <div class="max-w-5xl mx-auto w-full grid grid-cols-1 lg:grid-cols-[0.95fr_1.25fr] gap-8 items-start">
        <aside class="bg-primary text-white rounded-2xl shadow-sm p-8">
            <p class="text-xs font-semibold uppercase tracking-[0.12em] text-blue-100 mb-4">Get in touch</p>
            <h1 class="text-3xl font-bold leading-tight mb-4">We’re here to help with your property and tenancy questions.</h1>
            <p class="text-sm text-blue-100 leading-7 mb-8">Urban Escape supports landlords, property managers, tenants, and prospective renters with enquiries about listings, maintenance, rent, and general property support.</p>

            <div class="space-y-4 text-sm text-blue-50">
                <div class="flex items-start gap-3">
                    <i class="fa-solid fa-phone mt-1"></i>
                    <div>
                        <div class="font-semibold text-white">Phone</div>
                        <div>Use the number on your property onboarding documents or contact the office via the official managed number.</div>
                    </div>
                </div>
                <div class="flex items-start gap-3">
                    <i class="fa-solid fa-envelope mt-1"></i>
                    <div>
                        <div class="font-semibold text-white">Email</div>
                        <div>hello@urbanescape.co.ke</div>
                    </div>
                </div>
                <div class="flex items-start gap-3">
                    <i class="fa-solid fa-location-dot mt-1"></i>
                    <div>
                        <div class="font-semibold text-white">Location</div>
                        <div>Nairobi, Kenya</div>
                    </div>
                </div>
            </div>
        </aside>

        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-8">
            <div class="mb-6">
                <p class="text-xs font-semibold uppercase tracking-[0.12em] text-gray-500 mb-2">Submit message</p>
                <h2 class="text-2xl font-bold text-gray-900">Send an enquiry</h2>
            </div>

            <?php if (!empty($success)): ?>
                <div class="bg-green-50 text-green-800 p-4 rounded-lg text-sm mb-6 border-l-4 border-green-500">
                    <?php echo e($success); ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($error)): ?>
                <div class="bg-red-50 text-red-800 p-4 rounded-lg text-sm mb-6 border-l-4 border-red-500">
                    <?php echo e($error); ?>
                </div>
            <?php endif; ?>

            <form method="POST" class="space-y-5" id="contact-form" novalidate>
                <?php echo csrf_field(); ?>
                <div>
                    <label for="contact-name" class="block text-sm font-medium text-gray-700 mb-1">Full Name</label>
                    <input id="contact-name" type="text" name="name" value="<?php echo e($form['name']); ?>" required class="w-full px-3 py-2.5 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary outline-none" aria-describedby="contact-name-help">
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label for="contact-email" class="block text-sm font-medium text-gray-700 mb-1">Email Address</label>
                        <input id="contact-email" type="email" name="email" value="<?php echo e($form['email']); ?>" required class="w-full px-3 py-2.5 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary outline-none">
                    </div>
                    <div>
                        <label for="contact-phone" class="block text-sm font-medium text-gray-700 mb-1">Phone Number</label>
                        <input id="contact-phone" type="tel" name="phone" value="<?php echo e($form['phone']); ?>" inputmode="tel" placeholder="0712345678 or +254712345678" class="w-full px-3 py-2.5 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary outline-none" pattern="^(?:\\+?254|0)?[17]\\d{8}$|^\\+?\\d{10,15}$" aria-describedby="phone-help">
                        <p id="phone-help" class="mt-1 text-xs text-gray-500">Use a valid Kenyan or international phone format.</p>
                    </div>
                </div>

                <div>
                    <label for="contact-subject" class="block text-sm font-medium text-gray-700 mb-1">Subject</label>
                    <select id="contact-subject" name="subject" required class="w-full px-3 py-2.5 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary outline-none">
                        <option value="">Select a subject</option>
                        <option value="Property enquiry" <?php echo $form['subject'] === 'Property enquiry' ? 'selected' : ''; ?>>Property enquiry</option>
                        <option value="Tenancy support" <?php echo $form['subject'] === 'Tenancy support' ? 'selected' : ''; ?>>Tenancy support</option>
                        <option value="Maintenance request" <?php echo $form['subject'] === 'Maintenance request' ? 'selected' : ''; ?>>Maintenance request</option>
                        <option value="General enquiry" <?php echo $form['subject'] === 'General enquiry' ? 'selected' : ''; ?>>General enquiry</option>
                    </select>
                </div>

                <div>
                    <label for="contact-message" class="block text-sm font-medium text-gray-700 mb-1">Message</label>
                    <textarea id="contact-message" name="message" rows="5" required class="w-full px-3 py-2.5 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary outline-none" placeholder="Tell us how we can help you."><?php echo e($form['message']); ?></textarea>
                </div>

                <button type="submit" class="w-full sm:w-auto inline-flex items-center justify-center gap-2 bg-primary hover:bg-blue-800 text-white font-semibold px-6 py-3 rounded-lg text-sm transition shadow-sm">
                    <i class="fa-solid fa-paper-plane"></i>
                    Submit Message
                </button>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('contact-form');
    if (!form) return;

    const phoneField = document.getElementById('contact-phone');
    if (phoneField) {
        phoneField.addEventListener('input', function () {
            phoneField.value = phoneField.value.replace(/[^0-9+\s-]/g, '');
        });
    }

    form.addEventListener('submit', function (event) {
        const phone = (phoneField ? phoneField.value : '').trim();
        if (phone && !/^(?:\+?\d{10,15}|(?:\+?254|0)[17]\d{8})$/.test(phone.replace(/\s+/g, ''))) {
            event.preventDefault();
            const message = 'Please enter a valid phone number, for example 0712345678 or +254712345678.';
            const existing = document.getElementById('phone-validation-message');
            if (existing) existing.remove();
            const note = document.createElement('div');
            note.id = 'phone-validation-message';
            note.className = 'mt-2 text-sm text-red-700';
            note.textContent = message;
            phoneField.parentNode.appendChild(note);
            phoneField.focus();
        }
    });
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
