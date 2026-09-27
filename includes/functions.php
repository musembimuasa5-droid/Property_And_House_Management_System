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

function csrf_token() {
    if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf_token'];
}

function csrf_field() {
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function csrf_validate() {
    $submitted = $_POST['csrf_token'] ?? '';
    return is_string($submitted) && isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $submitted);
}

/**
 * Format amount into Kenyan Shillings (KSh)
 */
function format_currency($amount) {
    return 'KSh ' . number_format((float)($amount ?? 0), 2, '.', ',');
}

/**
 * Return a rotating local sample image for listings without an uploaded photo.
 * These images are illustrative and should never replace a saved property image.
 */
function sample_property_image($property_id) {
    $images = [
        'assets/house1.jpg',
        'assets/house6.jpg',
        'assets/house2.jpg',
        'assets/house3.jpg',
        'assets/house4.jpg',
        'assets/house5.jpg',    ];
    $index = max(0, (abs((int)$property_id) - 1) % count($images));
    return $images[$index];
}

/**
 * Generate a unique receipt number
 */
function generate_receipt_number() {
    return 'REC-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
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
