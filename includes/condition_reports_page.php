<?php
/**
 * Condition reports page shared by the staff portal (create, view, delete) and the
 * landlord portal (read-only, own properties). The including page checks access and sets
 * $conditionLandlordId (null for staff), $conditionCanEdit and $conditionPageUrl.
 */

if (!isset($conditionPageUrl) || !isset($conditionCanEdit) || !array_key_exists('conditionLandlordId', get_defined_vars())) {
    http_response_code(404);
    exit();
}

require_once __DIR__ . '/crud.php';
require_once __DIR__ . '/condition.php';
require_once __DIR__ . '/finance.php';

$db = db();
$action = is_string($_GET['action'] ?? null) ? $_GET['action'] : 'list';
$errors = [];
$form = ['lease_id' => '', 'report_type' => 'Move-in', 'inspection_date' => date('Y-m-d'), 'overall_condition' => 'Good', 'general_notes' => '',
    'items' => array_fill_keys(CONDITION_AREAS, ['item_condition' => 'Good', 'remarks' => ''])];

function condition_redirect(string $url, array $query = []): void {
    header('Location: ' . $url . ($query ? '?' . http_build_query($query) : ''));
    exit();
}

/** Uploaded checklist photos as one $_FILES-style array per area index. */
function condition_uploaded_photo(int $index): ?array {
    $files = $_FILES['photo'] ?? null;
    if (!$files || !isset($files['error'][$index]) || $files['error'][$index] === UPLOAD_ERR_NO_FILE) return null;
    return ['name' => $files['name'][$index], 'type' => $files['type'][$index], 'tmp_name' => $files['tmp_name'][$index],
        'error' => $files['error'][$index], 'size' => $files['size'][$index]];
}

