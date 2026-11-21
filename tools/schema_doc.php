<?php
/**
 * Writes docs/DATABASE_SCHEMA.md, the schema reference (ERD and data dictionary), from the
 * live database structure, so the document always matches what schema.sql and the
 * migrations actually create.
 *
 *   C:\xampp\php\php.exe tools\schema_doc.php            # reads DB_NAME (default nairobi_property_db)
 *
 * It reads information_schema only and never changes the database.
 */

if (PHP_SAPI !== 'cli') exit("Run this script from the command line.\n");

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

/** Purpose of each table, grouped by business area, in the order the document presents them. */
const SCHEMA_AREAS = [
    'Access and security' => [
        'roles' => 'The six user roles: Super Admin, Property Manager, Landlord, Caretaker, Accountant, Tenant.',
        'users' => 'Sign-in accounts. Passwords are stored only as bcrypt hashes; only Active accounts can sign in.',
        'login_attempts' => 'Failed sign-ins, used to block an email after 5 failures (or an IP after 20) in 15 minutes. Rows older than a day are removed.',
        'audit_logs' => 'Who did what and when, with before and after values (secrets removed) for changes to records.',
    ],
    'Portfolio' => [
        'locations' => 'Estates and sub-counties used to file and search properties.',
        'landlords' => 'Property owners, optionally linked to a Landlord sign-in account.',
        'properties' => 'Buildings, each owned by one landlord and optionally looked after by one caretaker.',
        'units' => 'Rentable houses or rooms within a property, with rent, deposit, billing methods and status.',
    ],
    'Tenancy' => [
        'tenants' => 'Tenant profiles, optionally linked to a Tenant sign-in account and to the landlord who registered them.',
        'leases' => 'Tenancy agreements between a tenant and a unit: dates, rent, deposit, due day, notice period and status.',
        'condition_reports' => 'Move-in and move-out inspections of a lease (at most one of each), with the tenant\'s response.',
        'condition_report_items' => 'One checklist line per area of the unit in a condition report, with an optional photo.',
        'documents' => 'Reserved for lease, ID and other documents (not yet used by the interface).',
    ],
    'Billing and finance' => [
        'rent_charges' => 'Monthly invoices per lease: rent, service charge, utilities and any balance brought forward.',
        'payments' => 'Money received, with a unique receipt number and a unique transaction reference.',
        'payment_allocations' => 'How each payment is applied to charges, oldest charge first; rebuilt after every change.',
        'deposit_transactions' => 'Deposit ledger per lease: deposits received, itemised deductions (with reasons) and refunds.',
        'utility_types' => 'Billable utilities such as water and garbage collection, with a default rate.',
        'utility_readings' => 'Meter readings per unit and period; their totals flow into that period\'s rent charge.',
        'expenses' => 'Property running costs used by the income statement.',
    ],
    'Operations' => [
        'maintenance_requests' => 'Repair requests from tenants, with priority, assignee, costs and an optional photo.',
        'maintenance_updates' => 'Time-stamped status history of each maintenance request.',
        'complaints' => 'Tenant complaints and management\'s resolution notes.',
        'notifications' => 'In-app notices and alerts shown in each user\'s notification bell.',
    ],
    'Public website' => [
        'viewing_requests' => 'Requests from visitors to view a property.',
        'enquiries' => 'Messages sent from the public Contact page.',
    ],
];

/** Rules the database cannot express, enforced in PHP. */
const SCHEMA_CODE_RULES = [
    'A unit and a tenant can each have only one Active lease; activating a lease marks the unit Occupied and ending it frees the unit (`includes/tenancy_resources.php`, `includes/finance.php`).',
    'Payments are allocated to the oldest unpaid charge of the lease, in cents; overpayments carry forward. Charge status (Unpaid, Partial, Overdue, Paid) is recalculated after every payment, charge or reading change.',
    'Deposit money received cannot exceed the agreed deposit, and deductions plus refunds cannot exceed what is held. A deduction needs a reason.',
    'A lease is charged at most once per billing period, and a payment date cannot be in the future.',
    'Records with financial history (properties with units or expenses, units with leases or readings, leases with charges or payments) cannot be deleted; they are archived or deactivated instead.',
    'Landlords see only their own properties and caretakers only the properties assigned to them; every query is scoped in SQL.',
];

