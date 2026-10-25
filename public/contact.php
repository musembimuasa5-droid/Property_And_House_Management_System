<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

$form = [
    'name' => '',
    'email' => '',
    'phone' => '',
    'subject' => '',
    'message' => '',
];
$errors = [];
$success = isset($_SESSION['contact_success']) && is_string($_SESSION['contact_success']) ? $_SESSION['contact_success'] : '';
unset($_SESSION['contact_success']);
$subjects = [
    'Property enquiry',
    'Viewing request',
    'Tenancy support',
    'Maintenance request',
    'Platform support',
    'General enquiry',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postValue = static function ($key) {
        return isset($_POST[$key]) && is_string($_POST[$key]) ? trim($_POST[$key]) : '';
    };
    foreach (array_keys($form) as $field) {
        $form[$field] = $postValue($field);
    }

    $form['name'] = preg_replace('/\s+/', ' ', $form['name']);
    $form['email'] = trim($form['email']);
    $form['phone'] = preg_replace('/\s+/', ' ', $form['phone']);
    $form['subject'] = trim($form['subject']);
    $form['message'] = trim($form['message']);

    $honeypot = $postValue('website');
    if (!csrf_validate()) {
        $errors[] = 'Your session expired. Reload the page and submit the form again.';
    }
    if ($honeypot !== '') {
        $errors[] = 'We could not accept this submission. Please check the form and try again.';
    }

    $nameLength = function_exists('mb_strlen') ? mb_strlen($form['name'], 'UTF-8') : strlen($form['name']);
    $messageLength = function_exists('mb_strlen') ? mb_strlen($form['message'], 'UTF-8') : strlen($form['message']);
    if ($form['name'] === '' || $nameLength > 120) {
        $errors[] = 'Enter your name (up to 120 characters).';
    }
    if (strlen($form['email']) > 150 || !filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid email address (up to 150 characters).';
    }
    if ($form['phone'] !== '') {
        if (strlen($form['phone']) > 30 || !preg_match('/^\+?[0-9\s().-]+$/', $form['phone'])) {
            $errors[] = 'Enter a valid phone number using digits and standard formatting such as +254 712 345 678.';
        } else {
            $normalizedPhone = preg_replace('/[\s().-]/', '', $form['phone']);
            $phoneDigits = ltrim($normalizedPhone, '+');
            $isKenyanLocal = preg_match('/^0[17]\d{8}$/', $phoneDigits) === 1;
            $isInternational = preg_match('/^\+?[1-9]\d{9,14}$/', $normalizedPhone) === 1;
            if (!$isKenyanLocal && !$isInternational) {
                $errors[] = 'Enter a valid phone number with 10 to 15 digits.';
            } else {
                $form['phone'] = $normalizedPhone;
            }
        }
    }
    if (!in_array($form['subject'], $subjects, true)) {
        $errors[] = 'Choose an enquiry category from the list.';
    }
    if ($messageLength < 10 || $messageLength > 5000) {
        $errors[] = 'Write a message between 10 and 5,000 characters.';
    }

    $lastSubmission = isset($_SESSION['contact_last_submission']) ? (int)$_SESSION['contact_last_submission'] : 0;
    if ($lastSubmission > 0 && time() - $lastSubmission < 10) {
        $errors[] = 'Please wait a few seconds before sending another enquiry.';
    }

    if (!$errors) {
        try {
            $db = db();
            $stmt = $db->prepare("INSERT INTO contact_messages (name, email, phone, subject, message, status, created_at) VALUES (?, ?, ?, ?, ?, 'New', NOW())");
            $stmt->execute([
                $form['name'],
                $form['email'],
                $form['phone'] !== '' ? $form['phone'] : null,
                $form['subject'],
                $form['message'],
            ]);
            if ($stmt->rowCount() === 1) {
                $_SESSION['contact_last_submission'] = time();
                $_SESSION['contact_success'] = 'Your enquiry has been saved for the authorized support team to review. Email notifications are not configured, so this confirmation does not mean an email was sent.';
                header('Location: ' . BASE_URL . '/public/contact.php#contact-form', true, 303);
                exit;
            }
            $errors[] = 'We could not save your enquiry. Please try again.';
        } catch (PDOException $e) {
            error_log('Contact message insert failed: ' . $e->getMessage());
            $errors[] = 'We could not save your enquiry right now. Please try again later.';
        }
    }
}

$page_title = 'Contact Us';
$page_description = 'Contact the Property and House Management System about property enquiries, viewings, tenancy, maintenance, or platform support.';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/navbar.php';
?>

