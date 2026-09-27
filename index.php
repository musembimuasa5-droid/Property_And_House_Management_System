<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/includes/functions.php';

$properties = [];
$stats = ['properties' => 0, 'available' => 0, 'tenants' => 0];
$databaseAvailable = true;
try {
    $db = db();
    $properties = $db->query("SELECT p.id, p.name, p.property_type, p.property_image, l.estate, l.sub_county, MIN(u.monthly_rent) AS min_rent, COUNT(u.id) AS available_units FROM properties p JOIN locations l ON l.id = p.location_id JOIN units u ON u.property_id = p.id AND u.status = 'Vacant' WHERE p.status = 'Active' GROUP BY p.id ORDER BY p.created_at DESC LIMIT 6")->fetchAll();
    $stats['properties'] = (int)$db->query("SELECT COUNT(*) FROM properties WHERE status = 'Active'")->fetchColumn();
    $stats['available'] = (int)$db->query("SELECT COUNT(*) FROM units WHERE status = 'Vacant'")->fetchColumn();
    $stats['tenants'] = (int)$db->query("SELECT COUNT(*) FROM tenants WHERE status = 'Active'")->fetchColumn();
} catch (Throwable $e) {
    error_log('Homepage data unavailable: ' . $e->getMessage());
    $databaseAvailable = false;
}
$page_title = 'Find Your Perfect Home in Nairobi';
include __DIR__ . '/includes/header.php';
?>
<header class="bg-white border-b sticky top-0 z-30">
  <nav class="max-w-7xl mx-auto px-4 h-16 flex items-center justify-between" aria-label="Main navigation">
    <a class="font-bold text-xl text-primary" href="<?php echo BASE_URL; ?>/">Nairobi Property</a>
    <div class="flex gap-4 items-center text-sm font-medium">
      <div class="hidden sm:flex gap-4"><a href="#properties">Properties</a><a href="#about">About</a><a href="#services">Services</a><a href="<?php echo BASE_URL; ?>/public/contact.php">Contact</a></div>
      <details class="sm:hidden relative"><summary class="cursor-pointer list-none border rounded-lg px-3 py-2">Menu</summary><div class="absolute right-0 mt-2 p-3 bg-white shadow-lg rounded-lg border flex flex-col gap-3 min-w-36"><a href="#properties">Properties</a><a href="#about">About</a><a href="#services">Services</a><a href="<?php echo BASE_URL; ?>/public/contact.php">Contact</a></div></details>
      <?php if (isLoggedIn()): $navUser = getCurrentUser(); if ($navUser['role_name'] === 'Tenant'): ?><a class="text-primary" href="<?php echo BASE_URL; ?>/tenant/dashboard.php">Tenant Dashboard</a><?php elseif (in_array($navUser['role_name'], ['Super Admin','Property Manager','Accountant'], true)): ?><a class="text-primary" href="<?php echo BASE_URL; ?>/admin/dashboard.php">Management Dashboard</a><?php endif; ?><?php else: ?><a href="<?php echo BASE_URL; ?>/public/login.php">Login</a><a class="bg-primary text-white px-4 py-2 rounded-lg" href="<?php echo BASE_URL; ?>/public/register.php">Register</a><?php endif; ?>
      <?php if (isLoggedIn()): ?><form method="post" action="<?php echo BASE_URL; ?>/api/logout.php"><?php echo csrf_field(); ?><button class="text-sm text-red-700" type="submit">Logout</button></form><?php endif; ?>
    </div>
  </nav>
