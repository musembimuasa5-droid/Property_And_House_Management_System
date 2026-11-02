<?php
require_once __DIR__ . '/includes/admin_people.php';

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE roles (id INTEGER PRIMARY KEY, name TEXT)');
$db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, role_id INTEGER, full_name TEXT, email TEXT, phone TEXT, password_hash TEXT, status TEXT)');
$db->exec('CREATE TABLE tenants (id INTEGER PRIMARY KEY, user_id INTEGER, full_name TEXT, email TEXT, phone TEXT, status TEXT, national_id TEXT, gender TEXT, emergency_contact_name TEXT, emergency_contact_phone TEXT)');
$db->exec('CREATE TABLE landlords (id INTEGER PRIMARY KEY, user_id INTEGER, full_name TEXT, email TEXT, phone TEXT, status TEXT, company_name TEXT)');
$db->exec('CREATE TABLE leases (id INTEGER PRIMARY KEY, tenant_id INTEGER)');
$db->exec('CREATE TABLE rent_charges (id INTEGER PRIMARY KEY, tenant_id INTEGER)');
$db->exec('CREATE TABLE payments (id INTEGER PRIMARY KEY, tenant_id INTEGER, recorded_by INTEGER)');
$db->exec('CREATE TABLE maintenance_requests (id INTEGER PRIMARY KEY, tenant_id INTEGER, assigned_staff INTEGER)');
$db->exec('CREATE TABLE complaints (id INTEGER PRIMARY KEY, tenant_id INTEGER, assigned_to INTEGER)');
$db->exec('CREATE TABLE documents (id INTEGER PRIMARY KEY, tenant_id INTEGER, uploaded_by INTEGER)');
$db->exec('CREATE TABLE properties (id INTEGER PRIMARY KEY, landlord_id INTEGER)');
$db->exec('CREATE TABLE utility_readings (id INTEGER PRIMARY KEY, recorded_by INTEGER)');
$db->exec('CREATE TABLE expenses (id INTEGER PRIMARY KEY, recorded_by INTEGER)');
$db->exec('CREATE TABLE notifications (id INTEGER PRIMARY KEY, user_id INTEGER)');
$db->exec('CREATE TABLE audit_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, action TEXT, module TEXT, record_id INTEGER, description TEXT, ip_address TEXT)');
$db->exec("INSERT INTO roles VALUES (1, 'Property Manager'), (2, 'Caretaker'), (3, 'Accountant'), (4, 'Super Admin'), (5, 'Tenant')");
$db->exec("INSERT INTO users VALUES (1, 1, 'Manager One', 'manager@example.test', '0700000001', 'manager-hash', 'Active'), (2, 2, 'Caretaker One', 'care@example.test', '0700000002', 'caretaker-hash', 'Active'), (3, 3, 'Accountant One', 'account@example.test', '0700000003', 'accountant-hash', 'Active')");
$db->exec("INSERT INTO users VALUES (4, 4, 'Admin One', 'admin@example.test', '0700000006', 'admin-hash', 'Active'), (5, 5, 'Tenant Two', 'tenant2@example.test', '0700000007', 'tenant-hash', 'Active')");
$db->exec("INSERT INTO tenants (id, full_name, email, phone, status, national_id) VALUES (1, 'Tenant One', 'tenant@example.test', '0700000004', 'Active', 'SENSITIVE-ID')");
$db->exec("INSERT INTO landlords (id, full_name, email, phone, status, company_name) VALUES (1, 'Landlord One', 'landlord@example.test', '0700000005', 'Active', 'Example Homes')");

$managerResults = fetchAdminPeople($db, 'managers');
if ($managerResults['total'] !== 1 || $managerResults['rows'][0]['name'] !== 'Manager One') {
    throw new RuntimeException('Manager directory did not return only property managers.');
}

$caretakerResults = fetchAdminPeople($db, 'caretakers');
if ($caretakerResults['total'] !== 1 || $caretakerResults['rows'][0]['name'] !== 'Caretaker One') {
    throw new RuntimeException('Caretaker directory returned an unexpected result.');
}

$landlordResults = fetchAdminPeople($db, 'landlords');
if ($landlordResults['total'] !== 1 || $landlordResults['rows'][0]['organization'] !== 'Example Homes') {
    throw new RuntimeException('Landlord directory returned an unexpected result.');
}

$tenantResults = fetchAdminPeople($db, 'tenants', 'tenant@example.test');
if ($tenantResults['total'] !== 1 || array_key_exists('national_id', $tenantResults['rows'][0])) {
    throw new RuntimeException('Tenant search failed or exposed a private identifier.');
}

for ($index = 2; $index <= 30; $index++) {
    $db->exec("INSERT INTO tenants (id, full_name, email, phone, status, national_id) VALUES ({$index}, 'Tenant {$index}', 'tenant{$index}@example.test', '0700000000', 'Active', 'PRIVATE')");
}
$firstPage = fetchAdminPeople($db, 'tenants', '', 1, 25);
$secondPage = fetchAdminPeople($db, 'tenants', '', 2, 25);
if (count($firstPage['rows']) !== 25 || count($secondPage['rows']) !== 5 || $firstPage['total'] !== 30) {
    throw new RuntimeException('Directory pagination returned an unexpected result.');
}

// Unlinked staff accounts can be permanently deleted.
$deleted = deleteAdminPerson($db, 'accountants', 3, 4, '127.0.0.1');
if ($deleted['archived'] || $db->query('SELECT COUNT(*) FROM users WHERE id = 3')->fetchColumn() !== 0) {
    throw new RuntimeException('An unlinked account was not permanently deleted.');
}

// Linked tenant history must be preserved by deactivating both profile and login.
$db->exec("INSERT INTO tenants (id, user_id, full_name, email, phone, status, national_id, gender) VALUES (31, 5, 'Tenant Two', 'tenant2@example.test', '0700000007', 'Active', 'TENANT-2', 'Other')");
$db->exec('INSERT INTO payments (tenant_id) VALUES (31)');
$archived = deleteAdminPerson($db, 'tenants', 31, 4, '127.0.0.1');
$tenantStatus = $db->query('SELECT status FROM tenants WHERE id = 31')->fetchColumn();
$tenantUserStatus = $db->query('SELECT status FROM users WHERE id = 5')->fetchColumn();
if (!$archived['archived'] || $tenantStatus !== 'Inactive' || $tenantUserStatus !== 'Inactive') {
    throw new RuntimeException('Linked tenant history was not preserved by deactivation.');
}

// Current and last-active administrator accounts are protected.
foreach ([['user' => 4, 'current' => 4, 'message' => 'own administrator'], ['user' => 4, 'current' => 99, 'message' => 'last active administrator']] as $protection) {
    try {
        assertAdminPersonDeletionAllowed($db, $protection['user'], $protection['current']);
        throw new RuntimeException('The ' . $protection['message'] . ' protection did not run.');
    } catch (InvalidArgumentException $exception) {
        if (strpos($exception->getMessage(), $protection['message'] === 'own administrator' ? 'own administrator' : 'last active administrator') === false) {
            throw $exception;
        }
    }
}

echo "Admin people directory tests passed.\n";
