<?php
/**
 * Secure File Upload Handler
 * 
 * @param array $file     The $_FILES['input_name'] array
 * @param string $subfolder Target directory inside /uploads/ (e.g., 'properties', 'tenants')
 * @param array $allowed_extensions Permitted extensions
 * @return array ['success' => bool, 'path' => string|null, 'error' => string|null]
 */
function uploadApplicationFile($file, $subfolder, $allowed_extensions = ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx']) {
    if (!isset($file) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return ['success' => false, 'path' => null, 'error' => 'No file uploaded.'];
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'path' => null, 'error' => 'Upload failed with error code: ' . $file['error']];
    }

    // Max size: 5MB
    if ($file['size'] > 5 * 1024 * 1024) {
        return ['success' => false, 'path' => null, 'error' => 'File size exceeds the 5MB limit.'];
    }

    $file_info = pathinfo($file['name']);
    $ext = strtolower($file_info['extension']);

    if (!in_array($ext, $allowed_extensions)) {
        return ['success' => false, 'path' => null, 'error' => 'Invalid file extension: ' . $ext];
    }

    // Generate a unique, collision-resistant filename
    $new_filename = uniqid('file_', true) . '.' . $ext;
    
    // Define absolute target directory
    $target_dir = __DIR__ . '/../uploads/' . trim($subfolder, '/') . '/';
    
    if (!is_dir($target_dir)) {
        mkdir($target_dir, 0755, true);
    }

    $target_path = $target_dir . $new_filename;
    $relative_path = 'uploads/' . trim($subfolder, '/') . '/' . $new_filename;

    if (move_uploaded_file($file['tmp_name'], $target_path)) {
        return ['success' => true, 'path' => $relative_path, 'error' => null];
    }

    return ['success' => false, 'path' => null, 'error' => 'Failed to move uploaded file.'];
}
?>