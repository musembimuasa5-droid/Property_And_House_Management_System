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
    $cleanupPropertyImage = static function ($path) use ($db) {
        if (!is_string($path) || $path === '') {
            return;
        }
        $reference = $db->prepare('SELECT COUNT(*) FROM properties WHERE property_image = ?');
        $reference->execute([$path]);
        if ((int)$reference->fetchColumn() > 0) {
            return;
        }
        $file = realpath(__DIR__ . '/../' . $path);
        $uploadRoot = realpath(__DIR__ . '/../uploads/properties');
        if ($file && $uploadRoot && strpos($file, $uploadRoot . DIRECTORY_SEPARATOR) === 0 && !unlink($file)) {
            error_log('Unable to remove unreferenced property image: ' . $path);
        }
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
                $amenities = $textValue('amenities');
                $yearBuilt = $textValue('year_built');
                $latitude = $textValue('latitude');
                $longitude = $textValue('longitude');
                $yearBuilt = $yearBuilt === '' ? null : filter_var($yearBuilt, FILTER_VALIDATE_INT);
                $latitude = $latitude === '' ? null : filter_var($latitude, FILTER_VALIDATE_FLOAT);
                $longitude = $longitude === '' ? null : filter_var($longitude, FILTER_VALIDATE_FLOAT);
                if ($name === '' || strlen($name) > 150 || !in_array($propertyType, PROPERTY_TYPES, true) || $address === '' || strlen($address) > 255 ||
                    strlen($description) > 5000 || strlen($amenities) > 2000 ||
                    ($yearBuilt !== null && ($yearBuilt === false || $yearBuilt < 1800 || $yearBuilt > (int)date('Y'))) ||
                    ($latitude !== null && ($latitude === false || $latitude < -90 || $latitude > 90)) ||
                    ($longitude !== null && ($longitude === false || $longitude < -180 || $longitude > 180))) {
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
                    $insert = $db->prepare("INSERT INTO properties (landlord_id, name, property_type, description, location_id, physical_address, latitude, longitude, number_of_units, year_built, amenities, status, property_image)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, 'Active', ?)");
                    $insert->execute([$landlordId, $name, $propertyType, $description !== '' ? $description : null, $locationId, $address, $latitude, $longitude, $yearBuilt, $amenities !== '' ? $amenities : null, $imagePath]);
                    $propertyId = (int)$db->lastInsertId();
                    $audit = $db->prepare("INSERT INTO audit_logs (user_id, action, module, record_id, description, ip_address) VALUES (?, 'Create', 'Properties', ?, ?, ?)");
                    $audit->execute([(int)$user['id'], $propertyId, 'Created property ' . $name, $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN']);
                    $db->commit();
                } catch (Throwable $exception) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    $cleanupPropertyImage($imagePath);
                    throw $exception;
                }
                $_SESSION['admin_dashboard_success'] = 'Property created successfully.';
                redirect('admin/dashboard.php#property-control');
            } elseif ($action === 'update_property') {
                $propertyId = filter_var($textValue('property_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
                $name = $textValue('name');
                $propertyType = $textValue('property_type');
                $locationId = filter_var($textValue('location_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
                $landlordId = filter_var($textValue('landlord_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
                $address = $textValue('physical_address');
                $description = $textValue('description');
                $amenities = $textValue('amenities');
                $yearBuilt = $textValue('year_built');
                $latitude = $textValue('latitude');
                $longitude = $textValue('longitude');
                $yearBuilt = $yearBuilt === '' ? null : filter_var($yearBuilt, FILTER_VALIDATE_INT);
                $latitude = $latitude === '' ? null : filter_var($latitude, FILTER_VALIDATE_FLOAT);
                $longitude = $longitude === '' ? null : filter_var($longitude, FILTER_VALIDATE_FLOAT);
                $removeImage = $textValue('remove_property_image') === '1';
                if (!$propertyId || $name === '' || strlen($name) > 150 || !in_array($propertyType, PROPERTY_TYPES, true) ||
                    !$locationId || !$landlordId || $address === '' || strlen($address) > 255 || strlen($description) > 5000 || strlen($amenities) > 2000 ||
                    ($yearBuilt !== null && ($yearBuilt === false || $yearBuilt < 1800 || $yearBuilt > (int)date('Y'))) ||
                    ($latitude !== null && ($latitude === false || $latitude < -90 || $latitude > 90)) ||
                    ($longitude !== null && ($longitude === false || $longitude < -180 || $longitude > 180))) {
                    throw new InvalidArgumentException('Provide valid property details, location, landlord, and optional coordinates/year built.');
                }
                if (isset($_FILES['property_image']) && $_FILES['property_image']['error'] !== UPLOAD_ERR_NO_FILE && $removeImage) {
                    throw new InvalidArgumentException('Choose either a replacement image or image removal, not both.');
                }
                $validLocation = $db->prepare('SELECT id FROM locations WHERE id = ?');
                $validLocation->execute([$locationId]);
                $currentLandlordQuery = $db->prepare('SELECT landlord_id FROM properties WHERE id = ?');
                $currentLandlordQuery->execute([$propertyId]);
                $currentLandlordId = $currentLandlordQuery->fetchColumn();
                $validLandlord = $db->prepare("SELECT id FROM landlords WHERE id = ? AND (status = 'Active' OR id = ?)");
                $validLandlord->execute([$landlordId, $currentLandlordId === false ? 0 : (int)$currentLandlordId]);
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
                $oldImagePath = null;
                try {
                    $db->beginTransaction();
                    $propertyQuery = $db->prepare('SELECT property_image FROM properties WHERE id = ? FOR UPDATE');
                    $propertyQuery->execute([$propertyId]);
                    $oldImagePath = $propertyQuery->fetchColumn();
                    if ($oldImagePath === false) {
                        throw new InvalidArgumentException('That property no longer exists.');
                    }
                    $newImagePath = $imagePath !== null ? $imagePath : ($removeImage ? null : $oldImagePath);
                    $update = $db->prepare('UPDATE properties SET landlord_id = ?, name = ?, property_type = ?, description = ?, location_id = ?, physical_address = ?, latitude = ?, longitude = ?, year_built = ?, amenities = ?, property_image = ? WHERE id = ?');
                    $update->execute([$landlordId, $name, $propertyType, $description !== '' ? $description : null, $locationId, $address, $latitude, $longitude, $yearBuilt, $amenities !== '' ? $amenities : null, $newImagePath, $propertyId]);
                    $audit = $db->prepare("INSERT INTO audit_logs (user_id, action, module, record_id, description, ip_address) VALUES (?, 'Update', 'Properties', ?, ?, ?)");
                    $audit->execute([(int)$user['id'], $propertyId, 'Updated property ' . $name, $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN']);
                    $db->commit();
                } catch (Throwable $exception) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    $cleanupPropertyImage($imagePath);
                    throw $exception;
                }
                if ($oldImagePath !== false && $oldImagePath !== null && $oldImagePath !== ($imagePath !== null ? $imagePath : ($removeImage ? null : $oldImagePath))) {
                    $cleanupPropertyImage($oldImagePath);
                }
                $_SESSION['admin_dashboard_success'] = 'Property updated successfully.';
                redirect('admin/dashboard.php#property-control');
            } elseif ($action === 'create_unit') {
                $propertyId = filter_var($textValue('property_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
                $unitNumber = $textValue('unit_number');
                $floor = $textValue('floor');
                $houseType = $textValue('house_type');
                $rent = $textValue('monthly_rent');
                $deposit = $textValue('deposit');
                $serviceCharge = $textValue('service_charge');
                $bedrooms = filter_var($textValue('bedrooms'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 50]]);
                $bathrooms = filter_var($textValue('bathrooms'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 50]]);
                $waterBillingMethod = $textValue('water_billing_method') ?: 'Meter Reading';
                $electricityBillingMethod = $textValue('electricity_billing_method') ?: 'Prepaid';
                $description = $textValue('description');
                if (!$propertyId || $unitNumber === '' || strlen($unitNumber) > 50 || strlen($floor) > 20 || !in_array($houseType, UNIT_TYPES, true) ||
                    !is_numeric($rent) || (float)$rent <= 0 || (float)$rent > 9999999999 || !is_numeric($deposit) || (float)$deposit < 0 || (float)$deposit > 9999999999 ||
                    !is_numeric($serviceCharge) || (float)$serviceCharge < 0 || (float)$serviceCharge > 9999999999 ||
                    !in_array($waterBillingMethod, ['Meter Reading', 'Fixed Rate', 'Per Unit', 'Shared'], true) ||
                    !in_array($electricityBillingMethod, ['Prepaid', 'Postpaid Sub-Meter', 'Fixed'], true) ||
                    $bedrooms === false || $bathrooms === false || strlen($description) > 5000) {
                    throw new InvalidArgumentException('Enter a valid property, unit number, unit type, rent, deposit, bedrooms, and bathrooms.');
                }
                $db->beginTransaction();
                $propertyCheck = $db->prepare("SELECT id FROM properties WHERE id = ? AND status = 'Active' FOR UPDATE");
                $propertyCheck->execute([$propertyId]);
                if (!$propertyCheck->fetchColumn()) {
                    throw new InvalidArgumentException('Choose an active property before adding a unit.');
                }
                $insert = $db->prepare("INSERT INTO units (property_id, unit_number, floor, house_type, bedrooms, bathrooms, monthly_rent, deposit, service_charge, water_billing_method, electricity_billing_method, status, description)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Vacant', ?)");
                $insert->execute([$propertyId, $unitNumber, $floor !== '' ? $floor : 'Ground', $houseType, $bedrooms, $bathrooms, $rent, $deposit, $serviceCharge, $waterBillingMethod, $electricityBillingMethod, $description !== '' ? $description : null]);
                $unitId = (int)$db->lastInsertId();
                $db->prepare('UPDATE properties SET number_of_units = number_of_units + 1 WHERE id = ?')->execute([$propertyId]);
                $audit = $db->prepare("INSERT INTO audit_logs (user_id, action, module, record_id, description, ip_address) VALUES (?, 'Create', 'Units', ?, ?, ?)");
                $audit->execute([(int)$user['id'], $unitId, 'Added unit ' . $unitNumber . ' to property #' . $propertyId, $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN']);
                $db->commit();
                $_SESSION['admin_dashboard_success'] = 'Unit created and marked vacant.';
                redirect('admin/dashboard.php#property-control');
            } elseif ($action === 'update_unit') {
                $unitId = filter_var($textValue('unit_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
                $unitNumber = $textValue('unit_number');
                $floor = $textValue('floor');
                $houseType = $textValue('house_type');
                $rent = $textValue('monthly_rent');
                $deposit = $textValue('deposit');
                $serviceCharge = $textValue('service_charge');
                $bedrooms = filter_var($textValue('bedrooms'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 50]]);
                $bathrooms = filter_var($textValue('bathrooms'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 50]]);
                $waterBillingMethod = $textValue('water_billing_method');
                $electricityBillingMethod = $textValue('electricity_billing_method');
                $description = $textValue('description');
                if (!$unitId || $unitNumber === '' || strlen($unitNumber) > 50 || strlen($floor) > 20 || !in_array($houseType, UNIT_TYPES, true) ||
                    !is_numeric($rent) || (float)$rent <= 0 || (float)$rent > 9999999999 || !is_numeric($deposit) || (float)$deposit < 0 || (float)$deposit > 9999999999 ||
                    !is_numeric($serviceCharge) || (float)$serviceCharge < 0 || (float)$serviceCharge > 9999999999 ||
                    !in_array($waterBillingMethod, ['Meter Reading', 'Fixed Rate', 'Per Unit', 'Shared'], true) ||
                    !in_array($electricityBillingMethod, ['Prepaid', 'Postpaid Sub-Meter', 'Fixed'], true) ||
                    $bedrooms === false || $bathrooms === false || strlen($description) > 5000) {
                    throw new InvalidArgumentException('Enter valid unit details, rent, deposit, billing methods, bedrooms, and bathrooms.');
                }
                $db->beginTransaction();
                $unitQuery = $db->prepare('SELECT id, property_id, unit_number, status FROM units WHERE id = ? FOR UPDATE');
                $unitQuery->execute([$unitId]);
                $unit = $unitQuery->fetch();
                if (!$unit) {
                    throw new InvalidArgumentException('That unit no longer exists.');
                }
                $leaseQuery = $db->prepare("SELECT id FROM leases WHERE unit_id = ? AND status = 'Active' AND end_date >= CURRENT_DATE() LIMIT 1");
                $leaseQuery->execute([$unitId]);
                if ($unit['status'] === 'Occupied' || $leaseQuery->fetchColumn()) {
                    throw new InvalidArgumentException('Units with active occupancy must be updated through lease management.');
                }
                $update = $db->prepare('UPDATE units SET unit_number = ?, floor = ?, house_type = ?, bedrooms = ?, bathrooms = ?, monthly_rent = ?, deposit = ?, service_charge = ?, water_billing_method = ?, electricity_billing_method = ?, description = ? WHERE id = ?');
                $update->execute([$unitNumber, $floor !== '' ? $floor : 'Ground', $houseType, $bedrooms, $bathrooms, $rent, $deposit, $serviceCharge, $waterBillingMethod, $electricityBillingMethod, $description !== '' ? $description : null, $unitId]);
                $audit = $db->prepare("INSERT INTO audit_logs (user_id, action, module, record_id, description, ip_address) VALUES (?, 'Update', 'Units', ?, ?, ?)");
                $audit->execute([(int)$user['id'], $unitId, 'Updated unit ' . $unitNumber . ' on property #' . $unit['property_id'], $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN']);
                $db->commit();
                $_SESSION['admin_dashboard_success'] = 'Unit updated successfully.';
                redirect('admin/dashboard.php#property-control');
            } elseif ($action === 'delete_unit') {
                $unitId = filter_var($textValue('unit_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
                if (!$unitId) {
                    throw new InvalidArgumentException('Choose a valid unit to delete.');
                }
                $db->beginTransaction();
                $unitQuery = $db->prepare('SELECT id, property_id, unit_number, status FROM units WHERE id = ? FOR UPDATE');
                $unitQuery->execute([$unitId]);
                $unit = $unitQuery->fetch();
                if (!$unit) {
                    throw new InvalidArgumentException('That unit no longer exists.');
                }
                if ($unit['status'] === 'Occupied') {
                    throw new InvalidArgumentException('An occupied unit cannot be permanently deleted.');
                }
                $historyQuery = $db->prepare("SELECT
                    (SELECT COUNT(*) FROM leases WHERE unit_id = ?) +
                    (SELECT COUNT(*) FROM payments WHERE unit_id = ?) +
                    (SELECT COUNT(*) FROM rent_charges WHERE unit_id = ?) +
                    (SELECT COUNT(*) FROM maintenance_requests WHERE unit_id = ?) +
                    (SELECT COUNT(*) FROM utility_readings WHERE unit_id = ?) +
                    (SELECT COUNT(*) FROM viewing_requests WHERE unit_id = ?) AS linked_records");
                $historyQuery->execute(array_fill(0, 6, $unitId));
                if ((int)$historyQuery->fetchColumn() > 0) {
                    throw new InvalidArgumentException('This unit has linked tenancy, financial, maintenance, utility, or viewing history. Mark it unavailable instead of deleting it.');
                }
                $db->prepare('DELETE FROM units WHERE id = ?')->execute([$unitId]);
                $db->prepare('UPDATE properties SET number_of_units = (SELECT COUNT(*) FROM units WHERE property_id = ?) WHERE id = ?')->execute([$unit['property_id'], $unit['property_id']]);
                $audit = $db->prepare("INSERT INTO audit_logs (user_id, action, module, record_id, description, ip_address) VALUES (?, 'Delete', 'Units', ?, ?, ?)");
                $audit->execute([(int)$user['id'], $unitId, 'Deleted unreferenced unit ' . $unit['unit_number'] . ' from property #' . $unit['property_id'], $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN']);
                $db->commit();
                $_SESSION['admin_dashboard_success'] = 'Unreferenced unit deleted.';
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
                    $occupiedUnitQuery = $db->prepare("SELECT id FROM units WHERE property_id = ? AND status = 'Occupied' LIMIT 1");
                    $occupiedUnitQuery->execute([$propertyId]);
                    if ($leaseQuery->fetchColumn() || $occupiedUnitQuery->fetchColumn()) {
                        throw new InvalidArgumentException('A property with an active lease or occupied unit cannot be archived.');
                    }
                }
                $db->prepare('UPDATE properties SET status = ? WHERE id = ?')->execute([$status, $propertyId]);
                $audit = $db->prepare("INSERT INTO audit_logs (user_id, action, module, record_id, description, ip_address) VALUES (?, 'Update status', 'Properties', ?, ?, ?)");
                $audit->execute([(int)$user['id'], $propertyId, 'Changed property status to ' . $status, $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN']);
                $db->commit();
                $_SESSION['admin_dashboard_success'] = 'Property status updated.';
                redirect('admin/dashboard.php#property-control');
            } elseif ($action === 'delete_property') {
                $propertyId = filter_var($textValue('property_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
                if (!$propertyId) {
                    throw new InvalidArgumentException('Choose a valid property to delete.');
                }
                $db->beginTransaction();
                $propertyQuery = $db->prepare('SELECT id, name, property_image FROM properties WHERE id = ? FOR UPDATE');
                $propertyQuery->execute([$propertyId]);
                $property = $propertyQuery->fetch();
                if (!$property) {
                    throw new InvalidArgumentException('That property no longer exists.');
                }
                $historyQuery = $db->prepare("SELECT
                    (SELECT COUNT(*) FROM units WHERE property_id = ?) +
                    (SELECT COUNT(*) FROM leases WHERE property_id = ?) +
                    (SELECT COUNT(*) FROM payments WHERE property_id = ?) +
                    (SELECT COUNT(*) FROM maintenance_requests WHERE property_id = ?) +
                    (SELECT COUNT(*) FROM complaints WHERE property_id = ?) +
                    (SELECT COUNT(*) FROM expenses WHERE property_id = ?) +
                    (SELECT COUNT(*) FROM documents WHERE property_id = ?) +
                    (SELECT COUNT(*) FROM viewing_requests WHERE property_id = ?) AS linked_records");
                $historyQuery->execute(array_fill(0, 8, $propertyId));
                if ((int)$historyQuery->fetchColumn() > 0) {
                    throw new InvalidArgumentException('This property has linked operational or historical records. Archive it instead of deleting it.');
                }
                $audit = $db->prepare("INSERT INTO audit_logs (user_id, action, module, record_id, description, ip_address) VALUES (?, 'Delete', 'Properties', ?, ?, ?)");
                $audit->execute([(int)$user['id'], $propertyId, 'Deleted unreferenced property ' . $property['name'], $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN']);
                $db->prepare('DELETE FROM properties WHERE id = ?')->execute([$propertyId]);
                $db->commit();
                $cleanupPropertyImage($property['property_image']);
                $_SESSION['admin_dashboard_success'] = 'Unreferenced property deleted.';
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
            $dashboardError = 'The change could not be saved. Check field values and linked records, then try again.';
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

    $propertySearch = isset($_GET['property_q']) && is_string($_GET['property_q']) ? trim($_GET['property_q']) : '';
    $propertySearch = mb_substr($propertySearch, 0, 100);
    $propertyStatusFilter = isset($_GET['property_status']) && is_string($_GET['property_status']) ? $_GET['property_status'] : '';
    if (!in_array($propertyStatusFilter, ['Active', 'Under Maintenance', 'Archived'], true)) {
        $propertyStatusFilter = '';
    }
    $propertyPageInput = isset($_GET['property_page']) && is_scalar($_GET['property_page']) ? (string)$_GET['property_page'] : '1';
    $propertyPage = max(1, filter_var($propertyPageInput, FILTER_VALIDATE_INT) ?: 1);
    $pageSize = 10;
    $propertyWhere = [];
    $propertyParams = [];
    if ($propertySearch !== '') {
        $propertyWhere[] = '(p.name LIKE ? OR p.physical_address LIKE ? OR l.estate LIKE ? OR l.sub_county LIKE ? OR ll.full_name LIKE ? OR ll.company_name LIKE ?)';
        $searchLike = '%' . $propertySearch . '%';
        $propertyParams = array_merge($propertyParams, array_fill(0, 6, $searchLike));
    }
    if ($propertyStatusFilter !== '') {
        $propertyWhere[] = 'p.status = ?';
        $propertyParams[] = $propertyStatusFilter;
    }
    $propertyWhereSql = $propertyWhere ? ' WHERE ' . implode(' AND ', $propertyWhere) : '';
    $propertyCountQuery = $db->prepare("SELECT COUNT(*) FROM properties p JOIN locations l ON l.id = p.location_id JOIN landlords ll ON ll.id = p.landlord_id" . $propertyWhereSql);
    $propertyCountQuery->execute($propertyParams);
    $propertyCount = (int)$propertyCountQuery->fetchColumn();
    $propertyPageCount = max(1, (int)ceil($propertyCount / $pageSize));
    $propertyPage = min($propertyPage, $propertyPageCount);
    $propertyOffset = ($propertyPage - 1) * $pageSize;
    $propertyRowsQuery = $db->prepare("SELECT p.id, p.landlord_id, p.name, p.property_type, p.description, p.location_id, p.physical_address,
            p.latitude, p.longitude, p.year_built, p.amenities, p.status, p.property_image, l.estate, l.sub_county,
            ll.full_name AS landlord_name, ll.company_name, ll.status AS landlord_status,
            COALESCE(ut.actual_units, 0) AS actual_units, COALESCE(ut.occupied_units, 0) AS occupied_units,
            CASE WHEN NOT EXISTS (SELECT 1 FROM units u WHERE u.property_id = p.id)
                AND NOT EXISTS (SELECT 1 FROM leases le WHERE le.property_id = p.id)
                AND NOT EXISTS (SELECT 1 FROM payments pay WHERE pay.property_id = p.id)
                AND NOT EXISTS (SELECT 1 FROM maintenance_requests mr WHERE mr.property_id = p.id)
                AND NOT EXISTS (SELECT 1 FROM complaints c WHERE c.property_id = p.id)
                AND NOT EXISTS (SELECT 1 FROM expenses ex WHERE ex.property_id = p.id)
                AND NOT EXISTS (SELECT 1 FROM documents d WHERE d.property_id = p.id)
                AND NOT EXISTS (SELECT 1 FROM viewing_requests vr WHERE vr.property_id = p.id)
                THEN 1 ELSE 0 END AS safe_to_delete
        FROM properties p
        JOIN locations l ON l.id = p.location_id
        JOIN landlords ll ON ll.id = p.landlord_id
        LEFT JOIN (SELECT property_id, COUNT(*) AS actual_units, SUM(status = 'Occupied') AS occupied_units FROM units GROUP BY property_id) ut ON ut.property_id = p.id" .
        $propertyWhereSql . " ORDER BY p.created_at DESC, p.id DESC LIMIT ? OFFSET ?");
    foreach ($propertyParams as $index => $value) {
        $propertyRowsQuery->bindValue($index + 1, $value, PDO::PARAM_STR);
    }
    $propertyRowsQuery->bindValue(count($propertyParams) + 1, $pageSize, PDO::PARAM_INT);
    $propertyRowsQuery->bindValue(count($propertyParams) + 2, $propertyOffset, PDO::PARAM_INT);
    $propertyRowsQuery->execute();
    $propertyRows = $propertyRowsQuery->fetchAll();

    $unitSearch = isset($_GET['unit_q']) && is_string($_GET['unit_q']) ? trim($_GET['unit_q']) : '';
    $unitSearch = mb_substr($unitSearch, 0, 100);
    $unitStatusFilter = isset($_GET['unit_status']) && is_string($_GET['unit_status']) ? $_GET['unit_status'] : '';
    if (!in_array($unitStatusFilter, ['Vacant', 'Occupied', 'Reserved', 'Under Maintenance', 'Unavailable'], true)) {
        $unitStatusFilter = '';
    }
    $unitPageInput = isset($_GET['unit_page']) && is_scalar($_GET['unit_page']) ? (string)$_GET['unit_page'] : '1';
    $unitPage = max(1, filter_var($unitPageInput, FILTER_VALIDATE_INT) ?: 1);
    $unitWhere = [];
    $unitParams = [];
    if ($unitSearch !== '') {
        $unitWhere[] = '(u.unit_number LIKE ? OR p.name LIKE ? OR u.house_type LIKE ?)';
        $unitLike = '%' . $unitSearch . '%';
        $unitParams = array_fill(0, 3, $unitLike);
    }
    if ($unitStatusFilter !== '') {
        $unitWhere[] = 'u.status = ?';
        $unitParams[] = $unitStatusFilter;
    }
    $unitWhereSql = $unitWhere ? ' WHERE ' . implode(' AND ', $unitWhere) : '';
    $unitCountQuery = $db->prepare('SELECT COUNT(*) FROM units u JOIN properties p ON p.id = u.property_id' . $unitWhereSql);
    $unitCountQuery->execute($unitParams);
    $unitCount = (int)$unitCountQuery->fetchColumn();
    $unitPageCount = max(1, (int)ceil($unitCount / $pageSize));
    $unitPage = min($unitPage, $unitPageCount);
    $unitOffset = ($unitPage - 1) * $pageSize;
    $unitRowsQuery = $db->prepare("SELECT u.id, u.property_id, u.unit_number, u.floor, u.house_type, u.bedrooms, u.bathrooms,
            u.monthly_rent, u.deposit, u.service_charge, u.water_billing_method, u.electricity_billing_method,
            u.description, u.status, p.name AS property_name,
            EXISTS (SELECT 1 FROM leases le WHERE le.unit_id = u.id AND le.status = 'Active' AND le.end_date >= CURRENT_DATE()) AS has_active_lease,
            CASE WHEN u.status <> 'Occupied'
                AND NOT EXISTS (SELECT 1 FROM leases le WHERE le.unit_id = u.id)
                AND NOT EXISTS (SELECT 1 FROM payments pay WHERE pay.unit_id = u.id)
                AND NOT EXISTS (SELECT 1 FROM rent_charges rc WHERE rc.unit_id = u.id)
                AND NOT EXISTS (SELECT 1 FROM maintenance_requests mr WHERE mr.unit_id = u.id)
                AND NOT EXISTS (SELECT 1 FROM utility_readings ur WHERE ur.unit_id = u.id)
                AND NOT EXISTS (SELECT 1 FROM viewing_requests vr WHERE vr.unit_id = u.id)
                THEN 1 ELSE 0 END AS safe_to_delete
        FROM units u JOIN properties p ON p.id = u.property_id" . $unitWhereSql .
        " ORDER BY p.name, u.unit_number LIMIT ? OFFSET ?");
    foreach ($unitParams as $index => $value) {
        $unitRowsQuery->bindValue($index + 1, $value, PDO::PARAM_STR);
    }
    $unitRowsQuery->bindValue(count($unitParams) + 1, $pageSize, PDO::PARAM_INT);
    $unitRowsQuery->bindValue(count($unitParams) + 2, $unitOffset, PDO::PARAM_INT);
    $unitRowsQuery->execute();
    $unitRows = $unitRowsQuery->fetchAll();
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
                            <label class="text-sm text-gray-700 sm:col-span-2">Amenities<input name="amenities" maxlength="2000" class="mt-1 w-full rounded-lg border-gray-300" placeholder="Parking, CCTV, borehole"></label>
                            <label class="text-sm text-gray-700">Year built<input type="number" name="year_built" min="1800" max="<?php echo (int)date('Y'); ?>" class="mt-1 w-full rounded-lg border-gray-300"></label>
                            <label class="text-sm text-gray-700">Latitude<input type="number" name="latitude" min="-90" max="90" step="0.00000001" class="mt-1 w-full rounded-lg border-gray-300"></label>
                            <label class="text-sm text-gray-700">Longitude<input type="number" name="longitude" min="-180" max="180" step="0.00000001" class="mt-1 w-full rounded-lg border-gray-300"></label>
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
                            <label class="text-sm text-gray-700">Service charge (KSh) <input type="number" name="service_charge" min="0" step="0.01" value="0" required class="mt-1 w-full rounded-lg border-gray-300"></label>
                            <label class="text-sm text-gray-700">Bedrooms <input type="number" name="bedrooms" min="0" max="50" value="1" required class="mt-1 w-full rounded-lg border-gray-300"></label>
                            <label class="text-sm text-gray-700">Bathrooms <input type="number" name="bathrooms" min="0" max="50" value="1" required class="mt-1 w-full rounded-lg border-gray-300"></label>
                            <label class="text-sm text-gray-700">Water billing<select name="water_billing_method" class="mt-1 w-full rounded-lg border-gray-300"><?php foreach (['Meter Reading', 'Fixed Rate', 'Per Unit', 'Shared'] as $method): ?><option value="<?php echo e($method); ?>"><?php echo e($method); ?></option><?php endforeach; ?></select></label>
                            <label class="text-sm text-gray-700">Electricity billing<select name="electricity_billing_method" class="mt-1 w-full rounded-lg border-gray-300"><?php foreach (['Prepaid', 'Postpaid Sub-Meter', 'Fixed'] as $method): ?><option value="<?php echo e($method); ?>"><?php echo e($method); ?></option><?php endforeach; ?></select></label>
                            <label class="text-sm text-gray-700 sm:col-span-2">Description<textarea name="description" maxlength="5000" rows="2" class="mt-1 w-full rounded-lg border-gray-300"></textarea></label>
                            <button class="sm:col-span-2 justify-self-start rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-blue-800 focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2">Create vacant unit</button>
                        </form><?php endif; ?>
                    </details>
                </div>
                <form method="get" class="flex flex-col sm:flex-row gap-3 p-5 border-b border-gray-100">
                    <label class="sr-only" for="property-q">Search properties</label><input id="property-q" name="property_q" value="<?php echo e($propertySearch); ?>" placeholder="Search name, address, estate, or landlord" class="flex-1 rounded-lg border-gray-300 text-sm">
                    <label class="sr-only" for="property-status-filter">Filter properties by status</label><select id="property-status-filter" name="property_status" class="rounded-lg border-gray-300 text-sm"><option value="">All property statuses</option><?php foreach (['Active', 'Under Maintenance', 'Archived'] as $status): ?><option value="<?php echo e($status); ?>" <?php echo $propertyStatusFilter === $status ? 'selected' : ''; ?>><?php echo e($status); ?></option><?php endforeach; ?></select>
                    <button class="rounded-lg bg-gray-100 px-4 py-2 text-sm font-semibold">Search properties</button>
                </form>
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse min-w-[760px]">
                        <thead><tr class="bg-gray-50 text-xs font-semibold text-gray-500 uppercase"><th scope="col" class="p-4">Property</th><th scope="col" class="p-4">Location</th><th scope="col" class="p-4">Landlord</th><th scope="col" class="p-4">Units</th><th scope="col" class="p-4">Status</th><th scope="col" class="p-4">Actions</th></tr></thead>
                        <tbody class="divide-y divide-gray-100 text-sm">
                            <?php if (!$propertyRows): ?><tr><td colspan="6" class="p-6 text-center text-gray-500">No properties have been recorded.</td></tr>
                            <?php else: foreach ($propertyRows as $property): ?>
                                <tr>
                                    <th scope="row" class="p-4 font-medium text-gray-900"><?php echo e($property['name']); ?><span class="block text-xs font-normal text-gray-500"><?php echo e($property['property_type']); ?></span></th>
                                    <td class="p-4"><?php echo e($property['estate'] . ', ' . $property['sub_county']); ?></td>
                                    <td class="p-4"><?php echo e($property['landlord_name'] . ($property['company_name'] ? ' · ' . $property['company_name'] : '')); ?></td>
                                    <td class="p-4"><?php echo (int)$property['actual_units']; ?> unit(s) · <?php echo (int)$property['occupied_units']; ?> occupied</td>
                                    <td class="p-4"><?php echo render_status_badge($property['status']); ?></td>
                                    <td class="p-4">
                                        <details class="mb-2 rounded border border-gray-200 p-2">
                                            <summary class="cursor-pointer text-xs font-semibold text-primary">Edit property details</summary>
                                            <form method="post" enctype="multipart/form-data" class="mt-3 grid grid-cols-1 gap-2 min-w-[260px]">
                                                <?php echo csrf_field(); ?><input type="hidden" name="dashboard_action" value="update_property"><input type="hidden" name="property_id" value="<?php echo (int)$property['id']; ?>">
                                                <label class="text-xs">Name<input name="name" maxlength="150" required value="<?php echo e($property['name']); ?>" class="mt-1 w-full rounded border-gray-300 text-xs"></label>
                                                <label class="text-xs">Type<select name="property_type" required class="mt-1 w-full rounded border-gray-300 text-xs"><?php foreach (PROPERTY_TYPES as $type): ?><option value="<?php echo e($type); ?>" <?php echo $property['property_type'] === $type ? 'selected' : ''; ?>><?php echo e($type); ?></option><?php endforeach; ?></select></label>
                                                <label class="text-xs">Neighborhood<select name="location_id" required class="mt-1 w-full rounded border-gray-300 text-xs"><?php foreach ($locations as $location): ?><option value="<?php echo (int)$location['id']; ?>" <?php echo (int)$property['location_id'] === (int)$location['id'] ? 'selected' : ''; ?>><?php echo e($location['estate'] . ' · ' . $location['sub_county']); ?></option><?php endforeach; ?></select></label>
                                                <label class="text-xs">Landlord<select name="landlord_id" required class="mt-1 w-full rounded border-gray-300 text-xs"><?php if ($property['landlord_status'] !== 'Active'): ?><option value="<?php echo (int)$property['landlord_id']; ?>" selected><?php echo e($property['landlord_name'] . ' · Inactive (current assignment)'); ?></option><?php endif; ?><?php foreach ($landlords as $landlord): ?><option value="<?php echo (int)$landlord['id']; ?>" <?php echo (int)$property['landlord_id'] === (int)$landlord['id'] ? 'selected' : ''; ?>><?php echo e($landlord['company_name'] ? $landlord['full_name'] . ' · ' . $landlord['company_name'] : $landlord['full_name']); ?></option><?php endforeach; ?></select></label>
                                                <label class="text-xs">Physical address<input name="physical_address" maxlength="255" required value="<?php echo e($property['physical_address']); ?>" class="mt-1 w-full rounded border-gray-300 text-xs"></label>
                                                <label class="text-xs">Description<textarea name="description" maxlength="5000" rows="2" class="mt-1 w-full rounded border-gray-300 text-xs"><?php echo e($property['description']); ?></textarea></label>
                                                <label class="text-xs">Amenities<input name="amenities" maxlength="2000" value="<?php echo e($property['amenities']); ?>" class="mt-1 w-full rounded border-gray-300 text-xs"></label>
                                                <label class="text-xs">Year built<input type="number" name="year_built" min="1800" max="<?php echo (int)date('Y'); ?>" value="<?php echo e($property['year_built']); ?>" class="mt-1 w-full rounded border-gray-300 text-xs"></label>
                                                <label class="text-xs">Latitude<input type="number" name="latitude" min="-90" max="90" step="0.00000001" value="<?php echo e($property['latitude']); ?>" class="mt-1 w-full rounded border-gray-300 text-xs"></label>
                                                <label class="text-xs">Longitude<input type="number" name="longitude" min="-180" max="180" step="0.00000001" value="<?php echo e($property['longitude']); ?>" class="mt-1 w-full rounded border-gray-300 text-xs"></label>
                                                <?php $propertyImagePath = trim((string)$property['property_image'], '/'); $propertyImageFile = $propertyImagePath !== '' ? dirname(__DIR__) . '/' . $propertyImagePath : ''; $propertyImageAvailable = $propertyImagePath !== '' && is_file($propertyImageFile); ?><img src="<?php echo BASE_URL . '/' . e($propertyImageAvailable ? $propertyImagePath : sample_property_image($property['id'])); ?>" alt="<?php echo $propertyImageAvailable ? 'Current property image' : 'Fallback sample image; property photo is unavailable'; ?>" class="h-20 w-full rounded object-cover">
                                                <label class="text-xs">Replace image (JPG/PNG, max 5 MB)<input type="file" name="property_image" accept=".jpg,.jpeg,.png,image/jpeg,image/png" class="mt-1 block w-full text-xs"></label>
                                                <?php if ($property['property_image']): ?><label class="text-xs"><input type="checkbox" name="remove_property_image" value="1"> Remove current image</label><?php endif; ?>
                                                <button class="rounded bg-primary px-3 py-2 text-xs font-semibold text-white">Save property</button>
                                            </form>
                                        </details>
                                        <form method="post" class="flex items-center gap-2" onsubmit="return this.elements.status.value !== 'Archived' || confirm('Archive this property? Historical records will be retained.');">
                                            <?php echo csrf_field(); ?><input type="hidden" name="dashboard_action" value="set_property_status"><input type="hidden" name="property_id" value="<?php echo (int)$property['id']; ?>">
                                            <label class="sr-only" for="property-status-<?php echo (int)$property['id']; ?>">Property status</label>
                                            <select id="property-status-<?php echo (int)$property['id']; ?>" name="status" class="rounded border-gray-300 text-xs">
                                                <?php foreach (['Active', 'Under Maintenance', 'Archived'] as $status): ?><option value="<?php echo e($status); ?>" <?php echo $property['status'] === $status ? 'selected' : ''; ?>><?php echo e($status); ?></option><?php endforeach; ?>
                                            </select><button class="rounded bg-gray-100 px-2 py-1 text-xs font-semibold hover:bg-gray-200">Save</button>
                                        </form>
                                        <?php if ((int)$property['safe_to_delete'] === 1): ?><form method="post" class="mt-2" onsubmit="return confirm('Permanently delete this property? This is allowed only because no linked records exist.');"><?php echo csrf_field(); ?><input type="hidden" name="dashboard_action" value="delete_property"><input type="hidden" name="property_id" value="<?php echo (int)$property['id']; ?>"><button class="text-xs font-semibold text-red-700 underline">Delete permanently</button></form><?php else: ?><p class="mt-2 text-xs text-gray-500">Delete unavailable: linked records exist.</p><?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="flex items-center justify-between p-4 text-sm text-gray-600">
                    <span><?php echo number_format($propertyCount); ?> properties · page <?php echo (int)$propertyPage; ?> of <?php echo (int)$propertyPageCount; ?></span>
                    <div class="flex gap-3"><?php if ($propertyPage > 1): ?><a class="font-semibold text-primary hover:underline" href="<?php echo e('?' . http_build_query(array_merge($_GET, ['property_page' => $propertyPage - 1])) . '#property-control'); ?>">Previous</a><?php endif; ?><?php if ($propertyPage < $propertyPageCount): ?><a class="font-semibold text-primary hover:underline" href="<?php echo e('?' . http_build_query(array_merge($_GET, ['property_page' => $propertyPage + 1])) . '#property-control'); ?>">Next</a><?php endif; ?></div>
                </div>
                <div class="overflow-x-auto border-t border-gray-100">
                    <form method="get" class="flex flex-col sm:flex-row gap-3 p-5 border-b border-gray-100">
                        <label class="sr-only" for="unit-q">Search units</label><input id="unit-q" name="unit_q" value="<?php echo e($unitSearch); ?>" placeholder="Search unit number, property, or type" class="flex-1 rounded-lg border-gray-300 text-sm">
                        <label class="sr-only" for="unit-status-filter">Filter units by status</label><select id="unit-status-filter" name="unit_status" class="rounded-lg border-gray-300 text-sm"><option value="">All unit statuses</option><?php foreach (['Vacant', 'Occupied', 'Reserved', 'Under Maintenance', 'Unavailable'] as $status): ?><option value="<?php echo e($status); ?>" <?php echo $unitStatusFilter === $status ? 'selected' : ''; ?>><?php echo e($status); ?></option><?php endforeach; ?></select>
                        <button class="rounded-lg bg-gray-100 px-4 py-2 text-sm font-semibold">Search units</button>
                    </form>
                    <table class="w-full text-left border-collapse min-w-[800px]">
                        <thead><tr class="bg-gray-50 text-xs font-semibold text-gray-500 uppercase"><th scope="col" class="p-4">Property / unit</th><th scope="col" class="p-4">Type</th><th scope="col" class="p-4">Rent</th><th scope="col" class="p-4">Current status</th><th scope="col" class="p-4">Quick status</th></tr></thead>
                        <tbody class="divide-y divide-gray-100 text-sm">
                            <?php if (!$unitRows): ?><tr><td colspan="5" class="p-6 text-center text-gray-500">No units have been recorded.</td></tr>
                            <?php else: foreach ($unitRows as $unit): ?>
                                <tr><th scope="row" class="p-4 font-medium text-gray-900"><?php echo e($unit['property_name']); ?><span class="block text-xs font-normal text-gray-500">Unit <?php echo e($unit['unit_number']); ?></span></th><td class="p-4"><?php echo e($unit['house_type']); ?></td><td class="p-4"><?php echo format_currency($unit['monthly_rent']); ?></td><td class="p-4"><?php echo render_status_badge($unit['status']); ?></td><td class="p-4">
                                    <details class="mb-2 rounded border border-gray-200 p-2">
                                        <summary class="cursor-pointer text-xs font-semibold text-primary">Edit unit</summary>
                                        <form method="post" class="mt-3 grid grid-cols-1 gap-2 min-w-[260px]">
                                            <?php echo csrf_field(); ?><input type="hidden" name="dashboard_action" value="update_unit"><input type="hidden" name="unit_id" value="<?php echo (int)$unit['id']; ?>">
                                            <label class="text-xs">Unit number<input name="unit_number" maxlength="50" required value="<?php echo e($unit['unit_number']); ?>" class="mt-1 w-full rounded border-gray-300 text-xs"></label>
                                            <label class="text-xs">Floor<input name="floor" maxlength="20" value="<?php echo e($unit['floor']); ?>" class="mt-1 w-full rounded border-gray-300 text-xs"></label>
                                            <label class="text-xs">Unit type<select name="house_type" required class="mt-1 w-full rounded border-gray-300 text-xs"><?php foreach (UNIT_TYPES as $type): ?><option value="<?php echo e($type); ?>" <?php echo $unit['house_type'] === $type ? 'selected' : ''; ?>><?php echo e($type); ?></option><?php endforeach; ?></select></label>
                                            <label class="text-xs">Bedrooms<input type="number" name="bedrooms" min="0" max="50" required value="<?php echo (int)$unit['bedrooms']; ?>" class="mt-1 w-full rounded border-gray-300 text-xs"></label>
                                            <label class="text-xs">Bathrooms<input type="number" name="bathrooms" min="0" max="50" required value="<?php echo (int)$unit['bathrooms']; ?>" class="mt-1 w-full rounded border-gray-300 text-xs"></label>
                                            <label class="text-xs">Monthly rent (KSh)<input type="number" name="monthly_rent" min="0.01" step="0.01" required value="<?php echo e($unit['monthly_rent']); ?>" class="mt-1 w-full rounded border-gray-300 text-xs"></label>
                                            <label class="text-xs">Deposit (KSh)<input type="number" name="deposit" min="0" step="0.01" required value="<?php echo e($unit['deposit']); ?>" class="mt-1 w-full rounded border-gray-300 text-xs"></label>
                                            <label class="text-xs">Service charge (KSh)<input type="number" name="service_charge" min="0" step="0.01" required value="<?php echo e($unit['service_charge']); ?>" class="mt-1 w-full rounded border-gray-300 text-xs"></label>
                                            <label class="text-xs">Water billing<select name="water_billing_method" class="mt-1 w-full rounded border-gray-300 text-xs"><?php foreach (['Meter Reading', 'Fixed Rate', 'Per Unit', 'Shared'] as $method): ?><option value="<?php echo e($method); ?>" <?php echo $unit['water_billing_method'] === $method ? 'selected' : ''; ?>><?php echo e($method); ?></option><?php endforeach; ?></select></label>
                                            <label class="text-xs">Electricity billing<select name="electricity_billing_method" class="mt-1 w-full rounded border-gray-300 text-xs"><?php foreach (['Prepaid', 'Postpaid Sub-Meter', 'Fixed'] as $method): ?><option value="<?php echo e($method); ?>" <?php echo $unit['electricity_billing_method'] === $method ? 'selected' : ''; ?>><?php echo e($method); ?></option><?php endforeach; ?></select></label>
                                            <label class="text-xs">Description<textarea name="description" maxlength="5000" rows="2" class="mt-1 w-full rounded border-gray-300 text-xs"><?php echo e($unit['description']); ?></textarea></label>
                                            <button class="rounded bg-primary px-3 py-2 text-xs font-semibold text-white">Save unit</button>
                                        </form>
                                    </details>
                                    <?php if ($unit['status'] !== 'Occupied' && !(int)$unit['has_active_lease']): ?>
                                    <form method="post" class="flex items-center gap-2" onsubmit="return this.elements.status.value !== 'Unavailable' || confirm('Archive this unit by marking it unavailable? Historical records will be retained.');">
                                        <?php echo csrf_field(); ?><input type="hidden" name="dashboard_action" value="set_unit_status"><input type="hidden" name="unit_id" value="<?php echo (int)$unit['id']; ?>">
                                        <label class="sr-only" for="unit-status-<?php echo (int)$unit['id']; ?>">Unit status</label>
                                        <select id="unit-status-<?php echo (int)$unit['id']; ?>" name="status" class="rounded border-gray-300 text-xs">
                                            <?php foreach (['Vacant', 'Reserved', 'Under Maintenance', 'Unavailable'] as $status): ?><option value="<?php echo e($status); ?>" <?php echo $unit['status'] === $status ? 'selected' : ''; ?>><?php echo e($status); ?></option><?php endforeach; ?>
                                        </select><button class="rounded bg-gray-100 px-2 py-1 text-xs font-semibold hover:bg-gray-200">Update</button>
                                    </form>
                                    <?php else: ?><span class="text-xs text-gray-500">Managed by lease</span><?php endif; ?>
                                    <?php if ((int)$unit['safe_to_delete'] === 1): ?><form method="post" class="mt-2" onsubmit="return confirm('Permanently delete this unit? It has no linked records.');"><?php echo csrf_field(); ?><input type="hidden" name="dashboard_action" value="delete_unit"><input type="hidden" name="unit_id" value="<?php echo (int)$unit['id']; ?>"><button class="text-xs font-semibold text-red-700 underline">Delete permanently</button></form><?php else: ?><p class="mt-2 text-xs text-gray-500">Delete unavailable: linked records exist.</p><?php endif; ?>
                                </td></tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="flex items-center justify-between border-t border-gray-100 p-4 text-sm text-gray-600">
                    <span><?php echo number_format($unitCount); ?> units · page <?php echo (int)$unitPage; ?> of <?php echo (int)$unitPageCount; ?></span>
                    <div class="flex gap-3"><?php if ($unitPage > 1): ?><a class="font-semibold text-primary hover:underline" href="<?php echo e('?' . http_build_query(array_merge($_GET, ['unit_page' => $unitPage - 1])) . '#property-control'); ?>">Previous</a><?php endif; ?><?php if ($unitPage < $unitPageCount): ?><a class="font-semibold text-primary hover:underline" href="<?php echo e('?' . http_build_query(array_merge($_GET, ['unit_page' => $unitPage + 1])) . '#property-control'); ?>">Next</a><?php endif; ?></div>
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
