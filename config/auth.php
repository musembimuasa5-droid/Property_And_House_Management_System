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

/** Return the existing portal entry point for a user's database role. */
function dashboardPathForRole($role_name) {
    if ($role_name === 'Tenant') return 'tenant/dashboard.php';
    if ($role_name === 'Landlord') return 'landlord/dashboard.php';
    if ($role_name === 'Caretaker') return 'caretaker/dashboard.php';
    if (in_array($role_name, ['Super Admin', 'Property Manager', 'Accountant'], true)) return 'admin/dashboard.php';
    return 'public/index.php?error=role_portal_unavailable';
}

function redirectToRoleDashboard($role_name) {
    redirect(dashboardPathForRole($role_name));
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

    if (!in_array($user['role_name'], (array)$allowed_roles, true)) {
        header("Location: " . BASE_URL . "/public/index.php?error=forbidden");
        exit();
    }
}

/** The signed-in landlord's profile; stops with 403 when the account is not linked to one. */
function requireLandlordProfile() {
    requireRole('Landlord');
    $stmt = db()->prepare('SELECT id, full_name FROM landlords WHERE user_id = ? LIMIT 1');
    $stmt->execute([$_SESSION['user_id']]);
    $landlord = $stmt->fetch();
    if (!$landlord) {
        http_response_code(403);
        exit('Landlord profile is not linked to this account. Please contact an administrator.');
    }
    return $landlord;
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
