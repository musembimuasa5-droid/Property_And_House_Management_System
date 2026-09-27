<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/constants.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/includes/functions.php';

$properties = [];
$stats = ['properties' => 0, 'available' => 0, 'tenants' => 0];
$databaseAvailable = true;
try {
    $db = db();
    $listingQuery = $db->prepare("SELECT p.id, p.name, p.property_type, p.property_image, l.estate, l.sub_county, MIN(u.monthly_rent) AS min_rent, MIN(u.bedrooms) AS bedrooms, MIN(u.bathrooms) AS bathrooms, COUNT(u.id) AS available_units FROM properties p JOIN locations l ON l.id = p.location_id JOIN units u ON u.property_id = p.id AND u.status = 'Vacant' WHERE p.status = 'Active' GROUP BY p.id ORDER BY p.created_at DESC LIMIT 6");
    $listingQuery->execute();
    $properties = $listingQuery->fetchAll();
    $stats['properties'] = (int)$db->query("SELECT COUNT(*) FROM properties WHERE status = 'Active'")->fetchColumn();
    $stats['available'] = (int)$db->query("SELECT COUNT(*) FROM units WHERE status = 'Vacant'")->fetchColumn();
    $stats['tenants'] = (int)$db->query("SELECT COUNT(*) FROM tenants WHERE status = 'Active'")->fetchColumn();
} catch (Throwable $e) {
    error_log('Homepage data unavailable: ' . $e->getMessage());
    $databaseAvailable = false;
}

