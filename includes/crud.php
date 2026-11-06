<?php
/**
 * Configuration-driven CRUD engine for the management modules.
 *
 * Each module page describes one table as a resource array (fields, list
 * columns, filters, optional hooks) and calls crud_run(). Listing, search,
 * filters, pagination, CSV export, forms, validation, deletes, CSRF checks and
 * audit logging all live here, so every module behaves the same way and a
 * security fix only has to be made once.
 *
 * Table and column names come only from the resource definition in code, never
 * from the request, so interpolating them is safe; every value is a bound parameter.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/modules.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/alerts.php';
require_once __DIR__ . '/upload_helper.php';

/** A business-rule violation whose message is safe to show to the user. */
class UserFacingException extends RuntimeException {}

const CRUD_PAGE_SIZE = 15;
const CRUD_RESERVED_PARAMS = ['action', 'id', 'q', 'page'];

function crud_run(array $resource): void {
    $r = crud_prepare_resource($resource);
    require_module($r['module']);
    $db = db();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        crud_handle_post($db, $r);
        return;
    }

    $action = $_GET['action'] ?? 'list';
    if ($action === 'export') {
        crud_export_csv($db, $r);
        return;
    }
    if ($action === 'create' && crud_can($r, 'create')) {
        crud_render_form($db, $r, null, crud_initial_values($r), []);
        return;
    }
    if ($action === 'edit' && crud_can($r, 'edit')) {
        $id = crud_request_id($_GET);
        $row = $id ? crud_find($db, $r, $id) : null;
        if ($row) {
            crud_render_form($db, $r, $id, $row, []);
            return;
        }
        set_flash('error', $r['singular'] . ' not found.');
        crud_redirect($r);
    }
    crud_render_list($db, $r);
}

function crud_prepare_resource(array $r): array {
    // "scope" limits every lookup to rows the user owns: ['find' => SQL on the bare table,
    // 'list' => the same rule on the list query's aliases, 'params' => its bound values].
    $r += ['description' => '', 'allow_create' => true, 'allow_edit' => true, 'allow_delete' => true,
        'delete_roles' => null, 'hooks' => [], 'actions' => [], 'list' => [], 'messages' => [], 'scope' => null];
    $r['list'] += ['where' => '', 'search' => [], 'filters' => [], 'order' => 'id DESC', 'row_actions' => null, 'panel' => null];
    $r['url'] = module_url($r['module']);
    return $r;
}

function crud_can(array $r, string $operation): bool {
    if ($operation === 'create') return $r['allow_create'];
    if ($operation === 'edit') return $r['allow_edit'];
    if (!$r['allow_delete']) return false;
    if ($r['delete_roles'] === null) return true;
    $user = getCurrentUser();
    return (int)$user['role_id'] === 1 || in_array($user['role_name'], $r['delete_roles'], true);
}

