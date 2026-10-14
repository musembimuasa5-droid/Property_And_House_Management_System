<?php

function adminPeopleCategories() {
    return [
        'tenants' => [
            'label' => 'Tenants',
            'query' => 'SELECT full_name AS name, email, phone, status, NULL AS organization FROM tenants'
        ],
        'caretakers' => [
            'label' => 'Caretakers',
            'query' => "SELECT u.full_name AS name, u.email, u.phone, u.status, r.name AS organization FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name = 'Caretaker'"
        ],
        'managers' => [
            'label' => 'Managers',
            'query' => "SELECT u.full_name AS name, u.email, u.phone, u.status, r.name AS organization FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name = 'Property Manager'"
        ],
        'landlords' => [
            'label' => 'Landlords',
            'query' => 'SELECT full_name AS name, email, phone, status, company_name AS organization FROM landlords'
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

    $statement = $db->prepare("SELECT name, email, phone, status, organization FROM ({$query}) people{$where} ORDER BY name ASC LIMIT ? OFFSET ?");
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
