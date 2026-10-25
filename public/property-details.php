<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

$db = db();
$property_id = filter_var($_GET['id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;

$stmt = $db->prepare("SELECT p.*, l.estate, l.sub_county FROM properties p JOIN locations l ON p.location_id = l.id WHERE p.id = ? AND p.status = 'Active'");
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
$form_errors = [];
$form_values = [
    'visitor_name' => '',
    'visitor_phone' => '',
    'visitor_email' => '',
    'unit_id' => '',
    'preferred_date' => '',
    'preferred_time' => '',
    'message' => '',
];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postValue = static function ($key) {
        return isset($_POST[$key]) && is_string($_POST[$key]) ? trim($_POST[$key]) : '';
    };
    foreach (array_keys($form_values) as $field) {
        $form_values[$field] = $postValue($field);
    }

    if (!csrf_validate()) {
        $form_errors[] = 'Your session expired. Please reload the page and try again.';
    }
    $name = $form_values['visitor_name'];
    $phone = $form_values['visitor_phone'];
    $email = $form_values['visitor_email'];
    $unitId = filter_var($form_values['unit_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
    $date = $form_values['preferred_date'];
    $time = $form_values['preferred_time'];
    $message = $form_values['message'];

    if ($name === '' || strlen($name) > 100) {
        $form_errors[] = 'Enter your name (up to 100 characters).';
    }
    if (!is_valid_phone_number($phone)) {
        $form_errors[] = 'Enter a valid phone number.';
    }
    if (strlen($email) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $form_errors[] = 'Enter a valid email address.';
    }
    $dateValue = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$dateValue || $dateValue->format('Y-m-d') !== $date || $date < date('Y-m-d')) {
        $form_errors[] = 'Choose a valid viewing date that is today or later.';
    }
    if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)) {
        $form_errors[] = 'Choose a valid preferred viewing time.';
    }
    if (strlen($message) > 2000) {
        $form_errors[] = 'Keep your message to 2,000 characters or fewer.';
    }
    $availableUnitIds = array_map('intval', array_column($units, 'id'));
    if (!$unitId || !in_array($unitId, $availableUnitIds, true)) {
        $form_errors[] = 'Choose an available unit for this viewing request.';
    }

    if (!$form_errors && $property['status'] === 'Active') {
        $unitCheck = $db->prepare("SELECT id FROM units WHERE id = ? AND property_id = ? AND status = 'Vacant'");
        $unitCheck->execute([$unitId, $property_id]);
        if (!$unitCheck->fetchColumn()) {
            $form_errors[] = 'That unit is no longer available. Please reload the page and choose another unit.';
        } else {
            $req = $db->prepare('INSERT INTO viewing_requests (property_id, unit_id, visitor_name, visitor_phone, visitor_email, preferred_date, preferred_time, message) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $req->execute([$property_id, $unitId, $name, $phone, $email, $date, $time, $message !== '' ? $message : null]);
            if ($req->rowCount() === 1) {
                $success_msg = 'Your viewing request has been saved and is pending management approval. Management will contact you.';
                $form_values = array_fill_keys(array_keys($form_values), '');
            } else {
                $form_errors[] = 'We could not save your viewing request. Please try again.';
            }
        }
    }
}

$page_title = $property['name'];
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/navbar.php';
?>

<div id="main-content" class="flex-1 flex flex-col bg-gray-50">
    <div class="max-w-7xl mx-auto px-4 py-8 w-full grid grid-cols-1 lg:grid-cols-3 gap-8">
        <!-- Left: Property Details & Units -->
        <div class="lg:col-span-2 space-y-6">
            <div class="bg-white p-6 rounded-xl border border-gray-100 shadow-sm">
                <span class="text-xs font-semibold text-accent uppercase"><?php echo e($property['property_type']); ?></span>
                <h1 class="text-3xl font-bold text-gray-900 mt-1"><?php echo e($property['name']); ?></h1>
                <p class="text-sm text-gray-500 mt-2"><i class="fa-solid fa-location-dot mr-1 text-red-500"></i> <?php echo e($property['physical_address'] . ', ' . $property['estate']); ?></p>
                <?php $imagePath = trim((string)($property['property_image'] ?? ''), '/'); $imageFile = $imagePath !== '' ? dirname(__DIR__) . '/' . $imagePath : ''; ?>
                <?php if ($imagePath !== '' && is_file($imageFile)): ?><img class="w-full h-64 sm:h-80 object-cover rounded-lg mt-5" src="<?php echo BASE_URL . '/' . e($imagePath); ?>" alt="Exterior of <?php echo e($property['name']); ?>" fetchpriority="high"><?php else: ?><img class="w-full h-64 sm:h-80 object-cover rounded-lg mt-5" src="<?php echo BASE_URL . '/' . e(sample_property_image($property['id'])); ?>" alt="Sample home image; actual property photo not provided" fetchpriority="high"><?php endif; ?>
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
        <div id="request-viewing">
            <div class="bg-white p-6 rounded-xl border border-gray-100 shadow-sm sticky top-20">
                <h3 class="font-bold text-lg text-gray-900 mb-4">Request Property Viewing</h3>
                <?php if (!empty($success_msg)): ?>
                    <div class="bg-green-50 text-green-800 p-3 rounded-lg text-sm mb-4" role="status"><?php echo e($success_msg); ?></div>
                <?php endif; ?>
                <?php if ($form_errors): ?>
                    <div class="bg-red-50 text-red-800 p-3 rounded-lg text-sm mb-4" role="alert">
                        <ul class="list-disc pl-5"><?php foreach ($form_errors as $error): ?><li><?php echo e($error); ?></li><?php endforeach; ?></ul>
                    </div>
                <?php endif; ?>
                <?php if (!$units): ?>
                    <p class="text-sm text-gray-600">There are no vacant units available to schedule a viewing for at this property right now.</p>
                <?php else: ?>
                <form method="POST" class="space-y-4">
                    <?php echo csrf_field(); ?>
                    <div>
                        <label for="visitor-name" class="block text-xs font-semibold text-gray-500 uppercase mb-1">Full Name</label>
                        <input id="visitor-name" type="text" name="visitor_name" maxlength="100" value="<?php echo e($form_values['visitor_name']); ?>" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary outline-none">
                    </div>
                    <div>
                        <label for="visitor-phone" class="block text-xs font-semibold text-gray-500 uppercase mb-1">Phone Number</label>
                        <input id="visitor-phone" type="tel" name="visitor_phone" value="<?php echo e($form_values['visitor_phone']); ?>" required placeholder="+2547..." class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary outline-none">
                    </div>
                    <div>
                        <label for="visitor-email" class="block text-xs font-semibold text-gray-500 uppercase mb-1">Email Address</label>
                        <input id="visitor-email" type="email" name="visitor_email" maxlength="150" value="<?php echo e($form_values['visitor_email']); ?>" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary outline-none">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase mb-1" for="viewing-unit">Unit of interest</label>
                        <select id="viewing-unit" name="unit_id" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary outline-none">
                            <option value="">Select an available unit</option>
                            <?php foreach ($units as $unit): ?>
                                <option value="<?php echo (int)$unit['id']; ?>" <?php echo $form_values['unit_id'] === (string)$unit['id'] ? 'selected' : ''; ?>><?php echo e($unit['unit_number'] . ' · ' . $unit['house_type'] . ' · ' . format_currency($unit['monthly_rent']) . '/mo'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label for="preferred-date" class="block text-xs font-semibold text-gray-500 uppercase mb-1">Preferred Date</label>
                            <input id="preferred-date" type="date" name="preferred_date" min="<?php echo e(date('Y-m-d')); ?>" value="<?php echo e($form_values['preferred_date']); ?>" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary outline-none">
                        </div>
                        <div>
                            <label for="preferred-time" class="block text-xs font-semibold text-gray-500 uppercase mb-1">Preferred Time</label>
                            <input id="preferred-time" type="time" name="preferred_time" value="<?php echo e($form_values['preferred_time']); ?>" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary outline-none">
                        </div>
                    </div>
                    <div>
                        <label for="viewing-message" class="block text-xs font-semibold text-gray-500 uppercase mb-1">Message (Optional)</label>
                        <textarea id="viewing-message" name="message" rows="3" maxlength="2000" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary outline-none"><?php echo e($form_values['message']); ?></textarea>
                    </div>
                    <button type="submit" class="w-full bg-primary hover:bg-blue-800 text-white font-semibold py-2.5 rounded-lg transition text-sm">
                        Submit Viewing Request
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
