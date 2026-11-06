<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/constants.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/includes/functions.php';

$properties = [];
$areas = [];
$locationSuggestions = [];
$stats = ['properties' => 0, 'available' => 0, 'neighbourhoods' => 0];
$databaseAvailable = true;
try {
    $db = db();
    // Everything on this page comes from the same live data the dashboards manage.
    $properties = $db->query("SELECT p.id, p.name, p.property_type, p.property_image, l.estate, l.sub_county, MIN(u.monthly_rent) AS min_rent,
            MIN(u.bedrooms) AS bedrooms, MAX(u.bedrooms) AS max_bedrooms, MIN(u.bathrooms) AS bathrooms, COUNT(u.id) AS available_units
        FROM properties p JOIN locations l ON l.id = p.location_id JOIN units u ON u.property_id = p.id AND u.status = 'Vacant'
        WHERE p.status = 'Active' GROUP BY p.id, p.name, p.property_type, p.property_image, l.estate, l.sub_county
        ORDER BY p.created_at DESC, p.id DESC LIMIT 6")->fetchAll();
    $areas = $db->query("SELECT l.estate, l.sub_county, COUNT(u.id) AS vacant, MIN(u.monthly_rent) AS from_rent
        FROM locations l JOIN properties p ON p.location_id = l.id AND p.status = 'Active' JOIN units u ON u.property_id = p.id AND u.status = 'Vacant'
        GROUP BY l.id, l.estate, l.sub_county ORDER BY vacant DESC, l.estate LIMIT 8")->fetchAll();
    $stats = $db->query("SELECT COUNT(DISTINCT p.id) AS properties,
            (SELECT COUNT(*) FROM units u JOIN properties ap ON ap.id = u.property_id AND ap.status = 'Active' WHERE u.status = 'Vacant') AS available,
            COUNT(DISTINCT p.location_id) AS neighbourhoods
        FROM properties p WHERE p.status = 'Active'")->fetch();
    $locationSuggestions = $db->query('SELECT estate FROM locations UNION SELECT sub_county FROM locations ORDER BY 1')->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    error_log('Homepage data unavailable: ' . $e->getMessage());
    $databaseAvailable = false;
}

/** "Studio / bedsitter", "2 bedrooms" or "2–4 bedrooms" from a listing's vacant units. */
function bedroom_summary(array $listing): string {
    $min = (int)$listing['bedrooms'];
    $max = (int)$listing['max_bedrooms'];
    if ($max === 0) return 'Studio / bedsitter';
    if ($min === $max) return $min . ' bedroom' . ($min === 1 ? '' : 's');
    return ($min === 0 ? 'Studio' : $min) . '–' . $max . ' bedrooms';
}

$page_title = 'Nairobi Property & House Management';
$page_description = 'Find available homes in Nairobi and manage properties, rent, tenants and maintenance through one practical platform.';
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navbar.php';
?>
<main id="main-content" class="landing-page">
  <?php if (($_GET['error'] ?? '') === 'role_portal_unavailable'): ?><div class="site-container"><p class="notice notice-warning" role="status">Your account does not have a dashboard available in this version of the system. Please contact your system administrator.</p></div><?php endif; ?>

  <section class="hero-section" aria-labelledby="hero-title">
    <div class="site-container hero-layout">
      <div class="hero-copy">
        <p class="eyebrow">Nairobi homes, easier to find and manage</p>
        <h1 id="hero-title">Find a home. Manage property with confidence.</h1>
        <p class="hero-lede">Explore available rentals across Nairobi, or keep properties, units, tenants and rent organised in one place.</p>
        <div class="hero-actions">
          <a class="button button-accent" href="<?php echo BASE_URL; ?>/public/properties.php">Explore available homes <span aria-hidden="true">→</span></a>
          <a class="button button-outline" href="<?php echo BASE_URL; ?>/public/login.php">Landlord &amp; manager login</a>
        </div>
        <ul class="hero-points">
          <li><i class="fa-solid fa-circle-check" aria-hidden="true"></i> Free to search</li>
          <li><i class="fa-solid fa-circle-check" aria-hidden="true"></i> Book viewings online</li>
          <li><i class="fa-solid fa-circle-check" aria-hidden="true"></i> Rent, receipts &amp; repairs in one portal</li>
        </ul>
      </div>
      <div class="hero-art">
        <img src="<?php echo BASE_URL; ?>/assets/house4.jpg" alt="A row of new bungalows with red-tiled roofs in a gated community" width="725" height="530" fetchpriority="high">
        <div class="art-caption"><i class="fa-solid fa-house-chimney art-caption-mark" aria-hidden="true"></i><span><strong>Room to settle in</strong><small>Homes across Nairobi and its environs</small></span></div>
      </div>
    </div>
  </section>

  <section class="search-wrap" aria-labelledby="search-title">
    <div class="site-container"><div class="search-panel"><div class="search-heading"><p class="eyebrow">Start your search</p><h2 id="search-title">Find a place that fits your life</h2></div>
      <form action="<?php echo BASE_URL; ?>/public/properties.php" method="get" class="property-search">
        <label class="search-location">Location<input name="location" type="search" list="estate-options" placeholder="Estate or area, e.g. Kilimani" autocomplete="off"></label>
        <datalist id="estate-options"><?php foreach ($locationSuggestions as $place): ?><option value="<?php echo e($place); ?>"></option><?php endforeach; ?></datalist>
        <label class="search-type">Property type<select name="type"><option value="">Any type</option><?php foreach (PROPERTY_TYPES as $type): ?><option value="<?php echo e($type); ?>"><?php echo e($type); ?></option><?php endforeach; ?></select></label>
        <label>House type<select name="house_type"><option value="">Any</option><?php foreach (UNIT_TYPES as $type): ?><option value="<?php echo e($type); ?>"><?php echo e($type); ?></option><?php endforeach; ?></select></label>
        <label>Bedrooms<select name="bedrooms"><option value="">Any</option><?php foreach ([0, 1, 2, 3, 4] as $bedrooms): ?><option value="<?php echo $bedrooms; ?>"><?php echo $bedrooms ? $bedrooms . '+' : 'Studio / bedsitter'; ?></option><?php endforeach; ?></select></label>
        <label>Min rent (KSh)<input type="number" name="min_rent" min="0" step="500" inputmode="numeric" placeholder="No min"></label>
        <label>Max rent (KSh)<input type="number" name="max_rent" min="0" step="500" inputmode="numeric" placeholder="No max"></label>
        <button type="submit" class="button button-primary search-button"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i> Search homes</button>
      </form>
    </div></div>
  </section>

  <section class="stats-section" aria-label="Platform overview"><div class="site-container stats-grid">
    <?php foreach (['properties' => ['Properties listed', 'fa-building'], 'available' => ['Homes available now', 'fa-key'], 'neighbourhoods' => ['Neighbourhoods', 'fa-map-location-dot']] as $key => [$label, $icon]): ?>
      <div class="stat-item"><i class="fa-solid <?php echo e($icon); ?>" aria-hidden="true"></i><strong><?php echo $databaseAvailable ? number_format((int)$stats[$key]) : '—'; ?></strong><span><?php echo e($label); ?></span></div>
    <?php endforeach; ?>
  </div></section>

  <section id="properties" class="content-section" aria-labelledby="listings-title"><div class="site-container">
    <div class="section-heading"><div><p class="eyebrow">A place to call home</p><h2 id="listings-title">Available homes</h2><p>The latest listings with vacant units, straight from our property records.</p></div><a class="text-link" href="<?php echo BASE_URL; ?>/public/properties.php">View all properties <span aria-hidden="true">→</span></a></div>
    <?php if (!$databaseAvailable): ?><div class="empty-state"><h3>Listings are temporarily unavailable</h3><p>Please try again later.</p></div><?php elseif (!$properties): ?><div class="empty-state"><h3>No available listings yet</h3><p>New homes will appear here when they become available. You can still browse the property directory.</p><a class="text-link" href="<?php echo BASE_URL; ?>/public/properties.php">Browse the directory →</a></div><?php else: ?>
      <div class="property-grid"><?php foreach ($properties as $property): [$image, $isPlaceholder] = property_image($property); $detailsUrl = BASE_URL . '/public/property-details.php?id=' . (int)$property['id']; ?>
        <article class="property-card">
          <a class="property-image" href="<?php echo e($detailsUrl); ?>" tabindex="-1" aria-hidden="true">
            <img src="<?php echo e(BASE_URL . '/' . $image); ?>" alt="" loading="lazy" width="400" height="250">
            <span class="availability-tag"><i aria-hidden="true"></i> <?php echo (int)$property['available_units']; ?> available</span>
          </a>
          <div class="property-card-body">
            <p class="property-type"><?php echo e($property['property_type']); ?></p>
            <h3><a href="<?php echo e($detailsUrl); ?>"><?php echo e($property['name']); ?></a></h3>
            <p class="property-location"><i class="fa-solid fa-location-dot" aria-hidden="true"></i> <?php echo e($property['estate'] . ', ' . $property['sub_county']); ?></p>
            <ul class="property-facts">
              <li><i class="fa-solid fa-bed" aria-hidden="true"></i> <?php echo e(bedroom_summary($property)); ?></li>
              <li><i class="fa-solid fa-bath" aria-hidden="true"></i> <?php echo (int)$property['bathrooms']; ?>+ bath<?php echo (int)$property['bathrooms'] === 1 ? '' : 's'; ?></li>
            </ul>
            <div class="property-card-bottom"><p><span>From</span> <strong>KSh <?php echo number_format((float)$property['min_rent']); ?></strong><span> / month</span></p><a href="<?php echo e($detailsUrl); ?>" class="view-link">View home<span class="sr-only">: <?php echo e($property['name']); ?></span> <span aria-hidden="true">→</span></a></div>
          </div>
        </article>
      <?php endforeach; ?></div><?php endif; ?>
  </div></section>

  <?php if ($areas): ?>
  <section class="areas-section" aria-labelledby="areas-title"><div class="site-container">
    <div class="section-heading"><div><p class="eyebrow">Where to live</p><h2 id="areas-title">Browse by neighbourhood</h2><p>Areas with homes available right now.</p></div></div>
    <ul class="area-grid">
      <?php foreach ($areas as $area): ?>
        <li><a class="area-card" href="<?php echo e(BASE_URL . '/public/properties.php?' . http_build_query(['location' => $area['estate']])); ?>">
          <span class="area-icon" aria-hidden="true"><i class="fa-solid fa-location-dot"></i></span>
          <span class="area-text"><strong><?php echo e($area['estate']); ?></strong><small><?php echo e($area['sub_county']); ?></small></span>
          <span class="area-meta"><strong><?php echo (int)$area['vacant']; ?> available</strong><small>from KSh <?php echo number_format((float)$area['from_rent']); ?></small></span>
        </a></li>
      <?php endforeach; ?>
    </ul>
  </div></section>
  <?php endif; ?>

  <section class="service-section" id="services" aria-labelledby="services-title"><div class="site-container"><div class="section-heading"><div><p class="eyebrow">One practical platform</p><h2 id="services-title">Make property work simpler</h2><p>Useful tools for the everyday work of running rental properties.</p></div></div>
    <div class="service-grid"><?php foreach ([
        ['fa-building', 'Property & unit management', 'Keep property details, photos, units and availability organised.'],
        ['fa-users', 'Tenant records & leases', 'Manage tenant information and tenancy agreements in one place.'],
        ['fa-money-bill-wave', 'Rent & payment tracking', 'Raise monthly charges, record payments and issue receipts.'],
        ['fa-screwdriver-wrench', 'Maintenance requests', 'Receive tenant reports and follow each request through to completion.'],
        ['fa-droplet', 'Bills & utilities', 'Record meter readings and add utility costs to rent automatically.'],
        ['fa-chart-line', 'Property reports', 'Track occupancy, arrears and income with printable reports.'],
    ] as [$icon, $title, $text]): ?><article class="service-card"><span class="service-icon" aria-hidden="true"><i class="fa-solid <?php echo e($icon); ?>"></i></span><h3><?php echo e($title); ?></h3><p><?php echo e($text); ?></p></article><?php endforeach; ?></div>
  </div></section>

  <section class="how-section" id="about" aria-labelledby="how-title"><div class="site-container how-layout"><div><p class="eyebrow">A clearer workflow</p><h2 id="how-title">From available unit to a well-managed home</h2><p>Bring the essential steps of renting and property management into one connected system.</p></div>
    <ol class="steps-list"><?php foreach ([['01', 'Add a property', 'Record buildings, units, photos and rental details.'], ['02', 'Connect tenants', 'Sign leases and keep occupancy and tenant records current.'], ['03', 'Track the details', 'Follow rent, bills and maintenance from one dashboard.']] as [$number, $title, $text]): ?><li><span><?php echo e($number); ?></span><div><h3><?php echo e($title); ?></h3><p><?php echo e($text); ?></p></div></li><?php endforeach; ?></ol>
  </div></section>

  <section class="audience-section" aria-labelledby="audience-title"><div class="site-container"><div class="section-heading"><div><p class="eyebrow">Designed for the people involved</p><h2 id="audience-title">One platform, useful to everyone</h2></div></div>
    <div class="audience-grid"><?php foreach ([
        ['fa-user-tie', 'Landlords & managers', 'Keep an eye on properties, occupancy, rent and reported maintenance.', 'public/login.php', 'Sign in to your portal'],
        ['fa-house-user', 'Tenants', 'See rent balances, payment receipts, bills and notices, and report repairs.', 'public/register.php', 'Create a tenant account'],
        ['fa-magnifying-glass-location', 'Home seekers', 'Search available Nairobi homes and request a viewing with the management team.', 'public/properties.php', 'Browse available homes'],
    ] as [$icon, $title, $text, $path, $link]): ?><article><span class="audience-icon" aria-hidden="true"><i class="fa-solid <?php echo e($icon); ?>"></i></span><h3><?php echo e($title); ?></h3><p><?php echo e($text); ?></p><a class="text-link" href="<?php echo BASE_URL . '/' . e($path); ?>"><?php echo e($link); ?> <span aria-hidden="true">→</span></a></article><?php endforeach; ?></div>
  </div></section>

  <section class="closing-cta" aria-labelledby="cta-title"><div class="site-container cta-inner"><div><p class="eyebrow">A better way to stay organised</p><h2 id="cta-title">Ready to find a home or manage yours?</h2><p>Explore current rentals or sign in to your property portal.</p></div><div class="cta-actions"><a class="button button-light" href="<?php echo BASE_URL; ?>/public/properties.php">Explore available homes</a><a class="button button-quiet" href="<?php echo BASE_URL; ?>/public/contact.php">Contact management</a></div></div></section>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
