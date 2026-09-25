<?php
// Enable error visibility to troubleshoot 500 errors immediately
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$properties = [];$error_message = '';

try {
    // Safely require configuration and database scripts
    require_once __DIR__ . '/../config/config.php';
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../includes/functions.php';
    
    $db = db();
    if ($db) {
        $stmt =$db->query("SELECT p.*, l.estate, l.sub_county FROM properties p JOIN locations l ON p.location_id = l.id WHERE p.status = 'Active' LIMIT 6");
        if ($stmt) {
            $properties =$stmt->fetchAll();
        }
    }
} catch (Exception $e) {
    // Capture any database connection or query crash
    $error_message =$e->getMessage();
}

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
                <a href="<?php echo defined('BASE_URL') ? BASE_URL : ''; ?>/public/properties.php" class="bg-white text-primary font-bold px-8 py-3 rounded-lg shadow hover:bg-blue-50 transition">
                    Browse Vacant Houses
                </a>
            </div>
        </div>
    </div>

    <!-- Troubleshooting Error Banner (If database or config fails) -->
    <?php if (!empty($error_message)): ?>
        <div class="max-w-7xl mx-auto px-4 mt-6 w-full">
            <div class="bg-red-50 border border-red-200 text-red-800 p-4 rounded-xl shadow-sm">
                <p class="font-bold"><i class="fa-solid fa-triangle-exclamation mr-2"></i> Application Startup / Database Error:</p>
                <p class="text-sm mt-1 font-mono bg-red-100 p-2 rounded"><?php echo htmlspecialchars($error_message); ?></p>
                <p class="text-xs text-red-600 mt-2">Tip: Check your `config/database.php` settings and make sure your database schema is imported in phpMyAdmin.</p>
            </div>
        </div>
    <?php endif; ?>

    <!-- Featured Properties Grid -->
    <div class="max-w-7xl mx-auto px-4 py-12 flex-1 w-full">
        <h2 class="text-2xl font-bold text-gray-900 mb-6">Featured Properties</h2>
        
        <?php if (empty($properties)): ?>
            <!-- Fallback state if no properties are found or DB is pending setup -->
            <div class="bg-blue-50 border border-blue-200 text-blue-800 p-6 rounded-xl text-center">
                <p class="font-medium">No active properties found at the moment.</p>
                <p class="text-sm text-blue-600 mt-1">Please ensure your database tables and seed rows are successfully created.</p>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                <?php foreach ($properties as$prop): ?>
                    <div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden flex flex-col">
                        <div class="h-48 bg-gray-200 flex items-center justify-center text-gray-400">
                            <i class="fa-solid fa-building text-4xl"></i>
                        </div>
                        <div class="p-5 flex-1 flex flex-col justify-between">
                            <div>
                                <span class="text-xs font-semibold text-accent uppercase"><?php echo htmlspecialchars($prop['property_type'] ?? ''); ?></span>
                                <h3 class="font-bold text-lg text-gray-900 mt-1"><?php echo htmlspecialchars($prop['name'] ?? ''); ?></h3>
                                <p class="text-sm text-gray-500 mt-1"><i class="fa-solid fa-location-dot mr-1 text-red-500"></i> <?php echo htmlspecialchars(($prop['estate'] ?? '') . ', ' . ($prop['sub_county'] ?? '')); ?></p>
                            </div>
                            <div class="mt-6 pt-4 border-t border-gray-100 flex items-center justify-between">
                                <span class="text-xs text-gray-500">Units Available</span>
                                <a href="<?php echo defined('BASE_URL') ? BASE_URL : ''; ?>/public/property-details.php?id=<?php echo $prop['id']; ?>" class="text-sm bg-primary text-white px-4 py-2 rounded-lg font-medium hover:bg-blue-800 transition">
                                    View Details
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>