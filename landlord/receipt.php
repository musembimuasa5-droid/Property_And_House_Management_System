<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/modules.php';
require_once __DIR__ . '/../includes/receipt_view.php';

$landlord = requireLandlordProfile();
$db = db();
$paymentId = filter_var($_GET['id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$payment = $paymentId ? find_receipt($db, $paymentId) : null;
// Landlords may only open receipts for payments on their own properties.
if (!$payment || (int)$payment['landlord_id'] !== (int)$landlord['id']) {
    set_flash('error', 'Payment not found.');
    redirect('landlord/payments.php');
}
render_receipt_page($db, $payment, module_url('my_payments'));
