<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

requireRole('Tenant');
$db = db();
$user = getCurrentUser();
$tenantQuery = $db->prepare('SELECT id FROM tenants WHERE user_id = ? ORDER BY id LIMIT 1');
$tenantQuery->execute([$user['id']]);
$tenantId = (int)$tenantQuery->fetchColumn();
$documentId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$documentType = isset($_GET['type']) && is_string($_GET['type']) ? $_GET['type'] : '';

if (!$tenantId || !$documentId || !in_array($documentType, ['lease', 'document'], true)) {
    http_response_code(404);
    exit('Document not found.');
}

if ($documentType === 'lease') {
    $documentQuery = $db->prepare("SELECT document_path FROM leases WHERE id = ? AND tenant_id = ? AND document_path IS NOT NULL");
} else {
    $documentQuery = $db->prepare('SELECT file_path AS document_path FROM documents WHERE id = ? AND tenant_id = ?');
}
$documentQuery->execute([$documentId, $tenantId]);
$storedPath = $documentQuery->fetchColumn();
$uploadsRoot = realpath(__DIR__ . '/../uploads');
$filePath = $storedPath ? realpath(__DIR__ . '/../' . $storedPath) : false;

if (!$uploadsRoot || !$filePath || strpos($filePath, $uploadsRoot . DIRECTORY_SEPARATOR) !== 0 || !is_file($filePath) || !is_readable($filePath)) {
    http_response_code(404);
    exit('Document not found.');
}

$fileName = basename($filePath);
$mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($filePath) ?: 'application/octet-stream';
header('Content-Type: ' . $mimeType);
header('Content-Disposition: attachment; filename="' . str_replace('"', '', $fileName) . '"; filename*=UTF-8\'\'' . rawurlencode($fileName));
header('Content-Length: ' . (string)filesize($filePath));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');
readfile($filePath);
exit;
