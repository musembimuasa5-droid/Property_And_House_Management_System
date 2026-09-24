<?php
/**
 * Global Utility Functions
 */

require_once __DIR__ . '/../config/config.php';

/**
 * Sanitize output for HTML display (XSS prevention)
 */
function e($string) {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Format amount into Kenyan Shillings (KSh)
 */
function format_currency($amount) {
    return 'KSh ' . number_format((float)($amount ?? 0), 2, '.', ',');
}

/**
 * Generate a unique receipt number
 */
function generate_receipt_number() {
    return 'REC-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -5));
}

/**
 * Redirect helper
 */
function redirect($path) {
    header("Location: " . BASE_URL . '/' . ltrim($path, '/'));
    exit();
}

/**
 * Display status badge with appropriate Tailwind colors
 */
function render_status_badge($status) {
    $colors = [
        'Active' => 'bg-green-100 text-green-800',
        'Paid' => 'bg-green-100 text-green-800',
        'Occupied' => 'bg-blue-100 text-blue-800',
        'Vacant' => 'bg-yellow-100 text-yellow-800',
        'Pending' => 'bg-orange-100 text-orange-800',
        'Unpaid' => 'bg-red-100 text-red-800',
        'Overdue' => 'bg-red-100 text-red-800',
        'Inactive' => 'bg-gray-100 text-gray-800',
        'Under Maintenance' => 'bg-purple-100 text-purple-800'
    ];
    
    $class = $colors[$status] ?? 'bg-gray-100 text-gray-800';
    return '<span class="px-2.5 py-0.5 rounded-full text-xs font-semibold ' . $class . '">' . e($status) . '</span>';
}