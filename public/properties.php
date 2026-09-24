<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../includes/functions.php';

$db = db();

// Handle search filters
$location = $_GET['location'] ?? '';
$house_type = $_GET['house_type'] ?? '';
$max_rent = $_GET['max_rent'] ?? '';

$query = "SELECT p.*, l.estate, l.sub_county, MIN(u.monthly_rent) as min_rent 
          FROM properties p 
          JOIN locations l ON p.location_id = l.id 
          JOIN units u ON p.id = u.property_id 
          WHERE p.status = 'Active'";
$params = [];

if (!empty($location)) {
    $query .= " AND l.estate = ?";
    $params[] = $location;
}
if (!empty($house_type)) {
    $query .= " AND u.house_type = ?";
    $params[] = $house_type;
}
if (!empty($max_rent)) {
    $query .= " AND u.monthly_rent <= ?";
    $params[] = $max_rent;
}

$query .= " GROUP BY p.id";
$stmt = $db->prepare($query);
$stmt->execute($params);
$properties = $stmt->fetchAll();

$page_title = 'Browse Available Properties';
include __DIR__ . '/../includes/header.php';
?>

<div class="flex-1 flex flex-col bg-gray-50">
    <div class="max-w-7xl mx-auto px-4 py-8 w-full">
        <h1 class="text-3xl font-bold text-gray-900 mb-6">Available Properties in Nairobi</h1>

        <!-- Search & Filter Form -->
        <form method="GET" class="bg-white p-4 rounded-xl shadow-sm border border-gray-100 grid grid-cols-1 sm:grid-cols-4 gap-4 mb-8">
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
                <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Max Rent (KSh)</label>
                <input type="number" name="max_rent" value="<?php echo e($max_rent); ?>" placeholder="e.g. 30000" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary outline-none">
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
                            <i class="fa-solid fa-building text-4xl"></i>
                        </div>
                        <div class="p-5 flex-1 flex flex-col justify-between">
                            <div>
                                <span class="text-xs font-semibold text-accent uppercase"><?php echo e($prop['property_type']); ?></span>
                                <h3 class="font-bold text-lg text-gray-900 mt-1"><?php echo e($prop['name']); ?></h3>
                                <p class="text-sm text-gray-500 mt-1"><i class="fa-solid fa-location-dot mr-1 text-red-500"></i> <?php echo e($prop['estate'] . ', ' . $prop['sub_county']); ?></p>
                                <p class="text-sm font-semibold text-success mt-2">From <?php echo format_currency($prop['min_rent']); ?> /mo</p>
                            </div>
                            <div class="mt-6 pt-4 border-t border-gray-100 flex items-center justify-between">
                                <span class="text-xs text-gray-500"><?php echo e($prop['number_of_units']); ?> Units Total</span>
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