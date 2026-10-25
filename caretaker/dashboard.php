<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/upload_helper.php';

requireRole('Caretaker');
$db = db();
$user = getCurrentUser();
$errors = [];
$success = $_SESSION['caretaker_dashboard_success'] ?? '';
unset($_SESSION['caretaker_dashboard_success']);
$textValue = static function ($key) {
    return isset($_POST[$key]) && is_string($_POST[$key]) ? trim($_POST[$key]) : '';
};

$assignedPropertyQuery = $db->prepare("SELECT DISTINCT p.id, p.name
    FROM maintenance_requests mr
    JOIN properties p ON p.id = mr.property_id
    WHERE mr.assigned_staff = ?
    ORDER BY p.name");
$assignedPropertyQuery->execute([$user['id']]);
$assignedProperties = $assignedPropertyQuery->fetchAll();
$propertyIds = array_map(static function ($property) {
    return (int)$property['id'];
}, $assignedProperties);
$propertyPlaceholders = $propertyIds ? implode(',', array_fill(0, count($propertyIds), '?')) : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate()) {
        $errors[] = 'Your session expired. Reload the page and try again.';
    } elseif (!$propertyIds) {
        $errors[] = 'No property work is assigned to your account yet.';
    } else {
        $action = $textValue('dashboard_action');
        $uploadedPath = null;
        try {
            if ($action === 'update_maintenance') {
                $requestId = filter_var($textValue('request_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
                $status = $textValue('status');
                $note = $textValue('repair_note');
                if (!$requestId || !in_array($status, ['In Progress', 'Waiting', 'Completed'], true) || strlen($note) > 2000) {
                    throw new InvalidArgumentException('Choose a valid request status and keep repair notes under 2,000 characters.');
                }
                $requestQuery = $db->prepare("SELECT id, tenant_id, property_id FROM maintenance_requests WHERE id = ? AND assigned_staff = ?");
                $requestQuery->execute([$requestId, $user['id']]);
                $request = $requestQuery->fetch();
                if (!$request) {
                    throw new InvalidArgumentException('That request is not assigned to your account.');
                }
                if (isset($_FILES['completion_photo']) && $_FILES['completion_photo']['error'] !== UPLOAD_ERR_NO_FILE) {
                    $upload = uploadApplicationFile($_FILES['completion_photo'], 'maintenance', ['jpg', 'jpeg', 'png']);
                    if (!$upload['success']) {
                        throw new InvalidArgumentException($upload['error']);
                    }
                    $uploadedPath = $upload['path'];
                }
                $db->beginTransaction();
                $update = $db->prepare('UPDATE maintenance_requests SET status = ?, completed_date = ? WHERE id = ? AND assigned_staff = ?');
                $update->execute([$status, $status === 'Completed' ? date('Y-m-d H:i:s') : null, $requestId, $user['id']]);
                if ($note !== '') {
                    $audit = $db->prepare("INSERT INTO audit_logs (user_id, action, module, record_id, description, ip_address) VALUES (?, 'Repair note', 'Caretaker Maintenance', ?, ?, ?)");
                    $audit->execute([(int)$user['id'], $requestId, $note, $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN']);
                }
                if ($uploadedPath !== null) {
                    $document = $db->prepare("INSERT INTO documents (property_id, tenant_id, title, document_type, file_path, uploaded_by)
                        VALUES (?, ?, ?, 'Maintenance Photo', ?, ?)");
                    $document->execute([(int)$request['property_id'], (int)$request['tenant_id'], 'Caretaker completion photo - request #' . $requestId, $uploadedPath, (int)$user['id']]);
                }
                $audit = $db->prepare("INSERT INTO audit_logs (user_id, action, module, record_id, description, ip_address) VALUES (?, 'Update status', 'Caretaker Maintenance', ?, ?, ?)");
                $audit->execute([(int)$user['id'], $requestId, 'Changed maintenance request status to ' . $status, $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN']);
                $db->commit();
                $_SESSION['caretaker_dashboard_success'] = 'Assigned maintenance request updated.';
                redirect('caretaker/dashboard.php#assigned-requests');
            } elseif ($action === 'record_utility') {
                $unitId = filter_var($textValue('unit_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
                $utilityTypeId = filter_var($textValue('utility_type_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
                $currentReading = $textValue('current_reading');
                $billingPeriod = $textValue('billing_period');
                if (!$unitId || !$utilityTypeId || !is_numeric($currentReading) || (float)$currentReading < 0 || (float)$currentReading > 99999999.99 || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $billingPeriod)) {
                    throw new InvalidArgumentException('Enter a valid unit, utility type, non-negative reading, and billing month.');
                }
                $unitQuery = $db->prepare("SELECT u.id, u.property_id FROM units u WHERE u.id = ? AND u.property_id IN ($propertyPlaceholders)");
                $unitQuery->execute(array_merge([$unitId], $propertyIds));
                $unit = $unitQuery->fetch();
                if (!$unit) {
                    throw new InvalidArgumentException('That unit is outside your assigned property work.');
                }
                if ($billingPeriod > date('Y-m')) {
                    throw new InvalidArgumentException('A utility reading cannot be recorded for a future month.');
                }
                $utilityQuery = $db->prepare("SELECT id, default_rate FROM utility_types WHERE id = ? AND name IN ('Water', 'Electricity')");
                $utilityQuery->execute([$utilityTypeId]);
                $utility = $utilityQuery->fetch();
                if (!$utility) {
                    throw new InvalidArgumentException('Select a configured water or electricity meter type.');
                }
                $duplicateQuery = $db->prepare('SELECT id FROM utility_readings WHERE unit_id = ? AND utility_type_id = ? AND billing_period = ? LIMIT 1');
                $duplicateQuery->execute([$unitId, $utilityTypeId, $billingPeriod]);
                if ($duplicateQuery->fetchColumn()) {
                    throw new InvalidArgumentException('A reading has already been recorded for this unit and billing month.');
                }
                $previousQuery = $db->prepare('SELECT current_reading FROM utility_readings WHERE unit_id = ? AND utility_type_id = ? ORDER BY reading_date DESC, id DESC LIMIT 1');
                $previousQuery->execute([$unitId, $utilityTypeId]);
                $previousReading = $previousQuery->fetchColumn();
                $previousReading = $previousReading === false ? 0.0 : (float)$previousReading;
                if ((float)$currentReading < $previousReading) {
                    throw new InvalidArgumentException('The current reading cannot be lower than the previous recorded reading (' . number_format($previousReading, 2) . ').');
                }
                $consumption = (float)$currentReading - $previousReading;
                $rate = (float)$utility['default_rate'];
                $readingDate = date('Y-m-t', strtotime($billingPeriod . '-01'));
                $db->beginTransaction();
                $insert = $db->prepare('INSERT INTO utility_readings (unit_id, utility_type_id, previous_reading, current_reading, consumption, rate, total_amount, billing_period, reading_date, recorded_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $insert->execute([$unitId, $utilityTypeId, $previousReading, $currentReading, $consumption, $rate, $consumption * $rate, $billingPeriod, $readingDate, (int)$user['id']]);
                $readingId = (int)$db->lastInsertId();
                $audit = $db->prepare("INSERT INTO audit_logs (user_id, action, module, record_id, description, ip_address) VALUES (?, 'Create reading', 'Caretaker Utilities', ?, ?, ?)");
                $audit->execute([(int)$user['id'], $readingId, 'Recorded utility meter reading for unit #' . $unitId . ', ' . $billingPeriod, $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN']);
                $db->commit();
                $_SESSION['caretaker_dashboard_success'] = 'Utility meter reading recorded.';
                redirect('caretaker/dashboard.php#utility-readings');
            } elseif ($action === 'set_unit_status') {
                $unitId = filter_var($textValue('unit_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
                $status = $textValue('status');
                if (!$unitId || !in_array($status, ['Vacant', 'Reserved', 'Under Maintenance', 'Unavailable'], true)) {
                    throw new InvalidArgumentException('Choose a valid unit and status.');
                }
                $db->beginTransaction();
                $unitQuery = $db->prepare("SELECT u.id, u.status FROM units u WHERE u.id = ? AND u.property_id IN ($propertyPlaceholders) FOR UPDATE");
                $unitQuery->execute(array_merge([$unitId], $propertyIds));
                $unit = $unitQuery->fetch();
                if (!$unit) {
                    throw new InvalidArgumentException('That unit is outside your assigned property work.');
                }
                $leaseQuery = $db->prepare("SELECT id FROM leases WHERE unit_id = ? AND status = 'Active' AND end_date >= CURRENT_DATE() LIMIT 1");
                $leaseQuery->execute([$unitId]);
                if ($unit['status'] === 'Occupied' || $leaseQuery->fetchColumn()) {
                    throw new InvalidArgumentException('Occupied units and units with active leases must be updated through lease management.');
                }
                $db->prepare('UPDATE units SET status = ? WHERE id = ?')->execute([$status, $unitId]);
                $audit = $db->prepare("INSERT INTO audit_logs (user_id, action, module, record_id, description, ip_address) VALUES (?, 'Update status', 'Caretaker Units', ?, ?, ?)");
                $audit->execute([(int)$user['id'], $unitId, 'Changed unit status to ' . $status, $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN']);
                $db->commit();
                $_SESSION['caretaker_dashboard_success'] = 'Unit status updated.';
                redirect('caretaker/dashboard.php#unit-overview');
            } elseif ($action === 'record_viewing_feedback') {
                $viewingId = filter_var($textValue('viewing_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
                $feedback = $textValue('feedback');
                $note = $textValue('feedback_note');
                if (!$viewingId || !in_array($feedback, ['Interested', 'Considering', 'No-show', 'Completed'], true) || strlen($note) > 1000) {
                    throw new InvalidArgumentException('Choose a feedback outcome and keep notes under 1,000 characters.');
                }
                $viewingQuery = $db->prepare("SELECT id FROM viewing_requests WHERE id = ? AND property_id IN ($propertyPlaceholders)");
                $viewingQuery->execute(array_merge([$viewingId], $propertyIds));
                if (!$viewingQuery->fetchColumn()) {
                    throw new InvalidArgumentException('That viewing is outside your assigned property work.');
                }
                $description = $feedback . ($note !== '' ? ': ' . $note : '');
                $audit = $db->prepare("INSERT INTO audit_logs (user_id, action, module, record_id, description, ip_address) VALUES (?, 'Viewing feedback', 'Caretaker Viewings', ?, ?, ?)");
                $audit->execute([(int)$user['id'], $viewingId, $description, $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN']);
                $_SESSION['caretaker_dashboard_success'] = 'Viewing feedback saved for management.';
                redirect('caretaker/dashboard.php#viewing-schedule');
            } elseif ($action === 'record_incident') {
                $propertyId = filter_var($textValue('property_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
                $incident = $textValue('incident');
                if (!$propertyId || strlen($incident) < 5 || strlen($incident) > 2000 || !in_array($propertyId, $propertyIds, true)) {
                    throw new InvalidArgumentException('Choose an assigned property and enter an incident note between 5 and 2,000 characters.');
                }
                $audit = $db->prepare("INSERT INTO audit_logs (user_id, action, module, record_id, description, ip_address) VALUES (?, 'Incident report', 'Caretaker Incident', ?, ?, ?)");
                $audit->execute([(int)$user['id'], $propertyId, $incident, $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN']);
                $_SESSION['caretaker_dashboard_success'] = 'Incident report recorded for management.';
                redirect('caretaker/dashboard.php#site-reports');
            } elseif ($action === 'record_expense') {
                $propertyId = filter_var($textValue('property_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
                $category = $textValue('category');
                $description = $textValue('description');
                $amount = $textValue('amount');
                $expenseDate = $textValue('expense_date');
                $vendor = $textValue('vendor');
                $categories = ['Maintenance', 'Security', 'Utilities', 'Cleaning', 'Staff', 'Repairs', 'Management', 'Other'];
                $dateObject = DateTime::createFromFormat('Y-m-d', $expenseDate);
                if (!$propertyId || !in_array($propertyId, $propertyIds, true) || !in_array($category, $categories, true) ||
                    $description === '' || strlen($description) > 255 || !is_numeric($amount) || (float)$amount <= 0 || (float)$amount > 9999999999 ||
                    !$dateObject || $dateObject->format('Y-m-d') !== $expenseDate || $expenseDate > date('Y-m-d') || strlen($vendor) > 150) {
                    throw new InvalidArgumentException('Enter valid assigned-property expense details, amount, and date.');
                }
                $receiptPath = null;
                if (isset($_FILES['receipt']) && $_FILES['receipt']['error'] !== UPLOAD_ERR_NO_FILE) {
                    $upload = uploadApplicationFile($_FILES['receipt'], 'documents', ['jpg', 'jpeg', 'png', 'pdf']);
                    if (!$upload['success']) {
                        throw new InvalidArgumentException($upload['error']);
                    }
                    $uploadedPath = $receiptPath = $upload['path'];
                }
                $db->beginTransaction();
                $insert = $db->prepare('INSERT INTO expenses (property_id, category, description, amount, expense_date, vendor, receipt_path, recorded_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                $insert->execute([$propertyId, $category, $description, $amount, $expenseDate, $vendor !== '' ? $vendor : null, $receiptPath, (int)$user['id']]);
                $expenseId = (int)$db->lastInsertId();
                $audit = $db->prepare("INSERT INTO audit_logs (user_id, action, module, record_id, description, ip_address) VALUES (?, 'Submit expense', 'Caretaker Expenses', ?, ?, ?)");
                $audit->execute([(int)$user['id'], $expenseId, 'Recorded a caretaker expense for property #' . $propertyId, $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN']);
                $db->commit();
                $_SESSION['caretaker_dashboard_success'] = 'Expense recorded. This record does not itself confirm reimbursement approval.';
                redirect('caretaker/dashboard.php#site-reports');
            } else {
                throw new InvalidArgumentException('Choose a valid caretaker dashboard action.');
            }
        } catch (InvalidArgumentException $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            if ($uploadedPath !== null) {
                $uploadedFile = realpath(__DIR__ . '/../' . $uploadedPath);
                $uploadsRoot = realpath(__DIR__ . '/../uploads');
                if ($uploadedFile && $uploadsRoot && strpos($uploadedFile, $uploadsRoot . DIRECTORY_SEPARATOR) === 0) {
                    unlink($uploadedFile);
                }
            }
            $errors[] = $exception->getMessage();
        } catch (PDOException $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            if ($uploadedPath !== null) {
                $uploadedFile = realpath(__DIR__ . '/../' . $uploadedPath);
                $uploadsRoot = realpath(__DIR__ . '/../uploads');
                if ($uploadedFile && $uploadsRoot && strpos($uploadedFile, $uploadsRoot . DIRECTORY_SEPARATOR) === 0) {
                    unlink($uploadedFile);
                }
            }
            error_log('Caretaker dashboard database action failed: ' . $exception->getMessage());
            $errors[] = 'The requested update could not be saved. Check your entries and try again.';
        } catch (Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            if ($uploadedPath !== null) {
                $uploadedFile = realpath(__DIR__ . '/../' . $uploadedPath);
                $uploadsRoot = realpath(__DIR__ . '/../uploads');
                if ($uploadedFile && $uploadsRoot && strpos($uploadedFile, $uploadsRoot . DIRECTORY_SEPARATOR) === 0) {
                    unlink($uploadedFile);
                }
            }
            error_log('Caretaker dashboard action failed: ' . $exception->getMessage());
            $errors[] = 'The requested update could not be completed. Please try again.';
        }
    }
}

$statsQuery = $db->prepare("SELECT COUNT(DISTINCT mr.property_id) properties, COUNT(DISTINCT mr.unit_id) units,
    SUM(CASE WHEN mr.status NOT IN ('Completed', 'Cancelled') THEN 1 ELSE 0 END) open_count,
    SUM(CASE WHEN mr.priority IN ('Emergency', 'High') AND mr.status NOT IN ('Completed', 'Cancelled') THEN 1 ELSE 0 END) urgent
    FROM maintenance_requests mr WHERE mr.assigned_staff = ?");
$statsQuery->execute([$user['id']]);
$stats = $statsQuery->fetch();

$requestQuery = $db->prepare("SELECT mr.id, mr.category, mr.description, mr.priority, mr.status, mr.created_at, mr.unit_id, mr.tenant_id,
        p.name AS property_name, u.unit_number, t.full_name AS tenant_name, t.phone AS tenant_phone,
        (SELECT a.description FROM audit_logs a WHERE a.module = 'Caretaker Maintenance' AND a.record_id = mr.id AND a.action = 'Repair note' ORDER BY a.id DESC LIMIT 1) AS latest_note
    FROM maintenance_requests mr
    JOIN properties p ON p.id = mr.property_id
    JOIN units u ON u.id = mr.unit_id
    JOIN tenants t ON t.id = mr.tenant_id
    WHERE mr.assigned_staff = ?
    ORDER BY FIELD(mr.status, 'Submitted', 'Assigned', 'In Progress', 'Waiting', 'Reviewed', 'Completed', 'Cancelled'),
        FIELD(mr.priority, 'Emergency', 'High', 'Medium', 'Low'), mr.created_at ASC
    LIMIT 20");
$requestQuery->execute([$user['id']]);
$requests = $requestQuery->fetchAll();

$tenants = [];
$units = [];
$utilityTypes = [];
$utilityReadings = [];
$viewings = [];
$incidents = [];
$expenses = [];
$tenantDirectoryQuery = null;
if ($propertyIds) {
    $tenantDirectoryQuery = $db->prepare("SELECT DISTINCT t.id, t.full_name, t.phone, u.unit_number, p.name AS property_name
        FROM leases le JOIN tenants t ON t.id = le.tenant_id
        JOIN units u ON u.id = le.unit_id JOIN properties p ON p.id = le.property_id
        WHERE le.status = 'Active' AND le.end_date >= CURRENT_DATE() AND p.id IN ($propertyPlaceholders)
        ORDER BY p.name, u.unit_number");
    $tenantDirectoryQuery->execute($propertyIds);
    $tenants = $tenantDirectoryQuery->fetchAll();

    $unitQuery = $db->prepare("SELECT u.id, u.unit_number, u.house_type, u.status, p.name AS property_name,
            EXISTS(SELECT 1 FROM leases le WHERE le.unit_id = u.id AND le.status = 'Active' AND le.end_date >= CURRENT_DATE()) AS active_lease
        FROM units u JOIN properties p ON p.id = u.property_id WHERE p.id IN ($propertyPlaceholders) ORDER BY p.name, u.unit_number");
    $unitQuery->execute($propertyIds);
    $units = $unitQuery->fetchAll();

    $utilityTypes = $db->query("SELECT id, name, unit_of_measure, default_rate FROM utility_types WHERE name IN ('Water', 'Electricity') ORDER BY name")->fetchAll();
    $readingQuery = $db->prepare("SELECT r.id, r.billing_period, r.previous_reading, r.current_reading, r.consumption, r.total_amount,
            ty.name AS utility_name, u.unit_number, p.name AS property_name
        FROM utility_readings r JOIN utility_types ty ON ty.id = r.utility_type_id
        JOIN units u ON u.id = r.unit_id JOIN properties p ON p.id = u.property_id
        WHERE p.id IN ($propertyPlaceholders) ORDER BY r.reading_date DESC, r.id DESC LIMIT 12");
    $readingQuery->execute($propertyIds);
    $utilityReadings = $readingQuery->fetchAll();

    $viewingQuery = $db->prepare("SELECT vr.id, vr.visitor_name, vr.visitor_phone, vr.visitor_email, vr.preferred_date,
            vr.preferred_time, vr.status, p.name AS property_name, u.unit_number,
            (SELECT a.description FROM audit_logs a WHERE a.module = 'Caretaker Viewings' AND a.record_id = vr.id AND a.action = 'Viewing feedback' ORDER BY a.id DESC LIMIT 1) AS feedback
        FROM viewing_requests vr JOIN properties p ON p.id = vr.property_id LEFT JOIN units u ON u.id = vr.unit_id
        WHERE p.id IN ($propertyPlaceholders) AND vr.status IN ('Pending', 'Approved', 'Rescheduled') AND vr.preferred_date >= CURRENT_DATE()
        ORDER BY vr.preferred_date, vr.preferred_time LIMIT 12");
    $viewingQuery->execute($propertyIds);
    $viewings = $viewingQuery->fetchAll();

    $incidentQuery = $db->prepare("SELECT a.description, a.created_at, p.name AS property_name
        FROM audit_logs a JOIN properties p ON p.id = a.record_id
        WHERE a.user_id = ? AND a.module = 'Caretaker Incident' AND p.id IN ($propertyPlaceholders)
        ORDER BY a.created_at DESC LIMIT 8");
    $incidentQuery->execute(array_merge([(int)$user['id']], $propertyIds));
    $incidents = $incidentQuery->fetchAll();

    $expenseQuery = $db->prepare("SELECT ex.id, ex.category, ex.description, ex.amount, ex.expense_date, ex.vendor, p.name AS property_name
        FROM expenses ex JOIN properties p ON p.id = ex.property_id
        WHERE ex.recorded_by = ? AND p.id IN ($propertyPlaceholders)
        ORDER BY ex.expense_date DESC, ex.id DESC LIMIT 8");
    $expenseQuery->execute(array_merge([(int)$user['id']], $propertyIds));
    $expenses = $expenseQuery->fetchAll();
}

$page_title = 'Caretaker Dashboard';
include __DIR__ . '/../includes/header.php';
?>
<div class="flex h-screen bg-gray-50 overflow-hidden">
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>
    <div class="flex-1 flex flex-col overflow-y-auto">
        <?php include __DIR__ . '/../includes/navbar.php'; ?>
        <main id="main-content" class="flex-1 p-4 sm:p-6 space-y-6">
            <header>
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Caretaker workspace</p>
                <h1 class="text-2xl font-bold text-gray-900">Welcome, <?php echo e($user['full_name']); ?></h1>
                <p class="text-sm text-gray-500">On-site work assigned to your account, tenant contacts, and property records.</p>
            </header>
            <?php if ($success !== ''): ?><div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800" role="status"><?php echo e($success); ?></div><?php endif; ?>
            <?php foreach ($errors as $error): ?><div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert"><?php echo e($error); ?></div><?php endforeach; ?>
            <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900"><strong>Assignment scope:</strong> The database links caretakers to explicitly assigned maintenance requests, not to properties generally. Property-scoped panels below use those assigned requests; ask an administrator to assign work before acting on a property.</div>

            <section aria-label="Caretaker work summary" class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
                <?php foreach ([['Assigned properties', (int)$stats['properties'], 'fa-building'], ['Units at assigned properties', (int)$stats['units'], 'fa-door-open'], ['Open assigned requests', (int)$stats['open_count'], 'fa-screwdriver-wrench'], ['High priority / emergency', (int)$stats['urgent'], 'fa-triangle-exclamation']] as $card): ?>
                    <article class="bg-white p-5 rounded-xl border border-gray-100 shadow-sm"><div class="flex items-center justify-between gap-3"><div><p class="text-xs font-medium text-gray-500 uppercase"><?php echo e($card[0]); ?></p><h2 class="text-2xl font-bold text-gray-900 mt-2"><?php echo number_format($card[1]); ?></h2></div><span class="p-3 rounded-xl bg-blue-50 text-primary" aria-hidden="true"><i class="fa-solid <?php echo e($card[2]); ?>"></i></span></div></article>
                <?php endforeach; ?>
            </section>

            <section id="assigned-requests" class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden" aria-labelledby="assigned-requests-heading">
                <div class="p-5 border-b border-gray-100"><h2 id="assigned-requests-heading" class="font-bold text-gray-900">Assigned repair tickets</h2><p class="mt-1 text-sm text-gray-500">Update progress and leave an operational note; notes and status changes are recorded in the activity log.</p></div>
                <?php if (!$requests): ?><p class="p-6 text-center text-sm text-gray-500">No maintenance requests have been assigned to you.</p>
                <?php else: ?><div class="divide-y divide-gray-100">
                    <?php foreach ($requests as $request): ?>
                        <article class="p-4 sm:p-5">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div><h3 class="font-semibold text-gray-900"><?php echo e($request['category']); ?> · <?php echo e($request['property_name']); ?> / <?php echo e($request['unit_number']); ?></h3><p class="mt-1 text-sm text-gray-600"><?php echo e($request['tenant_name']); ?> · <a class="text-primary hover:underline" href="tel:<?php echo e(preg_replace('/[^0-9+]/', '', $request['tenant_phone'])); ?>"><?php echo e($request['tenant_phone']); ?></a></p><p class="mt-2 text-sm text-gray-700"><?php echo nl2br(e($request['description'])); ?></p><div class="mt-2 flex flex-wrap gap-2"><?php echo render_status_badge($request['priority']); ?><?php echo render_status_badge($request['status']); ?><span class="text-xs text-gray-500"><?php echo e(date('d M Y, H:i', strtotime($request['created_at']))); ?></span></div>
                                    <?php if ($request['latest_note']): ?><p class="mt-2 text-sm text-gray-600"><strong>Latest repair note:</strong> <?php echo e($request['latest_note']); ?></p><?php endif; ?>
                                </div>
                                <?php if (!in_array($request['status'], ['Completed', 'Cancelled'], true)): ?>
                                <form method="post" enctype="multipart/form-data" class="w-full lg:w-96 rounded-lg border border-gray-200 bg-gray-50 p-3 space-y-3">
                                    <?php echo csrf_field(); ?><input type="hidden" name="dashboard_action" value="update_maintenance"><input type="hidden" name="request_id" value="<?php echo (int)$request['id']; ?>">
                                    <label class="block text-xs font-semibold text-gray-700">Update status<select name="status" required class="mt-1 block w-full rounded-lg border-gray-300 text-sm"><?php foreach (['In Progress', 'Waiting', 'Completed'] as $status): ?><option value="<?php echo e($status); ?>" <?php echo $request['status'] === $status ? 'selected' : ''; ?>><?php echo e($status); ?></option><?php endforeach; ?></select></label>
                                    <label class="block text-xs font-semibold text-gray-700">Repair note<textarea name="repair_note" maxlength="2000" rows="2" class="mt-1 block w-full rounded-lg border-gray-300 text-sm" placeholder="Work performed or next action"></textarea></label>
                                    <label class="block text-xs font-semibold text-gray-700">Completion photo <span class="font-normal text-gray-500">(optional JPG/PNG, max 5 MB)</span><input type="file" name="completion_photo" accept=".jpg,.jpeg,.png,image/jpeg,image/png" class="mt-1 block w-full text-xs"></label>
                                    <button class="rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-blue-800 focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2">Save update</button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div><?php endif; ?>
            </section>

            <div class="grid grid-cols-1 xl:grid-cols-2 gap-5">
                <section class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden" aria-labelledby="tenant-directory-heading">
                    <div class="p-5 border-b border-gray-100"><h2 id="tenant-directory-heading" class="font-bold text-gray-900">Tenant directory</h2><p class="mt-1 text-sm text-gray-500">Active leases at properties linked to your assigned work.</p></div>
                    <div class="overflow-x-auto"><table class="w-full min-w-[600px] text-left"><thead><tr class="bg-gray-50 text-xs font-semibold uppercase text-gray-500"><th class="p-4">Tenant</th><th class="p-4">Property / unit</th><th class="p-4">Phone</th></tr></thead><tbody class="divide-y divide-gray-100 text-sm">
                        <?php if (!$tenants): ?><tr><td colspan="3" class="p-5 text-center text-gray-500">No active tenant records for assigned properties.</td></tr><?php else: foreach ($tenants as $tenant): ?><tr><th class="p-4 font-medium text-gray-900"><?php echo e($tenant['full_name']); ?></th><td class="p-4"><?php echo e($tenant['property_name'] . ' / ' . $tenant['unit_number']); ?></td><td class="p-4"><a class="text-primary hover:underline" href="tel:<?php echo e(preg_replace('/[^0-9+]/', '', $tenant['phone'])); ?>"><?php echo e($tenant['phone']); ?></a></td></tr><?php endforeach; endif; ?>
                    </tbody></table></div>
                </section>
                <section id="unit-overview" class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden" aria-labelledby="unit-overview-heading">
                    <div class="p-5 border-b border-gray-100"><h2 id="unit-overview-heading" class="font-bold text-gray-900">Unit status overview</h2><p class="mt-1 text-sm text-gray-500">Vacant, reserved, maintenance, and unavailable states can be updated here. Occupied status remains governed by lease management.</p></div>
                    <div class="divide-y divide-gray-100"><?php if (!$units): ?><p class="p-5 text-sm text-gray-500">No units are in your assigned-property scope.</p><?php else: foreach ($units as $unit): ?><div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 p-4"><div><p class="font-medium text-sm text-gray-900"><?php echo e($unit['property_name']); ?> · <?php echo e($unit['unit_number']); ?></p><p class="text-xs text-gray-500"><?php echo e($unit['house_type']); ?></p></div><div class="flex items-center gap-3"><?php echo render_status_badge($unit['status']); ?><?php if ($unit['status'] !== 'Occupied' && !(int)$unit['active_lease']): ?><form method="post" class="flex items-center gap-2"><?php echo csrf_field(); ?><input type="hidden" name="dashboard_action" value="set_unit_status"><input type="hidden" name="unit_id" value="<?php echo (int)$unit['id']; ?>"><label class="sr-only" for="caretaker-unit-status-<?php echo (int)$unit['id']; ?>">Update unit status</label><select id="caretaker-unit-status-<?php echo (int)$unit['id']; ?>" name="status" class="rounded border-gray-300 text-xs"><?php foreach (['Vacant', 'Reserved', 'Under Maintenance', 'Unavailable'] as $status): ?><option value="<?php echo e($status); ?>" <?php echo $unit['status'] === $status ? 'selected' : ''; ?>><?php echo e($status); ?></option><?php endforeach; ?></select><button class="rounded bg-gray-100 px-2 py-1 text-xs font-semibold hover:bg-gray-200 focus:outline-none focus:ring-2 focus:ring-primary">Save</button></form><?php else: ?><span class="text-xs text-gray-500">Lease-managed</span><?php endif; ?></div></div><?php endforeach; endif; ?></div>
                </section>
            </div>

            <section id="utility-readings" class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden" aria-labelledby="utility-heading">
                <div class="p-5 border-b border-gray-100"><h2 id="utility-heading" class="font-bold text-gray-900">Utility meter readings</h2><p class="mt-1 text-sm text-gray-500">Readings use the previous recorded meter value and the utility type’s default rate. Only configured Water or Electricity types are available; contact an administrator if a utility or rate is missing.</p></div>
                <?php if ($units && $utilityTypes): ?>
                <form method="post" class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-5 gap-3 p-5 border-b border-gray-100">
                    <?php echo csrf_field(); ?><input type="hidden" name="dashboard_action" value="record_utility">
                    <label class="text-xs font-semibold text-gray-700 xl:col-span-2">Unit<select name="unit_id" required class="mt-1 block w-full rounded-lg border-gray-300 text-sm"><?php foreach ($units as $unit): ?><option value="<?php echo (int)$unit['id']; ?>"><?php echo e($unit['property_name'] . ' / ' . $unit['unit_number']); ?></option><?php endforeach; ?></select></label>
                    <label class="text-xs font-semibold text-gray-700">Utility<select name="utility_type_id" required class="mt-1 block w-full rounded-lg border-gray-300 text-sm"><?php foreach ($utilityTypes as $type): ?><option value="<?php echo (int)$type['id']; ?>"><?php echo e($type['name'] . ' · KSh ' . number_format((float)$type['default_rate'], 2) . '/' . $type['unit_of_measure']); ?></option><?php endforeach; ?></select></label>
                    <label class="text-xs font-semibold text-gray-700">Billing month<input type="month" name="billing_period" value="<?php echo e(date('Y-m')); ?>" required class="mt-1 block w-full rounded-lg border-gray-300 text-sm"></label>
                    <label class="text-xs font-semibold text-gray-700">Current reading<input type="number" min="0" step="0.01" name="current_reading" required class="mt-1 block w-full rounded-lg border-gray-300 text-sm"></label>
                    <button class="sm:col-span-2 xl:col-span-5 justify-self-start rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-blue-800">Save meter reading</button>
                </form><?php else: ?><p class="p-5 text-sm text-gray-500">No assigned units or utility types are available for reading entry.</p><?php endif; ?>
                <div class="overflow-x-auto"><table class="w-full min-w-[700px] text-left"><thead><tr class="bg-gray-50 text-xs font-semibold uppercase text-gray-500"><th class="p-4">Billing month</th><th class="p-4">Property / unit</th><th class="p-4">Utility</th><th class="p-4">Previous → current</th><th class="p-4">Consumption</th><th class="p-4">Amount</th></tr></thead><tbody class="divide-y divide-gray-100 text-sm">
                    <?php if (!$utilityReadings): ?><tr><td colspan="6" class="p-5 text-center text-gray-500">No meter readings recorded for assigned properties.</td></tr><?php else: foreach ($utilityReadings as $reading): ?><tr><td class="p-4"><?php echo e($reading['billing_period']); ?></td><td class="p-4"><?php echo e($reading['property_name'] . ' / ' . $reading['unit_number']); ?></td><td class="p-4"><?php echo e($reading['utility_name']); ?></td><td class="p-4"><?php echo e(number_format((float)$reading['previous_reading'], 2) . ' → ' . number_format((float)$reading['current_reading'], 2)); ?></td><td class="p-4"><?php echo e(number_format((float)$reading['consumption'], 2)); ?></td><td class="p-4"><?php echo format_currency($reading['total_amount']); ?></td></tr><?php endforeach; endif; ?>
                </tbody></table></div>
            </section>

            <section id="viewing-schedule" class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden" aria-labelledby="viewing-heading">
                <div class="p-5 border-b border-gray-100"><h2 id="viewing-heading" class="font-bold text-gray-900">Scheduled viewings</h2><p class="mt-1 text-sm text-gray-500">Upcoming approved or pending visits at properties linked to your assigned work.</p></div>
                <?php if (!$viewings): ?><p class="p-5 text-sm text-gray-500">No upcoming viewing requests for assigned properties.</p><?php else: ?><div class="divide-y divide-gray-100">
                    <?php foreach ($viewings as $viewing): ?><article class="p-4 flex flex-col xl:flex-row xl:items-center xl:justify-between gap-4"><div><h3 class="font-semibold text-gray-900"><?php echo e($viewing['visitor_name']); ?> · <?php echo e($viewing['property_name']); ?><?php echo $viewing['unit_number'] ? ' / ' . e($viewing['unit_number']) : ''; ?></h3><p class="mt-1 text-sm text-gray-600"><?php echo e(date('d M Y', strtotime($viewing['preferred_date'])) . ' at ' . date('H:i', strtotime($viewing['preferred_time']))); ?> · <?php echo e($viewing['visitor_phone']); ?> · <?php echo e($viewing['visitor_email']); ?></p><div class="mt-2"><?php echo render_status_badge($viewing['status']); ?><?php if ($viewing['feedback']): ?><span class="ml-2 text-xs text-gray-600">Feedback: <?php echo e($viewing['feedback']); ?></span><?php endif; ?></div></div>
                        <form method="post" class="w-full xl:w-96 grid grid-cols-1 sm:grid-cols-2 gap-2">
                            <?php echo csrf_field(); ?><input type="hidden" name="dashboard_action" value="record_viewing_feedback"><input type="hidden" name="viewing_id" value="<?php echo (int)$viewing['id']; ?>">
                            <label class="text-xs font-semibold text-gray-700">Outcome<select name="feedback" required class="mt-1 block w-full rounded-lg border-gray-300 text-sm"><option value="Interested">Interested</option><option value="Considering">Considering</option><option value="No-show">No-show</option><option value="Completed">Completed</option></select></label>
                            <label class="text-xs font-semibold text-gray-700">Short note<input name="feedback_note" maxlength="1000" class="mt-1 block w-full rounded-lg border-gray-300 text-sm"></label>
                            <button class="sm:col-span-2 justify-self-start rounded-lg bg-primary px-3 py-2 text-sm font-semibold text-white hover:bg-blue-800">Save feedback</button>
                        </form></article><?php endforeach; ?>
                </div><?php endif; ?>
            </section>

            <section id="site-reports" class="grid grid-cols-1 xl:grid-cols-2 gap-5" aria-label="Site incident and expense reporting">
                <article class="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
                    <h2 class="font-bold text-gray-900">Report a site incident</h2><p class="mt-1 text-sm text-gray-500">Record security, compound, or service issues for management follow-up.</p>
                    <?php if ($assignedProperties): ?><form method="post" class="mt-4 space-y-3"><?php echo csrf_field(); ?><input type="hidden" name="dashboard_action" value="record_incident">
                        <label class="block text-xs font-semibold text-gray-700">Assigned property<select name="property_id" required class="mt-1 block w-full rounded-lg border-gray-300 text-sm"><?php foreach ($assignedProperties as $property): ?><option value="<?php echo (int)$property['id']; ?>"><?php echo e($property['name']); ?></option><?php endforeach; ?></select></label>
                        <label class="block text-xs font-semibold text-gray-700">Incident details<textarea name="incident" minlength="5" maxlength="2000" rows="3" required class="mt-1 block w-full rounded-lg border-gray-300 text-sm"></textarea></label>
                        <button class="rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-blue-800">Record incident</button>
                    </form><?php endif; ?>
                    <h3 class="mt-5 text-sm font-semibold text-gray-800">Recent reports</h3><ul class="mt-2 divide-y divide-gray-100"><?php if (!$incidents): ?><li class="py-3 text-sm text-gray-500">No incident reports recorded by this account.</li><?php else: foreach ($incidents as $incident): ?><li class="py-3 text-sm"><strong><?php echo e($incident['property_name']); ?></strong><p class="text-gray-600"><?php echo e($incident['description']); ?></p><p class="text-xs text-gray-400"><?php echo e($incident['created_at']); ?></p></li><?php endforeach; endif; ?></ul>
                </article>
                <article class="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
                    <h2 class="font-bold text-gray-900">Record minor expense</h2><p class="mt-1 text-sm text-gray-500">Expense records are saved for management review. Submission does not confirm reimbursement approval.</p>
                    <?php if ($assignedProperties): ?><form method="post" enctype="multipart/form-data" class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-3"><?php echo csrf_field(); ?><input type="hidden" name="dashboard_action" value="record_expense">
                        <label class="text-xs font-semibold text-gray-700 sm:col-span-2">Assigned property<select name="property_id" required class="mt-1 block w-full rounded-lg border-gray-300 text-sm"><?php foreach ($assignedProperties as $property): ?><option value="<?php echo (int)$property['id']; ?>"><?php echo e($property['name']); ?></option><?php endforeach; ?></select></label>
                        <label class="text-xs font-semibold text-gray-700">Category<select name="category" required class="mt-1 block w-full rounded-lg border-gray-300 text-sm"><?php foreach (['Maintenance', 'Security', 'Utilities', 'Cleaning', 'Staff', 'Repairs', 'Management', 'Other'] as $category): ?><option value="<?php echo e($category); ?>"><?php echo e($category); ?></option><?php endforeach; ?></select></label>
                        <label class="text-xs font-semibold text-gray-700">Amount (KSh)<input type="number" name="amount" min="0.01" step="0.01" required class="mt-1 block w-full rounded-lg border-gray-300 text-sm"></label>
                        <label class="text-xs font-semibold text-gray-700 sm:col-span-2">Description<input name="description" maxlength="255" required class="mt-1 block w-full rounded-lg border-gray-300 text-sm"></label>
                        <label class="text-xs font-semibold text-gray-700">Date<input type="date" name="expense_date" value="<?php echo e(date('Y-m-d')); ?>" required class="mt-1 block w-full rounded-lg border-gray-300 text-sm"></label>
                        <label class="text-xs font-semibold text-gray-700">Vendor (optional)<input name="vendor" maxlength="150" class="mt-1 block w-full rounded-lg border-gray-300 text-sm"></label>
                        <label class="text-xs font-semibold text-gray-700 sm:col-span-2">Receipt image or PDF (optional; max 5 MB)<input type="file" name="receipt" accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf" class="mt-1 block w-full text-xs"></label>
                        <button class="sm:col-span-2 justify-self-start rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-blue-800">Save expense</button>
                    </form><?php endif; ?>
                    <h3 class="mt-5 text-sm font-semibold text-gray-800">Recent recorded expenses</h3><ul class="mt-2 divide-y divide-gray-100"><?php if (!$expenses): ?><li class="py-3 text-sm text-gray-500">No expenses recorded by this account.</li><?php else: foreach ($expenses as $expense): ?><li class="py-3 text-sm"><div class="flex justify-between gap-3"><strong><?php echo e($expense['description']); ?></strong><span><?php echo format_currency($expense['amount']); ?></span></div><p class="text-gray-600"><?php echo e($expense['property_name'] . ' · ' . $expense['category'] . ' · ' . $expense['expense_date']); ?></p></li><?php endforeach; endif; ?></ul>
                </article>
            </section>
        </main>
        <?php include __DIR__ . '/../includes/footer.php'; ?>
    </div>
</div>
