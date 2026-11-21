<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

$page_title = 'Privacy Notice';
$page_description = 'How ' . APP_NAME . ' collects, uses and protects personal data.';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/navbar.php';
?>
<main id="main-content" class="flex-1 px-4 py-12 bg-gray-50">
    <article class="max-w-3xl mx-auto bg-white rounded-xl border border-gray-100 shadow-sm p-8 space-y-4 text-gray-700 leading-relaxed">
        <h1 class="text-3xl font-bold text-gray-900">Privacy notice</h1>
        <p><?php echo e(APP_NAME); ?> keeps the records needed to let, rent and look after residential homes. This notice explains what we collect, why, who can see it and the choices you have under Kenya's Data Protection Act, 2019.</p>

        <h2 class="text-xl font-bold text-gray-900 pt-2">What we collect</h2>
        <ul class="list-disc pl-6 space-y-1">
            <li><strong>Tenants:</strong> name, email, phone number, national ID or passport number, gender, emergency contact, lease terms, rent charges, payments, deposit records, condition reports with photos, repair requests and complaints.</li>
            <li><strong>Landlords:</strong> name, contacts, ID or company registration number and payout details.</li>
            <li><strong>Visitors:</strong> the name, phone, email and message you send when you request a viewing or contact us.</li>
            <li><strong>Everyone who signs in:</strong> the time of sign-in and the network address used, kept in the audit log to protect accounts.</li>
        </ul>

        <h2 class="text-xl font-bold text-gray-900 pt-2">Why we use it</h2>
        <p>Only to manage tenancies and properties: to keep the lease and rent book, issue receipts, record deposits and the condition of the home, arrange repairs, answer enquiries and send notices about your tenancy. We do not sell personal data or use it for advertising. Rent is paid outside the system; we only record payments.</p>

        <h2 class="text-xl font-bold text-gray-900 pt-2">Who can see it</h2>
        <p>Access depends on your role. Tenants see only their own records. Landlords see their own properties and units. Caretakers see the repair requests assigned to them. Management staff see the records they need for their work. Passwords are stored only in a scrambled (hashed) form that staff cannot read.</p>

        <h2 class="text-xl font-bold text-gray-900 pt-2">How long we keep it</h2>
        <p>Tenancy, payment and deposit records are kept while the tenancy lasts and for as long afterwards as is needed to settle accounts and answer disputes. Enquiries and viewing requests are closed once handled.</p>

        <h2 class="text-xl font-bold text-gray-900 pt-2">Your rights</h2>
        <p>You may ask to see the personal data we hold about you, to correct it, or to have it deleted where the law allows. Tenants can update their phone number and emergency contact on the My Profile page. For anything else, use the <a class="text-primary font-semibold" href="<?php echo BASE_URL; ?>/public/contact.php">contact page</a>.</p>
    </article>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
