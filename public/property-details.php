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
$requestedUnitId = filter_var($_GET['unit'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$selectedUnitId = in_array($requestedUnitId, array_map('intval', array_column($units, 'id')), true) ? $requestedUnitId : 0;
$startingRent = $units ? min(array_map('floatval', array_column($units, 'monthly_rent'))) : null;
$imagePath = trim((string)($property['property_image'] ?? ''), '/');
$imageFile = $imagePath !== '' ? dirname(__DIR__) . '/' . $imagePath : '';
$hasPropertyImage = $imagePath !== '' && is_file($imageFile);

$success_msg = '';
$form_errors = [];
$form_values = [
    'visitor_name' => '',
    'visitor_phone' => '',
    'visitor_email' => '',
    'unit_id' => $selectedUnitId ? (string)$selectedUnitId : '',
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

<main id="main-content" class="property-detail-page">
    <div class="site-container property-detail-layout">
        <div class="property-detail-main">
            <section class="property-detail-hero" aria-labelledby="property-detail-title">
                <div class="property-detail-copy">
                    <a class="property-back-link" href="<?php echo BASE_URL; ?>/public/properties.php"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Back to homes</a>
                    <p class="property-detail-eyebrow"><?php echo e($property['property_type']); ?> <span>·</span> Nairobi</p>
                    <h1 id="property-detail-title"><?php echo e($property['name']); ?></h1>
                    <p class="property-detail-address"><i class="fa-solid fa-location-dot" aria-hidden="true"></i> <?php echo e($property['physical_address'] . ', ' . $property['estate'] . ', ' . $property['sub_county']); ?></p>
                    <div class="property-detail-status"><span class="<?php echo $units ? 'is-available' : 'is-unavailable'; ?>"><i aria-hidden="true"></i><?php echo $units ? 'Available to view' : 'No vacant units'; ?></span>
                        <?php if ($startingRent !== null): ?><strong>From <?php echo format_currency($startingRent); ?><small> / month</small></strong><?php endif; ?>
                    </div>
                </div>
                <figure class="property-detail-photo">
                    <?php if ($hasPropertyImage): ?>
                        <img src="<?php echo BASE_URL . '/' . e($imagePath); ?>" alt="Property image for <?php echo e($property['name']); ?>" fetchpriority="high">
                    <?php else: ?>
                        <img src="<?php echo BASE_URL . '/' . e(sample_property_image($property['id'])); ?>" alt="Illustrative residential photo; an actual photo of this property was not provided" fetchpriority="high">
                        <figcaption>Illustrative photo · actual property photo not provided</figcaption>
                    <?php endif; ?>
                </figure>
            </section>

            <section class="property-detail-panel" aria-labelledby="property-overview-title">
                <div class="property-detail-section-heading"><div><p class="eyebrow">Property overview</p><h2 id="property-overview-title">A closer look</h2></div><span class="property-detail-type"><?php echo e($property['property_type']); ?></span></div>
                <?php if (trim((string)$property['description']) !== ''): ?><p class="property-detail-description"><?php echo nl2br(e($property['description'])); ?></p><?php else: ?><p class="property-detail-description property-detail-muted">No additional property description has been provided.</p><?php endif; ?>
                <?php if (trim((string)($property['amenities'] ?? '')) !== ''): ?>
                    <div class="property-detail-amenities"><h3>Recorded amenities</h3><p><i class="fa-solid fa-sparkles" aria-hidden="true"></i> <?php echo e($property['amenities']); ?></p></div>
                <?php endif; ?>
                <a class="property-contact-link" href="<?php echo BASE_URL; ?>/public/contact.php?<?php echo e(http_build_query(['subject' => 'Property enquiry', 'property' => $property['name']])); ?>"><i class="fa-regular fa-message" aria-hidden="true"></i> Ask us about this property</a>
            </section>

            <section class="property-detail-panel" aria-labelledby="available-units-title">
                <div class="property-detail-section-heading"><div><p class="eyebrow">Current availability</p><h2 id="available-units-title">Vacant units</h2></div><span class="property-unit-count"><?php echo count($units); ?> available</span></div>
                <div class="property-unit-list">
                    <?php if (empty($units)): ?>
                        <p class="property-detail-muted">No vacant units are currently recorded for this property. You can still contact management with an enquiry.</p>
                    <?php else: ?>
                        <?php foreach ($units as $u): ?>
                            <article class="property-unit-card">
                                <div class="property-unit-info">
                                    <h3>Unit <?php echo e($u['unit_number']); ?></h3>
                                    <p><?php echo e($u['house_type']); ?><?php if ((int)$u['bedrooms'] > 0): ?> <span>·</span> <?php echo (int)$u['bedrooms']; ?> bed<?php endif; ?><?php if ((int)$u['bathrooms'] > 0): ?> <span>·</span> <?php echo (int)$u['bathrooms']; ?> bath<?php endif; ?></p>
                                    <small><?php echo e($u['floor']); ?> floor <span>·</span> Deposit <?php echo format_currency($u['deposit']); ?></small>
                                </div>
                                <strong><?php echo format_currency($u['monthly_rent']); ?><small> / mo</small></strong>
                            </article>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </section>
        </div>

        <aside id="request-viewing" class="property-viewing-column">
            <div class="property-viewing-card">
                <p class="eyebrow">Take the next step</p>
                <h2>Request a viewing</h2>
                <p class="property-viewing-intro">Choose an available unit and send management a convenient date and time.</p>
                <?php if (!empty($success_msg)): ?>
                    <div class="property-form-success" role="status"><?php echo e($success_msg); ?></div>
                <?php endif; ?>
                <?php if ($form_errors): ?>
                    <div class="property-form-error" role="alert">
                        <ul><?php foreach ($form_errors as $error): ?><li><?php echo e($error); ?></li><?php endforeach; ?></ul>
                    </div>
                <?php endif; ?>
                <?php if (!$units): ?>
                    <p class="property-detail-muted">There are no vacant units available to schedule a viewing for at this property right now.</p>
                    <a class="property-contact-button" href="<?php echo BASE_URL; ?>/public/contact.php?<?php echo e(http_build_query(['subject' => 'Property enquiry', 'property' => $property['name']])); ?>">Send a property enquiry</a>
                <?php else: ?>
                <form method="POST" class="property-viewing-form">
                    <?php echo csrf_field(); ?>
                    <div>
                        <label for="visitor-name">Full name</label>
                        <input id="visitor-name" type="text" name="visitor_name" maxlength="100" value="<?php echo e($form_values['visitor_name']); ?>" required autocomplete="name">
                    </div>
                    <div>
                        <label for="visitor-phone">Phone number</label>
                        <input id="visitor-phone" type="tel" name="visitor_phone" value="<?php echo e($form_values['visitor_phone']); ?>" required placeholder="+2547..." autocomplete="tel">
                    </div>
                    <div>
                        <label for="visitor-email">Email address</label>
                        <input id="visitor-email" type="email" name="visitor_email" maxlength="150" value="<?php echo e($form_values['visitor_email']); ?>" required autocomplete="email">
                    </div>
                    <div>
                        <label for="viewing-unit">Unit of interest</label>
                        <select id="viewing-unit" name="unit_id" required>
                            <option value="">Select an available unit</option>
                            <?php foreach ($units as $unit): ?>
                                <option value="<?php echo (int)$unit['id']; ?>" <?php echo $form_values['unit_id'] === (string)$unit['id'] ? 'selected' : ''; ?>><?php echo e($unit['unit_number'] . ' · ' . $unit['house_type'] . ' · ' . format_currency($unit['monthly_rent']) . '/mo'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="property-viewing-date-row">
                        <div>
                            <label for="preferred-date">Preferred date</label>
                            <input id="preferred-date" type="date" name="preferred_date" min="<?php echo e(date('Y-m-d')); ?>" value="<?php echo e($form_values['preferred_date']); ?>" required>
                        </div>
                        <div>
                            <label for="preferred-time">Preferred time</label>
                            <input id="preferred-time" type="time" name="preferred_time" value="<?php echo e($form_values['preferred_time']); ?>" required>
                        </div>
                    </div>
                    <div>
                        <label for="viewing-message">Message <span>(optional)</span></label>
                        <textarea id="viewing-message" name="message" rows="3" maxlength="2000"><?php echo e($form_values['message']); ?></textarea>
                    </div>
                    <button type="submit">
                        Send viewing request
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </aside>
    </div>
</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>
