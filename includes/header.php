<?php
require_once __DIR__ . '/../config/config.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?php echo e($page_description ?? 'Property and house management for Nairobi.'); ?>">
    <meta property="og:title" content="<?php echo e($page_title ?? APP_NAME); ?>">
    <meta property="og:description" content="<?php echo e($page_description ?? 'Property and house management for Nairobi.'); ?>">
    <meta property="og:type" content="website">
    <title><?php echo e(isset($page_title) ? $page_title . ' - ' . APP_NAME : APP_NAME); ?></title>
    <script>
        window.tailwind = window.tailwind || {};
        window.tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '#1E3A5F',
                        secondary: '#14283F',
                        accent: '#D89B32',
                        success: '#198754',
                        warning: '#D99A00',
                        danger: '#DC3545'
                    }
                }
            }
        }
    </script>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- FontAwesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Load local design rules last so shared components take precedence over framework utilities. -->
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/app.css?v=<?php echo (int)filemtime(__DIR__ . '/../assets/app.css'); ?>">
</head>
<body class="bg-gray-50 text-gray-800 font-sans antialiased min-h-screen flex flex-col">
