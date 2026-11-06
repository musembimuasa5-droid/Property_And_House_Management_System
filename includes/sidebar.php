<?php
require_once __DIR__ . '/shell.php';
$currentUser = getCurrentUser();
$role = $currentUser['role_name'] ?? 'Guest';
$dashboardPath = dashboardPathForRole($role);
if (!function_exists('sidebarLink')) {
    function sidebarLink($path, $icon, $label, $badge = 0) {
        $url = BASE_URL . '/' . ltrim($path, '/');
        $target = '/' . ltrim(strtok($path, '?#'), '/');
        $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
        $active = $target !== '/' && substr($script, -strlen($target)) === $target && strpos($path, '#') === false;
        echo '<a href="' . e($url) . '" class="sidebar-link"' . ($active ? ' aria-current="page"' : '') . '>';
        echo '<i class="fa-solid ' . e($icon) . '" aria-hidden="true"></i><span>' . e($label) . '</span>';
        if ($badge > 0) echo '<span class="sidebar-badge" aria-label="' . (int)$badge . ' waiting">' . ($badge > 99 ? '99+' : (int)$badge) . '</span>';
        echo '</a>';
    }
    function sidebarHeading($label) {
        echo '<p class="sidebar-heading">' . e($label) . '</p>';
    }
    function sidebarModules(string $portal, array $badges = []) {
        $lastGroup = '';
        foreach (portal_modules($portal) as $key => $module) {
            if ($module['group'] !== $lastGroup) { sidebarHeading($module['group']); $lastGroup = $module['group']; }
            sidebarLink($module['path'], $module['icon'], $module['label'], $badges[$key] ?? 0);
        }
    }
}
$unreadNotices = $currentUser ? shell_notifications((int)$currentUser['id'])['unread'] : 0;
?>
<aside id="sidebar" class="app-sidebar" aria-label="Sidebar">
    <div class="sidebar-brand">
        <a href="<?php echo e(BASE_URL . '/' . $dashboardPath); ?>">
            <img src="<?php echo BASE_URL; ?>/assets/houselogo.avif" alt="">
            <span><strong><?php echo e(APP_NAME); ?></strong><small><?php echo e($role); ?> workspace</small></span>
        </a>
        <button type="button" class="sidebar-close" data-sidebar-close aria-label="Close navigation"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
    </div>
    <nav class="sidebar-nav" aria-label="<?php echo e($role); ?> navigation">
        <?php if ($currentUser) { sidebarHeading('Overview'); sidebarLink($dashboardPath, 'fa-chart-pie', 'Dashboard'); } ?>
        <?php if (in_array($role, STAFF_ROLES, true)): ?>
            <?php sidebarModules('staff', shell_module_badges()); ?>
        <?php elseif ($role === 'Landlord'): ?>
            <?php sidebarModules('landlord'); ?>
        <?php elseif ($role === 'Caretaker'): ?>
            <?php sidebarHeading('Work'); sidebarLink('caretaker/dashboard.php#assigned-requests', 'fa-screwdriver-wrench', 'Assigned Requests'); ?>
        <?php elseif ($role === 'Tenant'): ?>
            <?php sidebarHeading('My tenancy'); sidebarLink('tenant/rent.php', 'fa-receipt', 'Rent & Statement'); sidebarLink('tenant/payments.php', 'fa-money-bill-wave', 'Payment History'); sidebarLink('tenant/bills.php', 'fa-droplet', 'Utility Bills'); sidebarLink('tenant/documents.php', 'fa-file', 'Lease Documents'); ?>
            <?php sidebarHeading('Help'); sidebarLink('tenant/maintenance.php', 'fa-screwdriver-wrench', 'Maintenance'); sidebarLink('tenant/complaints.php', 'fa-triangle-exclamation', 'Complaints'); ?>
        <?php endif; ?>
        <?php if ($currentUser): ?>
            <?php sidebarHeading('Account'); sidebarLink('account/notifications.php', 'fa-bell', $role === 'Tenant' ? 'Notices' : 'Notifications', $unreadNotices); ?>
            <?php if ($role === 'Tenant') sidebarLink('tenant/profile.php', 'fa-user', 'My Profile'); ?>
            <?php sidebarLink('account/settings.php', 'fa-gear', 'Account Settings'); ?>
        <?php endif; ?>
    </nav>
    <div class="sidebar-footer">
        <a class="sidebar-link" href="<?php echo BASE_URL; ?>/" target="_blank" rel="noopener"><i class="fa-solid fa-globe" aria-hidden="true"></i><span>View public website</span><i class="fa-solid fa-arrow-up-right-from-square ml-auto text-xs" aria-hidden="true"></i><span class="sr-only">(opens in a new tab)</span></a>
    </div>
</aside>
<div class="sidebar-backdrop" data-sidebar-close hidden></div>