$db = db();
$schema = $db->query('SELECT DATABASE()')->fetchColumn();
$query = function (string $sql) use ($db, $schema): array {
    $stmt = $db->prepare($sql);
    $stmt->execute([$schema]);
    return $stmt->fetchAll();
};

$tables = array_column($query('SELECT table_name, engine FROM information_schema.tables WHERE table_schema = ? ORDER BY table_name'), 'engine', 'table_name');
$columns = [];
foreach ($query('SELECT table_name, column_name, column_type, is_nullable, column_default, column_key, extra FROM information_schema.columns WHERE table_schema = ? ORDER BY table_name, ordinal_position') as $column) {
    $columns[$column['table_name']][] = $column;
}
$foreignKeys = [];
foreach ($query('SELECT k.table_name, k.column_name, k.referenced_table_name, k.referenced_column_name, r.delete_rule
        FROM information_schema.key_column_usage k JOIN information_schema.referential_constraints r ON r.constraint_schema = k.constraint_schema AND r.constraint_name = k.constraint_name
        WHERE k.table_schema = ? ORDER BY k.table_name, k.ordinal_position') as $fk) {
    $foreignKeys[$fk['table_name']][$fk['column_name']] = $fk;
}
$indexes = [];
foreach ($query("SELECT table_name, index_name, non_unique, GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ', ') AS cols
        FROM information_schema.statistics WHERE table_schema = ? AND index_name <> 'PRIMARY' GROUP BY table_name, index_name, non_unique ORDER BY table_name, index_name") as $index) {
    $indexes[$index['table_name']][] = $index;
}

$described = array_merge(...array_values(array_map('array_keys', SCHEMA_AREAS)));
$missing = array_diff(array_keys($tables), $described);
if ($missing) {
    fwrite(STDERR, 'Add a description for: ' . implode(', ', $missing) . "\n");
    exit(1);
}

/** Short type name for the ERD (Mermaid attribute types cannot contain brackets or commas). */
function erd_type(string $columnType): string {
    return preg_replace('/\W.*$/', '', $columnType);
}

function md(string $text): string {
    return str_replace(['|', "\n"], ['\|', ' '], $text);
}

$totalColumns = array_sum(array_map('count', $columns));
$totalKeys = array_sum(array_map('count', $foreignKeys));
$out = [];
$out[] = '# Database Schema: Urban Escape PHMS';
$out[] = '';
$out[] = "Generated by `tools/schema_doc.php` from database `$schema` on " . date('j F Y') . '. Do not edit by hand; change `database/schema.sql` and add a migration, then regenerate.';
$out[] = '';
$out[] = '| Item | Value |';
$out[] = '| --- | --- |';
$out[] = '| DBMS | ' . md((string)$db->query('SELECT VERSION()')->fetchColumn()) . ' (MySQL-compatible), InnoDB, utf8mb4 |';
$out[] = '| Tables | ' . count($tables) . ' |';
$out[] = "| Columns | $totalColumns |";
$out[] = "| Foreign keys | $totalKeys |";
$out[] = '| Fresh install | `database/schema.sql`, then `database/seed.sql` (development) or `database/reference_data.sql` (production) |';
$out[] = '| Upgrade path | `database/migrations/001` to `003`, applied in order; each is safe to re-run |';
$out[] = '';
$out[] = 'A fresh install from `schema.sql` and a database upgraded with the migrations have identical tables, columns, indexes and foreign keys.';
$out[] = '';

$out[] = '## Contents';
$out[] = '';
foreach (SCHEMA_AREAS as $area => $areaTables) {
    $out[] = "- **$area:** " . implode(', ', array_map(fn($t) => "[`$t`](#$t)", array_keys($areaTables)));
}
$out[] = '';

$out[] = '## Entity relationship diagram';
$out[] = '';
$out[] = 'Primary keys, foreign keys and unique keys are shown; see the data dictionary for every column. `||--o{` means one to many with a required parent; `|o--o{` means the parent is optional (nullable foreign key).';
$out[] = '';
$out[] = '```mermaid';
$out[] = 'erDiagram';
foreach ($foreignKeys as $table => $fks) {
    foreach ($fks as $column => $fk) {
        $nullable = current(array_filter($columns[$table], fn($c) => $c['column_name'] === $column))['is_nullable'] === 'YES';
        $out[] = "    {$fk['referenced_table_name']} " . ($nullable ? '|o--o{' : '||--o{') . " $table : \"$column\"";
    }
}
foreach (array_keys($tables) as $table) {
    $out[] = "    $table {";
    foreach ($columns[$table] as $column) {
        $keys = [];
        if ($column['column_key'] === 'PRI') $keys[] = 'PK';
        if (isset($foreignKeys[$table][$column['column_name']])) $keys[] = 'FK';
        if ($column['column_key'] === 'UNI') $keys[] = 'UK';
        if ($keys) $out[] = '        ' . erd_type($column['column_type']) . ' ' . $column['column_name'] . ' ' . implode(', ', $keys);
    }
    $out[] = '    }';
}
$out[] = '```';
$out[] = '';

$out[] = '## Data dictionary';
$out[] = '';
$out[] = 'Key: **PK** primary key, **FK** foreign key (with its ON DELETE rule), **UK** unique, **IX** indexed. Every table uses the InnoDB engine.';
foreach (SCHEMA_AREAS as $area => $areaTables) {
    $out[] = '';
    $out[] = "### $area";
    foreach ($areaTables as $table => $purpose) {
        $out[] = '';
        $out[] = "#### $table";
        $out[] = '';
        $out[] = $purpose;
        $out[] = '';
        $out[] = '| Column | Type | Null | Default | Key | Notes |';
        $out[] = '| --- | --- | --- | --- | --- | --- |';
        foreach ($columns[$table] as $column) {
            $name = $column['column_name'];
            $keys = [];
            $notes = [];
            if ($column['column_key'] === 'PRI') $keys[] = 'PK';
            if ($column['column_key'] === 'UNI') $keys[] = 'UK';
            if ($column['column_key'] === 'MUL' && !isset($foreignKeys[$table][$name])) $keys[] = 'IX';
            if (isset($foreignKeys[$table][$name])) {
                $fk = $foreignKeys[$table][$name];
                $keys[] = 'FK';
                $notes[] = "→ `{$fk['referenced_table_name']}.{$fk['referenced_column_name']}`, on delete " . strtolower($fk['delete_rule']);
            }
            if (str_contains($column['extra'], 'auto_increment')) $notes[] = 'auto increment';
            if (stripos($column['extra'], 'on update') !== false) $notes[] = 'updated automatically on change';
            $default = $column['column_default'];
            $default = $default === null || $default === 'NULL' ? '' : trim($default, "'");
            $out[] = '| `' . $name . '` | ' . md($column['column_type']) . ' | ' . ($column['is_nullable'] === 'YES' ? 'Yes' : 'No') . ' | ' . md($default)
                . ' | ' . implode(', ', $keys) . ' | ' . md(implode('; ', $notes)) . ' |';
        }
        $composite = array_filter($indexes[$table] ?? [], fn($i) => str_contains($i['cols'], ','));
        foreach ($composite as $index) {
            $out[] = '';
            $out[] = ($index['non_unique'] ? 'Index' : 'Unique key') . " `{$index['index_name']}` on ({$index['cols']}).";
        }
    }
}
$out[] = '';
$out[] = '## Integrity rules enforced in the application';
$out[] = '';
foreach (SCHEMA_CODE_RULES as $rule) $out[] = "- $rule";
$out[] = '';

$target = dirname(__DIR__) . '/docs/DATABASE_SCHEMA.md';
file_put_contents($target, implode("\n", $out));
printf("Wrote %s: %d tables, %d columns, %d foreign keys.\n", $target, count($tables), $totalColumns, $totalKeys);
