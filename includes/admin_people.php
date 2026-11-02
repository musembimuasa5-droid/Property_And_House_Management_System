<?php

function adminPeopleCategories() {
    return [
        'tenants' => [
            'label' => 'Tenants',
            'query' => 'SELECT id, user_id, NULL AS role_id, full_name AS name, email, phone, status, NULL AS organization FROM tenants'
        ],
        'caretakers' => [
            'label' => 'Caretakers',
            'query' => "SELECT u.id, u.id AS user_id, u.role_id, u.full_name AS name, u.email, u.phone, u.status, r.name AS organization FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name = 'Caretaker'"
        ],
        'managers' => [
            'label' => 'Managers',
            'query' => "SELECT u.id, u.id AS user_id, u.role_id, u.full_name AS name, u.email, u.phone, u.status, r.name AS organization FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name = 'Property Manager'"
        ],
        'accountants' => [
            'label' => 'Accountants',
            'query' => "SELECT u.id, u.id AS user_id, u.role_id, u.full_name AS name, u.email, u.phone, u.status, r.name AS organization FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name = 'Accountant'"
        ],
        'landlords' => [
            'label' => 'Landlords',
            'query' => 'SELECT id, user_id, NULL AS role_id, full_name AS name, email, phone, status, company_name AS organization FROM landlords'
        ]
    ];
}

function fetchAdminPeople(PDO $db, $category, $search = '', $page = 1, $pageSize = 25) {
    $categories = adminPeopleCategories();
    if (!isset($categories[$category])) {
        throw new InvalidArgumentException('Unknown directory category.');
    }

    $query = $categories[$category]['query'];
    $search = substr(trim((string)$search), 0, 100);
    $page = max(1, (int)$page);
    $pageSize = max(1, min(100, (int)$pageSize));
    $pattern = '%' . $search . '%';
    $where = ' WHERE name LIKE ? OR email LIKE ? OR phone LIKE ?';

    $count = $db->prepare("SELECT COUNT(*) FROM ({$query}) people{$where}");
    $count->execute([$pattern, $pattern, $pattern]);
    $total = (int)$count->fetchColumn();
    $totalPages = max(1, (int)ceil($total / $pageSize));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $pageSize;

    $statement = $db->prepare("SELECT id, user_id, role_id, name, email, phone, status, organization FROM ({$query}) people{$where} ORDER BY name ASC LIMIT ? OFFSET ?");
    $statement->bindValue(1, $pattern, PDO::PARAM_STR);
    $statement->bindValue(2, $pattern, PDO::PARAM_STR);
    $statement->bindValue(3, $pattern, PDO::PARAM_STR);
    $statement->bindValue(4, $pageSize, PDO::PARAM_INT);
    $statement->bindValue(5, $offset, PDO::PARAM_INT);
    $statement->execute();

    return [
        'rows' => $statement->fetchAll(PDO::FETCH_ASSOC),
        'total' => $total,
        'page' => $page,
        'page_size' => $pageSize,
        'total_pages' => $totalPages
    ];
}

function fetchAdminPerson(PDO $db, $category, $id) {
    $queries = [
        'tenants' => 'SELECT id, user_id, full_name AS name, email, phone, status, national_id, gender, emergency_contact_name, emergency_contact_phone FROM tenants WHERE id = ?',
        'landlords' => 'SELECT id, user_id, full_name AS name, email, phone, status, id_number AS national_id, company_name, address FROM landlords WHERE id = ?',
        'caretakers' => "SELECT u.id, u.id AS user_id, u.role_id, u.full_name AS name, u.email, u.phone, u.status, r.name AS organization FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ? AND r.name = 'Caretaker'",
        'managers' => "SELECT u.id, u.id AS user_id, u.role_id, u.full_name AS name, u.email, u.phone, u.status, r.name AS organization FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ? AND r.name = 'Property Manager'",
        'accountants' => "SELECT u.id, u.id AS user_id, u.role_id, u.full_name AS name, u.email, u.phone, u.status, r.name AS organization FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ? AND r.name = 'Accountant'"
    ];
    if (!isset($queries[$category])) {
        throw new InvalidArgumentException('Unknown directory category.');
    }
    $statement = $db->prepare($queries[$category]);
    $statement->execute([(int)$id]);
    return $statement->fetch(PDO::FETCH_ASSOC) ?: null;
}

function assertAdminPersonDeletionAllowed(PDO $db, $userId, $currentUserId) {
    if (!$userId) {
        return;
    }
    if ((int)$userId === (int)$currentUserId) {
        throw new InvalidArgumentException('You cannot delete or deactivate your own administrator account.');
    }

    $account = $db->prepare('SELECT r.name AS role_name, u.status FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?');
    $account->execute([(int)$userId]);
    $account = $account->fetch(PDO::FETCH_ASSOC);
    if (!$account || $account['role_name'] !== 'Super Admin') {
        return;
    }

    if ($account['status'] === 'Active') {
        $activeAdmins = $db->prepare("SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name = 'Super Admin' AND u.status = 'Active'");
        $activeAdmins->execute();
        if ((int)$activeAdmins->fetchColumn() <= 1) {
            throw new InvalidArgumentException('The last active administrator cannot be deleted or deactivated.');
        }
    }

    throw new InvalidArgumentException('Administrator accounts cannot be deleted from the People Directory.');
}

