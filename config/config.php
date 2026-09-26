<?php

// =====================================================
// Nairobi Property & House Management System
// Global Configuration
// =====================================================

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

define('APP_NAME', 'Nairobi Property Manager');

define('APP_ENV', 'development');

define('APP_DEBUG', true);


// =====================================================
// BASE URL
// =====================================================

define(
    'BASE_URL',
    'http://localhost/Property_And_House_Management_System'
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