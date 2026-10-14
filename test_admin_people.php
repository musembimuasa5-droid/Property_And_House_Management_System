<?php
require_once __DIR__ . '/includes/admin_people.php';

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE roles (id INTEGER PRIMARY KEY, name TEXT)');
$db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, role_id INTEGER, full_name TEXT, email TEXT, phone TEXT, status TEXT)');
$db->exec('CREATE TABLE tenants (id INTEGER PRIMARY KEY, full_name TEXT, email TEXT, phone TEXT, status TEXT, national_id TEXT)');
$db->exec('CREATE TABLE landlords (id INTEGER PRIMARY KEY, full_name TEXT, email TEXT, phone TEXT, status TEXT, company_name TEXT)');
$db->exec("INSERT INTO roles VALUES (1, 'Property Manager'), (2, 'Caretaker'), (3, 'Accountant')");
$db->exec("INSERT INTO users VALUES (1, 1, 'Manager One', 'manager@example.test', '0700000001', 'Active'), (2, 2, 'Caretaker One', 'care@example.test', '0700000002', 'Active'), (3, 3, 'Accountant One', 'account@example.test', '0700000003', 'Active')");
$db->exec("INSERT INTO tenants VALUES (1, 'Tenant One', 'tenant@example.test', '0700000004', 'Active', 'SENSITIVE-ID')");
$db->exec("INSERT INTO landlords VALUES (1, 'Landlord One', 'landlord@example.test', '0700000005', 'Active', 'Example Homes')");

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
    $db->exec("INSERT INTO tenants VALUES ({$index}, 'Tenant {$index}', 'tenant{$index}@example.test', '0700000000', 'Active', 'PRIVATE')");
}
$firstPage = fetchAdminPeople($db, 'tenants', '', 1, 25);
$secondPage = fetchAdminPeople($db, 'tenants', '', 2, 25);
if (count($firstPage['rows']) !== 25 || count($secondPage['rows']) !== 5 || $firstPage['total'] !== 30) {
    throw new RuntimeException('Directory pagination returned an unexpected result.');
}

echo "Admin people directory tests passed.\n";
