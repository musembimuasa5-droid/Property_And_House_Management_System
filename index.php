<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/constants.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/includes/functions.php';

$properties = [];
$stats = ['properties' => 0, 'available' => 0, 'tenants' => 0, 'rent' => 0];
$databaseAvailable = true;
try {
    $db = db(true);
    if (!$db) {
        $databaseAvailable = false;
    } else {
        $listingQuery = $db->prepare("SELECT p.id, p.name, p.property_type, p.property_image, l.estate, l.sub_county, MIN(u.monthly_rent) AS min_rent, MIN(u.bedrooms) AS bedrooms, MIN(u.bathrooms) AS bathrooms, COUNT(u.id) AS available_units FROM properties p JOIN locations l ON l.id = p.location_id JOIN units u ON u.property_id = p.id AND u.status = 'Vacant' WHERE p.status = 'Active' GROUP BY p.id ORDER BY p.created_at DESC LIMIT 6");
        $listingQuery->execute();
        $properties = $listingQuery->fetchAll();
        $stats['properties'] = (int)$db->query("SELECT COUNT(*) FROM properties WHERE status = 'Active'")->fetchColumn();
        $stats['available'] = (int)$db->query("SELECT COUNT(*) FROM units WHERE status = 'Vacant'")->fetchColumn();
        $stats['tenants'] = (int)$db->query("SELECT COUNT(*) FROM tenants WHERE status = 'Active'")->fetchColumn();
        $stats['rent'] = (float)$db->query("SELECT COALESCE(SUM(amount), 0) FROM payments")->fetchColumn();
    }
} catch (Throwable $e) {
    error_log('Homepage data unavailable: ' . $e->getMessage());
    $databaseAvailable = false;
}

$sampleProperties = [
    ['name' => 'Sunrise Apartments', 'estate' => 'Kasarani Main', 'type' => '1 Bedroom', 'rent' => 15000, 'status' => 'Available', 'image' => 'modern-residential-building.webp', 'id' => null],
    ['name' => 'Kilimani Heights', 'estate' => 'Kilimani', 'type' => '2 Bedroom Luxury', 'rent' => 65000, 'status' => 'Available', 'image' => 'modern-home-interior.webp', 'id' => null],
    ['name' => 'Roysambu Courts', 'estate' => 'Roysambu, near Thika Road Mall', 'type' => 'Bedsitter / Studio', 'rent' => 12500, 'status' => 'Available', 'image' => 'airy-apartment-living-room.webp', 'id' => null],
    ['name' => 'Greenfields Maisonettes', 'estate' => 'Embakasi', 'type' => '3 Bedroom House', 'rent' => 45000, 'status' => 'Occupied', 'image' => 'modern-family-home.webp', 'id' => null],
    ['name' => 'Westlands Executive Suites', 'estate' => 'Westlands', 'type' => '1 Bedroom Studio', 'rent' => 40000, 'status' => 'Available', 'image' => 'bright-open-plan-lounge.webp', 'id' => null],
];
$listingCards = [];
foreach (array_slice($properties, 0, 5) as $property) {
    $property['_is_sample'] = false;
    $listingCards[] = $property;
}
$existingPropertyNames = array_map(static function ($property) {
    return strtolower(trim((string)$property['name']));
}, $listingCards);
$showSampleListings = false;
foreach ($sampleProperties as $sampleProperty) {
    if (count($listingCards) >= 5) {
        break;
    }
    if (in_array(strtolower($sampleProperty['name']), $existingPropertyNames, true)) {
        continue;
    }
    $sampleProperty['_is_sample'] = true;
    $listingCards[] = $sampleProperty;
    $existingPropertyNames[] = strtolower($sampleProperty['name']);
    $showSampleListings = true;
}

