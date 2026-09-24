<?php
/**
 * Authentication and Role-Based Access Control (RBAC) Helpers
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';

function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function getCurrentUser() {
    if (!isLoggedIn()) return null;
    return [
        'id' => $_SESSION['user_id'] ?? null,
        'role_id' => $_SESSION['role_id'] ?? null,
        'role_name' => $_SESSION['role_name'] ?? null,
        'full_name' => $_SESSION['full_name'] ?? '',
        'email' => $_SESSION['email'] ?? ''
    ];
}

function requireLogin() {
    if (!isLoggedIn()) {
        header("Location: " . BASE_URL . "/public/login.php?error=unauthorized");
        exit();
    }
}

function requireRole($allowed_roles) {
    requireLogin();
    $user = getCurrentUser();
    
    // Allow Super Admin (role_id 1) access everywhere
    if ($user['role_id'] == 1) return;

    if (!in_array($user['role_name'], (array)$allowed_roles)) {
        header("Location: " . BASE_URL . "/public/index.php?error=forbidden");
        exit();
    }
}

function logAuditAction($action, $module, $record_id = null, $description = '') {
    try {
        $db = db();
        $user_id = $_SESSION['user_id'] ?? null;
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';

        $stmt = $db->prepare("INSERT INTO audit_logs (user_id, action, module, record_id, description, ip_address) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$user_id, $action, $module, $record_id, $description, $ip]);
    } catch (\Exception $e) {
        // Fail silently to avoid breaking primary transaction
    }
}