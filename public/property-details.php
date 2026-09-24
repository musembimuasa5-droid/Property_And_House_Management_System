<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

$db = db();
$property_id = $_GET['id'] ?? 0;

$stmt = $db->prepare("SELECT p.*, l.estate, l.sub_county FROM properties p JOIN locations l ON p.location_id = l.id WHERE p.id = ?");
$stmt->execute([$property_id]);
$property = $stmt->fetch();

if (!$property) {
    redirect('public/properties.php');
}

// Fetch units
$units_stmt = $db->prepare("SELECT * FROM units WHERE property_id = ? AND status = 'Vacant'");
$units_stmt->execute([$property_id]);
$units = $units_stmt->fetchAll();

$success_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['visitor_name'] ?? '');
    $phone = trim($_POST['visitor_phone'] ?? '');
    $email = trim($_POST['visitor_email'] ?? '');
    $date = $_POST['preferred_date'] ?? '';
    $time = $_POST['preferred_time'] ?? '';
    $message = trim($_POST['message'] ?? '');

    if (!empty($name) && !empty($phone) && !empty($date) && !empty($time)) {
        $req = $db->prepare("INSERT INTO viewing_requests (property_id, visitor_name, visitor_phone, visitor_email, preferred_date, preferred_time, message) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $req->execute([$property_id, $name, $phone, $email, $date, $time, $message]);
        $success_msg = 'Viewing request submitted successfully! Management will contact you.';
    }
}

$page_title = $property['name'];
include __DIR__ . '/../includes/header.php';
?>

<div class="flex-1 flex flex-col bg-gray-50">
    <div class="max-w-7xl mx-auto px-4 py-8 w-full grid grid-cols-1 lg:grid-cols-3 gap-8">
        <!-- Left: Property Details & Units -->
        <div class="lg:col-span-2 space-y-6">
            <div class="bg-white p-6 rounded-xl border border-gray-100 shadow-sm">
                <span class="text-xs font-semibold text-accent uppercase"><?php echo e($property['property_type']); ?></span>
                <h1 class="text-3xl font-bold text-gray-900 mt-1"><?php echo e($property['name']); ?></h1>
                <p class="text-sm text-gray-500 mt-2"><i class="fa-solid fa-location-dot mr-1 text-red-500"></i> <?php echo e($property['physical_address'] . ', ' . $property['estate']); ?></p>
                <p class="text-gray-700 mt-4"><?php echo nl2br(e($property['description'])); ?></p>
            </div>

            <!-- Vacant Units -->
            <div class="bg-white p-6 rounded-xl border border-gray-100 shadow-sm">
                <h3 class="font-bold text-lg text-gray-900 mb-4">Available Vacant Units</h3>
                <div class="space-y-3">
                    <?php if (empty($units)): ?>
                        <p class="text-sm text-gray-500">No vacant units currently available at this property.</p>
                    <?php else: ?>
                        <?php foreach ($units as $u): ?>
                            <div class="border border-gray-100 p-4 rounded-lg flex items-center justify-between">
                                <div>
                                    <h4 class="font-bold text-gray-900">Unit <?php echo e($u['unit_number']); ?> (<?php echo e($u['house_type']); ?>)</h4>
                                    <p class="text-xs text-gray-500 mt-1">Deposit: <?php echo format_currency($u['deposit']); ?> | Floor: <?php echo e($u['floor']); ?></p>
                                </div>
                                <div class="text-right">
                                    <span class="font-bold text-success block"><?php echo format_currency($u['monthly_rent']); ?>/mo</span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Right: Request Viewing Form -->
        <div>
            <div class="bg-white p-6 rounded-xl border border-gray-100 shadow-sm sticky top-20">
                <h3 class="font-bold text-lg text-gray-900 mb-4">Request Property Viewing</h3>
                <?php if (!empty($success_msg)): ?>
                    <div class="bg-green-50 text-green-800 p-3 rounded-lg text-sm mb-4"><?php echo e($success_msg); ?></div>
                <?php endif; ?>
                <form method="POST" class="space-y-4">
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Full Name</label>
                        <input type="text" name="visitor_name" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary outline-none">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Phone Number</label>
                        <input type="text" name="visitor_phone" required placeholder="+2547..." class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary outline-none">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Email Address</label>
                        <input type="email" name="visitor_email" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary outline-none">
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Preferred Date</label>
                            <input type="date" name="preferred_date" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary outline-none">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Preferred Time</label>
                            <input type="time" name="preferred_time" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary outline-none">
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Message (Optional)</label>
                        <textarea name="message" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary outline-none"></textarea>
                    </div>
                    <button type="submit" class="w-full bg-primary hover:bg-blue-800 text-white font-semibold py-2.5 rounded-lg transition text-sm">
                        Submit Viewing Request
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>