$page_title = 'Find a Home in Nairobi';
$page_description = 'Discover rental homes across Nairobi and explore a simpler way to manage property.';
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navbar.php';
?>
<main id="main-content" class="landing-page">
  <?php if (($_GET['error'] ?? '') === 'role_portal_unavailable'): ?><div class="site-container"><p class="notice notice-warning" role="status">Your account does not have a dashboard available in this version of the system. Please contact your system administrator.</p></div><?php endif; ?>

  <section class="hero-section">
    <img class="hero-photo" src="<?php echo BASE_URL; ?>/assets/house4.jpg" alt="" fetchpriority="high">
    <div class="hero-shade" aria-hidden="true"></div>
    <div class="site-container hero-content">
      <div class="hero-copy">
        <p class="eyebrow"><span class="eyebrow-dot" aria-hidden="true"></span> NAIROBI, KENYA <span class="hero-eyebrow-divider">·</span> A BETTER WAY HOME</p>
        <h1>Find a Place You’ll Love to Call Home.</h1>
        <p class="hero-lede">Discover thoughtfully managed rental homes across Nairobi — from a first studio to room for the whole family.</p>
        <div class="hero-proof"><span class="hero-proof-icon" aria-hidden="true"><i class="fa-solid fa-location-dot"></i></span><span>Find your next address in <strong>Kasarani, Kilimani, Westlands</strong> and beyond.</span></div>
      </div>

      <div class="search-panel" id="explore" aria-labelledby="search-title">
        <div class="search-heading"><div><p class="search-overline">A good place starts here</p><h2 id="search-title">Find your next home</h2></div><span class="search-heading-icon" aria-hidden="true"><i class="fa-solid fa-house"></i></span></div>
        <form action="<?php echo BASE_URL; ?>/public/properties.php" method="get" class="property-search">
          <label><span>Location</span><span class="search-input-wrap"><i class="fa-solid fa-location-dot" aria-hidden="true"></i><input name="location" type="search" placeholder="Where in Nairobi?" autocomplete="off"></span></label>
          <label><span>Property type</span><select name="type"><option value="">Any property</option><?php foreach (PROPERTY_TYPES as $type): ?><option value="<?php echo e($type); ?>"><?php echo e($type); ?></option><?php endforeach; ?></select></label>
          <label><span>House type</span><select name="house_type"><option value="">Any house type</option><?php foreach (UNIT_TYPES as $type): ?><option value="<?php echo e($type); ?>"><?php echo e($type); ?></option><?php endforeach; ?></select></label>
          <label class="price-range-label"><span>Monthly price range</span><span class="price-range-fields"><input type="number" name="min_rent" min="0" step="500" placeholder="Min KSh" aria-label="Minimum monthly rent"><span aria-hidden="true">–</span><input type="number" name="max_rent" min="0" step="500" placeholder="Max KSh" aria-label="Maximum monthly rent"></span></label>
          <button type="submit" class="button search-button"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i> Search Properties</button>
        </form>
        <div class="neighborhood-row"><span class="neighborhood-label">Popular in Nairobi</span><div class="neighborhood-pills">
          <?php foreach (['Kasarani', 'Kilimani', 'Westlands', 'Embakasi', 'Roysambu', 'Kahawa West'] as $index => $neighborhood): ?><a class="neighborhood-pill<?php echo $index === 0 ? ' is-selected' : ''; ?>" href="<?php echo BASE_URL; ?>/public/properties.php?location=<?php echo rawurlencode($neighborhood); ?>"><?php echo e($neighborhood); ?></a><?php endforeach; ?>
        </div></div>
      </div>
    </div>
    <div class="hero-bottom-note" aria-hidden="true"><span class="hero-note-line"></span> YOUR NAIROBI HOME IS CLOSER THAN YOU THINK</div>
  </section>

  <section class="stats-section" aria-label="Property management overview">
    <div class="site-container stats-grid">
      <article class="stat-item"><span class="stat-icon"><i class="fa-regular fa-building" aria-hidden="true"></i></span><div><strong><?php echo $databaseAvailable ? number_format($stats['properties']) : '—'; ?></strong><span>Managed properties</span></div></article>
      <article class="stat-item"><span class="stat-icon"><i class="fa-solid fa-key" aria-hidden="true"></i></span><div><strong><?php echo $databaseAvailable ? number_format($stats['available']) : '—'; ?></strong><span>Available units</span></div></article>
      <article class="stat-item"><span class="stat-icon"><i class="fa-solid fa-people-roof" aria-hidden="true"></i></span><div><strong><?php echo $databaseAvailable ? number_format($stats['tenants']) : '—'; ?></strong><span>Active tenants</span></div></article>
      <article class="stat-item"><span class="stat-icon"><i class="fa-solid fa-arrow-trend-up" aria-hidden="true"></i></span><div><strong><?php echo $databaseAvailable ? 'KSh ' . number_format($stats['rent'] / 1000000, 1) . 'M' : '—'; ?></strong><span>Rent processed</span></div></article>
      <article class="stat-item"><span class="stat-icon"><i class="fa-regular fa-heart" aria-hidden="true"></i></span><div><strong>—</strong><span>Satisfied tenants</span></div></article>
    </div>
  </section>

  <section id="properties" class="content-section">
    <div class="site-container">
      <div class="section-heading"><div><p class="eyebrow">FIND YOUR FIT</p><h2>Explore Places to <span class="heading-accent">Call Home</span></h2><p>A few of the homes and neighbourhoods to help you picture what’s possible.</p></div><a class="text-link" href="<?php echo BASE_URL; ?>/public/properties.php">View All Properties <span aria-hidden="true">↗</span></a></div>
      <?php if ($showSampleListings): ?><p class="listing-disclosure"><i class="fa-solid fa-circle-info" aria-hidden="true"></i> Illustrative sample listings for this homepage preview. Availability and pricing are examples, not live offers.</p><?php endif; ?>
      <div class="property-grid">
        <?php foreach ($listingCards as $index => $property):
          $isSample = (bool)($property['_is_sample'] ?? false);
          $status = $isSample ? $property['status'] : 'Available';
          $propertyId = $isSample ? null : (int)$property['id'];
          $propertyName = $property['name'];
          $propertyLocation = $isSample ? $property['estate'] : $property['estate'] . ', ' . $property['sub_county'];
          $propertyType = $isSample ? $property['type'] : $property['property_type'];
          $propertyRent = $isSample ? $property['rent'] : $property['min_rent'];
          $propertyImage = $isSample ? 'assets/' . $property['image'] : trim((string)($property['property_image'] ?? ''), '/');
          $propertyImageFile = $propertyImage !== '' ? __DIR__ . '/' . $propertyImage : '';
          $usingSampleImage = !$isSample && ($propertyImage === '' || !is_file($propertyImageFile));
          if ($usingSampleImage) $propertyImage = sample_property_image($propertyId);
          $detailsUrl = $propertyId ? BASE_URL . '/public/property-details.php?id=' . $propertyId : BASE_URL . '/public/properties.php?location=' . rawurlencode($property['estate']);
        ?>
        <article class="property-card<?php echo $status === 'Occupied' ? ' is-occupied' : ''; ?>">
          <a class="property-image" href="<?php echo e($detailsUrl); ?>" aria-label="View <?php echo e($propertyName); ?>">
            <img src="<?php echo BASE_URL . '/' . e($propertyImage); ?>" alt="<?php echo $isSample || $usingSampleImage ? 'Illustrative ' : ''; ?><?php echo str_contains(strtolower($propertyImage), 'interior') || str_contains(strtolower($propertyImage), 'lounge') || str_contains(strtolower($propertyImage), 'living-room') ? 'home interior' : 'home exterior'; ?><?php echo $isSample || $usingSampleImage ? ' image for ' : ' in '; ?><?php echo e($propertyLocation); ?>" width="840" height="560" loading="lazy" decoding="async">
            <span class="availability-tag status-<?php echo strtolower($status); ?>"><i aria-hidden="true"></i><?php echo e($status); ?></span>
            <?php if ($isSample): ?><span class="image-sample-tag">SAMPLE LISTING</span><?php endif; ?>
          </a>
          <div class="property-card-body">
            <div class="property-card-title-row"><div><p class="property-type"><?php echo e($propertyType); ?></p><h3><a href="<?php echo e($detailsUrl); ?>"><?php echo e($propertyName); ?></a></h3></div><span class="property-bookmark" aria-hidden="true"><i class="fa-regular fa-heart"></i></span></div>
            <p class="property-location"><i class="fa-solid fa-location-dot" aria-hidden="true"></i> <?php echo e($propertyLocation); ?></p>
            <div class="property-facts"><?php if (!$isSample && $property['bedrooms'] !== null): ?><span><i class="fa-solid fa-bed" aria-hidden="true"></i> <?php echo (int)$property['bedrooms']; ?> bedroom<?php echo (int)$property['bedrooms'] === 1 ? '' : 's'; ?></span><?php else: ?><span><i class="fa-solid fa-house" aria-hidden="true"></i> <?php echo e($propertyType); ?></span><?php endif; ?><span><i class="fa-regular fa-circle-check" aria-hidden="true"></i> <?php echo $status === 'Occupied' ? 'Currently occupied' : 'Ready to view'; ?></span></div>
            <div class="property-card-bottom"><p><strong>KSh <?php echo number_format((float)$propertyRent); ?></strong><span> / month</span></p><a href="<?php echo e($detailsUrl); ?>" class="view-link">View Details <span aria-hidden="true">↗</span></a></div>
            <?php if ($status === 'Available'): ?><button type="button" class="schedule-button" data-viewing-property="<?php echo e($propertyName); ?>" data-viewing-location="<?php echo e($propertyLocation); ?>" data-viewing-image="<?php echo BASE_URL . '/' . e($propertyImage); ?>">Schedule a Viewing <span aria-hidden="true">→</span></button><?php else: ?><p class="occupied-note"><i class="fa-solid fa-lock" aria-hidden="true"></i> Not available for booking</p><?php endif; ?>
          </div>
        </article>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <?php include __DIR__ . '/includes/property-gallery.php'; ?>

  <section class="service-section" id="services">
    <div class="site-container">
      <div class="section-heading">
        <div><p class="eyebrow">ONE PRACTICAL PLATFORM</p><h2>Make property work <span class="heading-accent">simpler</span></h2><p>Useful tools for the everyday work of finding and managing Nairobi rental homes.</p></div>
      </div>
      <div class="service-grid">
        <?php foreach ([['01', 'Property & unit management', 'Keep property details, units and availability organised.', 'fa-building'], ['02', 'Tenant records', 'Manage tenant information and tenancy records in one place.', 'fa-users'], ['03', 'Rent & payment tracking', 'Review rent charges, balances and payment history.', 'fa-wallet'], ['04', 'Maintenance requests', 'Receive tenant reports and follow requests through to completion.', 'fa-screwdriver-wrench'], ['05', 'Bills & utilities', 'Keep utility readings and tenant charges accessible.', 'fa-bolt'], ['06', 'Property reports', 'Review occupancy and financial activity from your dashboard.', 'fa-chart-line']] as $service): ?>
          <article class="service-card"><span class="service-icon" aria-hidden="true"><i class="fa-solid <?php echo e($service[3]); ?>"></i></span><span class="service-number"><?php echo e($service[0]); ?></span><h3><?php echo e($service[1]); ?></h3><p><?php echo e($service[2]); ?></p></article>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <section class="how-section" id="about">
    <div class="site-container how-layout">
    <div><p class="eyebrow">A CLEARER WORKFLOW</p><h2>From available unit to a <span class="heading-accent">well-managed home</span></h2><p>Bring the essential steps of renting and property management into one connected system.</p><a class="text-link" href="<?php echo BASE_URL; ?>/public/about.php">More about the platform <span aria-hidden="true">↗</span></a></div>
      <ol class="steps-list">
        <?php foreach ([['01', 'Add a property', 'Record buildings, units and rental details.'], ['02', 'Connect tenants', 'Keep occupancy and tenant records current.'], ['03', 'Track the details', 'Follow rent, bills and maintenance activity.']] as $step): ?>
          <li><span><?php echo e($step[0]); ?></span><div><h3><?php echo e($step[1]); ?></h3><p><?php echo e($step[2]); ?></p></div></li>
        <?php endforeach; ?>
      </ol>
    </div>
  </section>

  <section class="audience-section">
    <div class="site-container">
      <div class="section-heading"><div><p class="eyebrow">DESIGNED FOR THE PEOPLE INVOLVED</p><h2>One platform, <span class="heading-accent">useful to everyone</span></h2><p>Thoughtful tools for the people who find, own and care for a home.</p></div></div>
      <div class="audience-grid">
        <article><span aria-hidden="true"><i class="fa-solid fa-building"></i></span><h3>Landlords &amp; managers</h3><p>Keep an eye on properties, occupancy, rent and reported maintenance.</p></article>
        <article><span aria-hidden="true"><i class="fa-solid fa-key"></i></span><h3>Tenants</h3><p>Access rent details, payment history, bills, notices and service requests.</p></article>
        <article><span aria-hidden="true"><i class="fa-solid fa-magnifying-glass"></i></span><h3>Home seekers</h3><p>Search available Nairobi homes and request a viewing with the management team.</p></article>
      </div>
    </div>
  </section>

  <section class="testimonial-section" aria-labelledby="testimonial-title">
    <div class="site-container">
      <div class="section-heading"><div><p class="eyebrow">A LITTLE MORE AT HOME</p><h2 id="testimonial-title">Trusted by <span class="heading-accent">Property Owners and Tenants</span></h2><p>Illustrative placeholder reviews — not verified customer testimonials.</p></div><span class="review-note"><i class="fa-solid fa-quote-left" aria-hidden="true"></i> SAMPLE REVIEWS</span></div>
      <div class="testimonial-grid">
        <article class="testimonial-card"><div class="review-stars" aria-label="5 out of 5 stars">★★★★★</div><blockquote>“Finding a place in Kasarani felt much less overwhelming. I could compare what mattered and arrange a viewing.”</blockquote><div class="reviewer"><img class="review-avatar" src="assets/testimonial-wanjiku.jpg" alt="Illustrative portrait for Wanjiku M." width="64" height="64" loading="lazy" decoding="async"><div><strong>Wanjiku M.</strong><span>Illustrative tenant</span></div></div></article>
        <article class="testimonial-card"><div class="review-stars" aria-label="5 out of 5 stars">★★★★★</div><blockquote>“Having rent, occupancy and property details together makes the day-to-day feel a little more manageable.”</blockquote><div class="reviewer"><img class="review-avatar avatar-sand" src="assets/testimonial-david.jpg" alt="Illustrative portrait for David K." width="64" height="64" loading="lazy" decoding="async"><div><strong>David K.</strong><span>Illustrative property owner</span></div></div></article>
        <article class="testimonial-card"><div class="review-stars" aria-label="5 out of 5 stars">★★★★★</div><blockquote>“A straightforward way to explore homes across Nairobi and find a neighbourhood that feels like me.”</blockquote><div class="reviewer"><img class="review-avatar avatar-blue" src="assets/testimonial-akinyi.jpg" alt="Illustrative portrait for Akinyi O." width="64" height="64" loading="lazy" decoding="async"><div><strong>Akinyi O.</strong><span>Illustrative tenant</span></div></div></article>
      </div>
    </div>
  </section>

  <section class="closing-cta">
    <div class="site-container cta-inner">
      <div><p class="eyebrow">A BETTER WAY TO STAY ORGANISED</p><h2>Ready to <span class="heading-accent">find a home</span> or manage yours?</h2><p>Explore current rentals or sign in to your property portal.</p></div>
      <div class="cta-actions"><a class="button button-light" href="<?php echo BASE_URL; ?>/public/properties.php">Explore available homes</a><a class="button button-quiet" href="<?php echo BASE_URL; ?>/public/login.php">Sign in</a></div>
    </div>
  </section>