function crud_request_id(array $source): ?int {
    $id = filter_var($source['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return $id === false ? null : $id;
}

/** Load one row, honouring the resource scope; edit, save and delete all go through this. */
function crud_find(PDO $db, array $r, int $id): ?array {
    $scope = $r['scope'];
    $stmt = $db->prepare("SELECT * FROM {$r['table']} WHERE id = ?" . ($scope ? " AND ({$scope['find']})" : ''));
    $stmt->execute([$id, ...($scope['params'] ?? [])]);
    return $stmt->fetch() ?: null;
}

function crud_redirect(array $r, array $query = []): void {
    header('Location: ' . $r['url'] . ($query ? '?' . http_build_query($query) : ''));
    exit();
}

/** Options for a select field or filter as [value => label]; SQL options are cached per request. */
function crud_options(PDO $db, array $definition): array {
    static $cache = [];
    if (isset($definition['options_sql'])) {
        return $cache[$definition['options_sql']] ??= $db->query($definition['options_sql'])->fetchAll(PDO::FETCH_KEY_PAIR);
    }
    $options = $definition['options'] ?? [];
    return array_is_list($options) ? array_combine($options, $options) : $options;
}

function crud_initial_values(array $r): array {
    $values = [];
    foreach ($r['fields'] as $name => $field) {
        if (isset($_GET[$name]) && is_string($_GET[$name])) {
            $values[$name] = $_GET[$name];
        } elseif (array_key_exists('default', $field)) {
            $values[$name] = $field['default'] instanceof Closure ? $field['default']() : $field['default'];
        } else {
            $values[$name] = '';
        }
    }
    return $values;
}

// ---------------------------------------------------------------------------
// Validation
// ---------------------------------------------------------------------------

/** Validate one submitted value. Returns [typed value, error message or null]. */
function crud_validate_value(PDO $db, array $field, string $raw, bool $isCreate): array {
    $type = $field['type'] ?? 'text';
    $label = $field['label'];
    $required = !empty($field['required']) && ($type !== 'password' || $isCreate);

    if ($raw === '') {
        return $required ? [null, "$label is required."] : [null, null];
    }

    switch ($type) {
        case 'number':
            $value = filter_var($raw, FILTER_VALIDATE_INT);
            if ($value === false) return [null, "$label must be a whole number."];
            break;
        case 'decimal':
            if (!is_numeric($raw)) return [null, "$label must be a number."];
            $value = round((float)$raw, $field['decimals'] ?? 2);
            break;
        case 'email':
            if (!filter_var($raw, FILTER_VALIDATE_EMAIL)) return [null, "Enter a valid email address for $label."];
            $value = $raw;
            break;
        case 'tel':
            if (!preg_match('/^\+?[0-9][0-9 ()-]{6,19}$/', $raw)) return [null, "Enter a valid phone number for $label, e.g. +254712345678."];
            $value = $raw;
            break;
        case 'date':
            $date = DateTime::createFromFormat('!Y-m-d', $raw);
            if (!$date || $date->format('Y-m-d') !== $raw) return [null, "$label must be a valid date."];
            $value = $raw;
            break;
        case 'month':
            if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $raw)) return [null, "$label must be a month in the format YYYY-MM."];
            $value = $raw;
            break;
        case 'time':
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $raw)) return [null, "$label must be a valid time."];
            $value = $raw;
            break;
        case 'select':
            if (!array_key_exists($raw, crud_options($db, $field))) return [null, "Select a valid option for $label."];
            $value = $raw;
            break;
        case 'password':
            if (mb_strlen($raw) < 10) return [null, "$label must be at least 10 characters."];
            $value = $raw;
            break;
        default:
            $max = $field['max'] ?? ($type === 'textarea' ? 5000 : 255);
            if (mb_strlen($raw) > $max) return [null, "$label must be $max characters or fewer."];
            $value = $raw;
    }

    if (in_array($type, ['number', 'decimal'], true)) {
        if (isset($field['min']) && $value < $field['min']) return [null, "$label must be at least {$field['min']}."];
        if (isset($field['max']) && $value > $field['max']) return [null, "$label must be at most {$field['max']}."];
    }
    return [$value, null];
}

/** Read and validate the submitted form. Returns [data, errors, newly uploaded file paths]. */
function crud_collect_input(PDO $db, array $r, bool $isCreate): array {
    $data = [];
    $errors = [];
    $uploads = [];
    foreach ($r['fields'] as $name => $field) {
        $type = $field['type'] ?? 'text';
        if ($type === 'image') {
            $file = $_FILES[$name] ?? null;
            if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
            $upload = uploadApplicationFile($file, $field['folder'], ['jpg', 'jpeg', 'png']);
            if ($upload['success']) {
                $data[$name] = $uploads[] = $upload['path'];
            } else {
                $errors[$name] = $upload['error'];
            }
            continue;
        }
        $raw = $_POST[$name] ?? '';
        if (!is_string($raw)) {
            $errors[$name] = 'Invalid value.';
            continue;
        }
        [$value, $error] = crud_validate_value($db, $field, trim($raw), $isCreate);
        if ($error !== null) {
            $errors[$name] = $error;
        } elseif ($type !== 'password' || $value !== null) {
            $data[$name] = $value;
        }
    }
    return [$data, $errors, $uploads];
}

/** Delete a file this app uploaded; refuses anything outside /uploads. */
function crud_delete_upload(?string $path): void {
    if (!$path) return;
    $uploadsRoot = realpath(__DIR__ . '/../uploads');
    $file = realpath(__DIR__ . '/../' . $path);
    if ($uploadsRoot && $file && strpos($file, $uploadsRoot . DIRECTORY_SEPARATOR) === 0 && is_file($file)) unlink($file);
}