</header>
<main>
  <?php if (($_GET['error'] ?? '') === 'role_portal_unavailable'): ?><div class="max-w-7xl mx-auto px-4 pt-4"><p class="rounded-lg bg-amber-50 text-amber-900 p-4">Your account does not have a dashboard available in this version of the system. Please contact your system administrator.</p></div><?php endif; ?>
  <section class="bg-slate-900 text-white">
    <div class="max-w-7xl mx-auto px-4 py-20 lg:py-28">
      <p class="text-blue-200 font-semibold mb-3">NAIROBI RENTALS, MADE EASIER</p>
      <h1 class="text-4xl md:text-6xl font-extrabold max-w-3xl">Find Your Perfect Home in Nairobi</h1>
      <p class="text-lg text-slate-200 mt-5 max-w-2xl">Explore apartments, bedsitters, studios and houses across Nairobi. Search available homes and compare rent in one place.</p>
      <form action="<?php echo BASE_URL; ?>/public/properties.php" method="get" class="mt-8 bg-white text-gray-800 rounded-xl p-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3 max-w-5xl">
        <label class="text-xs font-semibold">Location<input name="location" placeholder="e.g. Kasarani" class="mt-1 w-full border rounded-lg p-3 text-sm"></label>
        <label class="text-xs font-semibold">Property type<select name="type" class="mt-1 w-full border rounded-lg p-3 text-sm"><option value="">Any type</option><?php foreach (['Apartment','Flats','Bedsitters','Maisonettes','Townhouses','Commercial'] as $type): ?><option><?php echo e($type); ?></option><?php endforeach; ?></select></label>
        <label class="text-xs font-semibold">Maximum rent<input type="number" min="0" name="max_rent" placeholder="KSh / month" class="mt-1 w-full border rounded-lg p-3 text-sm"></label>
        <label class="text-xs font-semibold">Bedrooms<select name="bedrooms" class="mt-1 w-full border rounded-lg p-3 text-sm"><option value="">Any</option><?php foreach ([0,1,2,3,4] as $bedrooms): ?><option value="<?php echo $bedrooms; ?>"><?php echo $bedrooms ? $bedrooms . '+' : 'Bedsitter / studio'; ?></option><?php endforeach; ?></select></label>
        <button class="bg-blue-700 hover:bg-blue-800 text-white rounded-lg font-semibold px-4 py-3 self-end" type="submit">Search Properties</button>
      </form>
    </div>
  </section>
  <section class="max-w-7xl mx-auto px-4 py-8 grid grid-cols-3 gap-4 text-center">
    <?php foreach (['properties' => 'Managed properties', 'available' => 'Available units', 'tenants' => 'Registered tenants'] as $key => $label): ?><div class="bg-white rounded-xl p-5 border"><p class="text-2xl font-bold text-primary"><?php echo $databaseAvailable ? number_format($stats[$key]) : '—'; ?></p><p class="text-sm text-gray-500"><?php echo e($label); ?></p></div><?php endforeach; ?>
  </section>
  <section id="properties" class="max-w-7xl mx-auto px-4 py-12">
    <div class="flex justify-between items-end mb-6"><div><p class="text-blue-700 font-semibold">A PLACE TO CALL HOME</p><h2 class="text-3xl font-bold">Featured available properties</h2></div><a class="text-blue-700 font-semibold" href="<?php echo BASE_URL; ?>/public/properties.php">Browse all properties →</a></div>
    <?php if (!$databaseAvailable): ?><div class="bg-amber-50 border border-amber-200 p-5 rounded-xl">Property listings are temporarily unavailable. Please try again later.</div><?php elseif (!$properties): ?><div class="bg-slate-50 border p-8 rounded-xl text-center"><h3 class="font-semibold">No available listings yet</h3><p class="text-gray-600 mt-1">Please check back soon or browse the directory for managed properties.</p></div><?php else: ?>
      <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-6"><?php foreach ($properties as $property): ?><article class="bg-white rounded-xl overflow-hidden border shadow-sm"><div class="h-48 bg-slate-100 flex items-center justify-center"><?php if (!empty($property['property_image'])): ?><img class="w-full h-full object-cover" src="<?php echo BASE_URL . '/' . e(ltrim($property['property_image'], '/')); ?>" alt="<?php echo e($property['name']); ?>"><?php else: ?><span class="text-slate-400 text-5xl">⌂</span><?php endif; ?></div><div class="p-5"><p class="text-sm text-blue-700 font-semibold"><?php echo e($property['property_type']); ?></p><h3 class="text-xl font-bold mt-1"><?php echo e($property['name']); ?></h3><p class="text-sm text-gray-500 mt-1"><?php echo e($property['estate'] . ', ' . $property['sub_county']); ?></p><div class="flex justify-between items-center mt-5"><span class="font-bold text-lg"><?php echo format_currency($property['min_rent']); ?><small class="font-normal text-gray-500"> / month</small></span><a class="bg-blue-700 text-white px-4 py-2 rounded-lg text-sm" href="<?php echo BASE_URL; ?>/public/property-details.php?id=<?php echo (int)$property['id']; ?>">View details</a></div><p class="text-xs text-emerald-700 mt-3"><?php echo (int)$property['available_units']; ?> vacant unit(s)</p></div></article><?php endforeach; ?></div>
    <?php endif; ?>
  </section>
  <section class="bg-slate-50 py-12"><div class="max-w-7xl mx-auto px-4"><h2 class="text-2xl font-bold">Explore Nairobi neighbourhoods</h2><div class="flex flex-wrap gap-3 mt-5"><?php foreach (['Kasarani','Roysambu','Githurai','Kahawa','Westlands','Ngara','Embakasi','Kilimani','Kileleshwa','South B','South C','Ruaka','Ruiru'] as $area): ?><a class="px-4 py-2 rounded-full border bg-white hover:border-blue-500" href="<?php echo BASE_URL; ?>/public/properties.php?location=<?php echo rawurlencode($area); ?>"><?php echo e($area); ?></a><?php endforeach; ?></div></div></section>
  <section class="max-w-7xl mx-auto px-4 py-12"><h2 class="text-2xl font-bold">Browse by home type</h2><div class="grid grid-cols-2 md:grid-cols-4 gap-4 mt-5"><?php foreach (['Bedsitter','Studio','1 Bedroom','2 Bedroom','3 Bedroom','Family Houses','Apartments','Commercial'] as $category): $queryType = $category === 'Apartments' ? 'Apartment' : ($category === 'Family Houses' ? 'Maisonettes' : $category); $queryKey = in_array($category, ['Apartments','Commercial'], true) ? 'type' : 'house_type'; ?><a class="bg-white border rounded-xl p-5 hover:border-blue-500" href="<?php echo BASE_URL; ?>/public/properties.php?<?php echo $queryKey; ?>=<?php echo rawurlencode($queryType); ?>"><span class="font-semibold"><?php echo e($category); ?></span><span class="block text-sm text-gray-500 mt-1">Explore available homes &rarr;</span></a><?php endforeach; ?></div></section>
  <section id="about" class="max-w-7xl mx-auto px-4 py-14 grid md:grid-cols-2 gap-8 items-center"><div><p class="text-blue-700 font-semibold">SIMPLE FROM SEARCH TO MOVE IN</p><h2 class="text-3xl font-bold mt-2">A clearer way to find and manage a home</h2><p class="text-gray-600 mt-4">Browse current availability, compare units and request a viewing. Tenants can also use the portal to follow rent, notices and maintenance requests.</p></div><div id="services" class="grid grid-cols-2 gap-4"><?php foreach (['Search homes','Compare rent','Request a viewing','Manage your tenancy','Track rent payments','Submit maintenance requests','View notices','Review lease details'] as $step): ?><div class="bg-white border rounded-xl p-5 font-semibold"><?php echo e($step); ?></div><?php endforeach; ?></div></section>
  <section class="max-w-7xl mx-auto px-4 pb-14 grid md:grid-cols-2 gap-5"><div class="bg-blue-50 rounded-2xl p-7"><h2 class="text-2xl font-bold">Find your next home</h2><p class="text-gray-600 my-3">Search current availability and request a viewing directly.</p><a class="text-blue-800 font-semibold" href="<?php echo BASE_URL; ?>/public/properties.php">Browse rentals →</a></div><div class="bg-slate-100 rounded-2xl p-7"><h2 class="text-2xl font-bold">Property owners and managers</h2><p class="text-gray-600 my-3">Sign in to the management dashboard to view portfolio activity.</p><a class="text-blue-800 font-semibold" href="<?php echo BASE_URL; ?>/public/login.php">Manager sign in →</a></div></section>
</main>
<footer class="bg-slate-950 text-slate-300"><div class="max-w-7xl mx-auto px-4 py-10 grid sm:grid-cols-3 gap-8"><div><p class="font-bold text-white text-lg">Nairobi Property</p><p class="text-sm mt-2">Property search and tenancy management for Nairobi.</p></div><div><p class="font-semibold text-white">Explore</p><div class="flex flex-col gap-2 mt-3 text-sm"><a href="<?php echo BASE_URL; ?>/">Home</a><a href="<?php echo BASE_URL; ?>/public/properties.php">Properties</a><a href="<?php echo BASE_URL; ?>/public/contact.php">Contact</a></div></div><div><p class="font-semibold text-white">Account</p><div class="flex flex-col gap-2 mt-3 text-sm"><a href="<?php echo BASE_URL; ?>/public/login.php">Login</a><a href="<?php echo BASE_URL; ?>/public/register.php">Tenant registration</a></div></div></div><div class="border-t border-slate-800 text-center text-xs py-4">&copy; <?php echo date('Y'); ?> Nairobi Property &amp; House Management System</div></footer>
</body></html>