/** Validate and store a new report. Returns [form values, errors]; redirects on success. */
function condition_save(PDO $db, array $form, string $pageUrl): array {
    $text = fn($key) => is_string($_POST[$key] ?? null) ? trim($_POST[$key]) : '';
    $form['lease_id'] = $text('lease_id');
    foreach (['report_type', 'inspection_date', 'overall_condition', 'general_notes'] as $key) $form[$key] = $text($key);
    $errors = [];

    $lease = $db->prepare('SELECT l.id, t.user_id, u.unit_number FROM leases l JOIN tenants t ON t.id = l.tenant_id JOIN units u ON u.id = l.unit_id WHERE l.id = ?');
    $lease->execute([(int)$form['lease_id']]);
    $lease = $lease->fetch();
    if (!$lease) $errors[] = 'Choose the lease this inspection belongs to.';
    if (!in_array($form['report_type'], CONDITION_REPORT_TYPES, true)) $errors[] = 'Choose Move-in or Move-out.';
    $date = DateTime::createFromFormat('!Y-m-d', $form['inspection_date']);
    if (!$date || $date->format('Y-m-d') !== $form['inspection_date']) $errors[] = 'Enter a valid inspection date.';
    elseif ($form['inspection_date'] > date('Y-m-d')) $errors[] = 'The inspection date cannot be in the future.';
    if (!in_array($form['overall_condition'], CONDITION_OVERALL, true)) $errors[] = 'Choose the overall condition.';
    if (mb_strlen($form['general_notes']) > 3000) $errors[] = 'Keep the general notes to 3,000 characters.';

    $postedConditions = (array)($_POST['item_condition'] ?? []);
    $postedRemarks = (array)($_POST['remarks'] ?? []);
    foreach (CONDITION_AREAS as $i => $area) {
        $condition = is_string($postedConditions[$i] ?? null) ? $postedConditions[$i] : '';
        $remarks = is_string($postedRemarks[$i] ?? null) ? trim($postedRemarks[$i]) : '';
        $form['items'][$area] = ['item_condition' => $condition, 'remarks' => $remarks];
        if (!in_array($condition, CONDITION_ITEM_STATES, true)) $errors[] = "Choose a condition for $area.";
        if (mb_strlen($remarks) > 255) $errors[] = "Keep the remarks for $area to 255 characters.";
    }
    if (!$errors) {
        $duplicate = $db->prepare('SELECT id FROM condition_reports WHERE lease_id = ? AND report_type = ?');
        $duplicate->execute([$lease['id'], $form['report_type']]);
        if ($duplicate->fetchColumn()) $errors[] = "This lease already has a {$form['report_type']} report. Open that report instead of creating another.";
    }
    if ($errors) return [$form, $errors];

    $photos = [];
    foreach (CONDITION_AREAS as $i => $area) {
        $file = condition_uploaded_photo($i);
        if (!$file) continue;
        $upload = uploadApplicationFile($file, 'condition', ['jpg', 'jpeg', 'png']);
        if ($upload['success']) $photos[$area] = $upload['path'];
        else $errors[] = "Photo for $area: " . $upload['error'];
    }
    if ($errors) {
        array_map('crud_delete_upload', $photos);
        return [$form, $errors];
    }

    try {
        $db->beginTransaction();
        $db->prepare('INSERT INTO condition_reports (lease_id, report_type, inspection_date, overall_condition, general_notes, inspected_by) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$lease['id'], $form['report_type'], $form['inspection_date'], $form['overall_condition'], $form['general_notes'] ?: null, $_SESSION['user_id']]);
        $reportId = (int)$db->lastInsertId();
        $insertItem = $db->prepare('INSERT INTO condition_report_items (report_id, area, item_condition, remarks, photo_path) VALUES (?, ?, ?, ?, ?)');
        foreach ($form['items'] as $area => $item) {
            $insertItem->execute([$reportId, $area, $item['item_condition'], $item['remarks'] ?: null, $photos[$area] ?? null]);
        }
        notify_user($db, $lease['user_id'], "{$form['report_type']} condition report ready",
            "The {$form['report_type']} inspection of unit {$lease['unit_number']} has been recorded. Please review it under Deposit & Condition and agree or raise a dispute.");
        $db->commit();
    } catch (PDOException $e) {
        if ($db->inTransaction()) $db->rollBack();
        array_map('crud_delete_upload', $photos);
        error_log('Condition report save failed: ' . $e->getMessage());
        return [$form, ['The report could not be saved. Check the details and try again.']];
    }
    logAuditAction('CREATE', 'condition_reports', $reportId, "Created {$form['report_type']} condition report #$reportId", null,
        ['lease_id' => $lease['id'], 'report_type' => $form['report_type'], 'inspection_date' => $form['inspection_date'], 'overall_condition' => $form['overall_condition'], 'photos' => count($photos)]);
    set_flash('success', "{$form['report_type']} report saved. The tenant has been asked to review it.");
    condition_redirect($pageUrl, ['action' => 'view', 'id' => $reportId]);
    return [$form, []];
}

function condition_delete(PDO $db, string $pageUrl): void {
    $id = (int)filter_var($_POST['id'] ?? 0, FILTER_VALIDATE_INT);
    $report = $id ? condition_report_find($db, $id) : null;
    $linked = $db->prepare('SELECT COUNT(*) FROM deposit_transactions WHERE condition_report_id = ?');
    $linked->execute([$id]);
    if (!$report) {
        set_flash('error', 'Condition report not found.');
    } elseif ($report['tenant_response'] !== 'Pending') {
        set_flash('error', 'The tenant has already responded, so this report is kept as evidence and cannot be deleted.');
    } elseif ((int)$linked->fetchColumn() > 0) {
        set_flash('error', 'A deposit deduction refers to this report, so it cannot be deleted.');
    } else {
        $items = condition_report_items($db, $id);
        $db->prepare('DELETE FROM condition_reports WHERE id = ?')->execute([$id]);
        foreach ($items as $item) crud_delete_upload($item['photo_path']);
        logAuditAction('DELETE', 'condition_reports', $id, "Deleted condition report #$id", array_intersect_key($report, array_flip(['lease_id', 'report_type', 'inspection_date', 'overall_condition'])));
        set_flash('success', 'Condition report deleted.');
    }
    condition_redirect($pageUrl);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$conditionCanEdit) {
        http_response_code(403);
        exit('You can view condition reports but not change them.');
    }
    if (!csrf_validate()) {
        set_flash('error', 'Your session expired. Please try again.');
        condition_redirect($conditionPageUrl);
    }
    $operation = $_POST['_action'] ?? '';
    if ($operation === 'delete') condition_delete($db, $conditionPageUrl);
    if ($operation === 'save') {
        [$form, $errors] = condition_save($db, $form, $conditionPageUrl);
        $action = 'create';
        http_response_code(422);
    }
}

$report = null;
if ($action === 'view') {
    $report = condition_report_find($db, (int)filter_var($_GET['id'] ?? 0, FILTER_VALIDATE_INT), $conditionLandlordId);
    if (!$report) {
        set_flash('error', 'Condition report not found.');
        condition_redirect($conditionPageUrl);
    }
}
if ($action === 'create' && !$conditionCanEdit) $action = 'list';
if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    foreach (['lease_id', 'report_type'] as $key) if (is_string($_GET[$key] ?? null)) $form[$key] = $_GET[$key];
}

