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

$locationStmt = $db->query('SELECT estate AS location_name FROM locations UNION SELECT sub_county AS location_name FROM locations ORDER BY location_name');
$locations = $locationStmt->fetchAll(PDO::FETCH_COLUMN);

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
$totalProperties = 0;
$properties = [];
$totalPages = 0;
$page = 1;
$resultStart = 0;
$resultEnd = 0;

if (!$filterErrors) {
    $filterSql = implode(' AND ', $where);
    $countStmt = $db->prepare("SELECT COUNT(DISTINCT p.id) FROM properties p JOIN locations l ON p.location_id = l.id JOIN units u ON p.id = u.property_id WHERE {$filterSql}");
    $countStmt->execute($params);
    $totalProperties = (int)$countStmt->fetchColumn();
    $totalPages = (int)ceil($totalProperties / $pageSize);
    $page = $totalPages > 0 ? min($requestedPage, $totalPages) : 1;
    $offset = ($page - 1) * $pageSize;

    $sortSql = [
        'price_asc' => 'min_rent ASC, p.name ASC',
        'price_desc' => 'min_rent DESC, p.name ASC',
        'name' => 'p.name ASC',
    ][$sort] ?? 'p.created_at DESC, p.name ASC';

    $query = "SELECT p.*, l.estate, l.sub_county, MIN(u.monthly_rent) AS min_rent,
                     COUNT(DISTINCT u.id) AS available_units,
                     GROUP_CONCAT(DISTINCT u.house_type ORDER BY u.house_type SEPARATOR ', ') AS house_types
              FROM properties p
              JOIN locations l ON p.location_id = l.id
              JOIN units u ON p.id = u.property_id
              WHERE {$filterSql}
              GROUP BY p.id, l.estate, l.sub_county
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
    $resultStart = $totalProperties > 0 ? $offset + 1 : 0;
    $resultEnd = min($offset + count($properties), $totalProperties);
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
$page_title = 'Explore Available Houses';
$page_description = 'Explore current rental properties and vacant units, with filters for Nairobi locations, house types, and monthly rent.';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/navbar.php';
?>

<main id="main-content" class="catalog-page">
    <div class="site-container catalog-container">
        <header class="catalog-intro">
            <p class="eyebrow">Find your next home</p>
            <h1>Explore <span class="heading-accent">available houses</span></h1>
            <p>Browse current listings and narrow your search by neighbourhood, home type, and monthly rent. Availability shown here is based on vacant units recorded in the system.</p>
        </header>

        <?php include __DIR__ . '/../includes/property-gallery.php'; ?>

        <form method="get" action="<?php echo BASE_URL; ?>/public/properties.php" class="catalog-filters" aria-label="Filter available properties">
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
                <button class="button button-primary" type="submit"><i class="fa-solid fa-sliders" aria-hidden="true"></i> Apply filters</button>
            </div>
        </form>

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
                    <h2 id="catalog-results-heading">Homes to explore</h2>
                </div>
                <?php if (!$filterErrors): ?><p class="catalog-result-count">Showing <?php echo $resultStart; ?>–<?php echo $resultEnd; ?> of <?php echo $totalProperties; ?> <?php echo $totalProperties === 1 ? 'property' : 'properties'; ?></p><?php endif; ?>
            </div>

            <?php if (!$filterErrors && !$properties): ?>
                <div class="catalog-empty-state">
                    <span class="catalog-empty-icon" aria-hidden="true"><i class="fa-solid fa-magnifying-glass"></i></span>
                    <h3>No matching homes right now</h3>
                    <p>Try a different neighbourhood or broaden the rent and house-type filters. Only active properties with vacant units appear here.</p>
                    <a class="button button-primary" href="<?php echo BASE_URL; ?>/public/properties.php">Browse all available homes</a>
                </div>
            <?php elseif (!$filterErrors): ?>
                <div class="catalog-property-grid">
                    <?php foreach ($properties as $property): ?>
                        <?php
                        $imagePath = trim((string)($property['property_image'] ?? ''), '/');
                        $imageFile = $imagePath !== '' ? dirname(__DIR__) . '/' . $imagePath : '';
                        $hasPropertyImage = $imagePath !== '' && is_file($imageFile);
                        $imageUrl = $hasPropertyImage ? BASE_URL . '/' . e($imagePath) : BASE_URL . '/' . e(sample_property_image($property['id']));
                        ?>
                        <article class="catalog-property-card">
                            <a class="catalog-property-image" href="<?php echo BASE_URL; ?>/public/property-details.php?id=<?php echo (int)$property['id']; ?>" aria-label="View details for <?php echo e($property['name']); ?>">
                                <img src="<?php echo $imageUrl; ?>" alt="<?php echo $hasPropertyImage ? 'Property image for ' . e($property['name']) : 'Illustrative residential image; an actual property photo was not provided'; ?>" width="840" height="560" loading="lazy" decoding="async" onerror="this.onerror=null;this.src='<?php echo BASE_URL; ?>/assets/modern-residential-building.webp';this.alt='Illustrative residential image; an actual property photo was not provided';">
                                <span class="catalog-availability"><i aria-hidden="true"></i> Available</span>
                            </a>
                            <div class="catalog-property-body">
                                <p class="catalog-property-type"><?php echo e($property['property_type']); ?></p>
                                <h3><a href="<?php echo BASE_URL; ?>/public/property-details.php?id=<?php echo (int)$property['id']; ?>"><?php echo e($property['name']); ?></a></h3>
                                <p class="catalog-property-location"><i class="fa-solid fa-location-dot" aria-hidden="true"></i> <?php echo e($property['estate'] . ', ' . $property['sub_county']); ?></p>
                                <div class="catalog-property-facts">
                                    <span><i class="fa-solid fa-door-open" aria-hidden="true"></i> <?php echo e($property['house_types']); ?></span>
                                    <span><i class="fa-solid fa-key" aria-hidden="true"></i> <?php echo (int)$property['available_units']; ?> vacant <?php echo (int)$property['available_units'] === 1 ? 'unit' : 'units'; ?></span>
                                </div>
                                <?php if (trim((string)($property['amenities'] ?? '')) !== ''): ?>
                                    <p class="catalog-property-amenities"><i class="fa-solid fa-star" aria-hidden="true"></i> <?php echo e($property['amenities']); ?></p>
                                <?php endif; ?>
                                <div class="catalog-property-footer">
                                    <p><strong><?php echo format_currency($property['min_rent']); ?></strong><span>From / month</span></p>
                                    <a class="catalog-details-link" href="<?php echo BASE_URL; ?>/public/property-details.php?id=<?php echo (int)$property['id']; ?>">View details <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a>
                                </div>
                                <a class="catalog-viewing-link" href="<?php echo BASE_URL; ?>/public/property-details.php?id=<?php echo (int)$property['id']; ?>#request-viewing">Schedule a viewing</a>
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
    </div>
</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>
