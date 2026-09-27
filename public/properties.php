<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../includes/functions.php';

$db = db();

// Handle search filters
$location = isset($_GET['location']) && is_string($_GET['location']) ? trim($_GET['location']) : '';
$house_type = $_GET['house_type'] ?? '';
$property_type = $_GET['type'] ?? '';
$max_rent = $_GET['max_rent'] ?? '';
$min_rent = $_GET['min_rent'] ?? '';
$bedrooms = $_GET['bedrooms'] ?? '';
$property_type = in_array($property_type, PROPERTY_TYPES, true) ? $property_type : '';
$house_type = in_array($house_type, UNIT_TYPES, true) ? $house_type : '';

$query = "SELECT p.*, l.estate, l.sub_county, MIN(u.monthly_rent) as min_rent, COUNT(u.id) AS available_units
          FROM properties p
          JOIN locations l ON p.location_id = l.id
          JOIN units u ON p.id = u.property_id
          WHERE p.status = 'Active' AND u.status = 'Vacant'";
$params = [];

if (!empty($location)) {
    $query .= " AND (l.estate = ? OR l.sub_county = ?)";
    $params[] = $location;
    $params[] = $location;
}
if (!empty($house_type)) {
    $query .= " AND u.house_type = ?";
    $params[] = $house_type;
}
if (!empty($max_rent)) {
    if (is_numeric($max_rent) && $max_rent >= 0) { $query .= " AND u.monthly_rent <= ?"; $params[] = (float)$max_rent; }
}
if (!empty($min_rent) && is_numeric($min_rent) && $min_rent >= 0) {
    $query .= " AND u.monthly_rent >= ?";
    $params[] = (float)$min_rent;
}
if (!empty($property_type)) {
    $query .= " AND p.property_type = ?";
    $params[] = $property_type;
}
if ($bedrooms !== '' && ctype_digit((string)$bedrooms)) {
    if ((int)$bedrooms === 0) {
        $query .= " AND u.house_type IN ('Bedsitter', 'Studio')";
    } else {
        $query .= " AND u.bedrooms >= ?";
        $params[] = (int)$bedrooms;
    }
}

$query .= " GROUP BY p.id, l.estate, l.sub_county";
$stmt = $db->prepare($query);
$stmt->execute($params);
$properties = $stmt->fetchAll();

$page_title = 'Browse Available Properties';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/navbar.php';
?>

<div class="flex-1 flex flex-col bg-gray-50">
    <div class="max-w-7xl mx-auto px-4 py-8 w-full">
        <h1 class="text-3xl font-bold text-gray-900 mb-6">Available Properties in Nairobi</h1>

        <?php include __DIR__ . '/../includes/property-gallery.php'; ?>

        <!-- Search & Filter Form -->
        <form method="GET" class="bg-white p-4 rounded-xl shadow-sm border border-gray-100 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-4 mb-8">
            <div>
                <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Property type</label>
                <select name="type" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"><option value="">Any property</option><?php foreach (PROPERTY_TYPES as $pt): ?><option value="<?php echo e($pt); ?>" <?php echo $property_type === $pt ? 'selected' : ''; ?>><?php echo e($pt); ?></option><?php endforeach; ?></select>
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Estate / Location</label>
                <select name="location" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary outline-none">
                    <option value="">All Locations</option>
                    <?php foreach (NAIROBI_ESTATES as $est): ?>
                        <option value="<?php echo $est; ?>" <?php echo $location === $est ? 'selected' : ''; ?>><?php echo $est; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">House Type</label>
                <select name="house_type" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary outline-none">
                    <option value="">All Types</option>
                    <?php foreach (UNIT_TYPES as $ut): ?>
                        <option value="<?php echo $ut; ?>" <?php echo $house_type === $ut ? 'selected' : ''; ?>><?php echo $ut; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Rent range (KSh)</label>
                <div class="flex gap-2"><input type="number" name="min_rent" min="0" value="<?php echo e($min_rent); ?>" placeholder="Min" class="min-w-0 w-1/2 px-3 py-2 border rounded-lg text-sm"><input type="number" name="max_rent" min="0" value="<?php echo e($max_rent); ?>" placeholder="Max" class="min-w-0 w-1/2 px-3 py-2 border rounded-lg text-sm"></div>
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Bedrooms</label><select name="bedrooms" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"><option value="">Any</option><?php foreach ([0,1,2,3,4] as $n): ?><option value="<?php echo $n; ?>" <?php echo (string)$bedrooms === (string)$n ? 'selected' : ''; ?>><?php echo $n ? $n . '+' : 'Bedsitter / studio'; ?></option><?php endforeach; ?></select>
            </div>
            <div class="flex items-end">
                <button type="submit" class="w-full bg-primary hover:bg-blue-800 text-white font-semibold py-2 px-4 rounded-lg text-sm transition">
                    Filter Properties
                </button>
            </div>
        </form>

        <!-- Properties Grid -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
            <?php if (empty($properties)): ?>
                <div class="col-span-3 bg-white p-8 rounded-xl text-center border border-gray-100">
                    <p class="text-gray-500">No properties found matching your search criteria.</p>
                </div>
            <?php else: ?>
                <?php foreach ($properties as $prop): ?>
                    <div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden flex flex-col">
                        <div class="h-48 bg-gray-200 flex items-center justify-center text-gray-400">
                            <?php $imagePath = trim((string)($prop['property_image'] ?? ''), '/'); $imageFile = $imagePath !== '' ? dirname(__DIR__) . '/' . $imagePath : ''; ?>
                            <?php if ($imagePath !== '' && is_file($imageFile)): ?><img class="w-full h-full object-cover" src="<?php echo BASE_URL . '/' . e($imagePath); ?>" alt="Exterior of <?php echo e($prop['name']); ?>" loading="lazy"><?php else: ?><img class="w-full h-full object-cover" src="<?php echo BASE_URL . '/' . e(sample_property_image($prop['id'])); ?>" alt="Sample home image; actual property photo not provided" loading="lazy"><?php endif; ?>
                        </div>
                        <div class="p-5 flex-1 flex flex-col justify-between">
                            <div>
                                <span class="text-xs font-semibold text-accent uppercase"><?php echo e($prop['property_type']); ?></span>
                                <h3 class="font-bold text-lg text-gray-900 mt-1"><?php echo e($prop['name']); ?></h3>
                                <p class="text-sm text-gray-500 mt-1"><i class="fa-solid fa-location-dot mr-1 text-red-500"></i> <?php echo e($prop['estate'] . ', ' . $prop['sub_county']); ?></p>
                                <p class="text-sm font-semibold text-success mt-2">From <?php echo format_currency($prop['min_rent']); ?> /mo</p>
                            </div>
                            <div class="mt-6 pt-4 border-t border-gray-100 flex items-center justify-between">
                                <span class="text-xs text-gray-500"><?php echo (int)$prop['available_units']; ?> Vacant Units</span>
                                <a href="<?php echo BASE_URL; ?>/public/property-details.php?id=<?php echo $prop['id']; ?>" class="text-sm bg-primary text-white px-4 py-2 rounded-lg font-medium hover:bg-blue-800 transition">
                                    View Details
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
