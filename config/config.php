<?php

// =====================================================
// Nairobi Property & House Management System
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


// =====================================================
// APPLICATION SETTINGS
// =====================================================

define('APP_NAME', getenv('APP_NAME') ?: 'Nairobi Property Manager');

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
