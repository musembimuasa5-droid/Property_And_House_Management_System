<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

$db = db();
$properties = $db->query("SELECT p.*, l.estate, l.sub_county FROM properties p JOIN locations l ON p.location_id = l.id WHERE p.status = 'Active' LIMIT 6")->fetchAll();

$page_title = 'Welcome to Nairobi Property Manager';
include __DIR__ . '/../includes/header.php';
?>

<div class="flex-1 flex flex-col">
    <!-- Hero Section -->
    <div class="bg-primary text-white py-20 px-4 text-center">
        <div class="max-w-3xl mx-auto space-y-4">
            <h1 class="text-4xl sm:text-5xl font-extrabold tracking-tight">Find Your Next Home in Nairobi</h1>
            <p class="text-lg text-blue-100">Explore verified apartments, bedsitters, and houses across Kasarani, Westlands, Roysambu, and more.</p>
            <div class="pt-4">
                <a href="<?php echo BASE_URL; ?>/public/properties.php" class="bg-white text-primary font-bold px-8 py-3 rounded-lg shadow hover:bg-blue-50 transition">
                    Browse Vacant Houses
                </a>
            </div>
        </div>
    </div>

    <!-- Featured Properties Grid -->
    <div class="max-w-7xl mx-auto px-4 py-12 flex-1 w-full">
        <h2 class="text-2xl font-bold text-gray-900 mb-6">Featured Properties</h2>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
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
                        </div>
                        <div class="mt-6 pt-4 border-t border-gray-100 flex items-center justify-between">
                            <span class="text-xs text-gray-500">Units Available</span>
                            <a href="<?php echo BASE_URL; ?>/public/property-details.php?id=<?php echo $prop['id']; ?>" class="text-sm bg-primary text-white px-4 py-2 rounded-lg font-medium hover:bg-blue-800 transition">
                                View Details
                            </a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>