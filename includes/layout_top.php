<?php
// Opens the signed-in app shell: document head, sidebar, top bar and <main>.
// Set $page_title before including this file and close it with layout_bottom.php.
require_once __DIR__ . '/../config/modules.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/alerts.php';
include __DIR__ . '/header.php';
?>
<div class="flex h-screen bg-gray-50 overflow-hidden">
<?php include __DIR__ . '/sidebar.php'; ?>
<div class="flex-1 flex flex-col overflow-y-auto min-w-0">
<?php include __DIR__ . '/navbar.php'; ?>
<main id="main-content" class="flex-1 p-6 space-y-6">
<?php display_flash_message(); ?>