</main>

<dialog class="viewing-dialog" id="viewing-dialog" aria-labelledby="viewing-title">
  <div class="viewing-modal">
    <button class="modal-close" type="button" data-close-viewing aria-label="Close viewing request"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
    <div class="viewing-image"><img id="viewing-image" src="<?php echo BASE_URL; ?>/assets/house4.jpg" alt=""><span class="viewing-image-label"><i class="fa-solid fa-location-dot" aria-hidden="true"></i> NAIROBI, KENYA</span></div>
    <div class="viewing-content">
      <div class="viewing-property"><span class="viewing-property-icon"><i class="fa-regular fa-calendar-check" aria-hidden="true"></i></span><div><p>VIEWING REQUEST</p><h2 id="viewing-title">Schedule a viewing</h2><span id="viewing-property-name">Choose a home to visit</span><small id="viewing-property-location"></small></div></div>
      <form id="viewing-form">
        <div class="viewing-form-grid">
          <label>Preferred date<input name="date" type="date" required></label>
          <label>Preferred time<select name="time" required><option value="">Select a time</option><option>9:00 am – 11:00 am</option><option>11:00 am – 1:00 pm</option><option>2:00 pm – 4:00 pm</option><option>4:00 pm – 6:00 pm</option></select></label>
          <label>Your name<input name="name" autocomplete="name" placeholder="e.g. Amina Wanjiku" required></label>
          <label>Phone number<input name="phone" type="tel" autocomplete="tel" placeholder="+254 7XX XXX XXX" required></label>
          <label class="viewing-full">Email address<input name="email" type="email" autocomplete="email" placeholder="you@example.com" required></label>
          <label class="viewing-full">Message <span class="optional-label">OPTIONAL</span><textarea name="message" rows="2" placeholder="Anything you'd like the property team to know?"></textarea></label>
        </div>
        <p class="approval-note"><i class="fa-solid fa-circle-info" aria-hidden="true"></i><span>Your request is not yet confirmed. A property manager must review and approve the viewing time.</span></p>
        <div class="viewing-actions"><button class="button search-button" type="submit">Request Viewing <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></button><button class="cancel-viewing" type="button" data-close-viewing>Close</button></div>
        <p class="demo-note">Homepage preview only. This form does not send or save a booking request.</p>
      </form>
      <div class="viewing-success" id="viewing-success" role="status" hidden><span class="success-icon"><i class="fa-solid fa-check" aria-hidden="true"></i></span><p class="search-overline">REQUEST PREPARED</p><h3>Your viewing request is ready.</h3><p>Your time is <strong>pending approval</strong> by the property manager. Nothing is confirmed yet.</p><button type="button" class="cancel-viewing" data-close-viewing>Done</button><small>Preview only — no request was sent.</small></div>
    </div>
  </div>
</dialog>
<?php include __DIR__ . '/includes/footer.php'; ?>
