<?php
$currentUser = getCurrentUser();
?>
<header class="bg-white border-b border-gray-200 sticky top-0 z-30">
    <div class="px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
        <!-- Left: Toggle & Brand -->
        <div class="flex items-center space-x-3">
            <button onclick="toggleSidebar()" class="text-gray-500 hover:text-gray-700 lg:hidden focus:outline-none">
                <i class="fa-solid fa-bars text-xl"></i>
            </button>
            <a href="<?php echo BASE_URL; ?>/public/index.php" class="font-bold text-lg text-primary flex items-center space-x-2">
                <i class="fa-solid fa-building-shield"></i>
                <span class="hidden sm:inline"><?php echo APP_NAME; ?></span>
            </a>
        </div>

        <!-- Right: User Profile & Notifications -->
        <div class="flex items-center space-x-4">
            <?php if ($currentUser): ?>
                <div class="text-right hidden sm:block">
                    <p class="text-sm font-medium text-gray-900"><?php echo e($currentUser['full_name']); ?></p>
                    <p class="text-xs text-gray-500 capitalize"><?php echo e(str_replace('_', ' ', $currentUser['role_name'] ?? 'User')); ?></p>
                </div>
                <a href="<?php echo BASE_URL; ?>/api/auth/logout.php" class="text-sm bg-red-50 text-red-600 hover:bg-red-100 px-3 py-1.5 rounded-lg font-medium transition">
                    <i class="fa-solid fa-right-from-bracket mr-1"></i> Logout
                </a>
            <?php else: ?>
                <a href="<?php echo BASE_URL; ?>/public/login.php" class="text-sm bg-primary text-white hover:bg-blue-800 px-4 py-2 rounded-lg font-medium transition">
                    Login
                </a>
            <?php endif; ?>
        </div>
    </div>
</header>