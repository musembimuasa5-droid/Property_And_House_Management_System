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
    // house5.jpg shows a real, branded development and house6.jpg carries a stock-photo
    // watermark, so neither is used as a stand-in listing photo.
    $images = [
        'assets/house1.jpg',
        'assets/house2.jpg',
        'assets/house3.jpg',
        'assets/house4.jpg',
    ];
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
    $green = 'bg-green-100 text-green-800';
    $blue = 'bg-blue-100 text-blue-800';
    $yellow = 'bg-yellow-100 text-yellow-800';
    $orange = 'bg-orange-100 text-orange-800';
    $red = 'bg-red-100 text-red-800';
    $purple = 'bg-purple-100 text-purple-800';
    $colors = [
        'Active' => $green, 'Paid' => $green, 'Completed' => $green, 'Resolved' => $green, 'Approved' => $green,
        'Occupied' => $blue, 'Reviewed' => $blue, 'Assigned' => $blue, 'In Progress' => $blue, 'Under Review' => $blue, 'Renewed' => $blue, 'New' => $blue,
        'Vacant' => $yellow, 'Waiting' => $yellow, 'Rescheduled' => $yellow, 'Medium' => $yellow,
        'Pending' => $orange, 'Partial' => $orange, 'Submitted' => $orange, 'Open' => $orange, 'High' => $orange,
        'Unpaid' => $red, 'Overdue' => $red, 'Rejected' => $red, 'Terminated' => $red, 'Emergency' => $red,
        'Suspended' => $red, 'Evicted' => $red, 'Blacklisted' => $red,
        'Under Maintenance' => $purple, 'Reserved' => $purple,
    ];

    $class = $colors[$status] ?? 'bg-gray-100 text-gray-800';
    return '<span class="px-2.5 py-0.5 rounded-full text-xs font-semibold ' . $class . '">' . e($status) . '</span>';
}

/** Queue a one-time message for the next page load (Post/Redirect/Get). */
function set_flash($type, $message) {
    $_SESSION['flash_type'] = $type;
    $_SESSION['flash_message'] = $message;
}

/** Add an in-app notice; shown to tenants on their Notices page. */
function notify_user(PDO $db, $user_id, $title, $message) {
    if (!$user_id) return;
    $db->prepare('INSERT INTO notifications (user_id, title, message) VALUES (?, ?, ?)')->execute([$user_id, $title, $message]);
}

/** Alert every active user with one of these roles, e.g. managers about a new public enquiry. */
function notify_roles(PDO $db, array $roles, $title, $message) {
    $placeholders = implode(', ', array_fill(0, count($roles), '?'));
    $stmt = $db->prepare("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE u.status = 'Active' AND r.name IN ($placeholders)");
    $stmt->execute(array_values($roles));
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $userId) notify_user($db, (int)$userId, $title, $message);
}

/** Roles alerted about new public and tenant requests. */
const MANAGEMENT_ROLES = ['Super Admin', 'Property Manager'];

/** Listing photo for a property: its uploaded image, else a labelled sample. Returns [url path, is sample]. */
function property_image(array $property) {
    $path = trim((string)($property['property_image'] ?? ''), '/');
    if ($path !== '' && is_file(dirname(__DIR__) . '/' . $path)) return [$path, false];
    return [sample_property_image($property['id'] ?? 0), true];
}

function format_date($date) {
    if (empty($date)) return '—';
    $timestamp = strtotime((string)$date);
    return $timestamp ? date('d M Y', $timestamp) : (string)$date;
}

/** Turn a 'YYYY-MM' billing period into 'Sep 2026'. */
function format_period($period) {
    $date = DateTime::createFromFormat('!Y-m', (string)$period);
    return $date ? $date->format('M Y') : (string)$period;
}

/** Stop spreadsheet apps from running cell text such as =HYPERLINK(...) as a formula. */
function csv_safe($value) {
    $value = (string)($value ?? '');
    return ($value !== '' && !is_numeric($value) && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) ? "'" . $value : $value;
}
