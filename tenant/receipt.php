<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/receipt_view.php';

requireRole('Tenant');
$db = db();
$paymentId = filter_var($_GET['id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$payment = $paymentId ? find_receipt($db, $paymentId) : null;
// Tenants may only open receipts for their own payments.
if (!$payment || (int)$payment['tenant_user_id'] !== (int)getCurrentUser()['id']) {
    set_flash('error', 'Receipt not found.');
    redirect('tenant/payments.php');
}
render_receipt_page($db, $payment, BASE_URL . '/tenant/payments.php');
