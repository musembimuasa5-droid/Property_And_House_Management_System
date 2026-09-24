<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;

    $tenant_id = $data['tenant_id'] ?? null;
    $lease_id = $data['lease_id'] ?? null;
    $amount = $data['amount'] ?? 0;
    $method = $data['payment_method'] ?? 'M-Pesa';
    $reference = $data['transaction_reference'] ?? '';
    $period = $data['payment_period'] ?? date('Y-m');

    if (!$tenant_id || !$lease_id || $amount <= 0 || empty($reference)) {
        echo json_encode(['success' => false, 'message' => 'Missing or invalid required payment fields']);
        exit();
    }

    try {
        $db = db();
        $db->beginTransaction();

        // Fetch property and unit from lease
        $lease_stmt = $db->prepare("SELECT property_id, unit_id FROM leases WHERE id = ?");
        $lease_stmt->execute([$lease_id]);
        $lease = $lease_stmt->fetch();

        if (!$lease) {
            throw new Exception('Invalid lease associated with payment.');
        }

        $receipt = generate_receipt_number();
        $recorded_by = $_SESSION['user_id'];

        $stmt = $db->prepare("INSERT INTO payments (receipt_number, tenant_id, property_id, unit_id, lease_id, amount, payment_method, transaction_reference, payment_date, payment_period, recorded_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?)");
        $stmt->execute([$receipt, $tenant_id, $lease['property_id'], $lease['unit_id'], $lease_id, $amount, $method, $reference, $period, $recorded_by]);

        $db->commit();
        logAuditAction('RECORD_PAYMENT', 'payments', $db->lastInsertId(), "Recorded payment of {$amount} for tenant {$tenant_id}");

        echo json_encode(['success' => true, 'message' => 'Payment recorded successfully', 'receipt' => $receipt]);
    } catch (Exception $e) {
        $db->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}