function crud_database_message(PDOException $e, array $r): string {
    $code = (int)($e->errorInfo[1] ?? 0);
    if ($code === 1062) return $r['messages']['duplicate'] ?? 'Another record already uses one of these unique values (such as an email, ID number or reference).';
    if ($code === 1451) return $r['messages']['in_use'] ?? 'This record is linked to other records, so it cannot be deleted. Change its status instead.';
    if ($code === 1452) return 'A linked record no longer exists. Reload the page and try again.';
    error_log("CRUD {$r['table']} failed: " . $e->getMessage());
    return 'The change could not be saved. Check the details and try again.';
}

// ---------------------------------------------------------------------------
// POST handling
// ---------------------------------------------------------------------------

function crud_handle_post(PDO $db, array $r): void {
    if (!csrf_validate()) {
        set_flash('error', 'Your session expired. Please try again.');
        crud_redirect($r);
    }
    $operation = $_POST['_action'] ?? '';
    if ($operation === 'save') {
        crud_save($db, $r);
    } elseif ($operation === 'delete') {
        crud_delete($db, $r);
    } elseif (is_string($operation) && isset($r['actions'][$operation])) {
        try {
            set_flash('success', $r['actions'][$operation]($db));
        } catch (UserFacingException $e) {
            if ($db->inTransaction()) $db->rollBack();
            set_flash('error', $e->getMessage());
        } catch (PDOException $e) {
            if ($db->inTransaction()) $db->rollBack();
            set_flash('error', crud_database_message($e, $r));
        }
        crud_redirect($r);
    } else {
        set_flash('error', 'Unknown action.');
        crud_redirect($r);
    }
}

function crud_save(PDO $db, array $r): void {
    $id = crud_request_id($_POST);
    $isCreate = $id === null;
    if (!crud_can($r, $isCreate ? 'create' : 'edit')) {
        set_flash('error', 'You do not have permission to do that.');
        crud_redirect($r);
    }
    $old = $isCreate ? null : crud_find($db, $r, $id);
    if (!$isCreate && !$old) {
        set_flash('error', $r['singular'] . ' not found.');
        crud_redirect($r);
    }

    [$data, $errors, $uploads] = crud_collect_input($db, $r, $isCreate);
    if (!$errors && isset($r['hooks']['prepare'])) {
        try {
            $data = $r['hooks']['prepare']($data, $old, $db);
        } catch (UserFacingException $e) {
            $errors['_form'] = $e->getMessage();
        }
    }

    if (!$errors) {
        foreach ($r['fields'] as $name => $field) {
            if (!empty($field['virtual'])) unset($data[$name]);
        }
        try {
            $db->beginTransaction();
            if ($isCreate) {
                $columns = array_keys($data);
                $db->prepare("INSERT INTO {$r['table']} (" . implode(', ', $columns) . ') VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')')
                    ->execute(array_values($data));
                $id = (int)$db->lastInsertId();
            } elseif ($data) {
                $assignments = implode(', ', array_map(fn($column) => "$column = ?", array_keys($data)));
                $db->prepare("UPDATE {$r['table']} SET $assignments WHERE id = ?")->execute([...array_values($data), $id]);
            }
            if (isset($r['hooks']['after_save'])) $r['hooks']['after_save']($db, $id, $data, $old);
            $db->commit();
        } catch (UserFacingException $e) {
            $db->rollBack();
            $errors['_form'] = $e->getMessage();
        } catch (PDOException $e) {
            if ($db->inTransaction()) $db->rollBack();
            $errors['_form'] = crud_database_message($e, $r);
        }
    }

    if ($errors) {
        array_map('crud_delete_upload', $uploads);
        $values = array_merge($old ?? [], array_filter($_POST, 'is_string'));
        http_response_code(422);
        crud_render_form($db, $r, $isCreate ? null : $id, $values, $errors);
        return;
    }

    foreach ($r['fields'] as $name => $field) {
        if (($field['type'] ?? '') === 'image' && isset($data[$name]) && $old) crud_delete_upload($old[$name] ?? null);
    }
    logAuditAction($isCreate ? 'CREATE' : 'UPDATE', $r['table'], $id, ($isCreate ? 'Created ' : 'Updated ') . strtolower($r['singular']) . " #$id");
    set_flash('success', $r['singular'] . ($isCreate ? ' created.' : ' updated.'));
    crud_redirect($r);
}

