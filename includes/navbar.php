<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/functions.php';
$currentUser = getCurrentUser();
$isPublicPage = strpos($_SERVER['SCRIPT_NAME'] ?? '', '/public/') !== false || basename($_SERVER['SCRIPT_NAME'] ?? '') === 'index.php';
$role = $currentUser['role_name'] ?? '';
$dashboardUrl = $currentUser ? BASE_URL . '/' . dashboardPathForRole($role) : '';
?>
<a class="skip-link" href="#main-content">Skip to content</a>
<?php if ($isPublicPage): ?>
<header class="public-header">
  <div class="site-container public-header-inner">
    <a class="brand" href="<?php echo BASE_URL; ?>/" aria-label="<?php echo e(APP_NAME); ?> home"><img class="brand-logo" src="<?php echo BASE_URL; ?>/assets/houselogo.avif" alt=""><span><?php echo e(APP_NAME); ?></span></a>
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
           <a class="nav-login" href="<?php echo BASE_URL; ?>/public/register.php">Register</a>
          <a class="nav-login" href="<?php echo BASE_URL; ?>/public/login.php">Log in</a>
          <a class="nav-cta" href="<?php echo BASE_URL; ?>/public/properties.php">Find a home</a>
        <?php endif; ?>
      </span>
    </nav>
  </div>
</header>
<?php else:
    require_once __DIR__ . '/shell.php';
    $isStaff = in_array($role, STAFF_ROLES, true);
    $crumbs = shell_breadcrumbs($dashboardUrl, $page_title ?? null);
    $bell = $currentUser ? shell_notifications((int)$currentUser['id']) : ['unread' => 0, 'latest' => []];
    $returnTo = $_SERVER['REQUEST_URI'] ?? '';
