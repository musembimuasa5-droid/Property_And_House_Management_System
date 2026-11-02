<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../includes/functions.php';

$db = db();
$filterErrors = [];
$readFilter = static function ($key) {
    return isset($_GET[$key]) && is_string($_GET[$key]) ? trim($_GET[$key]) : '';
};

$location = $readFilter('location');
$house_type = $readFilter('house_type');
$property_type = $readFilter('type');
$min_rent = $readFilter('min_rent');
$max_rent = $readFilter('max_rent');
$bedrooms = $readFilter('bedrooms');
$sort = $readFilter('sort');

if (strlen($location) > 100 || preg_match('/[\x00-\x1F\x7F]/', $location)) {
    $filterErrors[] = 'Enter a valid neighbourhood or estate.';
}
if ($property_type !== '' && !in_array($property_type, PROPERTY_TYPES, true)) {
    $filterErrors[] = 'Choose a property type from the list.';
}
if ($house_type !== '' && !in_array($house_type, UNIT_TYPES, true)) {
    $filterErrors[] = 'Choose a house type from the list.';
}
if ($bedrooms !== '' && !in_array($bedrooms, ['0', '1', '2', '3', '4'], true)) {
    $filterErrors[] = 'Choose a valid bedroom count.';
}
if ($sort !== '' && !in_array($sort, ['price_asc', 'price_desc', 'name'], true)) {
    $filterErrors[] = 'Choose a valid sort order.';
}
$validRent = static function ($value) {
    return $value === '' || (bool)preg_match('/^\d{1,9}(?:\.\d{1,2})?$/', $value);
};
if (!$validRent($min_rent) || !$validRent($max_rent)) {
    $filterErrors[] = 'Enter monthly rent as a non-negative amount with up to two decimal places.';
}
if ($min_rent !== '' && $max_rent !== '' && $validRent($min_rent) && $validRent($max_rent) && (float)$min_rent > (float)$max_rent) {
    $filterErrors[] = 'The minimum rent cannot be greater than the maximum rent.';
}

$locationStmt = $db->query("SELECT DISTINCT location_name FROM (
    SELECT l.estate AS location_name FROM locations l
    JOIN properties p ON p.location_id = l.id
    JOIN units u ON u.property_id = p.id AND u.status = 'Vacant'
    WHERE p.status = 'Active'
    UNION
    SELECT l.sub_county AS location_name FROM locations l
    JOIN properties p ON p.location_id = l.id
    JOIN units u ON u.property_id = p.id AND u.status = 'Vacant'
    WHERE p.status = 'Active'
) supported_locations ORDER BY location_name");
$locations = $locationStmt->fetchAll(PDO::FETCH_COLUMN);
$browseLocations = $db->query("SELECT l.estate, COUNT(DISTINCT p.id) AS property_count, COUNT(u.id) AS unit_count
    FROM locations l
    JOIN properties p ON p.location_id = l.id AND p.status = 'Active'
    JOIN units u ON u.property_id = p.id AND u.status = 'Vacant'
    GROUP BY l.estate
    ORDER BY unit_count DESC, l.estate ASC
    LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);

$where = ["p.status = 'Active'", "u.status = 'Vacant'"];
$params = [];
if ($location !== '') {
    $where[] = '(l.estate = ? OR l.sub_county = ?)';
    $params[] = $location;
    $params[] = $location;
}
if ($house_type !== '') {
    $where[] = 'u.house_type = ?';
    $params[] = $house_type;
}
if ($property_type !== '') {
    $where[] = 'p.property_type = ?';
    $params[] = $property_type;
}
if ($min_rent !== '') {
    $where[] = 'u.monthly_rent >= ?';
    $params[] = $min_rent;
}
if ($max_rent !== '') {
    $where[] = 'u.monthly_rent <= ?';
    $params[] = $max_rent;
}
if ($bedrooms !== '') {
    if ($bedrooms === '0') {
        $where[] = "u.house_type IN ('Bedsitter', 'Studio')";
    } else {
        $where[] = 'u.bedrooms >= ?';
        $params[] = (int)$bedrooms;
    }
}

$pageSize = 9;
$requestedPage = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;
$totalUnits = 0;
$totalMatchingProperties = 0;
$availableUnitInventory = (int)$db->query("SELECT COUNT(*) FROM properties p JOIN units u ON u.property_id = p.id WHERE p.status = 'Active' AND u.status = 'Vacant'")->fetchColumn();
$properties = [];
$totalPages = 0;
$page = 1;
$resultStart = 0;
$resultEnd = 0;