function crud_delete(PDO $db, array $r): void {
    $id = crud_request_id($_POST);
    $old = $id ? crud_find($db, $r, $id) : null;
    if (!crud_can($r, 'delete') || !$old) {
        set_flash('error', $old ? 'You do not have permission to delete this record.' : $r['singular'] . ' not found.');
        crud_redirect($r);
    }
    try {
        $db->beginTransaction();
        if (isset($r['hooks']['before_delete'])) $r['hooks']['before_delete']($db, $old);
        $db->prepare("DELETE FROM {$r['table']} WHERE id = ?")->execute([$id]);
        if (isset($r['hooks']['after_delete'])) $r['hooks']['after_delete']($db, $old);
        $db->commit();
    } catch (UserFacingException $e) {
        $db->rollBack();
        set_flash('error', $e->getMessage());
        crud_redirect($r);
    } catch (PDOException $e) {
        if ($db->inTransaction()) $db->rollBack();
        set_flash('error', crud_database_message($e, $r));
        crud_redirect($r);
    }
    foreach ($r['fields'] as $name => $field) {
        if (($field['type'] ?? '') === 'image') crud_delete_upload($old[$name] ?? null);
    }
    logAuditAction('DELETE', $r['table'], $id, 'Deleted ' . strtolower($r['singular']) . " #$id");
    set_flash('success', $r['singular'] . ' deleted.');
    crud_redirect($r);
}

// ---------------------------------------------------------------------------
// Listing and export
// ---------------------------------------------------------------------------

/** Build the filtered list query from the request. Returns [sql, params, active filters]. */
function crud_list_query(PDO $db, array $r): array {
    $list = $r['list'];
    $where = $list['where'] !== '' ? ['(' . $list['where'] . ')'] : [];
    $params = [];
    $active = [];
    if ($r['scope']) {
        $where[] = '(' . $r['scope']['list'] . ')';
        array_push($params, ...$r['scope']['params']);
    }

    $search = trim(is_string($_GET['q'] ?? null) ? $_GET['q'] : '');
    if ($search !== '' && $list['search']) {
        $like = '%' . addcslashes($search, '\\%_') . '%';
        $where[] = '(' . implode(' OR ', array_map(fn($column) => "$column LIKE ?", $list['search'])) . ')';
        array_push($params, ...array_fill(0, count($list['search']), $like));
        $active['q'] = $search;
    }
    foreach ($list['filters'] as $key => $filter) {
        $value = $_GET[$key] ?? '';
        if (is_string($value) && $value !== '' && array_key_exists($value, crud_options($db, $filter))) {
            $where[] = "{$filter['column']} = ?";
            $params[] = $value;
            $active[$key] = $value;
        }
    }
    $sql = $list['sql'] . ($where ? ' WHERE ' . implode(' AND ', $where) : '');
    return [$sql, $params, $active];
}

function crud_cell(array $column, array $row, string $key): string {
    $format = $column['format'] ?? null;
    $value = $row[$key] ?? null;
    // instanceof, not is_callable(): format names such as 'date' are also PHP function names.
    if ($format instanceof Closure) return $format($row);
    if ($format === 'currency') return format_currency($value);
    if ($format === 'badge') return $value === null ? '—' : render_status_badge($value);
    if ($format === 'date') return e(format_date($value));
    if ($format === 'period') return e(format_period($value));
    return $value === null || $value === '' ? '<span class="text-gray-400">—</span>' : e((string)$value);
}

