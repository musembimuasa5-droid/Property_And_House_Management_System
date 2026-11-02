<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/admin_people.php';

requireRole('Super Admin');

$categories = adminPeopleCategories();
$category = $_GET['type'] ?? 'tenants';
if (!is_string($category) || !isset($categories[$category])) $category = 'tenants';
$searchValue = $_GET['q'] ?? '';
$search = is_string($searchValue) ? trim($searchValue) : '';
$search = mb_substr($search, 0, 100);
$pageValue = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$page = $pageValue ?: 1;
$db = db();
$error = '';
$success = $_SESSION['admin_people_success'] ?? '';
unset($_SESSION['admin_people_success']);
$textValue = static function ($key) {
    return isset($_POST[$key]) && is_string($_POST[$key]) ? trim($_POST[$key]) : '';
};
$rawValue = static function ($key) {
    return isset($_POST[$key]) && is_string($_POST[$key]) ? $_POST[$key] : '';
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $textValue('action');
    $postCategory = $textValue('category');
    $recordId = filter_var($textValue('person_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
    if (!csrf_validate()) {
        http_response_code(400);
        $error = 'Your session expired. Reload the page and try again.';
    } elseif ($postCategory !== $category || !isset($categories[$postCategory])) {
        http_response_code(400);
        $error = 'Choose a valid people-directory category.';
    } elseif (!in_array($action, ['create', 'update', 'delete'], true) || (in_array($action, ['update', 'delete'], true) && !$recordId)) {
        http_response_code(400);
        $error = 'Choose a valid account action.';
    } else {
        $name = $textValue('full_name');
        $email = $textValue('email');
        $phone = $textValue('phone');
        $password = $rawValue('password');
        $passwordConfirm = $rawValue('password_confirm');
        $roleNames = [
            'tenants' => 'Tenant',
            'landlords' => 'Landlord',
            'caretakers' => 'Caretaker',
            'managers' => 'Property Manager',
            'accountants' => 'Accountant'
        ];
        $redirectCategory = $category;
        try {
            if ($action === 'delete') {
                $result = deleteAdminPerson($db, $category, $recordId, (int)$_SESSION['user_id'], $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN');
                $_SESSION['admin_people_success'] = $result['message'];
                redirect('admin/people.php?' . http_build_query(['type' => $category, 'q' => $search]));
            }

            if ($name === '' || strlen($name) > 100 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150 ||
                !is_valid_phone_number($phone) || strlen($phone) > 20) {
                throw new InvalidArgumentException('Enter a name (up to 100 characters), valid email address, and valid phone number.');
            }
            if ($password !== '' || $action === 'create') {
                if (strlen($password) < 10 || strlen($password) > 4096 || $password !== $passwordConfirm) {
                    throw new InvalidArgumentException('Set and confirm a password of at least 10 characters.');
                }
            }

            $current = $action === 'update' ? fetchAdminPerson($db, $category, $recordId) : null;
            if ($action === 'update' && !$current) {
                throw new InvalidArgumentException('That directory record no longer exists in this category.');
            }
            $userId = (int)($current['user_id'] ?? 0);
            $roleName = $roleNames[$category];
            if (in_array($category, ['caretakers', 'managers', 'accountants'], true)) {
                $requestedRole = $textValue('role_name');
                if ($action === 'update' && $requestedRole !== '') {
                    if (!in_array($requestedRole, ['Property Manager', 'Caretaker', 'Accountant'], true)) {
                        throw new InvalidArgumentException('Select a permitted staff role.');
                    }
                    $roleName = $requestedRole;
                    $redirectCategory = [
                        'Property Manager' => 'managers',
                        'Caretaker' => 'caretakers',
                        'Accountant' => 'accountants'
                    ][$requestedRole];
                }
            }

            $duplicate = $db->prepare('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1');
            $duplicate->execute([$email, $userId]);
            if ($duplicate->fetchColumn()) {
                throw new InvalidArgumentException('That email address is already associated with another login.');
            }
            if ($category === 'tenants' || $category === 'landlords') {
                $profileTable = $category === 'tenants' ? 'tenants' : 'landlords';
                $profileId = $action === 'update' ? $recordId : 0;
                $duplicateProfile = $db->prepare("SELECT id FROM {$profileTable} WHERE email = ? AND id <> ? LIMIT 1");
                $duplicateProfile->execute([$email, $profileId]);
                if ($duplicateProfile->fetchColumn()) {
                    throw new InvalidArgumentException('That email address is already used by another directory profile.');
                }
            }

            $nationalId = $textValue('national_id');
            $gender = $textValue('gender');
            $companyName = $textValue('company_name');
            $address = $textValue('address');
            $emergencyName = $textValue('emergency_contact_name');
            $emergencyPhone = $textValue('emergency_contact_phone');
            if ($category === 'tenants' && ($nationalId === '' || strlen($nationalId) > 50 || !in_array($gender, ['Male', 'Female', 'Other'], true))) {
                throw new InvalidArgumentException('Tenant records require a national ID and valid gender.');
            }
            if ($category === 'landlords' && ($nationalId === '' || strlen($nationalId) > 50 || strlen($companyName) > 150)) {
                throw new InvalidArgumentException('Landlord records require an ID number; company name must be 150 characters or fewer.');
            }
            if ($category === 'tenants' && $emergencyPhone !== '' && !is_valid_phone_number($emergencyPhone)) {
                throw new InvalidArgumentException('Enter a valid emergency contact phone number.');
            }

            $roleQuery = $db->prepare('SELECT id FROM roles WHERE name = ?');
            $roleQuery->execute([$roleName]);
            $roleId = (int)$roleQuery->fetchColumn();
            if (!$roleId) {
                throw new RuntimeException('The required account role is not configured in the database.');
            }

            $db->beginTransaction();
            if ($action === 'create') {
                $userInsert = $db->prepare("INSERT INTO users (role_id, full_name, email, phone, password_hash, status) VALUES (?, ?, ?, ?, ?, 'Active')");
                $userInsert->execute([$roleId, $name, $email, $phone, password_hash($password, PASSWORD_DEFAULT)]);
                $userId = (int)$db->lastInsertId();
                if ($category === 'tenants') {
                    $insert = $db->prepare("INSERT INTO tenants (user_id, full_name, phone, email, national_id, gender, status) VALUES (?, ?, ?, ?, ?, ?, 'Active')");
                    $insert->execute([$userId, $name, $phone, $email, $nationalId, $gender]);
                    $recordId = (int)$db->lastInsertId();
                } elseif ($category === 'landlords') {
                    $insert = $db->prepare("INSERT INTO landlords (user_id, full_name, company_name, phone, email, id_number, address, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'Active')");
                    $insert->execute([$userId, $name, $companyName !== '' ? $companyName : null, $phone, $email, $nationalId, $address !== '' ? $address : null]);
                    $recordId = (int)$db->lastInsertId();
                } else {
                    $recordId = $userId;
                }
                $auditAction = 'Create';
                $auditText = 'Created ' . rtrim($categories[$category]['label'], 's') . ' account ' . $email;
            } else {
                $status = $textValue('status');
                $allowedStatuses = $category === 'tenants' ? ['Active', 'Inactive', 'Evicted', 'Blacklisted'] :
                    ($category === 'landlords' ? ['Active', 'Inactive'] : ['Active', 'Inactive', 'Suspended']);
                if (!in_array($status, $allowedStatuses, true)) {
                    throw new InvalidArgumentException('Select a valid account status.');
                }
                $userStatus = $status === 'Active' ? 'Active' : ($status === 'Suspended' ? 'Suspended' : 'Inactive');
                if ($userId) {
                    $userUpdateSql = 'UPDATE users SET full_name = ?, email = ?, phone = ?, role_id = ?, status = ?';
                    $userUpdateParams = [$name, $email, $phone, $roleId, $userStatus];
                    if ($password !== '') {
                        $userUpdateSql .= ', password_hash = ?';
                        $userUpdateParams[] = password_hash($password, PASSWORD_DEFAULT);
                    }
                    $userUpdateSql .= ' WHERE id = ?';
                    $userUpdateParams[] = $userId;
                    $db->prepare($userUpdateSql)->execute($userUpdateParams);
                } elseif ($password !== '') {
                    $userInsert = $db->prepare('INSERT INTO users (role_id, full_name, email, phone, password_hash, status) VALUES (?, ?, ?, ?, ?, ?)');
                    $userInsert->execute([$roleId, $name, $email, $phone, password_hash($password, PASSWORD_DEFAULT), $userStatus]);
                    $userId = (int)$db->lastInsertId();
                }

                if ($category === 'tenants') {
                    $update = $db->prepare('UPDATE tenants SET user_id = ?, full_name = ?, email = ?, phone = ?, national_id = ?, gender = ?, emergency_contact_name = ?, emergency_contact_phone = ?, status = ? WHERE id = ?');
                    $update->execute([$userId ?: null, $name, $email, $phone, $nationalId, $gender, $emergencyName !== '' ? $emergencyName : null, $emergencyPhone !== '' ? $emergencyPhone : null, $status, $recordId]);
                } elseif ($category === 'landlords') {
                    $update = $db->prepare('UPDATE landlords SET user_id = ?, full_name = ?, company_name = ?, email = ?, phone = ?, id_number = ?, address = ?, status = ? WHERE id = ?');
                    $update->execute([$userId ?: null, $name, $companyName !== '' ? $companyName : null, $email, $phone, $nationalId, $address !== '' ? $address : null, $status, $recordId]);
                }
                $auditAction = 'Update';
                $auditText = 'Updated ' . $categories[$category]['label'] . ' record ' . $recordId;
            }

            $audit = $db->prepare('INSERT INTO audit_logs (user_id, action, module, record_id, description, ip_address) VALUES (?, ?, ?, ?, ?, ?)');
            $audit->execute([(int)$_SESSION['user_id'], $auditAction, 'People Directory', $recordId, $auditText, $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN']);
            $db->commit();
            $_SESSION['admin_people_success'] = $action === 'create' ? 'Account and directory record created.' : 'Account and directory record updated.';
            redirect('admin/people.php?' . http_build_query(['type' => $redirectCategory, 'q' => $search]));
        } catch (InvalidArgumentException $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $error = $exception->getMessage();
        } catch (PDOException $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('People directory update failed: ' . $exception->getMessage());
            $error = $action === 'delete'
                ? 'The person could not be deleted or archived. No changes were saved.'
                : 'The record could not be saved. Check for duplicate email or national ID and try again.';
        } catch (Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('People directory operation failed: ' . $exception->getMessage());
            $error = $action === 'delete'
                ? 'The person could not be deleted or archived. No changes were saved.'
                : 'The record could not be saved. Please try again.';
        }
    }
}

$directory = fetchAdminPeople($db, $category, $search, $page);
$editId = isset($_GET['edit']) && is_scalar($_GET['edit']) ? (filter_var($_GET['edit'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0) : 0;
$editing = $editId ? fetchAdminPerson($db, $category, $editId) : null;

$page_title = 'People Directory';
include __DIR__ . '/../includes/header.php';
?>

<div class="flex h-screen bg-gray-50 overflow-hidden">
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>
    <div class="flex-1 flex flex-col overflow-y-auto">
        <?php include __DIR__ . '/../includes/navbar.php'; ?>
        <main id="main-content" class="flex-1 p-6 space-y-6">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">People Directory</h1>
                <p class="text-sm text-gray-500">Browse tenant and property-team contact records.</p>
            </div>

            <nav class="flex flex-wrap gap-2 border-b border-gray-200" aria-label="People categories">
                <?php foreach ($categories as $key => $item): ?>
                    <a href="<?php echo BASE_URL; ?>/admin/people.php?type=<?php echo e($key); ?>"
                       class="px-4 py-2 text-sm font-medium <?php echo $category === $key ? 'border-b-2 border-primary text-primary' : 'text-gray-600 hover:text-gray-900'; ?>"
                       <?php echo $category === $key ? 'aria-current="page"' : ''; ?>><?php echo e($item['label']); ?></a>
                <?php endforeach; ?>
            </nav>

            <?php if ($error !== ''): ?><div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert"><?php echo e($error); ?></div><?php endif; ?>
            <?php if ($success !== ''): ?><div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800" role="status"><?php echo e($success); ?></div><?php endif; ?>

            <details class="bg-white border border-gray-200 rounded-lg p-4">
                <summary class="cursor-pointer font-semibold text-gray-900">Add <?php echo e(rtrim($categories[$category]['label'], 's')); ?></summary>
                <form method="post" class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <?php echo csrf_field(); ?><input type="hidden" name="action" value="create"><input type="hidden" name="category" value="<?php echo e($category); ?>">
                    <label class="text-sm text-gray-700">Full name<input name="full_name" maxlength="100" required class="mt-1 w-full rounded-lg border-gray-300"></label>
                    <label class="text-sm text-gray-700">Email<input type="email" name="email" maxlength="150" required class="mt-1 w-full rounded-lg border-gray-300"></label>
                    <label class="text-sm text-gray-700">Phone<input type="tel" name="phone" maxlength="20" required class="mt-1 w-full rounded-lg border-gray-300"></label>
                    <?php if ($category === 'tenants'): ?>
                        <label class="text-sm text-gray-700">National ID<input name="national_id" maxlength="50" required class="mt-1 w-full rounded-lg border-gray-300"></label>
                        <label class="text-sm text-gray-700">Gender<select name="gender" required class="mt-1 w-full rounded-lg border-gray-300"><option value="">Select</option><?php foreach (['Female', 'Male', 'Other'] as $gender): ?><option value="<?php echo e($gender); ?>"><?php echo e($gender); ?></option><?php endforeach; ?></select></label>
                    <?php elseif ($category === 'landlords'): ?>
                        <label class="text-sm text-gray-700">ID number<input name="national_id" maxlength="50" required class="mt-1 w-full rounded-lg border-gray-300"></label>
                        <label class="text-sm text-gray-700">Company name<input name="company_name" maxlength="150" class="mt-1 w-full rounded-lg border-gray-300"></label>
                        <label class="text-sm text-gray-700 sm:col-span-2">Address<textarea name="address" rows="2" class="mt-1 w-full rounded-lg border-gray-300"></textarea></label>
                    <?php endif; ?>
                    <label class="text-sm text-gray-700">Initial password<input type="password" name="password" minlength="10" autocomplete="new-password" required class="mt-1 w-full rounded-lg border-gray-300"></label>
                    <label class="text-sm text-gray-700">Confirm password<input type="password" name="password_confirm" minlength="10" autocomplete="new-password" required class="mt-1 w-full rounded-lg border-gray-300"></label>
                    <p class="sm:col-span-2 text-xs text-gray-500">The account receives only the selected directory role. Passwords are stored as hashes and never displayed.</p>
                    <button class="sm:col-span-2 justify-self-start rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white">Create account</button>
                </form>
            </details>

            <?php if ($editing): ?>
                <section class="bg-white border border-primary rounded-lg p-4" aria-labelledby="edit-person-heading">
                    <div class="flex items-start justify-between gap-4"><div><h2 id="edit-person-heading" class="font-semibold text-gray-900">Edit <?php echo e($categories[$category]['label']); ?> record</h2><p class="text-xs text-gray-500">ID #<?php echo (int)$editing['id']; ?> · Leave password blank to keep it unchanged.</p></div><a class="text-sm text-primary hover:underline" href="<?php echo BASE_URL; ?>/admin/people.php?<?php echo e(http_build_query(['type' => $category, 'q' => $search])); ?>">Close</a></div>
                    <form method="post" class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <?php echo csrf_field(); ?><input type="hidden" name="action" value="update"><input type="hidden" name="category" value="<?php echo e($category); ?>"><input type="hidden" name="person_id" value="<?php echo (int)$editing['id']; ?>">
                        <label class="text-sm text-gray-700">Full name<input name="full_name" maxlength="100" required value="<?php echo e($editing['name']); ?>" class="mt-1 w-full rounded-lg border-gray-300"></label>
                        <label class="text-sm text-gray-700">Email<input type="email" name="email" maxlength="150" required value="<?php echo e($editing['email']); ?>" class="mt-1 w-full rounded-lg border-gray-300"></label>
                        <label class="text-sm text-gray-700">Phone<input type="tel" name="phone" maxlength="20" required value="<?php echo e($editing['phone']); ?>" class="mt-1 w-full rounded-lg border-gray-300"></label>
                        <?php if ($category === 'tenants'): ?>
                            <label class="text-sm text-gray-700">National ID<input name="national_id" maxlength="50" required value="<?php echo e($editing['national_id']); ?>" class="mt-1 w-full rounded-lg border-gray-300"></label>
                            <label class="text-sm text-gray-700">Gender<select name="gender" required class="mt-1 w-full rounded-lg border-gray-300"><?php foreach (['Female', 'Male', 'Other'] as $gender): ?><option value="<?php echo e($gender); ?>" <?php echo $editing['gender'] === $gender ? 'selected' : ''; ?>><?php echo e($gender); ?></option><?php endforeach; ?></select></label>
                            <label class="text-sm text-gray-700">Emergency contact<input name="emergency_contact_name" maxlength="100" value="<?php echo e($editing['emergency_contact_name']); ?>" class="mt-1 w-full rounded-lg border-gray-300"></label>
                            <label class="text-sm text-gray-700">Emergency phone<input name="emergency_contact_phone" maxlength="20" value="<?php echo e($editing['emergency_contact_phone']); ?>" class="mt-1 w-full rounded-lg border-gray-300"></label>
                        <?php elseif ($category === 'landlords'): ?>
                            <label class="text-sm text-gray-700">ID number<input name="national_id" maxlength="50" required value="<?php echo e($editing['national_id']); ?>" class="mt-1 w-full rounded-lg border-gray-300"></label>
                            <label class="text-sm text-gray-700">Company name<input name="company_name" maxlength="150" value="<?php echo e($editing['company_name']); ?>" class="mt-1 w-full rounded-lg border-gray-300"></label>
                            <label class="text-sm text-gray-700 sm:col-span-2">Address<textarea name="address" rows="2" class="mt-1 w-full rounded-lg border-gray-300"><?php echo e($editing['address']); ?></textarea></label>
                        <?php else: ?>
                            <label class="text-sm text-gray-700">Role<select name="role_name" required class="mt-1 w-full rounded-lg border-gray-300"><?php foreach (['Property Manager', 'Caretaker', 'Accountant'] as $roleName): ?><option value="<?php echo e($roleName); ?>" <?php echo $editing['organization'] === $roleName ? 'selected' : ''; ?>><?php echo e($roleName); ?></option><?php endforeach; ?></select></label>
                        <?php endif; ?>
                        <?php
                            $statuses = $category === 'tenants' ? ['Active', 'Inactive', 'Evicted', 'Blacklisted'] :
                                ($category === 'landlords' ? ['Active', 'Inactive'] : ['Active', 'Inactive', 'Suspended']);
                        ?>
                        <label class="text-sm text-gray-700">Status<select name="status" required class="mt-1 w-full rounded-lg border-gray-300"><?php foreach ($statuses as $status): ?><option value="<?php echo e($status); ?>" <?php echo $editing['status'] === $status ? 'selected' : ''; ?>><?php echo e($status); ?></option><?php endforeach; ?></select></label>
                        <label class="text-sm text-gray-700">New password (optional)<input type="password" name="password" minlength="10" autocomplete="new-password" class="mt-1 w-full rounded-lg border-gray-300"></label>
                        <label class="text-sm text-gray-700">Confirm new password<input type="password" name="password_confirm" minlength="10" autocomplete="new-password" class="mt-1 w-full rounded-lg border-gray-300"></label>
                        <p class="sm:col-span-2 text-xs text-gray-500">Deactivation is used instead of deleting records so lease, payment, and audit history remains intact.</p>
                        <button class="sm:col-span-2 justify-self-start rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white">Save changes</button>
                    </form>
                </section>
            <?php elseif ($editId): ?>
                <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900" role="status">That person is not available in this directory category.</div>
            <?php endif; ?>

            <section class="bg-white border border-gray-200 rounded-lg">
                <div class="p-4 border-b border-gray-200 flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4">
                    <div>
                        <h2 class="font-semibold text-gray-900"><?php echo e($categories[$category]['label']); ?></h2>
                        <p class="text-sm text-gray-500"><?php echo number_format($directory['total']); ?> records</p>
                    </div>
                    <form method="get" action="<?php echo BASE_URL; ?>/admin/people.php" class="flex gap-2">
                        <input type="hidden" name="type" value="<?php echo e($category); ?>">
                        <label class="sr-only" for="people-search">Search records</label>
                        <input id="people-search" type="search" name="q" value="<?php echo e($search); ?>" maxlength="100" placeholder="Name, email or phone" class="min-w-0 w-64 max-w-full px-3 py-2 border border-gray-300 rounded text-sm">
                        <button type="submit" class="bg-primary text-white px-4 py-2 rounded text-sm font-medium">Search</button>
                    </form>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left">
                        <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                            <tr>
                                <th scope="col" class="p-4">Name</th>
                                <th scope="col" class="p-4">Email</th>
                                <th scope="col" class="p-4">Phone</th>
                                <th scope="col" class="p-4"><?php echo $category === 'landlords' ? 'Organization' : 'Role'; ?></th>
                                <th scope="col" class="p-4">Status</th>
                                <th scope="col" class="p-4">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 text-sm">
                            <?php if (!$directory['rows']): ?>
                                <tr><td colspan="6" class="p-6 text-center text-gray-500">No matching records found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($directory['rows'] as $person): ?>
                                    <tr>
                                        <td class="p-4 font-medium text-gray-900"><?php echo e($person['name']); ?></td>
                                        <td class="p-4"><?php echo e($person['email']); ?></td>
                                        <td class="p-4"><?php echo e($person['phone']); ?></td>
                                        <td class="p-4"><?php echo e($person['organization'] ?? '-'); ?></td>
                                        <td class="p-4"><?php echo render_status_badge($person['status']); ?></td>
                                        <td class="p-4">
                                            <div class="flex flex-wrap items-center gap-3">
                                                <a class="text-sm font-semibold text-primary hover:underline" href="<?php echo BASE_URL; ?>/admin/people.php?<?php echo e(http_build_query(['type' => $category, 'q' => $search, 'page' => $directory['page'], 'edit' => $person['id']])); ?>">View / edit</a>
                                                <form method="post" data-confirm="<?php echo e('Delete ' . $person['name'] . '? If linked records exist, this will deactivate the account instead and preserve its history.'); ?>" onsubmit="return confirm(this.dataset.confirm)">
                                                    <?php echo csrf_field(); ?>
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="category" value="<?php echo e($category); ?>">
                                                    <input type="hidden" name="person_id" value="<?php echo (int)$person['id']; ?>">
                                                    <button type="submit" class="rounded-md border border-red-300 px-3 py-1.5 text-sm font-semibold text-red-700 hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-red-500" aria-label="Delete <?php echo e($person['name']); ?>">Delete</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($directory['total_pages'] > 1): ?>
                    <div class="p-4 border-t border-gray-200 flex items-center justify-between text-sm">
                        <span class="text-gray-500">Page <?php echo $directory['page']; ?> of <?php echo $directory['total_pages']; ?></span>
                        <div class="flex gap-2">
                            <?php if ($directory['page'] > 1): ?>
                                <a class="px-3 py-2 border border-gray-300 rounded" href="?type=<?php echo e($category); ?>&amp;q=<?php echo rawurlencode($search); ?>&amp;page=<?php echo $directory['page'] - 1; ?>">Previous</a>
                            <?php endif; ?>
                            <?php if ($directory['page'] < $directory['total_pages']): ?>
                                <a class="px-3 py-2 border border-gray-300 rounded" href="?type=<?php echo e($category); ?>&amp;q=<?php echo rawurlencode($search); ?>&amp;page=<?php echo $directory['page'] + 1; ?>">Next</a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </section>
        </main>
        <?php include __DIR__ . '/../includes/footer.php'; ?>
    </div>
</div>
