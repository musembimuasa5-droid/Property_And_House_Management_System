<?php
/**
 * Serves photos from the private upload folders (maintenance, condition) to the people
 * entitled to see them. The folders themselves refuse direct web access.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/modules.php';

requireLogin();
$db = db();
$user = getCurrentUser();
$userId = (int)$user['id'];
$id = (int)filter_var($_GET['id'] ?? 0, FILTER_VALIDATE_INT);
$type = $_GET['type'] ?? '';

function attachment_not_found(): void {
    http_response_code(404);
    exit('File not found.');
}

$path = null;
if ($type === 'maintenance') {
    $stmt = $db->prepare('SELECT mr.image_path, mr.assigned_staff, t.user_id AS tenant_user FROM maintenance_requests mr JOIN tenants t ON t.id = mr.tenant_id WHERE mr.id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if ($row && (user_can_access_module('maintenance') || (int)$row['tenant_user'] === $userId || (int)$row['assigned_staff'] === $userId)) $path = $row['image_path'];
} elseif ($type === 'condition') {
    $stmt = $db->prepare('SELECT i.photo_path, t.user_id AS tenant_user, ld.user_id AS landlord_user, p.caretaker_id FROM condition_report_items i JOIN condition_reports cr ON cr.id = i.report_id
        JOIN leases l ON l.id = cr.lease_id JOIN tenants t ON t.id = l.tenant_id JOIN properties p ON p.id = l.property_id JOIN landlords ld ON ld.id = p.landlord_id WHERE i.id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    $allowed = $row && (user_can_access_module('condition_reports') || user_can_access_module('deposits')
        || (int)$row['tenant_user'] === $userId || ($user['role_name'] === 'Landlord' && (int)$row['landlord_user'] === $userId)
        || ($user['role_name'] === 'Caretaker' && (int)$row['caretaker_id'] === $userId));
    if ($allowed) $path = $row['photo_path'];
}
if (!$path) attachment_not_found();

// Only files inside uploads/ are served, whatever the database says.
$uploadsRoot = realpath(__DIR__ . '/../uploads');
$file = realpath(__DIR__ . '/../' . $path);
if (!$uploadsRoot || !$file || strpos($file, $uploadsRoot . DIRECTORY_SEPARATOR) !== 0 || !is_file($file)) attachment_not_found();
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($file);
if (!in_array($mime, ['image/jpeg', 'image/png'], true)) attachment_not_found();

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($file));
header('Content-Disposition: inline; filename="' . basename($file) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=600');
readfile($file);