function crud_render_list(PDO $db, array $r): void {
    [$sql, $params, $active] = crud_list_query($db, $r);
    $countStmt = $db->prepare("SELECT COUNT(*) FROM ($sql) AS crud_count");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();
    $pages = max(1, (int)ceil($total / CRUD_PAGE_SIZE));
    $page = min($pages, max(1, (int)($_GET['page'] ?? 1)));
    $offset = ($page - 1) * CRUD_PAGE_SIZE;
    $stmt = $db->prepare("$sql ORDER BY {$r['list']['order']} LIMIT " . CRUD_PAGE_SIZE . " OFFSET $offset");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    $columns = $r['list']['columns'];
    $canEdit = crud_can($r, 'edit');
    $canDelete = crud_can($r, 'delete');

    $page_title = $r['plural'];
    include __DIR__ . '/layout_top.php';
    ?>
    <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900"><?php echo e($r['plural']); ?></h1>
            <?php if ($r['description']): ?><p class="text-sm text-gray-500 mt-1"><?php echo e($r['description']); ?></p><?php endif; ?>
        </div>
        <div class="flex flex-wrap gap-2">
            <a class="btn btn-secondary" href="<?php echo e($r['url'] . '?' . http_build_query(['action' => 'export'] + $active)); ?>"><i class="fa-solid fa-file-csv" aria-hidden="true"></i> Export CSV</a>
            <?php if (crud_can($r, 'create')): ?><a class="btn btn-primary" href="<?php echo e($r['url'] . '?action=create'); ?>"><i class="fa-solid fa-plus" aria-hidden="true"></i> Add <?php echo e(strtolower($r['singular'])); ?></a><?php endif; ?>
        </div>
    </div>

    <?php if ($r['list']['panel']) $r['list']['panel']($db); ?>

    <?php if ($r['list']['search'] || $r['list']['filters']): ?>
    <form method="get" class="filter-bar bg-white p-4 rounded-xl border border-gray-100 shadow-sm" role="search">
        <?php if ($r['list']['search']): ?>
        <div class="filter-search"><label for="crud-q" class="block text-xs font-semibold text-gray-500 uppercase mb-1">Search</label>
            <input id="crud-q" type="search" name="q" value="<?php echo e($active['q'] ?? ''); ?>" placeholder="Search <?php echo e(strtolower($r['plural'])); ?>…" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"></div>
        <?php endif; ?>
        <?php foreach ($r['list']['filters'] as $key => $filter): ?>
        <div><label for="filter-<?php echo e($key); ?>" class="block text-xs font-semibold text-gray-500 uppercase mb-1"><?php echo e($filter['label']); ?></label>
            <select id="filter-<?php echo e($key); ?>" name="<?php echo e($key); ?>" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
                <option value="">All</option>
                <?php foreach (crud_options($db, $filter) as $value => $label): ?><option value="<?php echo e((string)$value); ?>" <?php echo (string)($active[$key] ?? '') === (string)$value ? 'selected' : ''; ?>><?php echo e((string)$label); ?></option><?php endforeach; ?>
            </select></div>
        <?php endforeach; ?>
        <div class="flex items-end gap-2"><button type="submit" class="btn btn-primary">Apply</button><?php if ($active): ?><a class="btn btn-secondary" href="<?php echo e($r['url']); ?>">Reset</a><?php endif; ?></div>
    </form>
    <?php endif; ?>

    <section class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden" aria-label="<?php echo e($r['plural']); ?> list">
        <div class="overflow-x-auto"><table class="w-full text-left border-collapse">
            <thead><tr class="bg-gray-50 text-xs font-semibold text-gray-500 uppercase">
                <?php foreach ($columns as $column): ?><th scope="col" class="p-4"><?php echo e($column['label']); ?></th><?php endforeach; ?>
                <th scope="col" class="p-4 text-right">Actions</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-100 text-sm">
            <?php if (!$rows): ?>
                <tr><td colspan="<?php echo count($columns) + 1; ?>" class="p-8 text-center text-gray-500">No <?php echo e(strtolower($r['plural'])); ?> found<?php echo $active ? ' for these filters' : ''; ?>.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <?php foreach ($columns as $key => $column): ?><td class="p-4 align-top"><?php echo crud_cell($column, $row, $key); ?></td><?php endforeach; ?>
                    <td class="p-4 align-top"><div class="row-actions">
                        <?php if ($r['list']['row_actions']) echo $r['list']['row_actions']($row); ?>
                        <?php if ($canEdit): ?><a class="btn btn-small btn-secondary" href="<?php echo e($r['url'] . '?action=edit&id=' . (int)$row['id']); ?>">Edit</a><?php endif; ?>
                        <?php if ($canDelete): ?>
                        <form method="post" action="<?php echo e($r['url']); ?>" data-confirm="Delete this <?php echo e(strtolower($r['singular'])); ?>? This cannot be undone.">
                            <?php echo csrf_field(); ?><input type="hidden" name="_action" value="delete"><input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                            <button type="submit" class="btn btn-small btn-danger">Delete</button>
                        </form>
                        <?php endif; ?>
                    </div></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php if ($total > 0): ?>
        <nav class="pagination" aria-label="Pagination">
            <p class="text-sm text-gray-500">Showing <?php echo $offset + 1; ?>–<?php echo min($offset + CRUD_PAGE_SIZE, $total); ?> of <?php echo $total; ?></p>
            <?php if ($pages > 1): ?><div class="flex gap-2">
                <?php if ($page > 1): ?><a class="btn btn-small btn-secondary" href="<?php echo e($r['url'] . '?' . http_build_query($active + ['page' => $page - 1])); ?>">Previous</a><?php endif; ?>
                <span class="text-sm text-gray-500 self-center">Page <?php echo $page; ?> of <?php echo $pages; ?></span>
                <?php if ($page < $pages): ?><a class="btn btn-small btn-secondary" href="<?php echo e($r['url'] . '?' . http_build_query($active + ['page' => $page + 1])); ?>">Next</a><?php endif; ?>
            </div><?php endif; ?>
        </nav>
        <?php endif; ?>
    </section>
    <?php
    include __DIR__ . '/layout_bottom.php';
}