$page_title = $action === 'view' ? $report['report_type'] . ' report' : ($action === 'create' ? 'New condition report' : 'Condition Reports');
include __DIR__ . '/layout_top.php';

if ($action === 'view'):
    $items = condition_report_items($db, (int)$report['id']);
    $baseline = condition_baseline($db, $report);
    ?>
    <div>
        <a href="<?php echo e($conditionPageUrl); ?>" class="text-sm text-primary font-medium"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Back to condition reports</a>
        <h1 class="text-2xl font-bold text-gray-900 mt-2"><?php echo e($report['report_type']); ?> condition report #<?php echo (int)$report['id']; ?></h1>
        <p class="text-sm text-gray-500">Lease <?php echo e(format_date($report['start_date']) . ' – ' . format_date($report['end_date'])); ?> · <?php echo render_status_badge($report['lease_status']); ?></p>
    </div>
    <section class="bg-white p-6 rounded-xl border border-gray-100 shadow-sm space-y-4" aria-label="Inspection">
        <?php render_condition_report($report, $items, $baseline); ?>
        <?php if ($report['report_type'] === 'Move-out' && $baseline === null): ?><p class="text-xs text-gray-500">No move-in report exists for this lease, so there is nothing to compare against.</p><?php endif; ?>
        <?php render_condition_response($report); ?>
    </section>
    <?php $deposit = deposit_summary($db, (int)$report['lease_id']); ?>
    <section class="bg-white p-6 rounded-xl border border-gray-100 shadow-sm" aria-labelledby="deposit-heading">
        <h2 id="deposit-heading" class="font-bold text-gray-900 mb-3">Deposit for this lease</h2>
        <p class="text-sm">Agreed <?php echo format_currency($deposit['agreed']); ?> · received <?php echo format_currency($deposit['received']); ?> · deducted <?php echo format_currency($deposit['deducted']); ?> · refunded <?php echo format_currency($deposit['refunded']); ?> · <strong>held <?php echo format_currency($deposit['held']); ?></strong> <?php echo render_status_badge($deposit['status']); ?></p>
        <div class="flex flex-wrap gap-2 mt-4">
            <?php if ($conditionCanEdit && $report['report_type'] === 'Move-out' && user_can_access_module('deposits')): ?>
                <a class="btn btn-accent" href="<?php echo e(module_url('deposits', ['action' => 'create', 'lease_id' => $report['lease_id'], 'transaction_type' => 'Deduction', 'condition_report_id' => $report['id']])); ?>">Record a deduction</a>
            <?php endif; ?>
            <?php if ($conditionCanEdit && $report['report_type'] === 'Move-in'): ?>
                <a class="btn btn-secondary" href="<?php echo e($conditionPageUrl . '?' . http_build_query(['action' => 'create', 'lease_id' => $report['lease_id'], 'report_type' => 'Move-out'])); ?>">Start move-out report</a>
            <?php endif; ?>
            <?php if ($conditionCanEdit && $report['tenant_response'] === 'Pending'): ?>
                <form method="post" action="<?php echo e($conditionPageUrl); ?>" data-confirm="Delete this condition report and its photos?"><?php echo csrf_field(); ?><input type="hidden" name="_action" value="delete"><input type="hidden" name="id" value="<?php echo (int)$report['id']; ?>"><button type="submit" class="btn btn-danger">Delete report</button></form>
            <?php endif; ?>
        </div>
    </section>