function adminPersonHasLinkedRecords(PDO $db, $category, $personId, $userId) {
    $lockSuffix = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    $profileQueries = [
        'tenants' => [
            'SELECT 1 FROM leases WHERE tenant_id = ? LIMIT 1',
            'SELECT 1 FROM rent_charges WHERE tenant_id = ? LIMIT 1',
            'SELECT 1 FROM payments WHERE tenant_id = ? LIMIT 1',
            'SELECT 1 FROM maintenance_requests WHERE tenant_id = ? LIMIT 1',
            'SELECT 1 FROM complaints WHERE tenant_id = ? LIMIT 1',
            'SELECT 1 FROM documents WHERE tenant_id = ? LIMIT 1'
        ],
        'landlords' => [
            'SELECT 1 FROM properties WHERE landlord_id = ? LIMIT 1'
        ],
        'caretakers' => [],
        'managers' => [],
        'accountants' => []
    ];
    if (!isset($profileQueries[$category])) {
        throw new InvalidArgumentException('Unknown directory category.');
    }

    foreach ($profileQueries[$category] as $query) {
        $statement = $db->prepare($query . $lockSuffix);
        $statement->execute([(int)$personId]);
        if ($statement->fetchColumn()) {
            return true;
        }
    }

    if (!$userId) {
        return false;
    }

    $userQueries = [
        'SELECT 1 FROM payments WHERE recorded_by = ? LIMIT 1',
        'SELECT 1 FROM utility_readings WHERE recorded_by = ? LIMIT 1',
        'SELECT 1 FROM maintenance_requests WHERE assigned_staff = ? LIMIT 1',
        'SELECT 1 FROM complaints WHERE assigned_to = ? LIMIT 1',
        'SELECT 1 FROM expenses WHERE recorded_by = ? LIMIT 1',
        'SELECT 1 FROM documents WHERE uploaded_by = ? LIMIT 1',
        'SELECT 1 FROM notifications WHERE user_id = ? LIMIT 1',
        'SELECT 1 FROM audit_logs WHERE user_id = ? LIMIT 1'
    ];
    foreach ($userQueries as $query) {
        $statement = $db->prepare($query . $lockSuffix);
        $statement->execute([(int)$userId]);
        if ($statement->fetchColumn()) {
            return true;
        }
    }

    return false;
}

function deleteAdminPerson(PDO $db, $category, $personId, $currentUserId, $ipAddress) {
    $personId = (int)$personId;
    if ($personId < 1) {
        throw new InvalidArgumentException('Choose a valid person to delete.');
    }

    $db->beginTransaction();
    try {
        $person = fetchAdminPerson($db, $category, $personId);
        if (!$person) {
            throw new InvalidArgumentException('That directory record no longer exists in this category.');
        }

        $userId = (int)($person['user_id'] ?? 0);
        $table = [
            'tenants' => 'tenants',
            'landlords' => 'landlords',
            'caretakers' => 'users',
            'managers' => 'users',
            'accountants' => 'users'
        ][$category];
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $lockSuffix = $driver === 'mysql' ? ' FOR UPDATE' : '';
        $lock = $db->prepare("SELECT id FROM {$table} WHERE id = ?{$lockSuffix}");
        $lock->execute([$personId]);
        if (!$lock->fetchColumn()) {
            throw new InvalidArgumentException('That directory record no longer exists.');
        }
        if ($userId && $userId !== $personId) {
            $userLock = $db->prepare("SELECT id FROM users WHERE id = ?{$lockSuffix}");
            $userLock->execute([$userId]);
            if (!$userLock->fetchColumn()) {
                throw new InvalidArgumentException('The linked login account no longer exists.');
            }
        }
        assertAdminPersonDeletionAllowed($db, $userId, $currentUserId);

        $hasLinkedRecords = adminPersonHasLinkedRecords($db, $category, $personId, $userId);
        if ($hasLinkedRecords) {
            if ($userId) {
                $db->prepare("UPDATE users SET status = 'Inactive' WHERE id = ?")->execute([$userId]);
            }
            if ($category === 'tenants' || $category === 'landlords') {
                $db->prepare("UPDATE {$table} SET status = 'Inactive' WHERE id = ?")->execute([$personId]);
            }
            $action = 'Archive';
            $description = 'Deactivated ' . rtrim(adminPeopleCategories()[$category]['label'], 's') . ' account while preserving linked history for ' . $person['name'];
            $successMessage = 'Linked records were found. The account was deactivated and its history preserved.';
        } else {
            if ($category === 'tenants' || $category === 'landlords') {
                $db->prepare("DELETE FROM {$table} WHERE id = ?")->execute([$personId]);
            }
            if ($userId) {
                $db->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
            }
            $action = 'Delete';
            $description = 'Deleted ' . rtrim(adminPeopleCategories()[$category]['label'], 's') . ' account for ' . $person['name'];
            $successMessage = 'Person deleted successfully.';
        }

        $audit = $db->prepare('INSERT INTO audit_logs (user_id, action, module, record_id, description, ip_address) VALUES (?, ?, ?, ?, ?, ?)');
        $audit->execute([(int)$currentUserId, $action, 'People Directory', $personId, $description, $ipAddress]);
        $db->commit();

        return ['name' => $person['name'], 'archived' => $hasLinkedRecords, 'message' => $successMessage];
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $exception;
    }
}