function crud_export_csv(PDO $db, array $r): void {
    [$sql, $params] = crud_list_query($db, $r);
    $stmt = $db->prepare("$sql ORDER BY {$r['list']['order']}");
    $stmt->execute($params);
    $columns = $r['list']['columns'];

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-z0-9]+/', '-', strtolower($r['plural'])) . '-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows KSh names and accents correctly
    fputcsv($out, array_map(fn($column) => $column['label'], $columns));
    while ($row = $stmt->fetch()) {
        fputcsv($out, array_map(function ($key) use ($columns, $row) {
            $value = array_key_exists($key, $row) ? $row[$key] : html_entity_decode(strip_tags(crud_cell($columns[$key], $row, $key)), ENT_QUOTES);
            return csv_safe($value);
        }, array_keys($columns)));
    }
    fclose($out);
    logAuditAction('EXPORT', $r['table'], null, 'Exported ' . strtolower($r['plural']) . ' to CSV');
}

// ---------------------------------------------------------------------------
// Forms
// ---------------------------------------------------------------------------

function crud_render_field(PDO $db, string $name, array $field, $value, ?string $error, bool $isCreate): void {
    $type = $field['type'] ?? 'text';
    $id = 'field-' . $name;
    $required = !empty($field['required']) && ($type !== 'password' || $isCreate);
    $describedBy = trim(($error ? "$id-error " : '') . (!empty($field['help']) ? "$id-help" : ''));
    $common = 'id="' . e($id) . '" name="' . e($name) . '"' . ($required ? ' required' : '') . ($error ? ' aria-invalid="true"' : '') . ($describedBy ? ' aria-describedby="' . e($describedBy) . '"' : '');
    $inputClass = 'class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"';
    $value = (string)($value ?? '');
    $wide = !empty($field['wide']) || in_array($type, ['textarea', 'image'], true);
    ?>
    <div class="<?php echo $wide ? 'md:col-span-2' : ''; ?>">
        <label for="<?php echo e($id); ?>" class="block text-xs font-semibold text-gray-600 uppercase mb-1"><?php echo e($field['label']); ?><?php if ($required): ?> <span class="text-red-600" aria-hidden="true">*</span><?php endif; ?></label>
        <?php if ($type === 'select'): ?>
            <select <?php echo $common . ' ' . $inputClass; ?>>
                <option value=""><?php echo $required ? 'Select…' : '— None —'; ?></option>
                <?php foreach (crud_options($db, $field) as $optionValue => $optionLabel): ?>
                    <option value="<?php echo e((string)$optionValue); ?>" <?php echo (string)$optionValue === $value ? 'selected' : ''; ?>><?php echo e((string)$optionLabel); ?></option>
                <?php endforeach; ?>
            </select>
        <?php elseif ($type === 'textarea'): ?>
            <textarea rows="3" <?php echo $common . ' ' . $inputClass; ?>><?php echo e($value); ?></textarea>
        <?php elseif ($type === 'image'): ?>
            <?php if ($value !== '' && is_file(__DIR__ . '/../' . $value)): ?><img src="<?php echo e(BASE_URL . '/' . $value); ?>" alt="Current photo" class="h-28 w-44 object-cover rounded-lg mb-2 border border-gray-200"><?php endif; ?>
            <input type="file" accept="image/jpeg,image/png" <?php echo $common; ?> class="block w-full text-sm">
        <?php else:
            $inputType = ['number' => 'number', 'decimal' => 'number', 'email' => 'email', 'tel' => 'tel', 'date' => 'date', 'time' => 'time', 'month' => 'month', 'password' => 'password'][$type] ?? 'text';
            $extra = '';
            if ($type === 'decimal') $extra .= ' step="' . (isset($field['decimals']) ? 'any' : '0.01') . '" inputmode="decimal"';
            if ($type === 'number') $extra .= ' step="1" inputmode="numeric"';
            if (in_array($type, ['number', 'decimal'], true)) {
                if (isset($field['min'])) $extra .= ' min="' . e((string)$field['min']) . '"';
                if (isset($field['max'])) $extra .= ' max="' . e((string)$field['max']) . '"';
            } elseif (!in_array($type, ['date', 'time', 'month'], true)) {
                $extra .= ' maxlength="' . (int)($field['max'] ?? 255) . '"';
            }
            if ($type === 'month') $extra .= ' pattern="\d{4}-\d{2}" placeholder="YYYY-MM"';
            if ($type === 'password') $extra .= ' autocomplete="new-password"';
            if ($type === 'time') $value = substr($value, 0, 5);
            ?>
            <input type="<?php echo $inputType; ?>" value="<?php echo $type === 'password' ? '' : e($value); ?>" <?php echo $common . $extra . ' ' . $inputClass; ?>>
        <?php endif; ?>
        <?php if (!empty($field['help'])): ?><p id="<?php echo e($id); ?>-help" class="text-xs text-gray-500 mt-1"><?php echo e($field['help']); ?></p><?php endif; ?>
        <?php if ($error): ?><p id="<?php echo e($id); ?>-error" class="text-xs text-red-700 mt-1 font-medium"><?php echo e($error); ?></p><?php endif; ?>
    </div>
    <?php
}

