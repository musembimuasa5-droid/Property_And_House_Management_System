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
    <link rel="icon" type="image/jpeg" href="<?php echo BASE_URL; ?>/assets/houselogo.jpg">
    <meta name="theme-color" content="#1e3a5f">
    <!-- FontAwesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/app.css?v=<?php echo (int)filemtime(__DIR__ . '/../assets/app.css'); ?>">
    <!-- Tailwind utilities compiled ahead of time (assets/tailwind.css). They load after app.css, the same
         order the former runtime CDN script produced, so utilities still win ties as the layout expects. -->
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/tailwind.css?v=<?php echo (int)filemtime(__DIR__ . '/../assets/tailwind.css'); ?>">
</head>
<body class="bg-gray-50 text-gray-800 font-sans antialiased min-h-screen flex flex-col">
