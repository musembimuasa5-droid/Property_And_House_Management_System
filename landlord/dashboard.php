<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/upload_helper.php';
requireRole('Landlord');
$db = db();
$user = getCurrentUser();
$profileQuery = $db->prepare('SELECT id, full_name FROM landlords WHERE user_id = ? LIMIT 1');
$profileQuery->execute([$user['id']]);
$landlord = $profileQuery->fetch();
if (!$landlord) { http_response_code(403); exit('Landlord profile is not linked to this account. Please contact an administrator.'); }
$ownerId = (int)$landlord['id'];
$error = '';
$success = $_SESSION['landlord_dashboard_success'] ?? '';
unset($_SESSION['landlord_dashboard_success']);
$textValue = static function ($key) {
    return isset($_POST[$key]) && is_string($_POST[$key]) ? trim($_POST[$key]) : '';
};
$cleanupPropertyImage = static function ($path) use ($db) {
    if (!is_string($path) || $path === '') {
        return;
    }
    $check = $db->prepare('SELECT COUNT(*) FROM properties WHERE property_image = ?');
    $check->execute([$path]);
    if ((int)$check->fetchColumn() > 0) {
        return;
    }
    $file = realpath(__DIR__ . '/../' . $path);
    $root = realpath(__DIR__ . '/../uploads/properties');
    if ($file && $root && strpos($file, $root . DIRECTORY_SEPARATOR) === 0 && !unlink($file)) {
        error_log('Unable to remove unreferenced landlord property image: ' . $path);
    }
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate()) {
        http_response_code(400);
        $error = 'Your session expired. Reload the page and try again.';
    } else {
        $action = $textValue('dashboard_action');
        $propertyId = filter_var($textValue('property_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
        $unitId = filter_var($textValue('unit_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
        try {
            if ($action === 'create_property' || $action === 'update_property') {
                $name = $textValue('name');
                $propertyType = $textValue('property_type');
                $locationId = filter_var($textValue('location_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
                $address = $textValue('physical_address');
                $description = $textValue('description');
                $amenities = $textValue('amenities');
                $yearBuilt = $textValue('year_built');
                $yearBuilt = $yearBuilt === '' ? null : filter_var($yearBuilt, FILTER_VALIDATE_INT);
                $latitude = $textValue('latitude');
                $longitude = $textValue('longitude');
                $latitude = $latitude === '' ? null : filter_var($latitude, FILTER_VALIDATE_FLOAT);
                $longitude = $longitude === '' ? null : filter_var($longitude, FILTER_VALIDATE_FLOAT);
                if (($action === 'update_property' && !$propertyId) || $name === '' || strlen($name) > 150 ||
                    !in_array($propertyType, PROPERTY_TYPES, true) || !$locationId || $address === '' || strlen($address) > 255 ||
                    strlen($description) > 5000 || strlen($amenities) > 2000 ||
                    ($yearBuilt !== null && ($yearBuilt === false || $yearBuilt < 1800 || $yearBuilt > (int)date('Y'))) ||
                    ($latitude !== null && ($latitude === false || $latitude < -90 || $latitude > 90)) ||
                    ($longitude !== null && ($longitude === false || $longitude < -180 || $longitude > 180))) {
                    throw new InvalidArgumentException('Enter valid property details, address, location, and optional coordinates/year built.');
                }
                $locationCheck = $db->prepare('SELECT id FROM locations WHERE id = ?');
                $locationCheck->execute([$locationId]);
                if (!$locationCheck->fetchColumn()) {
                    throw new InvalidArgumentException('Choose a valid property location.');
                }
                $removeImage = $textValue('remove_property_image') === '1';
                $hasUpload = isset($_FILES['property_image']) && $_FILES['property_image']['error'] !== UPLOAD_ERR_NO_FILE;
                if ($removeImage && $hasUpload) {
                    throw new InvalidArgumentException('Choose either a replacement image or image removal, not both.');
                }
                $imagePath = null;
                if ($hasUpload) {
                    $upload = uploadApplicationFile($_FILES['property_image'], 'properties', ['jpg', 'jpeg', 'png']);
                    if (!$upload['success']) {
                        throw new InvalidArgumentException($upload['error']);
                    }
                    $imagePath = $upload['path'];
                }
                $oldImage = null;
                try {
                    $db->beginTransaction();
                    if ($action === 'create_property') {
                        $insert = $db->prepare("INSERT INTO properties (landlord_id, name, property_type, description, location_id, physical_address, latitude, longitude, number_of_units, year_built, amenities, status, property_image)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, 'Active', ?)");
                        $insert->execute([$ownerId, $name, $propertyType, $description !== '' ? $description : null, $locationId, $address, $latitude, $longitude, $yearBuilt, $amenities !== '' ? $amenities : null, $imagePath]);
                        $propertyId = (int)$db->lastInsertId();
                        $auditAction = 'Create';
                    } else {
                        $owned = $db->prepare('SELECT property_image FROM properties WHERE id = ? AND landlord_id = ? FOR UPDATE');
                        $owned->execute([$propertyId, $ownerId]);
                        $oldImage = $owned->fetchColumn();
                        if ($oldImage === false) {
                            throw new InvalidArgumentException('That property is not assigned to your landlord account.');
                        }
                        $newImage = $imagePath !== null ? $imagePath : ($removeImage ? null : $oldImage);
                        $update = $db->prepare('UPDATE properties SET name = ?, property_type = ?, description = ?, location_id = ?, physical_address = ?, latitude = ?, longitude = ?, year_built = ?, amenities = ?, property_image = ? WHERE id = ? AND landlord_id = ?');
                        $update->execute([$name, $propertyType, $description !== '' ? $description : null, $locationId, $address, $latitude, $longitude, $yearBuilt, $amenities !== '' ? $amenities : null, $newImage, $propertyId, $ownerId]);
                        $auditAction = 'Update';
                    }
                    $audit = $db->prepare('INSERT INTO audit_logs (user_id, action, module, record_id, description, ip_address) VALUES (?, ?, ?, ?, ?, ?)');
                    $audit->execute([(int)$user['id'], $auditAction, 'Landlord Properties', $propertyId, $auditAction . ' property ' . $name, $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN']);
                    $db->commit();
                } catch (Throwable $exception) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    $cleanupPropertyImage($imagePath);
                    throw $exception;
                }
                if ($action === 'update_property' && $oldImage !== false && $oldImage !== null && ($imagePath !== null || $removeImage)) {
                    $cleanupPropertyImage($oldImage);
                }
                $_SESSION['landlord_dashboard_success'] = $action === 'create_property' ? 'Property created.' : 'Property updated.';
                redirect('landlord/dashboard.php#my-properties');
            } elseif ($action === 'set_property_status') {
                $status = $textValue('status');
                if (!$propertyId || !in_array($status, ['Active', 'Under Maintenance', 'Archived'], true)) {
                    throw new InvalidArgumentException('Choose a valid property and status.');
                }
                $db->beginTransaction();
                $owned = $db->prepare('SELECT id FROM properties WHERE id = ? AND landlord_id = ? FOR UPDATE');
                $owned->execute([$propertyId, $ownerId]);
                if (!$owned->fetchColumn()) {
                    throw new InvalidArgumentException('That property is not assigned to your account.');
                }
                if ($status === 'Archived') {
                    $occupied = $db->prepare("SELECT id FROM units WHERE property_id = ? AND status = 'Occupied' UNION SELECT id FROM leases WHERE property_id = ? AND status = 'Active' AND end_date >= CURRENT_DATE() LIMIT 1");
                    $occupied->execute([$propertyId, $propertyId]);
                    if ($occupied->fetchColumn()) {
                        throw new InvalidArgumentException('A property with occupied units or active leases cannot be archived.');
                    }
                }
                $db->prepare('UPDATE properties SET status = ? WHERE id = ? AND landlord_id = ?')->execute([$status, $propertyId, $ownerId]);
                $audit = $db->prepare("INSERT INTO audit_logs (user_id, action, module, record_id, description, ip_address) VALUES (?, 'Update status', 'Landlord Properties', ?, ?, ?)");
                $audit->execute([(int)$user['id'], $propertyId, 'Changed property status to ' . $status, $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN']);
                $db->commit();
                $_SESSION['landlord_dashboard_success'] = 'Property status updated.';
                redirect('landlord/dashboard.php#my-properties');
            } elseif ($action === 'create_unit' || $action === 'update_unit') {
                $unitNumber = $textValue('unit_number');
                $floor = $textValue('floor');
                $houseType = $textValue('house_type');
                $rent = $textValue('monthly_rent');
                $deposit = $textValue('deposit');
                $serviceCharge = $textValue('service_charge');
                $bedrooms = filter_var($textValue('bedrooms'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 50]]);
                $bathrooms = filter_var($textValue('bathrooms'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 50]]);
                $water = $textValue('water_billing_method') ?: 'Meter Reading';
                $electricity = $textValue('electricity_billing_method') ?: 'Prepaid';
                $description = $textValue('description');
                if (($action === 'update_unit' && !$unitId) || !$propertyId || $unitNumber === '' || strlen($unitNumber) > 50 ||
                    strlen($floor) > 20 || !in_array($houseType, UNIT_TYPES, true) ||
                    !is_numeric($rent) || (float)$rent <= 0 || (float)$rent > 9999999999.99 ||
                    !is_numeric($deposit) || (float)$deposit < 0 || (float)$deposit > 9999999999.99 ||
                    !is_numeric($serviceCharge) || (float)$serviceCharge < 0 || (float)$serviceCharge > 9999999999.99 ||
                    !in_array($water, ['Meter Reading', 'Fixed Rate', 'Per Unit', 'Shared'], true) ||
                    !in_array($electricity, ['Prepaid', 'Postpaid Sub-Meter', 'Fixed'], true) ||
                    $bedrooms === false || $bathrooms === false || strlen($description) > 5000) {
                    throw new InvalidArgumentException('Enter valid unit details, rent, deposit, billing methods, bedrooms, and bathrooms.');
                }
                $db->beginTransaction();
                $propertyCheck = $db->prepare('SELECT id, status FROM properties WHERE id = ? AND landlord_id = ? FOR UPDATE');
                $propertyCheck->execute([$propertyId, $ownerId]);
                $property = $propertyCheck->fetch();
                if (!$property || $property['status'] !== 'Active') {
                    throw new InvalidArgumentException('Choose an active property assigned to your account.');
                }
                if ($action === 'create_unit') {
                    $insert = $db->prepare("INSERT INTO units (property_id, unit_number, floor, house_type, bedrooms, bathrooms, monthly_rent, deposit, service_charge, water_billing_method, electricity_billing_method, status, description)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Vacant', ?)");
                    $insert->execute([$propertyId, $unitNumber, $floor !== '' ? $floor : 'Ground', $houseType, $bedrooms, $bathrooms, $rent, $deposit, $serviceCharge, $water, $electricity, $description !== '' ? $description : null]);
                    $unitId = (int)$db->lastInsertId();
                    $db->prepare('UPDATE properties SET number_of_units = (SELECT COUNT(*) FROM units WHERE property_id = ?) WHERE id = ? AND landlord_id = ?')->execute([$propertyId, $propertyId, $ownerId]);
                    $auditAction = 'Create';
                } else {
                    $unitCheck = $db->prepare('SELECT id, status FROM units WHERE id = ? AND property_id = ? FOR UPDATE');
                    $unitCheck->execute([$unitId, $propertyId]);
                    $unit = $unitCheck->fetch();
                    if (!$unit) {
                        throw new InvalidArgumentException('That unit does not belong to the selected property.');
                    }
                    $activeLease = $db->prepare("SELECT id FROM leases WHERE unit_id = ? AND status = 'Active' AND end_date >= CURRENT_DATE() LIMIT 1");
                    $activeLease->execute([$unitId]);
                    if ($unit['status'] === 'Occupied' || $activeLease->fetchColumn()) {
                        throw new InvalidArgumentException('Units with active occupancy must be updated through lease management.');
                    }
                    $update = $db->prepare('UPDATE units SET unit_number = ?, floor = ?, house_type = ?, bedrooms = ?, bathrooms = ?, monthly_rent = ?, deposit = ?, service_charge = ?, water_billing_method = ?, electricity_billing_method = ?, description = ? WHERE id = ? AND property_id = ?');
                    $update->execute([$unitNumber, $floor !== '' ? $floor : 'Ground', $houseType, $bedrooms, $bathrooms, $rent, $deposit, $serviceCharge, $water, $electricity, $description !== '' ? $description : null, $unitId, $propertyId]);
                    $auditAction = 'Update';
                }
                $audit = $db->prepare('INSERT INTO audit_logs (user_id, action, module, record_id, description, ip_address) VALUES (?, ?, ?, ?, ?, ?)');
                $audit->execute([(int)$user['id'], $auditAction, 'Landlord Units', $unitId, $auditAction . ' unit ' . $unitNumber . ' on property #' . $propertyId, $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN']);
                $db->commit();
                $_SESSION['landlord_dashboard_success'] = $action === 'create_unit' ? 'Vacant unit created.' : 'Unit updated.';
                redirect('landlord/dashboard.php#my-properties');
            } elseif ($action === 'set_unit_status') {
                $status = $textValue('status');
                if (!$unitId || !$propertyId || !in_array($status, ['Vacant', 'Reserved', 'Under Maintenance', 'Unavailable'], true)) {
                    throw new InvalidArgumentException('Choose a valid unit and status.');
                }
                $db->beginTransaction();
                $unitCheck = $db->prepare("SELECT u.status FROM units u JOIN properties p ON p.id = u.property_id WHERE u.id = ? AND p.id = ? AND p.landlord_id = ? FOR UPDATE");
                $unitCheck->execute([$unitId, $propertyId, $ownerId]);
                $unit = $unitCheck->fetch();
                if (!$unit) {
                    throw new InvalidArgumentException('That unit is not part of your property portfolio.');
                }
                $activeLease = $db->prepare("SELECT id FROM leases WHERE unit_id = ? AND status = 'Active' AND end_date >= CURRENT_DATE() LIMIT 1");
                $activeLease->execute([$unitId]);
                if ($unit['status'] === 'Occupied' || $activeLease->fetchColumn()) {
                    throw new InvalidArgumentException('Occupied units and units with active leases cannot be changed here.');
                }
                $db->prepare('UPDATE units SET status = ? WHERE id = ?')->execute([$status, $unitId]);
                $audit = $db->prepare("INSERT INTO audit_logs (user_id, action, module, record_id, description, ip_address) VALUES (?, 'Update status', 'Landlord Units', ?, ?, ?)");
                $audit->execute([(int)$user['id'], $unitId, 'Changed unit status to ' . $status, $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN']);
                $db->commit();
                $_SESSION['landlord_dashboard_success'] = 'Unit status updated.';
                redirect('landlord/dashboard.php#my-properties');
            } elseif ($action === 'delete_unit' || $action === 'delete_property') {
                $db->beginTransaction();
                if ($action === 'delete_unit') {
                    if (!$unitId || !$propertyId) {
                        throw new InvalidArgumentException('Choose a valid unit.');
                    }
                    $unitCheck = $db->prepare("SELECT u.unit_number, u.status FROM units u JOIN properties p ON p.id = u.property_id WHERE u.id = ? AND p.id = ? AND p.landlord_id = ? FOR UPDATE");
                    $unitCheck->execute([$unitId, $propertyId, $ownerId]);
                    $unit = $unitCheck->fetch();
                    if (!$unit) {
                        throw new InvalidArgumentException('That unit is not part of your property portfolio.');
                    }
                    $history = $db->prepare("SELECT (SELECT COUNT(*) FROM leases WHERE unit_id = ?) + (SELECT COUNT(*) FROM payments WHERE unit_id = ?) + (SELECT COUNT(*) FROM rent_charges WHERE unit_id = ?) + (SELECT COUNT(*) FROM maintenance_requests WHERE unit_id = ?) + (SELECT COUNT(*) FROM utility_readings WHERE unit_id = ?) + (SELECT COUNT(*) FROM viewing_requests WHERE unit_id = ?) AS linked_records");
                    $history->execute(array_fill(0, 6, $unitId));
                    if ($unit['status'] === 'Occupied' || (int)$history->fetchColumn() > 0) {
                        throw new InvalidArgumentException('Occupied units or units with linked history cannot be deleted. Mark the unit unavailable instead.');
                    }
                    $db->prepare('DELETE FROM units WHERE id = ?')->execute([$unitId]);
                    $db->prepare('UPDATE properties SET number_of_units = (SELECT COUNT(*) FROM units WHERE property_id = ?) WHERE id = ?')->execute([$propertyId, $propertyId]);
                    $recordId = $unitId;
                    $module = 'Landlord Units';
                    $description = 'Deleted unreferenced unit ' . $unit['unit_number'];
                } else {
                    if (!$propertyId) {
                        throw new InvalidArgumentException('Choose a valid property.');
                    }
                    $propertyCheck = $db->prepare('SELECT name, property_image FROM properties WHERE id = ? AND landlord_id = ? FOR UPDATE');
                    $propertyCheck->execute([$propertyId, $ownerId]);
                    $property = $propertyCheck->fetch();
                    if (!$property) {
                        throw new InvalidArgumentException('That property is not assigned to your account.');
                    }
                    $history = $db->prepare("SELECT (SELECT COUNT(*) FROM units WHERE property_id = ?) + (SELECT COUNT(*) FROM leases WHERE property_id = ?) + (SELECT COUNT(*) FROM payments WHERE property_id = ?) + (SELECT COUNT(*) FROM maintenance_requests WHERE property_id = ?) + (SELECT COUNT(*) FROM complaints WHERE property_id = ?) + (SELECT COUNT(*) FROM expenses WHERE property_id = ?) + (SELECT COUNT(*) FROM documents WHERE property_id = ?) + (SELECT COUNT(*) FROM viewing_requests WHERE property_id = ?) AS linked_records");
                    $history->execute(array_fill(0, 8, $propertyId));
                    if ((int)$history->fetchColumn() > 0) {
                        throw new InvalidArgumentException('Properties with linked units or history cannot be deleted. Archive the property instead.');
                    }
                    $db->prepare('DELETE FROM properties WHERE id = ? AND landlord_id = ?')->execute([$propertyId, $ownerId]);
                    $recordId = $propertyId;
                    $module = 'Landlord Properties';
                    $description = 'Deleted unreferenced property ' . $property['name'];
                }
                $audit = $db->prepare('INSERT INTO audit_logs (user_id, action, module, record_id, description, ip_address) VALUES (?, ?, ?, ?, ?, ?)');
                $audit->execute([(int)$user['id'], 'Delete', $module, $recordId, $description, $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN']);
                $db->commit();
                if ($action === 'delete_property') {
                    $cleanupPropertyImage($property['property_image']);
                }
                $_SESSION['landlord_dashboard_success'] = 'Unreferenced record deleted.';
                redirect('landlord/dashboard.php#my-properties');
            } else {
                throw new InvalidArgumentException('Choose a valid landlord dashboard action.');
            }
        } catch (InvalidArgumentException $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $error = $exception->getMessage();
        } catch (PDOException $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('Landlord dashboard database action failed: ' . $exception->getMessage());
            $error = 'The change could not be saved. Check the property, unit number, and linked records.';
        } catch (Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('Landlord dashboard action failed: ' . $exception->getMessage());
            $error = 'The change could not be completed. Please try again.';
        }
    }
}
$locations = $db->query('SELECT id, estate, sub_county FROM locations ORDER BY estate')->fetchAll();
$statsQuery = $db->prepare("SELECT COUNT(DISTINCT p.id) properties, COUNT(DISTINCT u.id) units, COUNT(DISTINCT CASE WHEN u.status = 'Occupied' THEN u.id END) occupied, COUNT(DISTINCT CASE WHEN u.status = 'Vacant' THEN u.id END) vacant, COUNT(DISTINCT CASE WHEN l.status = 'Active' THEN l.tenant_id END) tenants, COALESCE((SELECT SUM(pay.amount) FROM payments pay JOIN properties pp ON pp.id=pay.property_id WHERE pp.landlord_id=? AND YEAR(pay.payment_date)=YEAR(CURRENT_DATE()) AND MONTH(pay.payment_date)=MONTH(CURRENT_DATE())),0) collected, COALESCE((SELECT SUM(GREATEST(rc.total_due-COALESCE(pa.paid,0),0)) FROM rent_charges rc JOIN leases ll ON ll.id=rc.lease_id JOIN properties px ON px.id=ll.property_id LEFT JOIN (SELECT rent_charge_id,SUM(allocated_amount) paid FROM payment_allocations GROUP BY rent_charge_id) pa ON pa.rent_charge_id=rc.id WHERE px.landlord_id=? AND rc.status<>'Paid'),0) outstanding FROM properties p LEFT JOIN units u ON u.property_id=p.id LEFT JOIN leases l ON l.unit_id=u.id AND l.status='Active' WHERE p.landlord_id=?");
$statsQuery->execute([$ownerId,$ownerId,$ownerId]); $stats=$statsQuery->fetch();
$propertiesQuery=$db->prepare("SELECT p.id,p.name,p.property_type,p.description,p.location_id,p.physical_address,p.latitude,p.longitude,p.year_built,p.amenities,p.property_image,p.status,l.estate,l.sub_county,COUNT(DISTINCT u.id) unit_count,COUNT(DISTINCT CASE WHEN u.status='Occupied' THEN u.id END) occupied_count,
    CASE WHEN NOT EXISTS (SELECT 1 FROM units ux WHERE ux.property_id=p.id)
        AND NOT EXISTS (SELECT 1 FROM leases le WHERE le.property_id=p.id)
        AND NOT EXISTS (SELECT 1 FROM payments pay WHERE pay.property_id=p.id)
        AND NOT EXISTS (SELECT 1 FROM maintenance_requests mr WHERE mr.property_id=p.id)
        AND NOT EXISTS (SELECT 1 FROM complaints c WHERE c.property_id=p.id)
        AND NOT EXISTS (SELECT 1 FROM expenses ex WHERE ex.property_id=p.id)
        AND NOT EXISTS (SELECT 1 FROM documents d WHERE d.property_id=p.id)
        AND NOT EXISTS (SELECT 1 FROM viewing_requests vr WHERE vr.property_id=p.id)
        THEN 1 ELSE 0 END AS safe_to_delete
    FROM properties p LEFT JOIN locations l ON l.id=p.location_id LEFT JOIN units u ON u.property_id=p.id WHERE p.landlord_id=? GROUP BY p.id ORDER BY p.created_at DESC");
$propertiesQuery->execute([$ownerId]); $properties=$propertiesQuery->fetchAll();
$unitsQuery=$db->prepare("SELECT u.*,p.name AS property_name,p.status AS property_status,
    EXISTS (SELECT 1 FROM leases le WHERE le.unit_id=u.id AND le.status='Active' AND le.end_date>=CURRENT_DATE()) AS active_lease,
    CASE WHEN u.status<>'Occupied'
        AND NOT EXISTS (SELECT 1 FROM leases le WHERE le.unit_id=u.id)
        AND NOT EXISTS (SELECT 1 FROM payments pay WHERE pay.unit_id=u.id)
        AND NOT EXISTS (SELECT 1 FROM rent_charges rc WHERE rc.unit_id=u.id)
        AND NOT EXISTS (SELECT 1 FROM maintenance_requests mr WHERE mr.unit_id=u.id)
        AND NOT EXISTS (SELECT 1 FROM utility_readings ur WHERE ur.unit_id=u.id)
        AND NOT EXISTS (SELECT 1 FROM viewing_requests vr WHERE vr.unit_id=u.id)
        THEN 1 ELSE 0 END AS safe_to_delete
    FROM units u JOIN properties p ON p.id=u.property_id WHERE p.landlord_id=? ORDER BY p.name,u.unit_number");
$unitsQuery->execute([$ownerId]); $units=$unitsQuery->fetchAll();
$maintenanceQuery=$db->prepare("SELECT mr.category,mr.priority,mr.status,mr.created_at,p.name property_name,u.unit_number FROM maintenance_requests mr JOIN properties p ON p.id=mr.property_id JOIN units u ON u.id=mr.unit_id WHERE p.landlord_id=? AND mr.status NOT IN ('Completed','Cancelled') ORDER BY mr.created_at DESC LIMIT 5");
$maintenanceQuery->execute([$ownerId]); $maintenance=$maintenanceQuery->fetchAll();$paymentsQuery=$db->prepare('SELECT pay.receipt_number,pay.amount,pay.payment_date,t.full_name tenant_name,u.unit_number FROM payments pay JOIN properties p ON p.id=pay.property_id JOIN tenants t ON t.id=pay.tenant_id JOIN units u ON u.id=pay.unit_id WHERE p.landlord_id=? ORDER BY pay.payment_date DESC,pay.id DESC LIMIT 5');
$paymentsQuery->execute([$ownerId]); $payments=$paymentsQuery->fetchAll();
$page_title='Landlord Dashboard'; include __DIR__ . '/../includes/header.php';
?>
<div class="flex h-screen bg-gray-50 overflow-hidden">
<?php include __DIR__ . '/../includes/sidebar.php'; ?><div class="flex-1 flex flex-col overflow-y-auto"><?php include __DIR__ . '/../includes/navbar.php'; ?>
<main id="main-content" class="flex-1 p-6 space-y-6"><div><p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Landlord workspace</p><h1 class="text-2xl font-bold text-gray-900">Welcome, <?php echo e($landlord['full_name']); ?></h1><p class="text-sm text-gray-500">A private overview of your properties, occupancy and rental income.</p></div>
<?php if ($error !== ''): ?><div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert"><?php echo e($error); ?></div><?php endif; ?>
<?php if ($success !== ''): ?><div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800" role="status"><?php echo e($success); ?></div><?php endif; ?>
<div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-5">
<?php foreach ([['My Properties',$stats['properties'],'fa-building'],['My Units',$stats['units'],'fa-door-open'],['Occupied Units',$stats['occupied'],'fa-key'],['Vacant Units',$stats['vacant'],'fa-house-circle-check'],['My Tenants',$stats['tenants'],'fa-users'],['Collected This Month',format_currency($stats['collected']),'fa-money-bill-wave'],['Outstanding Rent',format_currency($stats['outstanding']),'fa-file-invoice-dollar']] as $card): ?><article class="bg-white p-5 rounded-xl border border-gray-100 shadow-sm"><div class="flex items-center justify-between"><div><p class="text-xs font-medium text-gray-500 uppercase"><?php echo e($card[0]); ?></p><h2 class="text-2xl font-bold text-gray-900 mt-2"><?php echo e((string)$card[1]); ?></h2></div><span class="p-3 rounded-xl bg-blue-50 text-primary"><i class="fa-solid <?php echo e($card[2]); ?>"></i></span></div></article><?php endforeach; ?>
</div>
<section id="my-properties" class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
    <div class="p-5 border-b border-gray-100"><h2 class="font-bold text-gray-900">My properties and units</h2><p class="mt-1 text-sm text-gray-500">These operations are limited to properties assigned to your landlord account. Occupied units remain governed by their lease.</p></div>
    <div class="grid grid-cols-1 xl:grid-cols-2 gap-5 p-5 border-b border-gray-100">
        <details class="rounded-lg border border-gray-200 p-4">
            <summary class="cursor-pointer font-semibold text-gray-900">Add property</summary>
            <?php if (!$locations): ?><p class="mt-3 text-sm text-amber-800">No locations are configured. Ask an administrator to add a location first.</p>
            <?php else: ?>
            <form method="post" enctype="multipart/form-data" class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-3">
                <?php echo csrf_field(); ?><input type="hidden" name="dashboard_action" value="create_property">
                <label class="text-sm">Property name<input name="name" maxlength="150" required class="mt-1 w-full rounded-lg border-gray-300"></label>
                <label class="text-sm">Property type<select name="property_type" required class="mt-1 w-full rounded-lg border-gray-300"><?php foreach (PROPERTY_TYPES as $type): ?><option value="<?php echo e($type); ?>"><?php echo e($type); ?></option><?php endforeach; ?></select></label>
                <label class="text-sm">Neighborhood<select name="location_id" required class="mt-1 w-full rounded-lg border-gray-300"><?php foreach ($locations as $location): ?><option value="<?php echo (int)$location['id']; ?>"><?php echo e($location['estate'] . ' · ' . $location['sub_county']); ?></option><?php endforeach; ?></select></label>
                <label class="text-sm">Year built<input type="number" name="year_built" min="1800" max="<?php echo (int)date('Y'); ?>" class="mt-1 w-full rounded-lg border-gray-300"></label>
                <label class="text-sm sm:col-span-2">Physical address<input name="physical_address" maxlength="255" required class="mt-1 w-full rounded-lg border-gray-300"></label>
                <label class="text-sm sm:col-span-2">Description<textarea name="description" maxlength="5000" rows="2" class="mt-1 w-full rounded-lg border-gray-300"></textarea></label>
                <label class="text-sm sm:col-span-2">Amenities<input name="amenities" maxlength="2000" class="mt-1 w-full rounded-lg border-gray-300" placeholder="Parking, CCTV, borehole"></label>
                <label class="text-sm">Latitude<input type="number" name="latitude" min="-90" max="90" step="0.00000001" class="mt-1 w-full rounded-lg border-gray-300"></label>
                <label class="text-sm">Longitude<input type="number" name="longitude" min="-180" max="180" step="0.00000001" class="mt-1 w-full rounded-lg border-gray-300"></label>
                <label class="text-sm sm:col-span-2">Property image (JPG/PNG, max 5 MB)<input type="file" name="property_image" accept=".jpg,.jpeg,.png,image/jpeg,image/png" class="mt-1 block w-full text-sm"></label>
                <button class="sm:col-span-2 justify-self-start rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white">Create property</button>
            </form><?php endif; ?>
        </details>
        <details class="rounded-lg border border-gray-200 p-4">
            <summary class="cursor-pointer font-semibold text-gray-900">Add unit</summary>
            <?php $activeProperties = array_values(array_filter($properties, static function ($property) { return $property['status'] === 'Active'; })); ?>
            <?php if (!$activeProperties): ?><p class="mt-3 text-sm text-amber-800">Create an active property before adding units.</p>
            <?php else: ?>
            <form method="post" class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-3">
                <?php echo csrf_field(); ?><input type="hidden" name="dashboard_action" value="create_unit">
                <label class="text-sm sm:col-span-2">Property<select name="property_id" required class="mt-1 w-full rounded-lg border-gray-300"><?php foreach ($activeProperties as $property): ?><option value="<?php echo (int)$property['id']; ?>"><?php echo e($property['name']); ?></option><?php endforeach; ?></select></label>
                <label class="text-sm">Unit number<input name="unit_number" maxlength="50" required class="mt-1 w-full rounded-lg border-gray-300"></label>
                <label class="text-sm">Floor<input name="floor" maxlength="20" value="Ground" class="mt-1 w-full rounded-lg border-gray-300"></label>
                <label class="text-sm">Unit type<select name="house_type" required class="mt-1 w-full rounded-lg border-gray-300"><?php foreach (UNIT_TYPES as $type): ?><option value="<?php echo e($type); ?>"><?php echo e($type); ?></option><?php endforeach; ?></select></label>
                <label class="text-sm">Monthly rent (KSh)<input type="number" name="monthly_rent" min="0.01" step="0.01" required class="mt-1 w-full rounded-lg border-gray-300"></label>
                <label class="text-sm">Deposit (KSh)<input type="number" name="deposit" min="0" step="0.01" required class="mt-1 w-full rounded-lg border-gray-300"></label>
                <label class="text-sm">Service charge (KSh)<input type="number" name="service_charge" min="0" step="0.01" value="0" required class="mt-1 w-full rounded-lg border-gray-300"></label>
                <label class="text-sm">Bedrooms<input type="number" name="bedrooms" min="0" max="50" value="1" required class="mt-1 w-full rounded-lg border-gray-300"></label>
                <label class="text-sm">Bathrooms<input type="number" name="bathrooms" min="0" max="50" value="1" required class="mt-1 w-full rounded-lg border-gray-300"></label>
                <label class="text-sm">Water billing<select name="water_billing_method" class="mt-1 w-full rounded-lg border-gray-300"><?php foreach (['Meter Reading', 'Fixed Rate', 'Per Unit', 'Shared'] as $method): ?><option value="<?php echo e($method); ?>"><?php echo e($method); ?></option><?php endforeach; ?></select></label>
                <label class="text-sm">Electricity billing<select name="electricity_billing_method" class="mt-1 w-full rounded-lg border-gray-300"><?php foreach (['Prepaid', 'Postpaid Sub-Meter', 'Fixed'] as $method): ?><option value="<?php echo e($method); ?>"><?php echo e($method); ?></option><?php endforeach; ?></select></label>
                <label class="text-sm sm:col-span-2">Description<textarea name="description" maxlength="5000" rows="2" class="mt-1 w-full rounded-lg border-gray-300"></textarea></label>
                <button class="sm:col-span-2 justify-self-start rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white">Create vacant unit</button>
            </form><?php endif; ?>
        </details>
    </div>
    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4 p-5">
        <?php if (!$properties): ?><p class="text-sm text-gray-500">No properties are linked to your account.</p><?php endif; ?>
        <?php foreach ($properties as $property): ?>
        <article class="bg-gray-50 p-5 rounded-xl border border-gray-100">
            <div class="flex items-start justify-between gap-2"><div><p class="text-xs uppercase text-gray-500"><?php echo e($property['status']); ?></p><h3 class="font-bold mt-1"><?php echo e($property['name']); ?></h3></div><?php echo render_status_badge($property['status']); ?></div>
            <p class="text-sm text-gray-500 mt-2"><?php echo e($property['estate'] . ', ' . $property['sub_county']); ?> · <?php echo (int)$property['unit_count']; ?> units · <?php echo (int)$property['occupied_count']; ?> occupied</p>
            <details class="mt-4 rounded border border-gray-200 bg-white p-3">
                <summary class="cursor-pointer text-sm font-semibold text-primary">Edit property</summary>
                <form method="post" enctype="multipart/form-data" class="mt-3 space-y-2">
                    <?php echo csrf_field(); ?><input type="hidden" name="dashboard_action" value="update_property"><input type="hidden" name="property_id" value="<?php echo (int)$property['id']; ?>">
                    <label class="block text-xs">Name<input name="name" maxlength="150" required value="<?php echo e($property['name']); ?>" class="mt-1 w-full rounded border-gray-300 text-sm"></label>
                    <label class="block text-xs">Type<select name="property_type" required class="mt-1 w-full rounded border-gray-300 text-sm"><?php foreach (PROPERTY_TYPES as $type): ?><option value="<?php echo e($type); ?>" <?php echo $property['property_type'] === $type ? 'selected' : ''; ?>><?php echo e($type); ?></option><?php endforeach; ?></select></label>
                    <label class="block text-xs">Location<select name="location_id" required class="mt-1 w-full rounded border-gray-300 text-sm"><?php foreach ($locations as $location): ?><option value="<?php echo (int)$location['id']; ?>" <?php echo (int)$property['location_id'] === (int)$location['id'] ? 'selected' : ''; ?>><?php echo e($location['estate'] . ' · ' . $location['sub_county']); ?></option><?php endforeach; ?></select></label>
                    <label class="block text-xs">Address<input name="physical_address" maxlength="255" required value="<?php echo e($property['physical_address']); ?>" class="mt-1 w-full rounded border-gray-300 text-sm"></label>
                    <label class="block text-xs">Description<textarea name="description" maxlength="5000" rows="2" class="mt-1 w-full rounded border-gray-300 text-sm"><?php echo e($property['description']); ?></textarea></label>
                    <label class="block text-xs">Amenities<input name="amenities" maxlength="2000" value="<?php echo e($property['amenities']); ?>" class="mt-1 w-full rounded border-gray-300 text-sm"></label>
                    <label class="block text-xs">Year built<input type="number" name="year_built" min="1800" max="<?php echo (int)date('Y'); ?>" value="<?php echo e($property['year_built']); ?>" class="mt-1 w-full rounded border-gray-300 text-sm"></label>
                    <label class="block text-xs">Latitude<input type="number" name="latitude" min="-90" max="90" step="0.00000001" value="<?php echo e($property['latitude']); ?>" class="mt-1 w-full rounded border-gray-300 text-sm"></label>
                    <label class="block text-xs">Longitude<input type="number" name="longitude" min="-180" max="180" step="0.00000001" value="<?php echo e($property['longitude']); ?>" class="mt-1 w-full rounded border-gray-300 text-sm"></label>
                    <?php $imagePath = trim((string)$property['property_image'], '/'); $imageFile = $imagePath !== '' ? dirname(__DIR__) . '/' . $imagePath : ''; $imageAvailable = $imagePath !== '' && is_file($imageFile); ?>
                    <img src="<?php echo BASE_URL . '/' . e($imageAvailable ? $imagePath : sample_property_image($property['id'])); ?>" alt="<?php echo $imageAvailable ? 'Current property image' : 'Fallback sample image'; ?>" class="h-24 w-full rounded object-cover">
                    <label class="block text-xs">Replace image (JPG/PNG, max 5 MB)<input type="file" name="property_image" accept=".jpg,.jpeg,.png,image/jpeg,image/png" class="mt-1 block w-full text-xs"></label>
                    <?php if ($property['property_image']): ?><label class="block text-xs"><input type="checkbox" name="remove_property_image" value="1"> Remove saved image</label><?php endif; ?>
                    <button class="rounded bg-primary px-3 py-2 text-sm font-semibold text-white">Save property</button>
                </form>
            </details>
            <form method="post" class="mt-3 flex flex-wrap items-center gap-2" onsubmit="return this.elements.status.value !== 'Archived' || confirm('Archive this property? Historical records will be retained.');">
                <?php echo csrf_field(); ?><input type="hidden" name="dashboard_action" value="set_property_status"><input type="hidden" name="property_id" value="<?php echo (int)$property['id']; ?>">
                <select name="status" class="rounded border-gray-300 text-xs"><?php foreach (['Active', 'Under Maintenance', 'Archived'] as $status): ?><option value="<?php echo e($status); ?>" <?php echo $property['status'] === $status ? 'selected' : ''; ?>><?php echo e($status); ?></option><?php endforeach; ?></select>
                <button class="rounded bg-gray-200 px-2 py-1 text-xs font-semibold">Update status</button>
            </form>
            <?php if ((int)$property['safe_to_delete'] === 1): ?><form method="post" class="mt-2" onsubmit="return confirm('Permanently delete this property? It has no linked records.');"><?php echo csrf_field(); ?><input type="hidden" name="dashboard_action" value="delete_property"><input type="hidden" name="property_id" value="<?php echo (int)$property['id']; ?>"><button class="text-xs font-semibold text-red-700 underline">Delete property</button></form><?php else: ?><p class="mt-2 text-xs text-gray-500">Deletion unavailable while units or historical records are linked.</p><?php endif; ?>
        </article>
        <?php endforeach; ?>
    </div>
    <div class="border-t border-gray-100 p-5"><h3 class="font-bold text-gray-900">Units in my portfolio</h3>
        <div class="mt-4 space-y-3">
            <?php if (!$units): ?><p class="text-sm text-gray-500">No units have been recorded.</p><?php endif; ?>
            <?php foreach ($units as $unit): ?>
            <details class="rounded-lg border border-gray-200 p-4">
                <summary class="cursor-pointer flex flex-wrap items-center justify-between gap-2"><span class="font-semibold text-gray-900"><?php echo e($unit['property_name'] . ' · Unit ' . $unit['unit_number']); ?> <span class="font-normal text-gray-500"><?php echo e($unit['house_type']); ?></span></span><span class="flex items-center gap-2"><?php echo render_status_badge($unit['status']); ?><span class="text-sm font-semibold"><?php echo format_currency($unit['monthly_rent']); ?></span></span></summary>
                <div class="mt-4 grid grid-cols-1 xl:grid-cols-2 gap-4">
                    <form method="post" class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                        <?php echo csrf_field(); ?><input type="hidden" name="dashboard_action" value="update_unit"><input type="hidden" name="unit_id" value="<?php echo (int)$unit['id']; ?>"><input type="hidden" name="property_id" value="<?php echo (int)$unit['property_id']; ?>">
                        <label class="text-xs">Unit number<input name="unit_number" maxlength="50" required value="<?php echo e($unit['unit_number']); ?>" class="mt-1 w-full rounded border-gray-300 text-sm"></label>
                        <label class="text-xs">Floor<input name="floor" maxlength="20" value="<?php echo e($unit['floor']); ?>" class="mt-1 w-full rounded border-gray-300 text-sm"></label>
                        <label class="text-xs">Type<select name="house_type" required class="mt-1 w-full rounded border-gray-300 text-sm"><?php foreach (UNIT_TYPES as $type): ?><option value="<?php echo e($type); ?>" <?php echo $unit['house_type'] === $type ? 'selected' : ''; ?>><?php echo e($type); ?></option><?php endforeach; ?></select></label>
                        <label class="text-xs">Monthly rent<input type="number" name="monthly_rent" min="0.01" step="0.01" required value="<?php echo e($unit['monthly_rent']); ?>" class="mt-1 w-full rounded border-gray-300 text-sm"></label>
                        <label class="text-xs">Deposit<input type="number" name="deposit" min="0" step="0.01" required value="<?php echo e($unit['deposit']); ?>" class="mt-1 w-full rounded border-gray-300 text-sm"></label>
                        <label class="text-xs">Service charge<input type="number" name="service_charge" min="0" step="0.01" required value="<?php echo e($unit['service_charge']); ?>" class="mt-1 w-full rounded border-gray-300 text-sm"></label>
                        <label class="text-xs">Bedrooms<input type="number" name="bedrooms" min="0" max="50" required value="<?php echo (int)$unit['bedrooms']; ?>" class="mt-1 w-full rounded border-gray-300 text-sm"></label>
                        <label class="text-xs">Bathrooms<input type="number" name="bathrooms" min="0" max="50" required value="<?php echo (int)$unit['bathrooms']; ?>" class="mt-1 w-full rounded border-gray-300 text-sm"></label>
                        <label class="text-xs">Water billing<select name="water_billing_method" class="mt-1 w-full rounded border-gray-300 text-sm"><?php foreach (['Meter Reading', 'Fixed Rate', 'Per Unit', 'Shared'] as $method): ?><option value="<?php echo e($method); ?>" <?php echo $unit['water_billing_method'] === $method ? 'selected' : ''; ?>><?php echo e($method); ?></option><?php endforeach; ?></select></label>
                        <label class="text-xs">Electricity billing<select name="electricity_billing_method" class="mt-1 w-full rounded border-gray-300 text-sm"><?php foreach (['Prepaid', 'Postpaid Sub-Meter', 'Fixed'] as $method): ?><option value="<?php echo e($method); ?>" <?php echo $unit['electricity_billing_method'] === $method ? 'selected' : ''; ?>><?php echo e($method); ?></option><?php endforeach; ?></select></label>
                        <label class="text-xs sm:col-span-2">Description<textarea name="description" maxlength="5000" rows="2" class="mt-1 w-full rounded border-gray-300 text-sm"><?php echo e($unit['description']); ?></textarea></label>
                        <button class="sm:col-span-2 justify-self-start rounded bg-primary px-3 py-2 text-sm font-semibold text-white">Save unit</button>
                    </form>
                    <div>
                        <?php if ($unit['status'] !== 'Occupied' && !(int)$unit['active_lease']): ?><form method="post" class="flex flex-wrap items-center gap-2" onsubmit="return this.elements.status.value !== 'Unavailable' || confirm('Mark this unit unavailable? History will be retained.');"><?php echo csrf_field(); ?><input type="hidden" name="dashboard_action" value="set_unit_status"><input type="hidden" name="unit_id" value="<?php echo (int)$unit['id']; ?>"><input type="hidden" name="property_id" value="<?php echo (int)$unit['property_id']; ?>"><select name="status" class="rounded border-gray-300 text-sm"><?php foreach (['Vacant', 'Reserved', 'Under Maintenance', 'Unavailable'] as $status): ?><option value="<?php echo e($status); ?>" <?php echo $unit['status'] === $status ? 'selected' : ''; ?>><?php echo e($status); ?></option><?php endforeach; ?></select><button class="rounded bg-gray-200 px-3 py-2 text-sm font-semibold">Update status</button></form><?php else: ?><p class="text-sm text-gray-500">Occupied unit details and status must be managed through an authorized lease workflow.</p><?php endif; ?>
                        <?php if ((int)$unit['safe_to_delete'] === 1): ?><form method="post" class="mt-3" onsubmit="return confirm('Permanently delete this unit? It has no linked records.');"><?php echo csrf_field(); ?><input type="hidden" name="dashboard_action" value="delete_unit"><input type="hidden" name="unit_id" value="<?php echo (int)$unit['id']; ?>"><input type="hidden" name="property_id" value="<?php echo (int)$unit['property_id']; ?>"><button class="text-sm font-semibold text-red-700 underline">Delete unit</button></form><?php else: ?><p class="mt-3 text-xs text-gray-500">Deletion unavailable: tenancy or operational history exists.</p><?php endif; ?>
                    </div>
                </div>
            </details>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<section class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden"><div class="p-5 border-b border-gray-100"><h2 class="font-bold">Open maintenance requests</h2></div><div class="overflow-x-auto"><table class="w-full text-left"><thead><tr><th class="p-4">Issue</th><th class="p-4">Property / Unit</th><th class="p-4">Priority</th><th class="p-4">Status</th></tr></thead><tbody><?php if (!$maintenance): ?><tr><td colspan="4" class="p-5 text-center text-gray-500">No open maintenance requests.</td></tr><?php endif; ?><?php foreach ($maintenance as $request): ?><tr class="border-t"><td class="p-4"><?php echo e($request['category']); ?></td><td class="p-4"><?php echo e($request['property_name'].' / '.$request['unit_number']); ?></td><td class="p-4"><?php echo render_status_badge($request['priority']); ?></td><td class="p-4"><?php echo render_status_badge($request['status']); ?></td></tr><?php endforeach; ?></tbody></table></div></section><section class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden"><div class="p-5 border-b border-gray-100"><h2 class="font-bold">Recent rent payments</h2></div><div class="overflow-x-auto"><table class="w-full text-left"><thead><tr><th class="p-4">Receipt</th><th class="p-4">Tenant</th><th class="p-4">Unit</th><th class="p-4">Amount</th><th class="p-4">Date</th></tr></thead><tbody><?php if (!$payments): ?><tr><td colspan="5" class="p-5 text-center text-gray-500">No payments recorded for your properties yet.</td></tr><?php endif; ?><?php foreach ($payments as $payment): ?><tr class="border-t"><td class="p-4"><?php echo e($payment['receipt_number']); ?></td><td class="p-4"><?php echo e($payment['tenant_name']); ?></td><td class="p-4"><?php echo e($payment['unit_number']); ?></td><td class="p-4 font-semibold"><?php echo format_currency($payment['amount']); ?></td><td class="p-4"><?php echo e($payment['payment_date']); ?></td></tr><?php endforeach; ?></tbody></table></div></section>
<div class="flex flex-wrap gap-3"><a class="bg-primary text-white px-4 py-2 rounded-lg" href="<?php echo BASE_URL; ?>/public/properties.php">View property directory</a></div></main><?php include __DIR__ . '/../includes/footer.php'; ?></div></div>