function crud_render_form(PDO $db, array $r, ?int $id, array $values, array $errors): void {
    $isCreate = $id === null;
    $hasUpload = (bool)array_filter($r['fields'], fn($field) => ($field['type'] ?? '') === 'image');
    $page_title = ($isCreate ? 'New ' : 'Edit ') . $r['singular'];
    include __DIR__ . '/layout_top.php';
    ?>
    <div>
        <a href="<?php echo e($r['url']); ?>" class="text-sm text-primary font-medium"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Back to <?php echo e(strtolower($r['plural'])); ?></a>
        <h1 class="text-2xl font-bold text-gray-900 mt-2"><?php echo e($page_title); ?></h1>
    </div>
    <?php if ($errors): ?>
        <div class="border-l-4 p-4 rounded-r-lg bg-red-50 border-red-500 text-red-800" role="alert">
            <p class="text-sm font-medium"><?php echo e($errors['_form'] ?? 'Please correct the highlighted fields.'); ?></p>
        </div>
    <?php endif; ?>
    <form method="post" action="<?php echo e($r['url']); ?>" class="bg-white p-6 rounded-xl border border-gray-100 shadow-sm max-w-4xl" <?php echo $hasUpload ? 'enctype="multipart/form-data"' : ''; ?>>
        <?php echo csrf_field(); ?>
        <input type="hidden" name="_action" value="save">
        <?php if (!$isCreate): ?><input type="hidden" name="id" value="<?php echo (int)$id; ?>"><?php endif; ?>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
            <?php foreach ($r['fields'] as $name => $field) crud_render_field($db, $name, $field, $values[$name] ?? '', $errors[$name] ?? null, $isCreate); ?>
        </div>
        <div class="flex flex-wrap gap-3 mt-6 pt-5 border-t border-gray-100">
            <button type="submit" class="btn btn-primary"><?php echo $isCreate ? 'Create ' . e(strtolower($r['singular'])) : 'Save changes'; ?></button>
            <a href="<?php echo e($r['url']); ?>" class="btn btn-secondary">Cancel</a>
        </div>
    </form>
    <?php
    include __DIR__ . '/layout_bottom.php';
}