$page_title = 'Nairobi Property & House Management';
$page_description = 'Find available homes in Nairobi and manage properties, rent, tenants and maintenance through one practical platform.';
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navbar.php';
$propertyTypes = PROPERTY_TYPES;
?>
<main id="main-content" class="landing-page">
  <?php if (($_GET['error'] ?? '') === 'role_portal_unavailable'): ?><div class="site-container"><p class="notice notice-warning" role="status">Your account does not have a dashboard available in this version of the system. Please contact your system administrator.</p></div><?php endif; ?>
  <section class="hero-section">
    <div class="site-container hero-layout">
      <div class="hero-copy">
        <p class="eyebrow">Nairobi homes, easier to find and manage</p>
        <h1>Find a home. Manage property with confidence.</h1>
        <p class="hero-lede">Explore available rentals across Nairobi, or keep properties, units, tenants and rent organised in one place.</p>
        <div class="hero-actions"><a class="button button-primary" href="<?php echo BASE_URL; ?>/public/properties.php">Explore available houses <span aria-hidden="true">→</span></a><a class="button button-outline" href="<?php echo BASE_URL; ?>/public/login.php">Property manager login</a></div>
        <p class="hero-note">For renters, landlords and property management teams.</p>
      </div>
      <div class="hero-art">
        <img src="<?php echo BASE_URL; ?>/assets/house4.jpg" alt="A row of modern homes in a residential community" fetchpriority="high"><div class="art-caption"><span class="art-caption-mark" aria-hidden="true">⌂</span><span><strong>Room to settle in</strong><small>Explore homes across Nairobi</small></span></div>
      </div>
    </div>
  </section>

  <section class="search-wrap" aria-labelledby="search-title">
    <div class="site-container"><div class="search-panel"><div class="search-heading"><p class="eyebrow">Start your search</p><h2 id="search-title">Find a place that fits your life</h2></div>
      <form action="<?php echo BASE_URL; ?>/public/properties.php" method="get" class="property-search">
        <label>Location<input name="location" type="search" placeholder="Estate or neighbourhood" autocomplete="off"></label>
        <label>Property type<select name="type"><option value="">Any property type</option><?php foreach ($propertyTypes as $type): ?><option value="<?php echo e($type); ?>"><?php echo e($type); ?></option><?php endforeach; ?></select></label>
        <label>House type<select name="house_type"><option value="">Any house type</option><?php foreach (UNIT_TYPES as $type): ?><option value="<?php echo e($type); ?>"><?php echo e($type); ?></option><?php endforeach; ?></select></label>
        <label>Minimum rent<input type="number" name="min_rent" min="0" step="500" placeholder="KSh"></label>
        <label>Maximum rent<input type="number" name="max_rent" min="0" step="500" placeholder="KSh"></label>
        <label>Bedrooms<select name="bedrooms"><option value="">Any</option><?php foreach ([0,1,2,3,4] as $bedrooms): ?><option value="<?php echo $bedrooms; ?>"><?php echo $bedrooms ? $bedrooms . '+' : 'Bedsitter / studio'; ?></option><?php endforeach; ?></select></label>
        <button type="submit" class="button button-primary search-button"><span aria-hidden="true">⌕</span> Search homes</button>
      </form>
    </div></div>
  </section>

  <section class="stats-section" aria-label="Platform overview"><div class="site-container stats-grid">
    <?php foreach (['properties' => 'Managed properties', 'available' => 'Available units', 'tenants' => 'Active tenants'] as $key => $label): ?><div class="stat-item"><strong><?php echo $databaseAvailable ? number_format($stats[$key]) : '—'; ?></strong><span><?php echo e($label); ?></span></div><?php endforeach; ?>
  </div></section>

  <section id="properties" class="content-section"><div class="site-container">
    <div class="section-heading"><div><p class="eyebrow">A place to call home</p><h2>Available homes</h2><p>Browse current listings and compare options that work for you.</p></div><a class="text-link" href="<?php echo BASE_URL; ?>/public/properties.php">View all properties <span aria-hidden="true">→</span></a></div>
    <?php if (!$databaseAvailable): ?><div class="empty-state"><h3>Listings are temporarily unavailable</h3><p>Please try again later.</p></div><?php elseif (!$properties): ?><div class="empty-state"><h3>No available listings yet</h3><p>New homes will appear here when they become available. You can still browse the property directory.</p><a class="text-link" href="<?php echo BASE_URL; ?>/public/properties.php">Browse the directory →</a></div><?php else: ?>
      <div class="property-grid"><?php foreach ($properties as $property): ?>
        <article class="property-card">
          <a class="property-image" href="<?php echo BASE_URL; ?>/public/property-details.php?id=<?php echo (int)$property['id']; ?>" aria-label="View <?php echo e($property['name']); ?>">
            <?php $imagePath = trim((string)($property['property_image'] ?? ''), '/'); $imageFile = $imagePath !== '' ? __DIR__ . '/' . $imagePath : ''; ?>
            <?php if ($imagePath !== '' && is_file($imageFile)): ?><img src="<?php echo BASE_URL . '/' . e($imagePath); ?>" alt="Exterior of <?php echo e($property['name']); ?>" loading="lazy"><?php else: ?><img src="<?php echo BASE_URL . '/' . e(sample_property_image($property['id'])); ?>" alt="Sample home image; actual property photo not provided" loading="lazy"><?php endif; ?>
            <span class="availability-tag"><i aria-hidden="true"></i> Available</span>
          </a>
          <div class="property-card-body"><p class="property-type"><?php echo e($property['property_type']); ?></p><h3><a href="<?php echo BASE_URL; ?>/public/property-details.php?id=<?php echo (int)$property['id']; ?>"><?php echo e($property['name']); ?></a></h3><p class="property-location"><span aria-hidden="true">⌖</span> <?php echo e($property['estate'] . ', ' . $property['sub_county']); ?></p>
            <div class="property-facts"><?php if ($property['bedrooms'] !== null): ?><span><?php echo (int)$property['bedrooms']; ?>+ bedrooms</span><?php endif; ?><?php if ($property['bathrooms'] !== null): ?><span><?php echo (int)$property['bathrooms']; ?>+ baths</span><?php endif; ?><span><?php echo (int)$property['available_units']; ?> vacant</span></div>
            <div class="property-card-bottom"><p><strong><?php echo format_currency($property['min_rent']); ?></strong><span> / month</span></p><a href="<?php echo BASE_URL; ?>/public/property-details.php?id=<?php echo (int)$property['id']; ?>" class="view-link">View home <span aria-hidden="true">→</span></a></div>
          </div>
        </article>
      <?php endforeach; ?></div><?php endif; ?>
  </div></section>

  <?php include __DIR__ . '/includes/property-gallery.php'; ?>

  <section class="service-section" id="services"><div class="site-container"><div class="section-heading"><div><p class="eyebrow">One practical platform</p><h2>Make property work simpler</h2><p>Useful tools for the everyday work of running rental properties.</p></div></div>
    <div class="service-grid"><?php foreach ([['01','Property & unit management','Keep property details, units and availability organised.'],['02','Tenant records','Manage tenant information and tenancy records in one place.'],['03','Rent & payment tracking','Review rent charges, balances and payment history.'],['04','Maintenance requests','Receive tenant reports and follow requests through to completion.'],['05','Bills & utilities','Keep utility readings and tenant charges accessible.'],['06','Property reports','Review occupancy and financial activity from your dashboard.']] as $service): ?><article class="service-card"><span class="service-number"><?php echo e($service[0]); ?></span><h3><?php echo e($service[1]); ?></h3><p><?php echo e($service[2]); ?></p></article><?php endforeach; ?></div>
  </div></section>

  <section class="how-section" id="about"><div class="site-container how-layout"><div><p class="eyebrow">A clearer workflow</p><h2>From available unit to a well-managed home</h2><p>Bring the essential steps of renting and property management into one connected system.</p></div><ol class="steps-list"><?php foreach ([['01','Add a property','Record buildings, units and rental details.'],['02','Connect tenants','Keep occupancy and tenant records current.'],['03','Track the details','Follow rent, bills and maintenance activity.']] as $step): ?><li><span><?php echo e($step[0]); ?></span><div><h3><?php echo e($step[1]); ?></h3><p><?php echo e($step[2]); ?></p></div></li><?php endforeach; ?></ol></div></section>

  <section class="audience-section"><div class="site-container"><div class="section-heading"><div><p class="eyebrow">Designed for the people involved</p><h2>One platform, useful to everyone</h2></div></div><div class="audience-grid"><article><span aria-hidden="true">▦</span><h3>Landlords & managers</h3><p>Keep an eye on properties, occupancy, rent and reported maintenance.</p></article><article><span aria-hidden="true">⌂</span><h3>Tenants</h3><p>Access rent details, payment history, bills, notices and service requests.</p></article><article><span aria-hidden="true">⌕</span><h3>Home seekers</h3><p>Search available Nairobi homes and request a viewing with the management team.</p></article></div></div></section>

  <section class="closing-cta"><div class="site-container cta-inner"><div><p class="eyebrow">A better way to stay organised</p><h2>Ready to find a home or manage yours?</h2><p>Explore current rentals or sign in to your property portal.</p></div><div class="cta-actions"><a class="button button-light" href="<?php echo BASE_URL; ?>/public/properties.php">Explore available homes</a><a class="button button-quiet" href="<?php echo BASE_URL; ?>/public/login.php">Sign in</a></div></div></section>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
