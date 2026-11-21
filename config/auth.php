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

/** The signed-in caretaker's user id, which properties.caretaker_id refers to; other roles are refused. */
function caretaker_user_id(): int {
    requireRole('Caretaker');
    return (int)$_SESSION['user_id'];
}

/** Fields never written to the audit log, even as before/after values. */
const AUDIT_HIDDEN_FIELDS = ['password', 'password_hash', 'csrf_token'];

/** JSON for the audit log's before/after columns, with secrets removed; null when empty. */
function audit_values(?array $values): ?string {
    if (!$values) return null;
    $values = array_diff_key($values, array_flip(AUDIT_HIDDEN_FIELDS));
    return $values ? json_encode($values, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) : null;
}

function logAuditAction($action, $module, $record_id = null, $description = '', ?array $old = null, ?array $new = null, $user_id = null) {
    try {
        $user_id = $user_id ?? ($_SESSION['user_id'] ?? null);
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
        $stmt = db()->prepare("INSERT INTO audit_logs (user_id, action, module, record_id, description, old_values, new_values, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$user_id, $action, $module, $record_id, $description, audit_values($old), audit_values($new), $ip]);
    } catch (\Exception $e) {
        // The main change has already succeeded, so record the logging failure instead of undoing it.
        error_log("Audit log write failed ($action $module #$record_id): " . $e->getMessage());
    }
}

// ---------------------------------------------------------------------------
// Sign-in throttling
// ---------------------------------------------------------------------------

const LOGIN_WINDOW_MINUTES = 15;
const LOGIN_MAX_FAILURES_PER_EMAIL = 5;
const LOGIN_MAX_FAILURES_PER_IP = 20;

/** True while an email address or client address has too many recent failed sign-ins. */
function login_is_throttled(PDO $db, string $email, string $ip): bool {
    $stmt = $db->prepare('SELECT
            (SELECT COUNT(*) FROM login_attempts WHERE email = ? AND attempted_at > NOW() - INTERVAL ' . LOGIN_WINDOW_MINUTES . ' MINUTE),
            (SELECT COUNT(*) FROM login_attempts WHERE ip_address = ? AND attempted_at > NOW() - INTERVAL ' . LOGIN_WINDOW_MINUTES . ' MINUTE)');
    $stmt->execute([$email, $ip]);
    [$byEmail, $byIp] = $stmt->fetch(PDO::FETCH_NUM);
    return $byEmail >= LOGIN_MAX_FAILURES_PER_EMAIL || $byIp >= LOGIN_MAX_FAILURES_PER_IP;
}

function login_record_failure(PDO $db, string $email, string $ip): void {
    $db->prepare('INSERT INTO login_attempts (email, ip_address) VALUES (?, ?)')->execute([$email, $ip]);
    // Old rows have no further use; trimming here avoids a scheduled clean-up job.
    $db->exec('DELETE FROM login_attempts WHERE attempted_at < NOW() - INTERVAL 1 DAY');
}

function login_clear_failures(PDO $db, string $email): void {
    $db->prepare('DELETE FROM login_attempts WHERE email = ?')->execute([$email]);
}
