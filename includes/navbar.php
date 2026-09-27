<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/functions.php';
$currentUser = getCurrentUser();
$isPublicPage = strpos($_SERVER['SCRIPT_NAME'] ?? '', '/public/') !== false || basename($_SERVER['SCRIPT_NAME'] ?? '') === 'index.php';
$role = $currentUser['role_name'] ?? '';
$dashboardUrl = $role === 'Tenant' ? BASE_URL . '/tenant/dashboard.php' : (in_array($role, ['Super Admin', 'Property Manager', 'Accountant'], true) ? BASE_URL . '/admin/dashboard.php' : '');
?>
<a class="skip-link" href="#main-content">Skip to content</a>
<?php if ($isPublicPage): ?>
<header class="public-header">
  <div class="site-container public-header-inner">
    <a class="brand" href="<?php echo BASE_URL; ?>/" aria-label="<?php echo e(APP_NAME); ?> home"><span class="brand-icon" aria-hidden="true">⌂</span><span><?php echo e(APP_NAME); ?></span></a>
    <button type="button" class="mobile-menu-toggle" aria-controls="public-navigation" aria-expanded="false" aria-label="Open navigation"><span></span><span></span><span></span></button>
    <nav id="public-navigation" class="public-navigation" aria-label="Main navigation">
      <a href="<?php echo BASE_URL; ?>/" <?php echo basename($_SERVER['SCRIPT_NAME'] ?? '') === 'index.php' ? 'aria-current="page"' : ''; ?>>Home</a>
      <a href="<?php echo BASE_URL; ?>/public/properties.php">Properties</a>
      <a href="<?php echo BASE_URL; ?>/#services">Services</a>
      <a href="<?php echo BASE_URL; ?>/#about">About</a>
      <a href="<?php echo BASE_URL; ?>/public/contact.php">Contact</a>
      <span class="public-nav-actions">
        <?php if ($currentUser): ?>
          <?php if ($dashboardUrl !== ''): ?><a class="nav-login" href="<?php echo $dashboardUrl; ?>">My dashboard</a><?php endif; ?>
          <form method="post" action="<?php echo BASE_URL; ?>/api/logout.php"><?php echo csrf_field(); ?><button class="nav-cta" type="submit">Log out</button></form>
        <?php else: ?>
          <a class="nav-login" href="<?php echo BASE_URL; ?>/public/login.php">Log in</a>
          <a class="nav-cta" href="<?php echo BASE_URL; ?>/public/properties.php">Find a home</a>
        <?php endif; ?>
      </span>
    </nav>
  </div>
</header>
<?php else: ?>
<header class="bg-white border-b border-gray-200 sticky top-0 z-30">
  <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
    <div class="flex items-center space-x-3"><button type="button" onclick="toggleSidebar()" aria-label="Toggle menu" class="text-gray-500 hover:text-gray-700 lg:hidden focus:outline-none"><i class="fa-solid fa-bars text-xl"></i></button><a href="<?php echo BASE_URL; ?>/" class="font-bold text-lg text-primary"><?php echo e(APP_NAME); ?></a></div>
    <nav class="hidden md:flex items-center gap-5 text-sm font-medium text-gray-600" aria-label="Main navigation"><a href="<?php echo BASE_URL; ?>/">Home</a><a href="<?php echo BASE_URL; ?>/public/properties.php">Properties</a><a href="<?php echo BASE_URL; ?>/public/contact.php">Contact</a></nav>
    <div class="flex items-center space-x-4"><?php if ($currentUser): ?><div class="text-right hidden sm:block"><p class="text-sm font-medium text-gray-900"><?php echo e($currentUser['full_name']); ?></p><p class="text-xs text-gray-500"><?php echo e($role); ?></p></div><?php if ($dashboardUrl !== ''): ?><a href="<?php echo $dashboardUrl; ?>" class="text-sm text-primary">Dashboard</a><?php endif; ?><form method="post" action="<?php echo BASE_URL; ?>/api/logout.php"><?php echo csrf_field(); ?><button type="submit" class="text-sm bg-red-50 text-red-600 px-3 py-1.5 rounded-lg font-medium">Logout</button></form><?php else: ?><a href="<?php echo BASE_URL; ?>/public/login.php" class="text-sm px-2 py-2">Login</a><a href="<?php echo BASE_URL; ?>/public/register.php" class="text-sm bg-primary text-white px-4 py-2 rounded-lg font-medium">Register</a><?php endif; ?></div>
  </div>
</header>
<?php endif; ?>
