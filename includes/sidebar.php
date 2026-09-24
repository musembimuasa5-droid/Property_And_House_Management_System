<?php
$currentUser = getCurrentUser();
$role = $currentUser['role_name'] ?? 'Guest';
?>
<aside id="sidebar" class="bg-secondary text-gray-300 w-64 space-y-6 py-7 px-2 absolute inset-y-0 left-0 transform -translate-x-full lg:relative lg:translate-x-0 transition duration-200 ease-in-out z-20 flex flex-col">
    <!-- Brand Header inside sidebar -->
    <div class="px-4 flex items-center space-x-2 text-white">
        <i class="fa-solid fa-city text-accent text-2xl"></i>
        <span class="text-lg font-bold">Nairobi Property</span>
    </div>

    <!-- Navigation Links based on Role -->
    <nav class="flex-1 space-y-1 px-2">
        <?php if ($role === 'Super Admin' || $role === 'Property Manager'): ?>
            <div class="text-xs font-semibold text-gray-400 uppercase tracking-wider px-3 pt-4 pb-2">Management Portal</div>
            <a href="<?php echo BASE_URL; ?>/admin/dashboard.php" class="flex items-center space-x-3 px-3 py-2.5 rounded-lg hover:bg-gray-800 transition">
                <i class="fa-solid fa-chart-pie w-5 text-accent"></i> <span>Dashboard</span>
            </a>
            <a href="<?php echo BASE_URL; ?>/admin/properties/" class="flex items-center space-x-3 px-3 py-2.5 rounded-lg hover:bg-gray-800 transition">
                <i class="fa-solid fa-building w-5 text-accent"></i> <span>Properties & Units</span>
            </a>
            <a href="<?php echo BASE_URL; ?>/admin/tenants/" class="flex items-center space-x-3 px-3 py-2.5 rounded-lg hover:bg-gray-800 transition">
                <i class="fa-solid fa-users w-5 text-accent"></i> <span>Tenants</span>
            </a>
            <a href="<?php echo BASE_URL; ?>/admin/leases/" class="flex items-center space-x-3 px-3 py-2.5 rounded-lg hover:bg-gray-800 transition">
                <i class="fa-solid fa-file-contract w-5 text-accent"></i> <span>Leases</span>
            </a>
            <a href="<?php echo BASE_URL; ?>/admin/payments/" class="flex items-center space-x-3 px-3 py-2.5 rounded-lg hover:bg-gray-800 transition">
                <i class="fa-solid fa-money-bill-wave w-5 text-accent"></i> <span>Rent & Payments</span>
            </a>
            <a href="<?php echo BASE_URL; ?>/admin/bills/" class="flex items-center space-x-3 px-3 py-2.5 rounded-lg hover:bg-gray-800 transition">
                <i class="fa-solid fa-faucet w-5 text-accent"></i> <span>Water & Utilities</span>
            </a>
            <a href="<?php echo BASE_URL; ?>/admin/maintenance/" class="flex items-center space-x-3 px-3 py-2.5 rounded-lg hover:bg-gray-800 transition">
                <i class="fa-solid fa-screwdriver-wrench w-5 text-accent"></i> <span>Maintenance</span>
            </a>
            <a href="<?php echo BASE_URL; ?>/admin/reports/" class="flex items-center space-x-3 px-3 py-2.5 rounded-lg hover:bg-gray-800 transition">
                <i class="fa-solid fa-chart-line w-5 text-accent"></i> <span>Financial Reports</span>
            </a>
        <?php elseif ($role === 'Tenant'): ?>
            <div class="text-xs font-semibold text-gray-400 uppercase tracking-wider px-3 pt-4 pb-2">Tenant Portal</div>
            <a href="<?php echo BASE_URL; ?>/tenant/dashboard.php" class="flex items-center space-x-3 px-3 py-2.5 rounded-lg hover:bg-gray-800 transition">
                <i class="fa-solid fa-home w-5 text-accent"></i> <span>Dashboard</span>
            </a>
            <a href="<?php echo BASE_URL; ?>/tenant/rent.php" class="flex items-center space-x-3 px-3 py-2.5 rounded-lg hover:bg-gray-800 transition">
                <i class="fa-solid fa-receipt w-5 text-accent"></i> <span>Rent & Payments</span>
            </a>
            <a href="<?php echo BASE_URL; ?>/tenant/bills.php" class="flex items-center space-x-3 px-3 py-2.5 rounded-lg hover:bg-gray-800 transition">
                <i class="fa-solid fa-droplet w-5 text-accent"></i> <span>Utility Bills</span>
            </a>
            <a href="<?php echo BASE_URL; ?>/tenant/maintenance.php" class="flex items-center space-x-3 px-3 py-2.5 rounded-lg hover:bg-gray-800 transition">
                <i class="fa-solid fa-tools w-5 text-accent"></i> <span>Maintenance Requests</span>
            </a>
            <a href="<?php echo BASE_URL; ?>/tenant/complaints.php" class="flex items-center space-x-3 px-3 py-2.5 rounded-lg hover:bg-gray-800 transition">
                <i class="fa-solid fa-triangle-exclamation w-5 text-accent"></i> <span>Complaints</span>
            </a>
        <?php endif; ?>

        <div class="text-xs font-semibold text-gray-400 uppercase tracking-wider px-3 pt-6 pb-2">Public Directory</div>
        <a href="<?php echo BASE_URL; ?>/public/properties.php" class="flex items-center space-x-3 px-3 py-2.5 rounded-lg hover:bg-gray-800 transition">
            <i class="fa-solid fa-magnifying-glass w-5 text-accent"></i> <span>Browse Vacancies</span>
        </a>
    </nav>
</aside>