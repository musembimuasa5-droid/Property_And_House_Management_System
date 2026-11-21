<?php
/**
 * Management module registry.
 *
 * This single list drives the sidebar, page-level access checks and dashboard
 * shortcuts, so each role's permissions are defined in exactly one place.
 * "portal" says whose sidebar shows the module. Super Admin can open every
 * staff module (requireRole() lets role_id 1 through).
 */

require_once __DIR__ . '/auth.php';

const STAFF_ROLES = ['Super Admin', 'Property Manager', 'Accountant'];

function app_modules(): array {
    static $modules = null;
    if ($modules !== null) return $modules;

    $managers = ['Super Admin', 'Property Manager'];
    $finance = ['Super Admin', 'Property Manager', 'Accountant'];
    $landlord = ['Landlord'];
    $caretaker = ['Caretaker'];
    $module = fn(string $group, string $label, string $icon, string $path, array $roles, string $portal = 'staff') => [
        'group' => $group, 'label' => $label, 'icon' => $icon, 'path' => $path, 'roles' => $roles, 'portal' => $portal,
    ];

    return $modules = [
        'properties' => $module('Portfolio', 'Properties', 'fa-building', 'admin/properties.php', $managers),
        'units' => $module('Portfolio', 'Units', 'fa-door-open', 'admin/units.php', $managers),
        'landlords' => $module('Portfolio', 'Landlords', 'fa-user-tie', 'admin/landlords.php', $managers),
        'locations' => $module('Portfolio', 'Locations', 'fa-map-location-dot', 'admin/locations.php', $managers),
        'tenants' => $module('Tenancy', 'Tenants', 'fa-users', 'admin/tenants.php', $managers),
        'leases' => $module('Tenancy', 'Leases', 'fa-file-signature', 'admin/leases.php', $managers),
        'viewings' => $module('Tenancy', 'Viewing Requests', 'fa-calendar-check', 'admin/viewings.php', $managers),
        'condition_reports' => $module('Tenancy', 'Condition Reports', 'fa-clipboard-check', 'admin/condition-reports.php', $managers),
        'rent_charges' => $module('Finance', 'Rent Charges', 'fa-file-invoice-dollar', 'admin/rent-charges.php', $finance),
        'payments' => $module('Finance', 'Payments', 'fa-money-bill-wave', 'admin/payments.php', $finance),
        'deposits' => $module('Finance', 'Deposit Ledger', 'fa-vault', 'admin/deposits.php', $finance),
        'utility_readings' => $module('Finance', 'Utility Readings', 'fa-droplet', 'admin/utility-readings.php', $finance),
        'utility_types' => $module('Finance', 'Utility Types', 'fa-gauge', 'admin/utility-types.php', $finance),
        'expenses' => $module('Finance', 'Expenses', 'fa-receipt', 'admin/expenses.php', $finance),
        'maintenance' => $module('Operations', 'Maintenance', 'fa-screwdriver-wrench', 'admin/maintenance.php', $managers),
        'complaints' => $module('Operations', 'Complaints', 'fa-triangle-exclamation', 'admin/complaints.php', $managers),
        'enquiries' => $module('Operations', 'Enquiries', 'fa-envelope-open-text', 'admin/enquiries.php', $managers),
        'notices' => $module('Operations', 'Notices', 'fa-bullhorn', 'admin/notices.php', $managers),
        'reports' => $module('Insights', 'Reports', 'fa-chart-line', 'admin/reports.php', $finance),
        'users' => $module('Administration', 'User Accounts', 'fa-user-shield', 'admin/users.php', ['Super Admin']),
        'audit_log' => $module('Administration', 'Audit Log', 'fa-clock-rotate-left', 'admin/audit-log.php', ['Super Admin']),
        // A landlord can run their own properties without a manager: every page is scoped to
        // the landlord's properties (includes/resources.php and tenancy_resources.php).
        'my_properties' => $module('My portfolio', 'My Properties', 'fa-building', 'landlord/properties.php', $landlord, 'landlord'),
        'my_units' => $module('My portfolio', 'My Units', 'fa-door-open', 'landlord/units.php', $landlord, 'landlord'),
        'my_tenants' => $module('Tenancy', 'My Tenants', 'fa-users', 'landlord/tenants.php', $landlord, 'landlord'),
        'my_leases' => $module('Tenancy', 'Leases', 'fa-file-signature', 'landlord/leases.php', $landlord, 'landlord'),
        'my_condition_reports' => $module('Tenancy', 'Condition Reports', 'fa-clipboard-check', 'landlord/condition-reports.php', $landlord, 'landlord'),
        'my_rent_charges' => $module('Finance', 'Rent Charges', 'fa-file-invoice-dollar', 'landlord/rent-charges.php', $landlord, 'landlord'),
        'my_payments' => $module('Finance', 'Payments', 'fa-money-bill-wave', 'landlord/payments.php', $landlord, 'landlord'),
        'my_deposits' => $module('Finance', 'Deposit Ledger', 'fa-vault', 'landlord/deposits.php', $landlord, 'landlord'),
        'my_readings' => $module('Finance', 'Utility Readings', 'fa-droplet', 'landlord/utility-readings.php', $landlord, 'landlord'),
        'my_reports' => $module('Insights', 'Reports', 'fa-chart-line', 'landlord/reports.php', $landlord, 'landlord'),
        // Caretakers work on the properties assigned to them (properties.caretaker_id).
        'care_units' => $module('My buildings', 'Units', 'fa-door-open', 'caretaker/units.php', $caretaker, 'caretaker'),
        'care_readings' => $module('My buildings', 'Meter Readings', 'fa-droplet', 'caretaker/utility-readings.php', $caretaker, 'caretaker'),
        'care_condition_reports' => $module('Inspections', 'Condition Reports', 'fa-clipboard-check', 'caretaker/condition-reports.php', $caretaker, 'caretaker'),
    ];
}

function user_can_access_module(string $key): bool {
    $user = getCurrentUser();
    $modules = app_modules();
    if (!$user || !isset($modules[$key])) return false;
    return (int)$user['role_id'] === 1 || in_array($user['role_name'], $modules[$key]['roles'], true);
}

function require_module(string $key): void {
    requireRole(app_modules()[$key]['roles']);
}

function module_url(string $key, array $query = []): string {
    return BASE_URL . '/' . app_modules()[$key]['path'] . ($query ? '?' . http_build_query($query) : '');
}

/** Modules shown in a portal's sidebar for the signed-in user, in registry order. */
function portal_modules(string $portal): array {
    return array_filter(app_modules(), fn($module, $key) => $module['portal'] === $portal && user_can_access_module($key), ARRAY_FILTER_USE_BOTH);
}
