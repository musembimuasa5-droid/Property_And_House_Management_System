<?php

// =====================================================
// Urban Escape Property & House Management System
// Global Configuration
// =====================================================

// Load optional local settings without adding a runtime package.
$environmentFile = dirname(__DIR__) . '/.env';
if (is_readable($environmentFile)) {
    foreach (file($environmentFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $environmentLine) {
        $environmentLine = trim($environmentLine);
        if ($environmentLine === '' || $environmentLine[0] === '#' || strpos($environmentLine, '=') === false) continue;
        [$environmentKey, $environmentValue] = explode('=', $environmentLine, 2);
        $environmentKey = trim($environmentKey);
        $environmentValue = trim(trim($environmentValue), "\"'");
        if (getenv($environmentKey) === false) putenv($environmentKey . '=' . $environmentValue);
    }
}
// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 86400,
        'path' => '/',
        'domain' => '',
        'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Strict'
    ]);

    session_start();
}

// Sign a user out after 30 minutes without activity, so an unattended browser
// does not stay open on tenant or financial records.
const SESSION_IDLE_SECONDS = 1800;
if (!empty($_SESSION['user_id'])) {
    if (isset($_SESSION['last_activity']) && time() - $_SESSION['last_activity'] > SESSION_IDLE_SECONDS) {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['session_expired'] = true;
    } else {
        $_SESSION['last_activity'] = time();
    }
}


// =====================================================
// APPLICATION SETTINGS
// =====================================================

define('APP_NAME', getenv('APP_NAME') ?: 'Urban Escape');

define('APP_ENV', getenv('APP_ENV') ?: 'development');

define('APP_DEBUG', filter_var(getenv('APP_DEBUG') === false ? (APP_ENV !== 'production') : getenv('APP_DEBUG'), FILTER_VALIDATE_BOOLEAN));


// =====================================================
// BASE URL
// =====================================================

define(
    'BASE_URL',
    rtrim(getenv('APP_URL') ?: 'http://localhost/Property_And_House_Management_System', '/')
);


// =====================================================
// ERROR REPORTING
// =====================================================

if (APP_DEBUG) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(0);
}


// =====================================================
// TIMEZONE
// ===================================================
date_default_timezone_set('Africa/Nairobi');