<main id="main-content" class="contact-page">
    <section class="contact-intro">
        <div class="site-container">
            <p class="contact-eyebrow">We’re here to help</p>
            <h1>Contact <span>our team</span></h1>
            <p>Questions about a property, arranging a viewing, tenancy, maintenance, or using the platform? Send us a message and the authorized team can follow up.</p>
        </div>
    </section>

    <section class="site-container contact-layout" aria-label="Contact information and enquiry form">
        <aside class="contact-sidebar">
            <div class="contact-information">
                <p class="contact-eyebrow">Get in touch</p>
                <h2>How can we help?</h2>
                <p class="contact-sidebar-intro">Choose the topic that best fits your question in the form. For an existing tenancy issue, please include only the details needed to help the team understand your enquiry.</p>

                <div class="contact-detail-card">
                    <span class="contact-detail-icon"><i class="fa-regular fa-envelope" aria-hidden="true"></i></span>
                    <div>
                        <h3>Email</h3>
                        <a href="mailto:hello@urbanescape.co.ke">hello@urbanescape.co.ke</a>
                        <p>For property, tenancy, and platform enquiries.</p>
                    </div>
                </div>
                <div class="contact-detail-card">
                    <span class="contact-detail-icon"><i class="fa-solid fa-location-dot" aria-hidden="true"></i></span>
                    <div>
                        <h3>Service area</h3>
                        <p>Nairobi, Kenya</p>
                        <p>This is the platform’s service area, not a published office address.</p>
                    </div>
                </div>
                <div class="contact-detail-card">
                    <span class="contact-detail-icon"><i class="fa-solid fa-inbox" aria-hidden="true"></i></span>
                    <div>
                        <h3>Enquiry follow-up</h3>
                        <p>Submitted messages are saved for authorized staff to review.</p>
                        <p>Automatic email notifications are not configured.</p>
                    </div>
                </div>
            </div>

            <nav class="contact-help-links" aria-label="Helpful links">
                <h2>Helpful links</h2>
                <a href="<?php echo BASE_URL; ?>/public/properties.php"><span>Explore available houses</span><i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a>
                <a href="<?php echo BASE_URL; ?>/public/about.php"><span>About the platform</span><i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a>
                <a href="<?php echo BASE_URL; ?>/public/login.php"><span>Log in to your account</span><i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a>
                <a href="<?php echo BASE_URL; ?>/public/register.php"><span>Create an account</span><i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a>
            </nav>
        </aside>

        <section class="contact-form-card" aria-labelledby="contact-form-heading">
            <div class="contact-form-heading">
                <p class="contact-eyebrow">Send a message</p>
                <h2 id="contact-form-heading">Tell us what you need</h2>
                <p>Fields marked <span aria-hidden="true">*</span> are required. Please don’t include passwords or sensitive account credentials.</p>
            </div>

            <?php if ($success !== ''): ?>
                <div class="contact-feedback contact-feedback-success" role="status"><?php echo e($success); ?></div>
            <?php endif; ?>
            <?php if ($errors): ?>
                <div class="contact-feedback contact-feedback-error" role="alert" tabindex="-1">
                    <strong>Please review the following:</strong>
                    <ul><?php foreach ($errors as $error): ?><li><?php echo e($error); ?></li><?php endforeach; ?></ul>
                </div>
            <?php endif; ?>

            <form method="post" action="<?php echo BASE_URL; ?>/public/contact.php#contact-form" class="contact-form" id="contact-form">
                <?php echo csrf_field(); ?>
                <div class="contact-honeypot" aria-hidden="true">
                    <label for="contact-website">Leave this field empty</label>
                    <input type="text" id="contact-website" name="website" value="" tabindex="-1" autocomplete="off">
                </div>
                <div class="contact-field">
                    <label for="contact-name">Full name <span aria-hidden="true">*</span></label>
                    <input type="text" id="contact-name" name="name" value="<?php echo e($form['name']); ?>" maxlength="120" autocomplete="name" required>
                </div>
                <div class="contact-form-row">
                    <div class="contact-field">
                        <label for="contact-email">Email address <span aria-hidden="true">*</span></label>
                        <input type="email" id="contact-email" name="email" value="<?php echo e($form['email']); ?>" maxlength="150" autocomplete="email" required>
                    </div>
                    <div class="contact-field">
                        <label for="contact-phone">Phone number <span class="contact-optional">(optional)</span></label>
                        <input type="tel" id="contact-phone" name="phone" value="<?php echo e($form['phone']); ?>" maxlength="30" inputmode="tel" autocomplete="tel" placeholder="+254 712 345 678" aria-describedby="contact-phone-help">
                        <small id="contact-phone-help">Digits and common formatting such as spaces, +, hyphens, or parentheses are accepted.</small>
                    </div>
                </div>
                <div class="contact-field">
                    <label for="contact-subject">Enquiry category <span aria-hidden="true">*</span></label>
                    <select id="contact-subject" name="subject" required>
                        <option value="">Select a category</option>
                        <?php foreach ($subjects as $subject): ?>
                            <option value="<?php echo e($subject); ?>" <?php echo $form['subject'] === $subject ? 'selected' : ''; ?>><?php echo e($subject); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="contact-field">
                    <label for="contact-message">Message <span aria-hidden="true">*</span></label>
                    <textarea id="contact-message" name="message" rows="6" minlength="10" maxlength="5000" required placeholder="Tell us how we can help. Please avoid sharing passwords or sensitive account credentials."><?php echo e($form['message']); ?></textarea>
                    <small>10–5,000 characters.</small>
                </div>
                <button class="contact-submit" type="submit"><i class="fa-solid fa-paper-plane" aria-hidden="true"></i> Send enquiry</button>
            </form>
        </section>
    </section>
</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>