<?php elseif ($action === 'create'):
    $leases = $db->query("SELECT l.id, CONCAT(t.full_name, ' — ', p.name, ' / ', u.unit_number, ' (', l.status, ')') FROM leases l JOIN tenants t ON t.id = l.tenant_id
        JOIN units u ON u.id = l.unit_id JOIN properties p ON p.id = l.property_id ORDER BY l.status = 'Active' DESC, t.full_name")->fetchAll(PDO::FETCH_KEY_PAIR);
    $select = function (string $id, string $name, array $options, string $value, string $label) {
        echo '<label for="' . e($id) . '" class="block text-xs font-semibold text-gray-600 uppercase mb-1">' . e($label) . ' <span class="text-red-600" aria-hidden="true">*</span></label>';
        echo '<select id="' . e($id) . '" name="' . e($name) . '" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">';
        foreach ($options as $optionValue => $optionLabel) echo '<option value="' . e((string)$optionValue) . '"' . ((string)$optionValue === $value ? ' selected' : '') . '>' . e((string)$optionLabel) . '</option>';
        echo '</select>';
    };
    ?>
    <div>
        <a href="<?php echo e($conditionPageUrl); ?>" class="text-sm text-primary font-medium"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Back to condition reports</a>
        <h1 class="text-2xl font-bold text-gray-900 mt-2">New condition report</h1>
        <p class="text-sm text-gray-500">Record the state of each area at move-in or move-out. Photos are optional but make the record stronger evidence.</p>
    </div>
    <?php if ($errors): ?><div class="border-l-4 p-4 rounded-r-lg bg-red-50 border-red-500 text-red-800" role="alert"><ul class="text-sm font-medium list-disc pl-4"><?php foreach ($errors as $error): ?><li><?php echo e($error); ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <form method="post" action="<?php echo e($conditionPageUrl); ?>" enctype="multipart/form-data" class="bg-white p-6 rounded-xl border border-gray-100 shadow-sm space-y-5">
        <?php echo csrf_field(); ?><input type="hidden" name="_action" value="save">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
            <div class="md:col-span-2"><?php $select('cr-lease', 'lease_id', ['' => 'Select…'] + $leases, (string)$form['lease_id'], 'Lease'); ?></div>
            <div><?php $select('cr-type', 'report_type', array_combine(CONDITION_REPORT_TYPES, CONDITION_REPORT_TYPES), $form['report_type'], 'Report type'); ?></div>
            <div><label for="cr-date" class="block text-xs font-semibold text-gray-600 uppercase mb-1">Inspection date <span class="text-red-600" aria-hidden="true">*</span></label>
                <input id="cr-date" type="date" name="inspection_date" required max="<?php echo e(date('Y-m-d')); ?>" value="<?php echo e($form['inspection_date']); ?>" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"></div>
            <div><?php $select('cr-overall', 'overall_condition', array_combine(CONDITION_OVERALL, CONDITION_OVERALL), $form['overall_condition'], 'Overall condition'); ?></div>
            <div class="md:col-span-2"><label for="cr-notes" class="block text-xs font-semibold text-gray-600 uppercase mb-1">General notes</label>
                <textarea id="cr-notes" name="general_notes" rows="3" maxlength="3000" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"><?php echo e($form['general_notes']); ?></textarea></div>
        </div>
        <fieldset>
            <legend class="font-bold text-gray-900 mb-2">Checklist</legend>
            <div class="overflow-x-auto"><table class="w-full text-left text-sm">
                <thead><tr><th scope="col" class="p-2">Area</th><th scope="col" class="p-2">Condition</th><th scope="col" class="p-2">Remarks</th><th scope="col" class="p-2">Photo (JPG or PNG, up to 5 MB)</th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                <?php foreach (CONDITION_AREAS as $i => $area): $item = $form['items'][$area]; ?>
                    <tr>
                        <th scope="row" class="p-2 font-medium"><?php echo e($area); ?></th>
                        <td class="p-2"><label class="sr-only" for="cond-<?php echo $i; ?>">Condition of <?php echo e($area); ?></label>
                            <select id="cond-<?php echo $i; ?>" name="item_condition[<?php echo $i; ?>]" class="px-2 py-1.5 border border-gray-300 rounded-lg text-sm"><?php foreach (CONDITION_ITEM_STATES as $state): ?><option <?php echo $item['item_condition'] === $state ? 'selected' : ''; ?>><?php echo e($state); ?></option><?php endforeach; ?></select></td>
                        <td class="p-2"><label class="sr-only" for="remarks-<?php echo $i; ?>">Remarks for <?php echo e($area); ?></label>
                            <input id="remarks-<?php echo $i; ?>" name="remarks[<?php echo $i; ?>]" maxlength="255" value="<?php echo e($item['remarks']); ?>" class="w-full px-2 py-1.5 border border-gray-300 rounded-lg text-sm"></td>
                        <td class="p-2"><label class="sr-only" for="photo-<?php echo $i; ?>">Photo of <?php echo e($area); ?></label>
                            <input id="photo-<?php echo $i; ?>" type="file" name="photo[<?php echo $i; ?>]" accept="image/jpeg,image/png" class="text-xs"></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        </fieldset>
        <div class="flex flex-wrap gap-3 pt-4 border-t border-gray-100"><button type="submit" class="btn btn-primary">Save report</button><a href="<?php echo e($conditionPageUrl); ?>" class="btn btn-secondary">Cancel</a></div>
    </form>
<?php else:
    $where = [];
    $params = [];
    if ($conditionLandlordId) { $where[] = 'p.landlord_id = ?'; $params[] = $conditionLandlordId; }
    $filters = ['report_type' => CONDITION_REPORT_TYPES, 'tenant_response' => ['Pending', 'Agreed', 'Disputed']];
    $active = [];
    foreach ($filters as $key => $allowed) {
        if (is_string($_GET[$key] ?? null) && in_array($_GET[$key], $allowed, true)) { $where[] = "cr.$key = ?"; $params[] = $active[$key] = $_GET[$key]; }
    }
    $stmt = $db->prepare("SELECT cr.id, cr.report_type, cr.inspection_date, cr.overall_condition, cr.tenant_response, t.full_name AS tenant_name, p.name AS property_name, u.unit_number,
            (SELECT COUNT(*) FROM condition_report_items i WHERE i.report_id = cr.id AND i.item_condition IN ('Poor', 'Damaged', 'Missing')) AS problems
        FROM condition_reports cr JOIN leases l ON l.id = cr.lease_id JOIN tenants t ON t.id = l.tenant_id JOIN properties p ON p.id = l.property_id JOIN units u ON u.id = l.unit_id"
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . " ORDER BY cr.tenant_response = 'Disputed' DESC, cr.inspection_date DESC, cr.id DESC LIMIT 200");
    $stmt->execute($params);
    $reports = $stmt->fetchAll();
    ?>
    <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-4">
        <div><h1 class="text-2xl font-bold text-gray-900">Condition Reports</h1>
            <p class="text-sm text-gray-500 mt-1">Move-in and move-out inspections. Tenants review each report and agree or dispute it; move-out reports are compared with move-in line by line.</p></div>
        <?php if ($conditionCanEdit): ?><div><a class="btn btn-primary" href="<?php echo e($conditionPageUrl . '?action=create'); ?>"><i class="fa-solid fa-plus" aria-hidden="true"></i> New report</a></div><?php endif; ?>
    </div>
    <form method="get" class="filter-bar bg-white p-4 rounded-xl border border-gray-100 shadow-sm">
        <?php foreach (['report_type' => 'Type', 'tenant_response' => 'Tenant response'] as $key => $label): ?>
        <div><label for="filter-<?php echo $key; ?>" class="block text-xs font-semibold text-gray-500 uppercase mb-1"><?php echo e($label); ?></label>
            <select id="filter-<?php echo $key; ?>" name="<?php echo $key; ?>" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"><option value="">All</option>
            <?php foreach ($filters[$key] as $option): ?><option <?php echo ($active[$key] ?? '') === $option ? 'selected' : ''; ?>><?php echo e($option); ?></option><?php endforeach; ?></select></div>
        <?php endforeach; ?>
        <div class="flex items-end gap-2"><button type="submit" class="btn btn-primary">Apply</button><?php if ($active): ?><a class="btn btn-secondary" href="<?php echo e($conditionPageUrl); ?>">Reset</a><?php endif; ?></div>
    </form>
    <section class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden" aria-label="Condition reports list">
        <div class="overflow-x-auto"><table class="w-full text-left">
            <thead><tr class="bg-gray-50 text-xs font-semibold text-gray-500 uppercase"><th scope="col" class="p-4">Inspected</th><th scope="col" class="p-4">Type</th><th scope="col" class="p-4">Tenant / unit</th><th scope="col" class="p-4">Overall</th><th scope="col" class="p-4">Areas with problems</th><th scope="col" class="p-4">Tenant response</th><th scope="col" class="p-4 text-right">Actions</th></tr></thead>
            <tbody class="divide-y divide-gray-100 text-sm">
            <?php if (!$reports): ?><tr><td colspan="7" class="p-8 text-center text-gray-500">No condition reports found.</td></tr><?php endif; ?>
            <?php foreach ($reports as $row): ?>
                <tr>
                    <td class="p-4"><?php echo e(format_date($row['inspection_date'])); ?></td>
                    <td class="p-4"><?php echo render_status_badge($row['report_type']); ?></td>
                    <td class="p-4"><?php echo e($row['tenant_name']); ?><div class="text-xs text-gray-500"><?php echo e($row['property_name'] . ' / ' . $row['unit_number']); ?></div></td>
                    <td class="p-4"><?php echo render_status_badge($row['overall_condition']); ?></td>
                    <td class="p-4"><?php echo (int)$row['problems']; ?></td>
                    <td class="p-4"><?php echo render_status_badge($row['tenant_response']); ?></td>
                    <td class="p-4"><div class="row-actions"><a class="btn btn-small btn-secondary" href="<?php echo e($conditionPageUrl . '?action=view&id=' . (int)$row['id']); ?>">View</a></div></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </section>
<?php endif;
include __DIR__ . '/layout_bottom.php';
