<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/upload_helper.php';

requireRole(['Super Admin', 'Property Manager', 'Accountant']);

$db = db();
$user = getCurrentUser();
$isSystemAdministrator = ($user['role_name'] ?? '') === 'Super Admin';
$stats = [];
$roleStats = [];
$recent_payments = [];
$recent_audit_events = [];
$propertyRows = [];
$unitRows = [];
$activeLeases = [];
$expiringLeases = [];
$maintenanceQueue = [];
$complaintQueue = [];
$locations = [];
$landlords = [];
$activeProperties = [];
$dashboardError = '';
$dashboardSuccess = $_SESSION['admin_dashboard_success'] ?? '';
unset($_SESSION['admin_dashboard_success']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isSystemAdministrator) {
    $action = isset($_POST['dashboard_action']) && is_string($_POST['dashboard_action']) ? $_POST['dashboard_action'] : '';
    $textValue = static function ($key) {
        return isset($_POST[$key]) && is_string($_POST[$key]) ? trim($_POST[$key]) : '';
    };

    if (!csrf_validate()) {
        $dashboardError = 'Your session expired. Reload the page and try again.';
    } else {
        try {
            if ($action === 'create_property') {
                $name = $textValue('name');
                $propertyType = $textValue('property_type');
                $locationId = filter_var($textValue('location_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
                $landlordId = filter_var($textValue('landlord_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
                $address = $textValue('physical_address');
                $description = $textValue('description');
                if ($name === '' || strlen($name) > 150 || !in_array($propertyType, PROPERTY_TYPES, true) || $address === '' || strlen($address) > 255 || strlen($description) > 5000) {
                    throw new InvalidArgumentException('Provide a property name, valid property type, physical address, and description (up to 5,000 characters).');
                }
                $validLocation = $db->prepare('SELECT id FROM locations WHERE id = ?');
                $validLocation->execute([$locationId]);
                $validLandlord = $db->prepare("SELECT id FROM landlords WHERE id = ? AND status = 'Active'");
                $validLandlord->execute([$landlordId]);
                if (!$validLocation->fetchColumn() || !$validLandlord->fetchColumn()) {
                    throw new InvalidArgumentException('Choose an existing location and active landlord.');
                }

                $imagePath = null;
                if (isset($_FILES['property_image']) && $_FILES['property_image']['error'] !== UPLOAD_ERR_NO_FILE) {
                    $upload = uploadApplicationFile($_FILES['property_image'], 'properties', ['jpg', 'jpeg', 'png']);
                    if (!$upload['success']) {
                        throw new InvalidArgumentException($upload['error']);
                    }
                    $imagePath = $upload['path'];
                }
                try {
                    $db->beginTransaction();
                    $insert = $db->prepare("INSERT INTO properties (landlord_id, name, property_type, description, location_id, physical_address, number_of_units, status, property_image)
                        VALUES (?, ?, ?, ?, ?, ?, 0, 'Active', ?)");
                    $insert->execute([$landlordId, $name, $propertyType, $description !== '' ? $description : null, $locationId, $address, $imagePath]);
                    $propertyId = (int)$db->lastInsertId();
                    $audit = $db->prepare("INSERT INTO audit_logs (user_id, action, module, record_id, description, ip_address) VALUES (?, 'Create', 'Properties', ?, ?, ?)");
                    $audit->execute([(int)$user['id'], $propertyId, 'Created property ' . $name, $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN']);
                    $db->commit();
                } catch (Throwable $exception) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    if ($imagePath !== null) {
                        $uploadedFile = realpath(__DIR__ . '/../' . $imagePath);
                        $uploadRoot = realpath(__DIR__ . '/../uploads/properties');
                        if ($uploadedFile && $uploadRoot && strpos($uploadedFile, $uploadRoot . DIRECTORY_SEPARATOR) === 0) {
                            unlink($uploadedFile);
                        }
                    }
                    throw $exception;
                }
                $_SESSION['admin_dashboard_success'] = 'Property created successfully.';
                redirect('admin/dashboard.php#property-control');
            } elseif ($action === 'create_unit') {
                $propertyId = filter_var($textValue('property_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
                $unitNumber = $textValue('unit_number');
                $floor = $textValue('floor');
                $houseType = $textValue('house_type');
                $rent = $textValue('monthly_rent');
                $deposit = $textValue('deposit');
                $bedrooms = filter_var($textValue('bedrooms'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 50]]);
                $bathrooms = filter_var($textValue('bathrooms'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 50]]);
                if (!$propertyId || $unitNumber === '' || strlen($unitNumber) > 50 || strlen($floor) > 20 || !in_array($houseType, UNIT_TYPES, true) ||
                    !is_numeric($rent) || (float)$rent <= 0 || (float)$rent > 9999999999 || !is_numeric($deposit) || (float)$deposit < 0 || (float)$deposit > 9999999999 ||
                    $bedrooms === false || $bathrooms === false) {
                    throw new InvalidArgumentException('Enter a valid property, unit number, unit type, rent, deposit, bedrooms, and bathrooms.');
                }
                $propertyCheck = $db->prepare("SELECT id FROM properties WHERE id = ? AND status = 'Active'");
                $propertyCheck->execute([$propertyId]);
                if (!$propertyCheck->fetchColumn()) {
                    throw new InvalidArgumentException('Choose an active property before adding a unit.');
                }
                $db->beginTransaction();
                $insert = $db->prepare("INSERT INTO units (property_id, unit_number, floor, house_type, bedrooms, bathrooms, monthly_rent, deposit, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Vacant')");
                $insert->execute([$propertyId, $unitNumber, $floor !== '' ? $floor : 'Ground', $houseType, $bedrooms, $bathrooms, $rent, $deposit]);
                $unitId = (int)$db->lastInsertId();
                $db->prepare('UPDATE properties SET number_of_units = number_of_units + 1 WHERE id = ?')->execute([$propertyId]);
                $audit = $db->prepare("INSERT INTO audit_logs (user_id, action, module, record_id, description, ip_address) VALUES (?, 'Create', 'Units', ?, ?, ?)");
                $audit->execute([(int)$user['id'], $unitId, 'Added unit ' . $unitNumber . ' to property #' . $propertyId, $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN']);
                $db->commit();
                $_SESSION['admin_dashboard_success'] = 'Unit created and marked vacant.';
                redirect('admin/dashboard.php#property-control');
            } elseif ($action === 'set_unit_status') {
                $unitId = filter_var($textValue('unit_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
                $status = $textValue('status');
                $allowedStatuses = ['Vacant', 'Reserved', 'Under Maintenance', 'Unavailable'];
                if (!$unitId || !in_array($status, $allowedStatuses, true)) {
                    throw new InvalidArgumentException('Choose a valid unit and status.');
                }
                $db->beginTransaction();
                $unitQuery = $db->prepare('SELECT id, status FROM units WHERE id = ? FOR UPDATE');
                $unitQuery->execute([$unitId]);
                $unit = $unitQuery->fetch();
                if (!$unit) {
                    throw new InvalidArgumentException('That unit no longer exists.');
                }
                $leaseQuery = $db->prepare("SELECT id FROM leases WHERE unit_id = ? AND status = 'Active' AND end_date >= CURRENT_DATE() LIMIT 1");
                $leaseQuery->execute([$unitId]);
                $hasActiveLease = (bool)$leaseQuery->fetchColumn();
                if ($unit['status'] === 'Occupied' || $hasActiveLease) {
                    throw new InvalidArgumentException('Occupied units and units with active leases must be updated through lease management.');
                }
                $db->prepare('UPDATE units SET status = ? WHERE id = ?')->execute([$status, $unitId]);
                $audit = $db->prepare("INSERT INTO audit_logs (user_id, action, module, record_id, description, ip_address) VALUES (?, 'Update status', 'Units', ?, ?, ?)");
                $audit->execute([(int)$user['id'], $unitId, 'Changed unit status to ' . $status, $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN']);
                $db->commit();
                $_SESSION['admin_dashboard_success'] = 'Unit status updated.';
                redirect('admin/dashboard.php#property-control');
            } elseif ($action === 'set_property_status') {
                $propertyId = filter_var($textValue('property_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
                $status = $textValue('status');
                if (!$propertyId || !in_array($status, ['Active', 'Under Maintenance', 'Archived'], true)) {
                    throw new InvalidArgumentException('Choose a valid property and status.');
                }
                $db->beginTransaction();
                $propertyQuery = $db->prepare('SELECT id FROM properties WHERE id = ? FOR UPDATE');
                $propertyQuery->execute([$propertyId]);
                if (!$propertyQuery->fetchColumn()) {
                    throw new InvalidArgumentException('That property no longer exists.');
                }
                if ($status === 'Archived') {
                    $leaseQuery = $db->prepare("SELECT id FROM leases WHERE property_id = ? AND status = 'Active' AND end_date >= CURRENT_DATE() LIMIT 1");
                    $leaseQuery->execute([$propertyId]);
                    if ($leaseQuery->fetchColumn()) {
                        throw new InvalidArgumentException('A property with an active lease cannot be archived.');
                    }
                }
                $db->prepare('UPDATE properties SET status = ? WHERE id = ?')->execute([$status, $propertyId]);
                $audit = $db->prepare("INSERT INTO audit_logs (user_id, action, module, record_id, description, ip_address) VALUES (?, 'Update status', 'Properties', ?, ?, ?)");
                $audit->execute([(int)$user['id'], $propertyId, 'Changed property status to ' . $status, $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN']);
                $db->commit();
                $_SESSION['admin_dashboard_success'] = 'Property status updated.';
                redirect('admin/dashboard.php#property-control');
            } else {
                throw new InvalidArgumentException('Choose a valid dashboard action.');
            }
        } catch (InvalidArgumentException $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $dashboardError = $exception->getMessage();
        } catch (PDOException $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('Administrator dashboard database action failed: ' . $exception->getMessage());
            $dashboardError = 'The change could not be saved. Check for duplicate unit numbers and try again.';
        } catch (Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('Administrator dashboard action failed: ' . $exception->getMessage());
            $dashboardError = 'The change could not be completed. Please try again.';
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    http_response_code(403);
    exit('Forbidden');
}

if ($isSystemAdministrator) {
    $currentMonthStart = date('Y-m-01');
    $previousMonthStart = date('Y-m-01', strtotime('first day of last month'));
    $previousMonthEnd = date('Y-m-t', strtotime('last day of last month'));
    $collectionQuery = $db->prepare('SELECT COALESCE(SUM(CASE WHEN payment_date >= ? AND payment_date < ? THEN amount ELSE 0 END), 0) AS current_month,
            COALESCE(SUM(CASE WHEN payment_date >= ? AND payment_date <= ? THEN amount ELSE 0 END), 0) AS previous_month
        FROM payments');
    $collectionQuery->execute([$currentMonthStart, date('Y-m-01', strtotime('first day of next month')), $previousMonthStart, $previousMonthEnd]);
    $collections = $collectionQuery->fetch();

    $stats = [
        'users' => (int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn(),
        'active_users' => (int)$db->query("SELECT COUNT(*) FROM users WHERE status = 'Active'")->fetchColumn(),
        'properties' => (int)$db->query("SELECT COUNT(*) FROM properties WHERE status = 'Active'")->fetchColumn(),
        'units' => (int)$db->query('SELECT COUNT(*) FROM units')->fetchColumn(),
        'occupied' => (int)$db->query("SELECT COUNT(*) FROM units WHERE status = 'Occupied'")->fetchColumn(),
        'vacant' => (int)$db->query("SELECT COUNT(*) FROM units WHERE status = 'Vacant'")->fetchColumn(),
        'active_tenants' => (int)$db->query("SELECT COUNT(*) FROM tenants WHERE status = 'Active'")->fetchColumn(),
        'payments' => (int)$db->query('SELECT COUNT(*) FROM payments')->fetchColumn(),
        'outstanding' => (float)$db->query("SELECT COALESCE(SUM(GREATEST(rc.total_due - COALESCE(pa.paid, 0), 0)), 0)
            FROM rent_charges rc
            LEFT JOIN (SELECT rent_charge_id, SUM(allocated_amount) AS paid FROM payment_allocations GROUP BY rent_charge_id) pa ON pa.rent_charge_id = rc.id
            WHERE rc.status <> 'Paid'")->fetchColumn(),
        'overdue' => (float)$db->query("SELECT COALESCE(SUM(GREATEST(rc.total_due - COALESCE(pa.paid, 0), 0)), 0)
            FROM rent_charges rc
            LEFT JOIN (SELECT rent_charge_id, SUM(allocated_amount) AS paid FROM payment_allocations GROUP BY rent_charge_id) pa ON pa.rent_charge_id = rc.id
            WHERE rc.due_date < CURRENT_DATE() AND rc.status <> 'Paid'")->fetchColumn(),
        'recorded_collections' => (float)$collections['current_month'],
        'previous_collections' => (float)$collections['previous_month'],
        'open_maintenance' => (int)$db->query("SELECT COUNT(*) FROM maintenance_requests WHERE status NOT IN ('Completed', 'Cancelled')")->fetchColumn(),
        'open_complaints' => (int)$db->query("SELECT COUNT(*) FROM complaints WHERE status IN ('Open', 'Under Review')")->fetchColumn(),
    ];
    $occupancyRate = $stats['units'] > 0 ? round(($stats['occupied'] / $stats['units']) * 100, 1) : 0;

    $roleStats = $db->query("SELECT r.name, COUNT(u.id) AS total_users,
            SUM(CASE WHEN u.status = 'Active' THEN 1 ELSE 0 END) AS active_users,
            SUM(CASE WHEN u.status = 'Inactive' THEN 1 ELSE 0 END) AS inactive_users,
            SUM(CASE WHEN u.status = 'Suspended' THEN 1 ELSE 0 END) AS suspended_users
        FROM roles r
        LEFT JOIN users u ON u.role_id = r.id
        GROUP BY r.id, r.name
        ORDER BY r.id")->fetchAll();

    $recent_payments = $db->query("SELECT p.receipt_number, p.transaction_reference, p.tenant_id, p.unit_id, p.amount, p.payment_method, p.payment_date,
            t.full_name AS tenant_name, u.unit_number, pr.name AS property_name
        FROM payments p
        JOIN tenants t ON t.id = p.tenant_id
        JOIN units u ON u.id = p.unit_id
        JOIN properties pr ON pr.id = p.property_id
        ORDER BY p.payment_date DESC, p.id DESC LIMIT 8")->fetchAll();

    $recent_audit_events = $db->query("SELECT a.action, a.module, a.record_id, a.description, a.ip_address, a.created_at,
            u.full_name AS actor_name
        FROM audit_logs a
        LEFT JOIN users u ON u.id = a.user_id
        ORDER BY a.created_at DESC, a.id DESC LIMIT 8")->fetchAll();

    $propertyRows = $db->query("SELECT p.id, p.name, p.property_type, p.status, p.number_of_units, l.estate, l.sub_county,
            ll.full_name AS landlord_name, COUNT(u.id) AS actual_units,
            SUM(CASE WHEN u.status = 'Occupied' THEN 1 ELSE 0 END) AS occupied_units
        FROM properties p
        JOIN locations l ON l.id = p.location_id
        JOIN landlords ll ON ll.id = p.landlord_id
        LEFT JOIN units u ON u.property_id = p.id
        GROUP BY p.id, p.name, p.property_type, p.status, p.number_of_units, l.estate, l.sub_county, ll.full_name
        ORDER BY p.created_at DESC, p.id DESC")->fetchAll();

    $unitRows = $db->query("SELECT u.id, u.property_id, u.unit_number, u.house_type, u.monthly_rent, u.status, p.name AS property_name
        FROM units u JOIN properties p ON p.id = u.property_id
        ORDER BY p.name, u.unit_number")->fetchAll();

    $activeLeases = $db->query("SELECT le.id, le.start_date, le.end_date, le.payment_due_day, le.monthly_rent, t.full_name AS tenant_name,
            t.phone, u.unit_number, p.name AS property_name
        FROM leases le
        JOIN tenants t ON t.id = le.tenant_id
        JOIN units u ON u.id = le.unit_id
        JOIN properties p ON p.id = le.property_id
        WHERE le.status = 'Active' AND le.end_date >= CURRENT_DATE()
        ORDER BY le.end_date ASC LIMIT 10")->fetchAll();

    $expiringLeases = $db->query("SELECT le.id, le.end_date, t.full_name AS tenant_name, u.unit_number, p.name AS property_name
        FROM leases le
        JOIN tenants t ON t.id = le.tenant_id
        JOIN units u ON u.id = le.unit_id
        JOIN properties p ON p.id = le.property_id
        WHERE le.status = 'Active' AND le.end_date BETWEEN CURRENT_DATE() AND DATE_ADD(CURRENT_DATE(), INTERVAL 30 DAY)
        ORDER BY le.end_date ASC")->fetchAll();

    $maintenanceQueue = $db->query("SELECT mr.id, mr.category, mr.description, mr.priority, mr.status, mr.created_at,
            t.full_name AS tenant_name, u.unit_number, p.name AS property_name
        FROM maintenance_requests mr
        JOIN tenants t ON t.id = mr.tenant_id
        JOIN units u ON u.id = mr.unit_id
        JOIN properties p ON p.id = mr.property_id
        WHERE mr.status NOT IN ('Completed', 'Cancelled')
        ORDER BY FIELD(mr.priority, 'Emergency', 'High', 'Medium', 'Low'), mr.created_at ASC LIMIT 10")->fetchAll();

    $complaintQueue = $db->query("SELECT c.id, c.category, c.subject, c.status, c.created_at, t.full_name AS tenant_name, p.name AS property_name
        FROM complaints c
        JOIN tenants t ON t.id = c.tenant_id
        JOIN properties p ON p.id = c.property_id
        WHERE c.status IN ('Open', 'Under Review')
        ORDER BY c.created_at ASC LIMIT 8")->fetchAll();

    $locations = $db->query('SELECT id, estate, sub_county FROM locations ORDER BY estate')->fetchAll();
    $landlords = $db->query("SELECT id, full_name, company_name FROM landlords WHERE status = 'Active' ORDER BY full_name")->fetchAll();
    $activeProperties = $db->query("SELECT id, name FROM properties WHERE status = 'Active' ORDER BY name")->fetchAll();
}

$page_title = $isSystemAdministrator ? 'Administrator Dashboard' : 'Operations Dashboard';
include __DIR__ . '/../includes/header.php';
?>

<div class="flex h-screen bg-gray-50 overflow-hidden">
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>

    <div class="flex-1 flex flex-col overflow-y-auto">
        <?php include __DIR__ . '/../includes/navbar.php'; ?>

        <main id="main-content" class="flex-1 p-4 sm:p-6 space-y-6">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500"><?php echo e($user['role_name']); ?> workspace</p>
                    <h1 class="text-2xl font-bold text-gray-900"><?php echo $isSystemAdministrator ? 'System Overview' : 'Operations Overview'; ?></h1>
                    <p class="text-sm text-gray-500"><?php echo $isSystemAdministrator ? 'Live portfolio records, financial activity, and operational alerts.' : 'System-wide summaries are reserved for the Super Admin. Use your authorized property workflows below.'; ?></p>
                </div>
                <a href="<?php echo BASE_URL; ?>/public/properties.php" class="bg-primary hover:bg-blue-800 text-white px-4 py-2 rounded-lg text-sm font-semibold transition shadow-sm self-start">
                    <i class="fa-solid fa-building mr-1" aria-hidden="true"></i> Browse Properties
                </a>
            </div>

            <?php if ($dashboardError !== ''): ?>
                <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert"><?php echo e($dashboardError); ?></div>
            <?php elseif ($dashboardSuccess !== ''): ?>
                <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800" role="status"><?php echo e($dashboardSuccess); ?></div>
            <?php endif; ?>

            <?php if ($isSystemAdministrator): ?>
            <section aria-label="Financial and occupancy metrics" class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4">
                <?php foreach ([
                    ['Rent recorded this month', format_currency($stats['recorded_collections']), 'fa-money-bill-wave', 'Current calendar month'],
                    ['Previous month', format_currency($stats['previous_collections']), 'fa-chart-column', 'Recorded payment comparison'],
                    ['Outstanding rent charges', format_currency($stats['outstanding']), 'fa-file-invoice-dollar', 'Unpaid and partially paid charges'],
                    ['Overdue balances', format_currency($stats['overdue']), 'fa-clock', 'Unpaid charges past their due date'],
                    ['Portfolio occupancy', number_format($occupancyRate, 1) . '%', 'fa-building-circle-check', number_format($stats['occupied']) . ' occupied of ' . number_format($stats['units']) . ' units'],
                    ['Open maintenance requests', number_format($stats['open_maintenance']), 'fa-screwdriver-wrench', 'Awaiting resolution'],
                ] as $card): ?>
                    <article class="bg-white p-5 rounded-xl border border-gray-100 shadow-sm">
                        <div class="flex items-center justify-between gap-3">
                            <div><p class="text-xs font-medium text-gray-500 uppercase"><?php echo e($card[0]); ?></p><h2 class="text-xl font-bold text-gray-900 mt-2"><?php echo e($card[1]); ?></h2><p class="mt-1 text-xs text-gray-500"><?php echo e($card[3]); ?></p></div>
                            <span class="p-3 rounded-xl bg-blue-50 text-primary" aria-hidden="true"><i class="fa-solid <?php echo e($card[2]); ?>"></i></span>
                        </div>
                    </article>
                <?php endforeach; ?>
            </section>

            <div class="grid grid-cols-1 xl:grid-cols-3 gap-5">
                <section class="dashboard-section p-5 rounded-xl xl:col-span-2" aria-labelledby="portfolio-heading">
                    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">
                        <div><h2 id="portfolio-heading" class="font-bold text-gray-900">Portfolio occupancy</h2><p class="text-sm text-gray-500">Occupied units divided by all registered units, including reserved and maintenance units.</p></div>
                        <span class="text-sm text-gray-500"><?php echo number_format($stats['occupied']); ?> of <?php echo number_format($stats['units']); ?> units</span>
                    </div>
                    <div class="mt-5 h-3 overflow-hidden rounded-full bg-gray-200" role="progressbar" aria-label="Portfolio occupancy" aria-valuenow="<?php echo e((string)$occupancyRate); ?>" aria-valuemin="0" aria-valuemax="100">
                        <div class="h-full rounded-full bg-primary" style="width: <?php echo e((string)$occupancyRate); ?>%"></div>
                    </div>
                    <div class="mt-3 flex justify-between text-sm text-gray-600"><span><?php echo number_format($occupancyRate, 1); ?>% occupied</span><span><?php echo number_format(max(0, $stats['units'] - $stats['occupied'])); ?> not occupied</span></div>
                </section>
                <section class="dashboard-section p-5 rounded-xl" aria-labelledby="lease-alert-heading">
                    <h2 id="lease-alert-heading" class="font-bold text-gray-900">Lease expiry alerts</h2>
                    <p class="text-sm text-gray-500 mt-1">Active leases ending within 30 days.</p>
                    <?php if (!$expiringLeases): ?><p class="text-sm text-gray-600 mt-4">No lease expiries in the next 30 days.</p>
                    <?php else: ?><ul class="mt-3 divide-y divide-gray-100">
                        <?php foreach ($expiringLeases as $lease): ?>
                            <li class="py-3 text-sm"><strong class="text-gray-900"><?php echo e($lease['tenant_name']); ?></strong><p class="text-gray-600"><?php echo e($lease['property_name']); ?> · Unit <?php echo e($lease['unit_number']); ?></p><p class="text-amber-700 text-xs mt-1">Expires <?php echo e(date('d M Y', strtotime($lease['end_date']))); ?></p></li>
                        <?php endforeach; ?>
                    </ul><?php endif; ?>
                </section>
            </div>

            <section id="property-control" class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden" aria-labelledby="property-control-heading">
                <div class="p-5 border-b border-gray-100">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                        <div><h2 id="property-control-heading" class="font-bold text-gray-900">Property and unit control</h2><p class="text-sm text-gray-500 mt-1">Manage portfolio records. Occupied units remain governed by their active lease.</p></div>
                        <a href="<?php echo BASE_URL; ?>/public/properties.php" class="text-sm font-semibold text-primary hover:underline">View public property directory <i class="fa-solid fa-arrow-right ml-1" aria-hidden="true"></i></a>
                    </div>
                </div>
                <div class="grid grid-cols-1 xl:grid-cols-2 gap-5 p-5 border-b border-gray-100">
                    <details class="rounded-lg border border-gray-200 p-4">
                        <summary class="font-semibold text-gray-900 cursor-pointer">Add a property</summary>
                        <?php if (!$locations || !$landlords): ?><p class="text-sm text-amber-700 mt-3">Add an active landlord and location to the system before creating a property.</p>
                        <?php else: ?>
                        <form method="post" enctype="multipart/form-data" class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <?php echo csrf_field(); ?><input type="hidden" name="dashboard_action" value="create_property">
                            <label class="text-sm text-gray-700">Property name <span class="text-red-600">*</span><input name="name" maxlength="150" required class="mt-1 w-full rounded-lg border-gray-300" autocomplete="organization"></label>
                            <label class="text-sm text-gray-700">Property type <span class="text-red-600">*</span><select name="property_type" required class="mt-1 w-full rounded-lg border-gray-300"><?php foreach (PROPERTY_TYPES as $type): ?><option value="<?php echo e($type); ?>"><?php echo e($type); ?></option><?php endforeach; ?></select></label>
                            <label class="text-sm text-gray-700">Neighborhood <span class="text-red-600">*</span><select name="location_id" required class="mt-1 w-full rounded-lg border-gray-300"><?php foreach ($locations as $location): ?><option value="<?php echo (int)$location['id']; ?>"><?php echo e($location['estate'] . ' · ' . $location['sub_county']); ?></option><?php endforeach; ?></select></label>
                            <label class="text-sm text-gray-700">Landlord <span class="text-red-600">*</span><select name="landlord_id" required class="mt-1 w-full rounded-lg border-gray-300"><?php foreach ($landlords as $landlord): ?><option value="<?php echo (int)$landlord['id']; ?>"><?php echo e($landlord['company_name'] ? $landlord['full_name'] . ' · ' . $landlord['company_name'] : $landlord['full_name']); ?></option><?php endforeach; ?></select></label>
                            <label class="text-sm text-gray-700 sm:col-span-2">Physical address <span class="text-red-600">*</span><input name="physical_address" maxlength="255" required class="mt-1 w-full rounded-lg border-gray-300" autocomplete="street-address"></label>
                            <label class="text-sm text-gray-700 sm:col-span-2">Description<textarea name="description" maxlength="5000" rows="3" class="mt-1 w-full rounded-lg border-gray-300"></textarea></label>
                            <label class="text-sm text-gray-700 sm:col-span-2">Property image <span class="text-gray-500">(JPG/PNG, up to 5 MB)</span><input type="file" name="property_image" accept=".jpg,.jpeg,.png,image/jpeg,image/png" class="mt-1 block w-full text-sm"></label>
                            <button class="sm:col-span-2 justify-self-start rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-blue-800 focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2">Create property</button>
                        </form><?php endif; ?>
                    </details>
                    <details class="rounded-lg border border-gray-200 p-4">
                        <summary class="font-semibold text-gray-900 cursor-pointer">Add a unit</summary>
                        <?php if (!$activeProperties): ?><p class="text-sm text-amber-700 mt-3">Create an active property before adding units.</p>
                        <?php else: ?>
                        <form method="post" class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <?php echo csrf_field(); ?><input type="hidden" name="dashboard_action" value="create_unit">
                            <label class="text-sm text-gray-700 sm:col-span-2">Property <span class="text-red-600">*</span><select name="property_id" required class="mt-1 w-full rounded-lg border-gray-300"><?php foreach ($activeProperties as $property): ?><option value="<?php echo (int)$property['id']; ?>"><?php echo e($property['name']); ?></option><?php endforeach; ?></select></label>
                            <label class="text-sm text-gray-700">Unit number <span class="text-red-600">*</span><input name="unit_number" maxlength="50" required class="mt-1 w-full rounded-lg border-gray-300"></label>
                            <label class="text-sm text-gray-700">Floor<input name="floor" maxlength="20" class="mt-1 w-full rounded-lg border-gray-300" placeholder="Ground"></label>
                            <label class="text-sm text-gray-700">House type <select name="house_type" required class="mt-1 w-full rounded-lg border-gray-300"><?php foreach (UNIT_TYPES as $type): ?><option value="<?php echo e($type); ?>"><?php echo e($type); ?></option><?php endforeach; ?></select></label>
                            <label class="text-sm text-gray-700">Monthly rent (KSh) <input type="number" name="monthly_rent" min="0.01" step="0.01" required class="mt-1 w-full rounded-lg border-gray-300"></label>
                            <label class="text-sm text-gray-700">Deposit (KSh) <input type="number" name="deposit" min="0" step="0.01" required class="mt-1 w-full rounded-lg border-gray-300"></label>
                            <label class="text-sm text-gray-700">Bedrooms <input type="number" name="bedrooms" min="0" max="50" value="1" required class="mt-1 w-full rounded-lg border-gray-300"></label>
                            <label class="text-sm text-gray-700">Bathrooms <input type="number" name="bathrooms" min="0" max="50" value="1" required class="mt-1 w-full rounded-lg border-gray-300"></label>
                            <button class="sm:col-span-2 justify-self-start rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-blue-800 focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2">Create vacant unit</button>
                        </form><?php endif; ?>
                    </details>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse min-w-[760px]">
                        <thead><tr class="bg-gray-50 text-xs font-semibold text-gray-500 uppercase"><th scope="col" class="p-4">Property</th><th scope="col" class="p-4">Location</th><th scope="col" class="p-4">Landlord</th><th scope="col" class="p-4">Units</th><th scope="col" class="p-4">Status</th><th scope="col" class="p-4">Actions</th></tr></thead>
                        <tbody class="divide-y divide-gray-100 text-sm">
                            <?php if (!$propertyRows): ?><tr><td colspan="6" class="p-6 text-center text-gray-500">No properties have been recorded.</td></tr>
                            <?php else: foreach ($propertyRows as $property): ?>
                                <tr>
                                    <th scope="row" class="p-4 font-medium text-gray-900"><?php echo e($property['name']); ?><span class="block text-xs font-normal text-gray-500"><?php echo e($property['property_type']); ?></span></th>
                                    <td class="p-4"><?php echo e($property['estate'] . ', ' . $property['sub_county']); ?></td>
                                    <td class="p-4"><?php echo e($property['landlord_name']); ?></td>
                                    <td class="p-4"><?php echo (int)$property['actual_units']; ?> unit(s) · <?php echo (int)$property['occupied_units']; ?> occupied</td>
                                    <td class="p-4"><?php echo render_status_badge($property['status']); ?></td>
                                    <td class="p-4">
                                        <?php if ($property['status'] !== 'Archived'): ?>
                                        <form method="post" class="flex items-center gap-2">
                                            <?php echo csrf_field(); ?><input type="hidden" name="dashboard_action" value="set_property_status"><input type="hidden" name="property_id" value="<?php echo (int)$property['id']; ?>">
                                            <label class="sr-only" for="property-status-<?php echo (int)$property['id']; ?>">Property status</label>
                                            <select id="property-status-<?php echo (int)$property['id']; ?>" name="status" class="rounded border-gray-300 text-xs">
                                                <?php foreach (['Active', 'Under Maintenance', 'Archived'] as $status): ?><option value="<?php echo e($status); ?>" <?php echo $property['status'] === $status ? 'selected' : ''; ?>><?php echo e($status); ?></option><?php endforeach; ?>
                                            </select><button class="rounded bg-gray-100 px-2 py-1 text-xs font-semibold hover:bg-gray-200">Save</button>
                                        </form>
                                        <?php else: ?><span class="text-xs text-gray-500">Archived</span><?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="overflow-x-auto border-t border-gray-100">
                    <table class="w-full text-left border-collapse min-w-[800px]">
                        <thead><tr class="bg-gray-50 text-xs font-semibold text-gray-500 uppercase"><th scope="col" class="p-4">Property / unit</th><th scope="col" class="p-4">Type</th><th scope="col" class="p-4">Rent</th><th scope="col" class="p-4">Current status</th><th scope="col" class="p-4">Quick status</th></tr></thead>
                        <tbody class="divide-y divide-gray-100 text-sm">
                            <?php if (!$unitRows): ?><tr><td colspan="5" class="p-6 text-center text-gray-500">No units have been recorded.</td></tr>
                            <?php else: foreach ($unitRows as $unit): ?>
                                <tr><th scope="row" class="p-4 font-medium text-gray-900"><?php echo e($unit['property_name']); ?><span class="block text-xs font-normal text-gray-500">Unit <?php echo e($unit['unit_number']); ?></span></th><td class="p-4"><?php echo e($unit['house_type']); ?></td><td class="p-4"><?php echo format_currency($unit['monthly_rent']); ?></td><td class="p-4"><?php echo render_status_badge($unit['status']); ?></td><td class="p-4">
                                    <?php if ($unit['status'] !== 'Occupied'): ?>
                                    <form method="post" class="flex items-center gap-2">
                                        <?php echo csrf_field(); ?><input type="hidden" name="dashboard_action" value="set_unit_status"><input type="hidden" name="unit_id" value="<?php echo (int)$unit['id']; ?>">
                                        <label class="sr-only" for="unit-status-<?php echo (int)$unit['id']; ?>">Unit status</label>
                                        <select id="unit-status-<?php echo (int)$unit['id']; ?>" name="status" class="rounded border-gray-300 text-xs">
                                            <?php foreach (['Vacant', 'Reserved', 'Under Maintenance', 'Unavailable'] as $status): ?><option value="<?php echo e($status); ?>" <?php echo $unit['status'] === $status ? 'selected' : ''; ?>><?php echo e($status); ?></option><?php endforeach; ?>
                                        </select><button class="rounded bg-gray-100 px-2 py-1 text-xs font-semibold hover:bg-gray-200">Update</button>
                                    </form>
                                    <?php else: ?><span class="text-xs text-gray-500">Managed by lease</span><?php endif; ?>
                                </td></tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden" aria-labelledby="lease-tracking-heading">
                <div class="p-5 border-b border-gray-100"><h2 id="lease-tracking-heading" class="font-bold text-gray-900">Active lease tracking</h2><p class="mt-1 text-sm text-gray-500">Current leases, assigned units, term dates, and rent due day are available in the lease directory.</p></div>
                <div class="overflow-x-auto"><table class="w-full min-w-[760px] text-left border-collapse">
                    <thead><tr class="bg-gray-50 text-xs font-semibold text-gray-500 uppercase"><th scope="col" class="p-4">Tenant</th><th scope="col" class="p-4">Property / unit</th><th scope="col" class="p-4">Lease dates</th><th scope="col" class="p-4">Monthly rent</th><th scope="col" class="p-4">Due day</th><th scope="col" class="p-4">Contact</th></tr></thead>
                    <tbody class="divide-y divide-gray-100 text-sm">
                        <?php if (!$activeLeases): ?><tr><td colspan="6" class="p-6 text-center text-gray-500">No current active leases.</td></tr>
                        <?php else: foreach ($activeLeases as $lease): ?><tr><th scope="row" class="p-4 font-medium text-gray-900"><?php echo e($lease['tenant_name']); ?></th><td class="p-4"><?php echo e($lease['property_name']); ?> · <?php echo e($lease['unit_number']); ?></td><td class="p-4"><?php echo e(date('d M Y', strtotime($lease['start_date']))); ?> – <?php echo e(date('d M Y', strtotime($lease['end_date']))); ?></td><td class="p-4"><?php echo format_currency($lease['monthly_rent']); ?></td><td class="p-4">Day <?php echo (int)$lease['payment_due_day']; ?> each month</td><td class="p-4"><?php echo e($lease['phone']); ?></td></tr><?php endforeach; endif; ?>
                    </tbody>
                </table></div>
            </section>

            <div class="grid grid-cols-1 xl:grid-cols-2 gap-5">
                <section class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden" aria-labelledby="maintenance-queue-heading">
                    <div class="p-5 border-b border-gray-100"><h2 id="maintenance-queue-heading" class="font-bold text-gray-900">Maintenance queue</h2><p class="mt-1 text-sm text-gray-500"><?php echo number_format($stats['open_maintenance']); ?> requests are currently unresolved.</p></div>
                    <div class="divide-y divide-gray-100">
                        <?php if (!$maintenanceQueue): ?><p class="p-6 text-center text-sm text-gray-500">No open maintenance requests.</p>
                        <?php else: foreach ($maintenanceQueue as $ticket): ?>
                            <article class="p-4"><div class="flex flex-wrap items-center justify-between gap-2"><h3 class="font-semibold text-gray-900"><?php echo e($ticket['category']); ?> · <?php echo e($ticket['property_name']); ?> / <?php echo e($ticket['unit_number']); ?></h3><div class="flex gap-2"><?php echo render_status_badge($ticket['priority']); ?><?php echo render_status_badge($ticket['status']); ?></div></div><p class="mt-1 text-sm text-gray-600"><?php echo e($ticket['tenant_name']); ?> · <?php echo e(mb_strimwidth($ticket['description'], 0, 180, '…')); ?></p><p class="mt-1 text-xs text-gray-500"><?php echo e(date('d M Y, H:i', strtotime($ticket['created_at']))); ?></p></article>
                        <?php endforeach; endif; ?>
                    </div>
                </section>
                <section class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden" aria-labelledby="complaint-queue-heading">
                    <div class="p-5 border-b border-gray-100"><h2 id="complaint-queue-heading" class="font-bold text-gray-900">Open complaints</h2><p class="mt-1 text-sm text-gray-500"><?php echo number_format($stats['open_complaints']); ?> open or under review.</p></div>
                    <div class="divide-y divide-gray-100">
                        <?php if (!$complaintQueue): ?><p class="p-6 text-center text-sm text-gray-500">No open complaints.</p>
                        <?php else: foreach ($complaintQueue as $complaint): ?>
                            <article class="p-4"><div class="flex flex-wrap items-center justify-between gap-2"><h3 class="font-semibold text-gray-900"><?php echo e($complaint['subject']); ?></h3><?php echo render_status_badge($complaint['status']); ?></div><p class="mt-1 text-sm text-gray-600"><?php echo e($complaint['category']); ?> · <?php echo e($complaint['property_name']); ?> · <?php echo e($complaint['tenant_name']); ?></p><p class="mt-1 text-xs text-gray-500"><?php echo e(date('d M Y, H:i', strtotime($complaint['created_at']))); ?></p></article>
                        <?php endforeach; endif; ?>
                    </div>
                </section>
            </div>

            <section class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden" aria-labelledby="role-summary-heading">
                <div class="p-5 border-b border-gray-100"><h2 id="role-summary-heading" class="font-bold text-gray-900">Users by role and status</h2><p class="text-sm text-gray-500 mt-1">Counts reflect roles and statuses stored in the system.</p></div>
                <div class="overflow-x-auto"><table class="w-full text-left border-collapse min-w-[620px]">
                    <thead><tr class="bg-gray-50 text-xs font-semibold text-gray-500 uppercase"><th scope="col" class="p-4">Role</th><th scope="col" class="p-4">Total</th><th scope="col" class="p-4">Active</th><th scope="col" class="p-4">Inactive</th><th scope="col" class="p-4">Suspended</th></tr></thead>
                    <tbody class="divide-y divide-gray-100 text-sm"><?php foreach ($roleStats as $roleStat): ?><tr><th scope="row" class="p-4 font-medium text-gray-900"><?php echo e($roleStat['name']); ?></th><td class="p-4"><?php echo number_format((int)$roleStat['total_users']); ?></td><td class="p-4"><?php echo number_format((int)$roleStat['active_users']); ?></td><td class="p-4"><?php echo number_format((int)$roleStat['inactive_users']); ?></td><td class="p-4"><?php echo number_format((int)$roleStat['suspended_users']); ?></td></tr><?php endforeach; ?></tbody>
                </table></div>
                <div class="p-4 border-t border-gray-100"><a href="<?php echo BASE_URL; ?>/admin/people.php" class="text-sm font-semibold text-primary hover:underline">Open People Directory <i class="fa-solid fa-arrow-right ml-1" aria-hidden="true"></i></a></div>
            </section>

            <section class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden" aria-labelledby="recent-payments-heading">
                <div class="p-5 border-b border-gray-100"><h2 id="recent-payments-heading" class="font-bold text-gray-900">Recent recorded transactions</h2><p class="mt-1 text-xs text-gray-500">The payment records do not store settlement status; “Recorded” does not verify M-Pesa or bank settlement.</p></div>
                <div class="overflow-x-auto"><table class="w-full text-left border-collapse min-w-[840px]">
                    <thead><tr class="bg-gray-50 text-xs font-semibold text-gray-500 uppercase"><th scope="col" class="p-4">Receipt / reference</th><th scope="col" class="p-4">Tenant</th><th scope="col" class="p-4">Property / unit</th><th scope="col" class="p-4">Amount</th><th scope="col" class="p-4">Method</th><th scope="col" class="p-4">Date</th><th scope="col" class="p-4">Record status</th></tr></thead>
                    <tbody class="divide-y divide-gray-100 text-sm">
                        <?php if (!$recent_payments): ?><tr><td colspan="7" class="p-6 text-center text-gray-500">No payment records found.</td></tr>
                        <?php else: foreach ($recent_payments as $payment): ?><tr><td class="p-4 font-medium text-gray-900"><?php echo e($payment['receipt_number']); ?><span class="block text-xs font-normal text-gray-500"><?php echo e($payment['transaction_reference']); ?></span></td><td class="p-4"><?php echo e($payment['tenant_name']); ?></td><td class="p-4"><?php echo e($payment['property_name']); ?> · <?php echo e($payment['unit_number']); ?></td><td class="p-4 font-semibold"><?php echo format_currency($payment['amount']); ?></td><td class="p-4"><?php echo e($payment['payment_method']); ?></td><td class="p-4 whitespace-nowrap"><?php echo e($payment['payment_date']); ?></td><td class="p-4"><?php echo render_status_badge('Recorded'); ?></td></tr><?php endforeach; endif; ?>
                    </tbody>
                </table></div>
            </section>

            <section class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden" aria-labelledby="audit-events-heading">
                <div class="p-5 border-b border-gray-100"><h2 id="audit-events-heading" class="font-bold text-gray-900">Recent audit events</h2><p class="text-sm text-gray-500 mt-1">Recent recorded system activity.</p></div>
                <div class="overflow-x-auto"><table class="w-full text-left border-collapse min-w-[700px]">
                    <thead><tr class="bg-gray-50 text-xs font-semibold text-gray-500 uppercase"><th scope="col" class="p-4">Event</th><th scope="col" class="p-4">Actor</th><th scope="col" class="p-4">Record</th><th scope="col" class="p-4">Source IP</th><th scope="col" class="p-4">Time</th></tr></thead>
                    <tbody class="divide-y divide-gray-100 text-sm">
                        <?php if (!$recent_audit_events): ?><tr><td colspan="5" class="p-6 text-center text-gray-500">No audit events have been recorded yet.</td></tr>
                        <?php else: foreach ($recent_audit_events as $event): ?><tr><td class="p-4"><strong class="font-medium text-gray-900"><?php echo e($event['action']); ?></strong><div class="mt-1 text-xs text-gray-500"><?php echo e($event['module']); ?> · <?php echo e($event['description']); ?></div></td><td class="p-4"><?php echo e($event['actor_name'] ?? 'Account no longer available'); ?></td><td class="p-4"><?php echo $event['record_id'] === null ? '—' : '#' . (int)$event['record_id']; ?></td><td class="p-4"><?php echo e($event['ip_address'] ?? '—'); ?></td><td class="p-4 whitespace-nowrap text-gray-500"><?php echo e(date('d M Y, H:i', strtotime($event['created_at']))); ?></td></tr><?php endforeach; endif; ?>
                    </tbody>
                </table></div>
            </section>
            <?php else: ?>
                <section class="notice notice-warning" role="status">
                    <strong>System-wide dashboard restricted.</strong>
                    Global user, financial, and audit summaries are available only to the Super Admin. A role-specific operational dashboard for <?php echo e($user['role_name']); ?> is not yet available here.
                    <div class="mt-4 flex flex-wrap gap-3"><a class="bg-primary text-white px-4 py-2 rounded-lg text-sm font-semibold" href="<?php echo BASE_URL; ?>/public/properties.php">Browse property directory</a></div>
                </section>
            <?php endif; ?>
        </main>
        <?php include __DIR__ . '/../includes/footer.php'; ?>
    </div>
</div>
