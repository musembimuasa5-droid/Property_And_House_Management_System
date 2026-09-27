<?php
$currentUser = getCurrentUser();
$role = $currentUser['role_name'] ?? 'Guest';
$dashboardPath = dashboardPathForRole($role);
$currentPath = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
function sidebarLink($path, $icon, $label) {
    global $currentPath;
    $url = BASE_URL . '/' . ltrim($path, '/');
    $active = strpos($currentPath, '/' . ltrim($path, '/')) !== false;
    echo '<a href="' . e($url) . '"' . ($active ? ' aria-current="page"' : '') . ' class="flex items-center space-x-3 px-3 py-2.5 rounded-lg">';
    echo '<i class="fa-solid ' . e($icon) . ' w-5 text-accent"></i><span>' . e($label) . '</span></a>';
}
?>
<aside id="sidebar" class="bg-secondary text-gray-300 w-64 space-y-6 py-7 px-2 absolute inset-y-0 left-0 transform -translate-x-full lg:relative lg:translate-x-0 transition duration-200 ease-in-out z-20 flex flex-col">
<div class="px-4 flex items-center space-x-2 text-white"><img src="<?php echo BASE_URL; ?>/assets/houselogo.avif" alt="" class="sidebar-brand-logo"><span class="text-lg font-bold"><?php echo e(APP_NAME); ?></span></div>
<nav class="flex-1 space-y-1 px-2" aria-label="<?php echo e($role); ?> navigation">
<div class="text-xs font-semibold text-gray-400 uppercase tracking-wider px-3 pt-4 pb-2"><?php echo e($role); ?> workspace</div>
<?php if ($currentUser) sidebarLink($dashboardPath, 'fa-chart-pie', 'Dashboard'); ?>
<?php if (in_array($role, ['Super Admin', 'Property Manager', 'Accountant'], true)): ?>
<?php sidebarLink('public/properties.php', 'fa-building', 'Property Directory'); ?>
<?php elseif ($role === 'Landlord'): ?>
<?php sidebarLink('public/properties.php', 'fa-building', 'Public Directory'); ?>
<?php elseif ($role === 'Caretaker'): ?>
<?php sidebarLink('caretaker/dashboard.php#assigned-requests', 'fa-screwdriver-wrench', 'Assigned Requests'); ?>
<?php elseif ($role === 'Tenant'): ?>
<?php sidebarLink('tenant/rent.php', 'fa-receipt', 'Rent & Payments'); sidebarLink('tenant/bills.php', 'fa-droplet', 'Utility Bills'); sidebarLink('tenant/maintenance.php', 'fa-screwdriver-wrench', 'Maintenance'); sidebarLink('tenant/complaints.php', 'fa-triangle-exclamation', 'Complaints'); sidebarLink('tenant/documents.php', 'fa-file', 'Lease Documents'); sidebarLink('tenant/notices.php', 'fa-bell', 'Notices'); sidebarLink('tenant/profile.php', 'fa-user', 'My Profile'); ?>
<?php endif; ?>
<?php if (!$currentUser): ?><div class="text-xs font-semibold text-gray-400 uppercase tracking-wider px-3 pt-6 pb-2">Public Directory</div><?php sidebarLink('public/properties.php', 'fa-magnifying-glass', 'Browse Vacancies'); ?><?php endif; ?>
</nav></aside>