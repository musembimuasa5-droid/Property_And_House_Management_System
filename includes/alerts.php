<?php
/**
 * Flash Alert Notification Helper
 */
function display_flash_message() {
    if (isset($_SESSION['flash_message'])) {
        $type = $_SESSION['flash_type'] ?? 'success';
        $message = $_SESSION['flash_message'];
        
        $bg_colors = [
            'success' => 'bg-green-50 border-green-500 text-green-800',
            'error' => 'bg-red-50 border-red-500 text-red-800',
            'warning' => 'bg-yellow-50 border-yellow-500 text-yellow-800'
        ];
        
        $class = $bg_colors[$type] ?? $bg_colors['success'];

        echo '<div class="border-l-4 p-4 mb-6 rounded-r-lg shadow-sm ' . $class . '">';
        echo '<p class="text-sm font-medium">' . htmlspecialchars($message) . '</p>';
        echo '</div>';

        unset($_SESSION['flash_message']);
        unset($_SESSION['flash_type']);
    }
}