if (!$filterErrors) {
    $filterSql = implode(' AND ', $where);
    $countStmt = $db->prepare("SELECT COUNT(DISTINCT u.id) FROM properties p JOIN locations l ON p.location_id = l.id JOIN units u ON p.id = u.property_id WHERE {$filterSql}");
    $countStmt->execute($params);
    $totalUnits = (int)$countStmt->fetchColumn();
    $propertyCountStmt = $db->prepare("SELECT COUNT(DISTINCT p.id) FROM properties p JOIN locations l ON p.location_id = l.id JOIN units u ON p.id = u.property_id WHERE {$filterSql}");
    $propertyCountStmt->execute($params);
    $totalMatchingProperties = (int)$propertyCountStmt->fetchColumn();
    $totalPages = (int)ceil($totalUnits / $pageSize);
    $page = $totalPages > 0 ? min($requestedPage, $totalPages) : 1;
    $offset = ($page - 1) * $pageSize;

    $sortSql = [
        'price_asc' => 'u.monthly_rent ASC, p.name ASC, u.unit_number ASC',
        'price_desc' => 'u.monthly_rent DESC, p.name ASC, u.unit_number ASC',
        'name' => 'p.name ASC, u.house_type ASC, u.unit_number ASC',
    ][$sort] ?? 'p.created_at DESC, p.name ASC, u.unit_number ASC';

    $query = "SELECT DISTINCT u.id AS unit_id, p.*, l.estate, l.sub_county,
                     u.unit_number, u.house_type, u.bedrooms, u.bathrooms,
                     u.monthly_rent, u.deposit, u.status AS unit_status, u.description AS unit_description
              FROM properties p
              JOIN locations l ON p.location_id = l.id
              JOIN units u ON p.id = u.property_id
              WHERE {$filterSql}
              ORDER BY {$sortSql}
              LIMIT ? OFFSET ?";
    $stmt = $db->prepare($query);
    foreach ($params as $index => $value) {
        $stmt->bindValue($index + 1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->bindValue(count($params) + 1, $pageSize, PDO::PARAM_INT);
    $stmt->bindValue(count($params) + 2, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $properties = $stmt->fetchAll();
    $resultStart = $totalUnits > 0 ? $offset + 1 : 0;
    $resultEnd = min($offset + count($properties), $totalUnits);
}

$queryFilters = array_filter([
    'location' => $location,
    'type' => $property_type,
    'house_type' => $house_type,
    'min_rent' => $min_rent,
    'max_rent' => $max_rent,
    'bedrooms' => $bedrooms,
    'sort' => $sort,
], static function ($value) {
    return $value !== '';
});
$activeFilters = [];
if ($location !== '') $activeFilters[] = ['Neighbourhood', $location];
if ($property_type !== '') $activeFilters[] = ['Property', $property_type];
if ($house_type !== '') $activeFilters[] = ['Home type', $house_type];
if ($min_rent !== '') $activeFilters[] = ['Minimum rent', 'KSh ' . number_format((float)$min_rent)];
if ($max_rent !== '') $activeFilters[] = ['Maximum rent', 'KSh ' . number_format((float)$max_rent)];
if ($bedrooms !== '') $activeFilters[] = ['Bedrooms', $bedrooms === '0' ? 'Bedsitter / studio' : $bedrooms . '+'];
$page_title = 'Explore Available Houses';
$page_description = 'Explore individually listed vacant rental units across active Nairobi properties, with filters for location, home type, bedrooms, and monthly rent.';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/navbar.php';
?>

<main id="main-content" class="catalog-page">
    <div class="site-container catalog-container">
        <header class="catalog-intro">
            <p class="eyebrow"><i class="fa-solid fa-location-dot" aria-hidden="true"></i> Nairobi rentals, made easier</p>
            <h1>Find Your <span class="heading-accent">Perfect Home</span></h1>
            <p>Explore real property records, compare current rent and unit details, and request a viewing directly with the management team.</p>
        </header>

        <form method="get" action="<?php echo BASE_URL; ?>/public/properties.php" class="catalog-filters" aria-label="Filter available properties">
            <div class="catalog-filter-heading">
                <div><span class="catalog-filter-icon" aria-hidden="true"><i class="fa-solid fa-sliders"></i></span><div><h2>Search homes</h2><p>Choose the details that matter to you.</p></div></div>
                <?php if ($activeFilters): ?><span class="catalog-active-count"><?php echo count($activeFilters); ?> active <?php echo count($activeFilters) === 1 ? 'filter' : 'filters'; ?></span><?php endif; ?>
            </div>
            <div class="catalog-filter-grid">
                <label>
                    <span>Neighbourhood or estate</span>
                    <select name="location">
                        <option value="">All locations</option>
                        <?php if ($location !== '' && !in_array($location, $locations, true)): ?>
                            <option value="<?php echo e($location); ?>" selected><?php echo e($location); ?></option>
                        <?php endif; ?>
                        <?php foreach ($locations as $locationOption): ?>
                            <option value="<?php echo e($locationOption); ?>" <?php echo $location === $locationOption ? 'selected' : ''; ?>><?php echo e($locationOption); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <span>Property type</span>
                    <select name="type">
                        <option value="">Any property type</option>
                        <?php foreach (PROPERTY_TYPES as $typeOption): ?>
                            <option value="<?php echo e($typeOption); ?>" <?php echo $property_type === $typeOption ? 'selected' : ''; ?>><?php echo e($typeOption); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <span>House type</span>
                    <select name="house_type">
                        <option value="">Any house type</option>
                        <?php foreach (UNIT_TYPES as $unitType): ?>
                            <option value="<?php echo e($unitType); ?>" <?php echo $house_type === $unitType ? 'selected' : ''; ?>><?php echo e($unitType); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <span>Minimum rent (KSh/month)</span>
                    <input type="number" name="min_rent" min="0" step="0.01" value="<?php echo e($min_rent); ?>" placeholder="No minimum">
                </label>
                <label>
                    <span>Maximum rent (KSh/month)</span>
                    <input type="number" name="max_rent" min="0" step="0.01" value="<?php echo e($max_rent); ?>" placeholder="No maximum">
                </label>
                <label>
                    <span>Bedrooms</span>
                    <select name="bedrooms">
                        <option value="">Any number</option>
                        <?php foreach ([0, 1, 2, 3, 4] as $bedroomCount): ?>
                            <option value="<?php echo $bedroomCount; ?>" <?php echo $bedrooms === (string)$bedroomCount ? 'selected' : ''; ?>><?php echo $bedroomCount === 0 ? 'Bedsitter or studio' : $bedroomCount . '+'; ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <span>Sort by</span>
                    <select name="sort">
                        <option value="">Recently added</option>
                        <option value="price_asc" <?php echo $sort === 'price_asc' ? 'selected' : ''; ?>>Price: low to high</option>
                        <option value="price_desc" <?php echo $sort === 'price_desc' ? 'selected' : ''; ?>>Price: high to low</option>
                        <option value="name" <?php echo $sort === 'name' ? 'selected' : ''; ?>>Property name</option>
                    </select>
                </label>
            </div>
            <div class="catalog-filter-actions">
                <a class="catalog-clear-link" href="<?php echo BASE_URL; ?>/public/properties.php">Clear all filters</a>
                <button class="button button-primary" type="submit"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i> Search homes</button>
            </div>
        </form>

        <?php if ($activeFilters): ?>
            <div class="catalog-active-filters" aria-label="Active filters">
                <span>Filtered by</span>
                <?php foreach ($activeFilters as [$filterLabel, $filterValue]): ?><span class="catalog-filter-chip"><strong><?php echo e($filterLabel); ?>:</strong> <?php echo e($filterValue); ?></span><?php endforeach; ?>
                <a href="<?php echo BASE_URL; ?>/public/properties.php">Reset</a>
            </div>
        <?php endif; ?>

        <?php if ($browseLocations): ?>
            <nav class="catalog-location-browse" aria-label="Browse available homes by neighbourhood">
                <div><p class="eyebrow">Explore Nairobi</p><h2>Browse by neighbourhood</h2></div>
                <div class="catalog-location-links">
                    <?php foreach ($browseLocations as $browseLocation): ?>
                        <a href="<?php echo BASE_URL; ?>/public/properties.php?<?php echo e(http_build_query(['location' => $browseLocation['estate']])); ?>">
                            <?php echo e($browseLocation['estate']); ?><span><?php echo (int)$browseLocation['unit_count']; ?> <?php echo (int)$browseLocation['unit_count'] === 1 ? 'unit' : 'units'; ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </nav>
        <?php endif; ?>

        <?php if ($filterErrors): ?>
            <div class="catalog-message catalog-message-error" role="alert">
                <strong>Check your filters</strong>
                <ul><?php foreach ($filterErrors as $error): ?><li><?php echo e($error); ?></li><?php endforeach; ?></ul>
            </div>
        <?php endif; ?>

        <section class="catalog-results" aria-labelledby="catalog-results-heading">
            <div class="catalog-results-heading">
                <div>
                    <p class="eyebrow">The listings</p>
                    <h2 id="catalog-results-heading">Available rental units</h2>
                </div>
                <?php if (!$filterErrors): ?><p class="catalog-result-count">Showing <?php echo $resultStart; ?>–<?php echo $resultEnd; ?> of <?php echo $totalUnits; ?> available <?php echo $totalUnits === 1 ? 'unit' : 'units'; ?> across <?php echo $totalMatchingProperties; ?> <?php echo $totalMatchingProperties === 1 ? 'property' : 'properties'; ?></p><?php endif; ?>
            </div>

            <?php if (!$filterErrors && !$properties): ?>
                <div class="catalog-empty-state">
                    <span class="catalog-empty-icon" aria-hidden="true"><i class="fa-solid fa-magnifying-glass"></i></span>
                    <?php if ($availableUnitInventory === 0): ?>
                        <h3>No vacant rental units right now</h3>
                        <p>There are currently no units recorded as vacant in active properties. Please check back later or contact our team for help with your search.</p>
                        <a class="button button-primary" href="<?php echo BASE_URL; ?>/public/contact.php?subject=<?php echo rawurlencode('Property enquiry'); ?>">Contact our team</a>
                    <?php else: ?>
                        <h3>No matching available units</h3>
                        <p>There are <?php echo $availableUnitInventory; ?> vacant <?php echo $availableUnitInventory === 1 ? 'unit' : 'units'; ?> in the system, but none match these filters. Try another neighbourhood or broaden your rent and home-type selections.</p>
                    <a class="button button-primary" href="<?php echo BASE_URL; ?>/public/properties.php">Browse all available homes</a>
                    <?php endif; ?>
                </div>
            <?php elseif (!$filterErrors): ?>
                <div class="catalog-property-grid">
                    <?php foreach ($properties as $property): ?>
                        <?php
                        $imagePath = trim((string)($property['property_image'] ?? ''), '/');
                        $imageFile = $imagePath !== '' ? dirname(__DIR__) . '/' . $imagePath : '';
                        $hasPropertyImage = $imagePath !== '' && is_file($imageFile);
                        $imageUrl = $hasPropertyImage ? BASE_URL . '/' . e($imagePath) : BASE_URL . '/' . e(sample_property_image($property['id']));
                        $detailsUrl = BASE_URL . '/public/property-details.php?' . http_build_query(['id' => (int)$property['id'], 'unit' => (int)$property['unit_id']]);
                        ?>
                        <article class="catalog-property-card" data-unit-id="<?php echo (int)$property['unit_id']; ?>">
                            <a class="catalog-property-image" href="<?php echo e($detailsUrl); ?>" aria-label="View unit <?php echo e($property['unit_number']); ?> details at <?php echo e($property['name']); ?>">
                                <img src="<?php echo $imageUrl; ?>" alt="<?php echo $hasPropertyImage ? 'Property image for ' . e($property['name']) : 'Illustrative residential image; an actual property photo was not provided'; ?>" width="840" height="560" loading="lazy" decoding="async" onerror="this.onerror=null;this.src='<?php echo BASE_URL; ?>/assets/modern-residential-building.webp';this.alt='Illustrative residential image; an actual property photo was not provided';">
                                <span class="catalog-availability"><i aria-hidden="true"></i>Vacant now</span>
                            </a>
                            <div class="catalog-property-body">
                                <p class="catalog-property-type"><?php echo e($property['property_type']); ?></p>
                                <h3><a href="<?php echo e($detailsUrl); ?>"><?php echo e($property['name']); ?></a></h3>
                                <p class="catalog-property-location"><i class="fa-solid fa-location-dot" aria-hidden="true"></i> <?php echo e($property['estate'] . ', ' . $property['sub_county']); ?></p>
                                <div class="catalog-property-facts">
                                    <span><i class="fa-solid fa-door-open" aria-hidden="true"></i> Unit <?php echo e($property['unit_number']); ?> · <?php echo e($property['house_type']); ?></span>
                                    <?php if ($property['bedrooms'] !== null): ?><span><i class="fa-solid fa-bed" aria-hidden="true"></i> <?php echo (int)$property['bedrooms']; ?> <?php echo (int)$property['bedrooms'] === 1 ? 'bedroom' : 'bedrooms'; ?></span><?php endif; ?>
                                    <?php if ($property['bathrooms'] !== null): ?><span><i class="fa-solid fa-bath" aria-hidden="true"></i> <?php echo (int)$property['bathrooms']; ?> <?php echo (int)$property['bathrooms'] === 1 ? 'bathroom' : 'bathrooms'; ?></span><?php endif; ?>
                                    <span><i class="fa-solid fa-circle-check" aria-hidden="true"></i> <?php echo e($property['unit_status']); ?></span>
                                </div>
                                <?php
                                $cardDescription = trim((string)($property['unit_description'] ?? '')) !== ''
                                    ? $property['unit_description']
                                    : ($property['description'] ?? '');
                                if (trim((string)$cardDescription) !== ''):
                                ?>
                                    <p class="catalog-property-description"><?php echo e(function_exists('mb_strimwidth') ? mb_strimwidth(strip_tags($cardDescription), 0, 130, '…', 'UTF-8') : substr(strip_tags($cardDescription), 0, 127) . '...'); ?></p>
                                <?php endif; ?>
                                <?php if (trim((string)($property['amenities'] ?? '')) !== ''): ?>
                                    <p class="catalog-property-amenities"><i class="fa-solid fa-star" aria-hidden="true"></i> <?php echo e($property['amenities']); ?></p>
                                <?php endif; ?>
                                <div class="catalog-property-footer">
                                    <p><strong><?php echo format_currency($property['monthly_rent']); ?></strong><span>Per month</span></p>
                                    <a class="catalog-details-link" href="<?php echo e($detailsUrl); ?>">View details <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a>
                                </div>
                                <a class="catalog-viewing-link" href="<?php echo e($detailsUrl); ?>#request-viewing">Schedule a viewing</a>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
                <?php if ($totalPages > 1): ?>
                    <nav class="catalog-pagination" aria-label="Property results pages">
                        <?php if ($page > 1): ?>
                            <?php $previousQuery = $queryFilters; $previousQuery['page'] = $page - 1; ?>
                            <a href="<?php echo BASE_URL; ?>/public/properties.php?<?php echo e(http_build_query($previousQuery)); ?>" aria-label="Previous page"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Previous</a>
                        <?php endif; ?>
                        <span>Page <?php echo $page; ?> of <?php echo $totalPages; ?></span>
                        <?php if ($page < $totalPages): ?>
                            <?php $nextQuery = $queryFilters; $nextQuery['page'] = $page + 1; ?>
                            <a href="<?php echo BASE_URL; ?>/public/properties.php?<?php echo e(http_build_query($nextQuery)); ?>" aria-label="Next page">Next <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a>
                        <?php endif; ?>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        </section>

        <section class="catalog-trust-section" aria-labelledby="catalog-trust-heading">
            <div><p class="eyebrow">A clearer way to find home</p><h2 id="catalog-trust-heading">Search with confidence</h2></div>
            <div class="catalog-trust-grid">
                <article><span aria-hidden="true"><i class="fa-solid fa-clipboard-check"></i></span><h3>Recorded property details</h3><p>Browse descriptions, locations and unit information entered in the system.</p></article>
                <article><span aria-hidden="true"><i class="fa-solid fa-magnifying-glass"></i></span><h3>Convenient search</h3><p>Filter by neighbourhood, home type, bedrooms and monthly rent.</p></article>
                <article><span aria-hidden="true"><i class="fa-regular fa-calendar-check"></i></span><h3>Easy viewing requests</h3><p>Choose an available unit and send a preferred date to management.</p></article>
            </div>
        </section>

        <?php include __DIR__ . '/../includes/property-gallery.php'; ?>

        <section class="catalog-cta">
            <div><p class="eyebrow">Your next step</p><h2>Found a home worth seeing?</h2><p>Explore a listing’s current unit details and request a viewing when a vacancy is available.</p></div>
            <a class="button button-primary" href="#catalog-results-heading">Explore the listings <i class="fa-solid fa-arrow-up" aria-hidden="true"></i></a>
        </section>
    </div>
</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>