?>
<header class="app-topbar">
  <div class="app-topbar-inner">
    <button type="button" class="topbar-icon lg:hidden" data-sidebar-toggle aria-controls="sidebar" aria-expanded="false" aria-label="Open navigation"><i class="fa-solid fa-bars" aria-hidden="true"></i></button>
    <a href="<?php echo e($dashboardUrl ?: BASE_URL . '/'); ?>" class="topbar-brand lg:hidden" aria-label="<?php echo e(APP_NAME); ?> dashboard"><img src="<?php echo BASE_URL; ?>/assets/houselogo.avif" alt=""></a>
    <nav class="topbar-breadcrumb hidden sm:block" aria-label="Breadcrumb"><ol>
      <?php foreach ($crumbs as $index => [$label, $url]): $isLast = $index === count($crumbs) - 1; ?>
        <li<?php echo $isLast ? ' aria-current="page"' : ''; ?>><?php if ($url && !$isLast): ?><a href="<?php echo e($url); ?>"><?php echo e($label); ?></a><?php else: ?><?php echo e($label); ?><?php endif; ?></li>
      <?php endforeach; ?>
    </ol></nav>

    <div class="topbar-actions">
      <?php if ($isStaff): ?>
        <form class="topbar-search hidden md:flex" action="<?php echo BASE_URL; ?>/admin/search.php" method="get" role="search">
          <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
          <label for="topbar-search-input" class="sr-only">Search the system</label>
          <input id="topbar-search-input" type="search" name="q" placeholder="Search tenants, units, receipts…" value="<?php echo e(is_string($_GET['q'] ?? null) && strpos($_SERVER['SCRIPT_NAME'] ?? '', '/admin/search.php') !== false ? $_GET['q'] : ''); ?>">
        </form>
        <a class="topbar-icon md:hidden" href="<?php echo BASE_URL; ?>/admin/search.php" aria-label="Search"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i></a>
      <?php endif; ?>
      <a class="topbar-icon hidden sm:inline-flex" href="<?php echo BASE_URL; ?>/" target="_blank" rel="noopener" title="View public website" aria-label="View public website (opens in a new tab)"><i class="fa-solid fa-globe" aria-hidden="true"></i></a>

      <details class="topbar-menu">
        <summary class="topbar-icon" aria-label="Notifications<?php echo $bell['unread'] ? ', ' . $bell['unread'] . ' unread' : ''; ?>">
          <i class="fa-regular fa-bell" aria-hidden="true"></i>
          <?php if ($bell['unread']): ?><span class="topbar-count" aria-hidden="true"><?php echo $bell['unread'] > 9 ? '9+' : $bell['unread']; ?></span><?php endif; ?>
        </summary>
        <div class="menu-panel menu-panel-wide">
          <div class="menu-header flex items-center justify-between"><strong>Notifications</strong><?php if ($bell['unread']): ?><span><?php echo $bell['unread']; ?> unread</span><?php endif; ?></div>
          <ul class="notification-list">
            <?php if (!$bell['latest']): ?><li class="notification-item"><p>You're all caught up. New alerts will appear here.</p></li><?php endif; ?>
            <?php foreach ($bell['latest'] as $notice): ?>
              <li class="notification-item<?php echo $notice['is_read'] ? '' : ' is-unread'; ?>"><span class="notification-dot" aria-hidden="true"></span>
                <div class="min-w-0"><strong><?php echo e($notice['title']); ?><?php echo $notice['is_read'] ? '' : '<span class="sr-only"> (unread)</span>'; ?></strong><p><?php echo e(mb_strimwidth($notice['message'], 0, 110, '…')); ?></p><time datetime="<?php echo e(date('c', strtotime($notice['created_at']))); ?>"><?php echo e(time_ago($notice['created_at'])); ?></time></div></li>
            <?php endforeach; ?>
          </ul>
          <div class="menu-footer">
            <a href="<?php echo BASE_URL; ?>/account/notifications.php">View all</a>
            <?php if ($bell['unread']): ?><form method="post" action="<?php echo BASE_URL; ?>/account/notifications.php"><?php echo csrf_field(); ?><input type="hidden" name="_action" value="mark_read"><input type="hidden" name="return" value="<?php echo e($returnTo); ?>"><button type="submit">Mark all as read</button></form><?php endif; ?>
          </div>
        </div>
      </details>

      <details class="topbar-menu">
        <summary class="topbar-user" aria-label="Account menu for <?php echo e($currentUser['full_name'] ?? ''); ?>">
          <span class="avatar" aria-hidden="true"><?php echo e(user_initials($currentUser['full_name'] ?? '')); ?></span>
          <span class="topbar-user-text hidden md:block"><strong><?php echo e($currentUser['full_name'] ?? ''); ?></strong><small><?php echo e($role); ?></small></span>
          <i class="fa-solid fa-chevron-down text-xs text-gray-500 hidden md:inline" aria-hidden="true"></i>
        </summary>
        <div class="menu-panel">
          <div class="menu-header"><strong><?php echo e($currentUser['full_name'] ?? ''); ?></strong><span><?php echo e($currentUser['email'] ?? ''); ?></span></div>
          <a class="menu-item" href="<?php echo e($dashboardUrl); ?>"><i class="fa-solid fa-chart-pie" aria-hidden="true"></i> Dashboard</a>
          <?php if ($role === 'Tenant'): ?><a class="menu-item" href="<?php echo BASE_URL; ?>/tenant/profile.php"><i class="fa-solid fa-user" aria-hidden="true"></i> My profile</a><?php endif; ?>
          <a class="menu-item" href="<?php echo BASE_URL; ?>/account/settings.php"><i class="fa-solid fa-gear" aria-hidden="true"></i> Account settings</a>
          <a class="menu-item" href="<?php echo BASE_URL; ?>/" target="_blank" rel="noopener"><i class="fa-solid fa-globe" aria-hidden="true"></i> View public website</a>
          <div class="menu-divider"></div>
          <form method="post" action="<?php echo BASE_URL; ?>/api/logout.php"><?php echo csrf_field(); ?><button type="submit" class="menu-item menu-item-danger"><i class="fa-solid fa-arrow-right-from-bracket" aria-hidden="true"></i> Sign out</button></form>
        </div>
      </details>
    </div>
  </div>
</header>
<?php endif; ?>
