<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/finance.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

if (!in_array(getCurrentUser()['role_name'], ['Super Admin', 'Property Manager', 'Accountant'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($data['csrf_token'] ?? '');
    if (!isset($_SESSION['csrf_token']) || !is_string($token) || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid security token']);
        exit();
    }

    $tenant_id = $data['tenant_id'] ?? null;
    $lease_id = $data['lease_id'] ?? null;
    $amount = $data['amount'] ?? 0;
    $method = $data['payment_method'] ?? 'M-Pesa';
    $reference = $data['transaction_reference'] ?? '';
    $period = $data['payment_period'] ?? date('Y-m');

    $amount = filter_var($amount, FILTER_VALIDATE_FLOAT);
    $tenant_id = filter_var($tenant_id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $lease_id = filter_var($lease_id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$tenant_id || !$lease_id || $amount === false || $amount <= 0 || !is_string($reference) || $reference === '' || strlen($reference) > 100 || !in_array($method, ['M-Pesa', 'Bank', 'Cash', 'Card', 'Other'], true) || !is_string($period) || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
        echo json_encode(['success' => false, 'message' => 'Missing or invalid required payment fields']);
        exit();
    }

    try {
        $db = db();
        $db->beginTransaction();

        // Fetch property and unit from lease
        $lease_stmt = $db->prepare("SELECT property_id, unit_id FROM leases WHERE id = ? AND tenant_id = ? AND status = 'Active'");
        $lease_stmt->execute([$lease_id, $tenant_id]);
        $lease = $lease_stmt->fetch();

        if (!$lease) {
            throw new Exception('Invalid lease associated with payment.');
        }

        $receipt = generate_receipt_number();
        $recorded_by = $_SESSION['user_id'];

        $stmt = $db->prepare("INSERT INTO payments (receipt_number, tenant_id, property_id, unit_id, lease_id, amount, payment_method, transaction_reference, payment_date, payment_period, recorded_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?)");
        $stmt->execute([$receipt, $tenant_id, $lease['property_id'], $lease['unit_id'], $lease_id, $amount, $method, $reference, $period, $recorded_by]);
        $payment_id = (int)$db->lastInsertId();
        recalculate_lease_balances($db, (int)$lease_id);

        $db->commit();
        logAuditAction('RECORD_PAYMENT', 'payments', $payment_id, "Recorded payment of {$amount} for tenant {$tenant_id}");

        echo json_encode(['success' => true, 'message' => 'Payment recorded successfully', 'receipt' => $receipt]);
    } catch (Exception $e) {
        if (isset($db) && $db->inTransaction()) $db->rollBack();
        error_log('Payment API failed: ' . $e->getMessage());
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Unable to record this payment. Check the details and try again.']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
