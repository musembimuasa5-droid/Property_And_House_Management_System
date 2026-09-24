<?php
/**
 * Nairobi Property & House Management SaaS
 * Global Configuration File
 */

// Start secure session
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 86400,
        'path' => '/',
        'domain' => '',
        'secure' => isset($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Strict'
    ]);
    session_start();
}

// Environment settings
define('APP_NAME', 'Nairobi Property Manager');
define('APP_ENV', 'development'); // Change to 'production' on live server
define('APP_DEBUG', true);

// Base URL configuration for XAMPP local path
define('BASE_URL', 'http://localhost/Nairobi_Property_And_House_Management_SaaS');

// Error reporting based on environment
if (APP_DEBUG) {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    error_reporting(0);
}

// Timezone setup for East Africa Time (EAT)
date_default_timezone_set('Africa/Nairobi');