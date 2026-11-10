<?php
/**
 * Command-line test runner for Urban Escape.
 *
 *   php tests/run_tests.php --base-url=http://127.0.0.1:8090 [--suite=UT,IT,ST,SEC,NFR] [--only=ST-01,ST-02]
 *       [--json=results.json] [--apache-url=http://localhost/Property_And_House_Management_System]
 *
 * Needs DB_NAME set to a test database (name ending in _test) freshly built by
 * tests/setup_test_database.ps1, and the application served from that database at --base-url.
 * System tests change data, so rebuild the database before each full run.
 */

if (PHP_SAPI !== 'cli') exit("Run this script from the command line.\n");
if (!preg_match('/_test$/', (string)getenv('DB_NAME'))) {
    fwrite(STDERR, "Refusing to run: set DB_NAME to a test database whose name ends in _test.\n");
    exit(1);
}
$options = getopt('', ['base-url:', 'suite::', 'only::', 'json::', 'apache-url::']);
$base = rtrim($options['base-url'] ?? 'http://127.0.0.1:8090', '/');
$apache = isset($options['apache-url']) ? rtrim($options['apache-url'], '/') : null;

$root = dirname(__DIR__);
require_once $root . '/config/config.php';
require_once $root . '/config/database.php';
require_once $root . '/config/auth.php';
require_once $root . '/includes/crud.php';
require_once $root . '/includes/finance.php';
require_once $root . '/includes/condition.php';
require_once $root . '/includes/reports.php';
require_once __DIR__ . '/lib/TestKit.php';

$db = db();
define('PW', getenv('TEST_PASSWORD') ?: 'UrbanEscape-Test-2026');
define('PHOTO', __DIR__ . '/fixtures/photos/sink_leak.jpg');
$state = [];

function one(string $sql, array $params = []) {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

function row(string $sql, array $params = []): array {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch() ?: [];
}

function signed_in(string $email): Browser {
    global $base;
    $browser = new Browser($base);
    $response = $browser->login($email, PW);
    check($response['status'] === 302 && !str_contains((string)$response['location'], 'login.php'), "Could not sign in as $email");
    return $browser;
}

function lease_of(string $tenantName): int {
    return (int)one("SELECT l.id FROM leases l JOIN tenants t ON t.id = l.tenant_id WHERE t.full_name = ? ORDER BY l.id DESC LIMIT 1", [$tenantName]);
}

function unit_id(string $number): int {
    return (int)one('SELECT id FROM units WHERE unit_number = ?', [$number]);
}

function user_id(string $email): int {
    return (int)one('SELECT id FROM users WHERE email = ?', [$email]);
}

$photo = ['path' => PHOTO, 'mime' => 'image/jpeg', 'name' => 'leak.jpg'];
$today = date('Y-m-d');

// ===========================================================================
// UT: unit tests of rules with no side effects
// ===========================================================================

TestKit::add('UT-01', 'Email field validation', 'Validation rules loaded', ['Validate "not-an-email"', 'Validate "jane@example.com"'],
    'The invalid address is rejected with a message; the valid address is accepted', function () use ($db) {
        $field = ['label' => 'Email', 'type' => 'email', 'required' => true];
        [, $bad] = crud_validate_value($db, $field, 'not-an-email', true);
        [$value, $good] = crud_validate_value($db, $field, 'jane@example.com', true);
        check($bad !== null && $good === null && $value === 'jane@example.com', 'Email rule did not behave as specified');
        return "Rejected with \"$bad\"; valid address accepted";
    });
TestKit::add('UT-02', 'Phone number and required-field validation', 'Validation rules loaded', ['Validate "+254712345678"', 'Validate "12ab"', 'Validate an empty required value'],
    'Only the Kenyan-format number is accepted; the others return messages', function () use ($db) {
        $field = ['label' => 'Phone', 'type' => 'tel', 'required' => true];
        [, $ok] = crud_validate_value($db, $field, '+254712345678', true);
        [, $bad] = crud_validate_value($db, $field, '12ab', true);
        [, $empty] = crud_validate_value($db, $field, '', true);
        check($ok === null && $bad !== null && $empty === 'Phone is required.', 'Phone rule did not behave as specified');
        return "Valid number accepted; \"12ab\" rejected; empty value: \"$empty\"";
    });
TestKit::add('UT-03', 'Amount limits, rounding and password length', 'Validation rules loaded', ['Validate -5 for a field with minimum 0', 'Validate 12.345', 'Validate a 9- and a 10-character password'],
    'Negative amount rejected, 12.345 stored as 12.35, passwords shorter than 10 characters rejected', function () use ($db) {
        [, $negative] = crud_validate_value($db, ['label' => 'Rent', 'type' => 'decimal', 'min' => 0], '-5', true);
        [$rounded] = crud_validate_value($db, ['label' => 'Rent', 'type' => 'decimal'], '12.345', true);
        [, $short] = crud_validate_value($db, ['label' => 'Password', 'type' => 'password', 'required' => true], '123456789', true);
        [, $long] = crud_validate_value($db, ['label' => 'Password', 'type' => 'password', 'required' => true], '1234567890', true);
        check($negative !== null && abs($rounded - 12.35) < 0.0001 && $short !== null && $long === null, 'Amount or password rule failed');
        return "Negative rejected; rounded to $rounded; 9-character password rejected; 10 accepted";
    });
TestKit::add('UT-04', 'Rent due date never falls on a missing day', 'Billing rules loaded', ['charge_due_date("2026-02", 31)', 'charge_due_date("2026-11", 5)', 'charge_due_date("2026-11", 0)'],
    '2026-02-28, 2026-11-05 and 2026-11-01', function () {
        $results = [charge_due_date('2026-02', 31), charge_due_date('2026-11', 5), charge_due_date('2026-11', 0)];
        check($results === ['2026-02-28', '2026-11-05', '2026-11-01'], 'Got ' . implode(', ', $results));
        return implode(', ', $results);
    });
TestKit::add('UT-05', 'CSV export guards against formula injection', 'Helper loaded', ['csv_safe on "=HYPERLINK(...)", "@SUM(A1)", "-500" and "Plain text"'],
    'Formula-like text is prefixed with an apostrophe; numbers and plain text are unchanged', function () {
        $out = [csv_safe('=HYPERLINK("http://x")'), csv_safe('@SUM(A1)'), csv_safe('-500'), csv_safe('Plain text')];
        check($out[0][0] === "'" && $out[1][0] === "'" && $out[2] === '-500' && $out[3] === 'Plain text', 'Guard output: ' . implode(' | ', $out));
        return 'Outputs: ' . implode(' | ', $out);
    });
TestKit::add('UT-06', 'Receipt numbers are well-formed and unique', 'Helper loaded', ['Generate 1,000 receipt numbers'],
    'Every number matches REC-YYYYMMDD-XXXXXXXX and none repeats', function () {
        $numbers = array_map(fn() => generate_receipt_number(), range(1, 1000));
        $valid = count(array_filter($numbers, fn($n) => preg_match('/^REC-\d{8}-[0-9A-F]{8}$/', $n)));
        check($valid === 1000 && count(array_unique($numbers)) === 1000, "$valid valid, " . count(array_unique($numbers)) . ' unique');
        return "1,000 valid and unique (e.g. {$numbers[0]})";
    });
TestKit::add('UT-07', 'Deposit status rules', 'Billing rules loaded', ['Evaluate five lease and ledger combinations'],
    'Not received; Held; Part received; Due for refund; Settled', function () {
        $out = [deposit_status('Active', 22000, 0, 0), deposit_status('Active', 22000, 22000, 22000), deposit_status('Active', 9500, 5000, 5000),
            deposit_status('Terminated', 22000, 22000, 17000), deposit_status('Terminated', 22000, 22000, 0)];
        check($out === ['Not received', 'Held', 'Part received', 'Due for refund', 'Settled'], 'Got ' . implode(', ', $out));
        return implode('; ', $out);
    });
TestKit::add('UT-08', 'Move-out deterioration is detected', 'Condition rules loaded', ['Compare Good→Damaged, Fair→Good, none→Poor, Poor→Poor'],
    'Only Good→Damaged counts as worse', function () {
        $out = [condition_got_worse('Good', 'Damaged'), condition_got_worse('Fair', 'Good'), condition_got_worse(null, 'Poor'), condition_got_worse('Poor', 'Poor')];
        check($out === [true, false, false, false], 'Unexpected comparison result');
        return 'true, false, false, false';
    });
TestKit::add('UT-09', 'Audit log records only fields that changed', 'CRUD engine loaded', ['Compare {amount: "25000.00", method: M-Pesa} with {amount: 25000, method: Bank}', 'Compare a create'],
    'Only the method appears as changed; a create has no "before" values', function () {
        [$before, $after] = crud_changes(['amount' => '25000.00', 'payment_method' => 'M-Pesa'], ['amount' => 25000.0, 'payment_method' => 'Bank']);
        [$createBefore] = crud_changes(null, ['amount' => 1]);
        check($before === ['payment_method' => 'M-Pesa'] && $after === ['payment_method' => 'Bank'] && $createBefore === null, 'Diff was ' . json_encode([$before, $after]));
        return 'Changed fields: ' . json_encode($after) . '; create has no before values';
    });
TestKit::add('UT-10', 'Secrets never reach the audit log', 'Audit helper loaded', ['Encode {full_name, password_hash} for the log', 'Encode an empty set'],
    'password_hash is removed; an empty set gives no value', function () {
        $json = audit_values(['full_name' => 'Test', 'password_hash' => '$2y$10$abc']);
        check(!str_contains((string)$json, 'password_hash') && audit_values([]) === null, "Encoded: $json");
        return "Stored: $json";
    });

// ===========================================================================
// IT: integration tests against the test database (rolled back afterwards)
// ===========================================================================

/** A throwaway tenant and lease on unit A104 for integration tests. */
function it_lease(PDO $db, string $status = 'Active'): int {
    $tenant = insert_row($db, 'tenants', ['full_name' => 'IT Tenant', 'phone' => '+254700999000', 'email' => 'it@urbanescape.test', 'national_id' => 'IT' . mt_rand(100000, 999999), 'gender' => 'Other']);
    $unit = unit_id('A104');
    return insert_row($db, 'leases', ['tenant_id' => $tenant, 'property_id' => 1, 'unit_id' => $unit, 'start_date' => '2026-09-01', 'end_date' => '2027-08-31',
        'monthly_rent' => 10000, 'deposit' => 10000, 'status' => $status]);
}

function insert_row(PDO $db, string $table, array $row): int {
    $db->prepare("INSERT INTO $table (" . implode(', ', array_keys($row)) . ') VALUES (' . implode(', ', array_fill(0, count($row), '?')) . ')')->execute(array_values($row));
    return (int)$db->lastInsertId();
}

function it_charge(PDO $db, int $lease, string $period, float $total): int {
    $l = $db->query("SELECT tenant_id, unit_id FROM leases WHERE id = $lease")->fetch();
    return insert_row($db, 'rent_charges', ['lease_id' => $lease, 'tenant_id' => $l['tenant_id'], 'unit_id' => $l['unit_id'], 'billing_period' => $period,
        'rent_amount' => $total, 'total_due' => $total, 'due_date' => "$period-05"]);
}

function it_payment(PDO $db, int $lease, float $amount, string $date): void {
    $l = $db->query("SELECT tenant_id, property_id, unit_id FROM leases WHERE id = $lease")->fetch();
    insert_row($db, 'payments', ['receipt_number' => generate_receipt_number(), 'tenant_id' => $l['tenant_id'], 'property_id' => $l['property_id'], 'unit_id' => $l['unit_id'],
        'lease_id' => $lease, 'amount' => $amount, 'payment_method' => 'Cash', 'transaction_reference' => 'IT-' . bin2hex(random_bytes(5)), 'payment_date' => $date,
        'payment_period' => substr($date, 0, 7), 'recorded_by' => 5]);
}

TestKit::add('IT-01', 'Payment applied to the oldest charge first', 'Lease with September and October charges of KSh 10,000 each', ['Record a payment of KSh 15,000', 'Recalculate balances'],
    'September Paid with KSh 10,000 allocated; October receives KSh 5,000 and is Overdue', function () use ($db) {
        return in_rollback($db, function (PDO $db) {
            $lease = it_lease($db);
            $sep = it_charge($db, $lease, '2026-09', 10000);
            $oct = it_charge($db, $lease, '2026-10', 10000);
            it_payment($db, $lease, 15000, '2026-10-10');
            recalculate_lease_balances($db, $lease);
            $status = $db->query("SELECT id, status, (SELECT COALESCE(SUM(allocated_amount),0) FROM payment_allocations WHERE rent_charge_id = rc.id) paid FROM rent_charges rc WHERE lease_id = $lease ORDER BY billing_period")->fetchAll();
            check($status[0]['status'] === 'Paid' && (float)$status[0]['paid'] === 10000.0 && (float)$status[1]['paid'] === 5000.0 && $status[1]['status'] === 'Overdue', json_encode($status));
            return "September: {$status[0]['status']}, KSh 10,000 allocated; October: {$status[1]['status']}, KSh 5,000 allocated";
        });
    });
TestKit::add('IT-02', 'Overpayment carries forward to the next charge', 'Lease with one KSh 10,000 charge', ['Pay KSh 12,000', 'Add a second KSh 10,000 charge and recalculate'],
    'The extra KSh 2,000 is allocated to the second charge', function () use ($db) {
        return in_rollback($db, function (PDO $db) {
            $lease = it_lease($db);
            it_charge($db, $lease, '2026-09', 10000);
            it_payment($db, $lease, 12000, '2026-09-03');
            recalculate_lease_balances($db, $lease);
            $second = it_charge($db, $lease, '2026-10', 10000);
            recalculate_lease_balances($db, $lease);
            $carried = (float)$db->query("SELECT COALESCE(SUM(allocated_amount),0) FROM payment_allocations WHERE rent_charge_id = $second")->fetchColumn();
            check($carried === 2000.0, "Carried forward: $carried");
            return 'KSh 2,000 allocated to the October charge';
        });
    });
TestKit::add('IT-03', 'Monthly charge generation is not repeated', 'Active leases running in January 2027', ['Generate charges for 2027-01', 'Generate again for 2027-01'],
    'The second run creates nothing and skips every lease charged by the first run', function () use ($db) {
        return in_rollback($db, function (PDO $db) {
            [$created] = generate_rent_charges($db, '2027-01');
            [$again, $skipped] = generate_rent_charges($db, '2027-01');
            check($created > 0 && $again === 0 && $skipped === $created, "First $created, second $again, skipped $skipped");
            return "First run created $created; second created 0 and skipped $skipped";
        });
    });
TestKit::add('IT-04', 'Utility reading updates an existing charge', 'Jane Njeri has a November 2026 charge', ['Record a KSh 300 garbage reading for unit A101, November', 'Synchronise charges'],
    'The November charge total rises by KSh 300', function () use ($db) {
        return in_rollback($db, function (PDO $db) {
            $charge = fn() => (float)$db->query("SELECT total_due FROM rent_charges WHERE unit_id = 1 AND billing_period = '2026-11'")->fetchColumn();
            $before = $charge();
            insert_row($db, 'utility_readings', ['unit_id' => 1, 'utility_type_id' => 2, 'previous_reading' => 0, 'current_reading' => 1, 'consumption' => 1, 'rate' => 300,
                'total_amount' => 300, 'billing_period' => '2026-11', 'reading_date' => '2026-11-01', 'recorded_by' => 5]);
            sync_charge_utilities($db, 1, '2026-11');
            check(abs($charge() - $before - 300) < 0.01, 'Total changed from ' . $before . ' to ' . $charge());
            return 'Total changed from ' . format_currency($before) . ' to ' . format_currency($charge());
        });
    });
TestKit::add('IT-05', 'Unit occupancy follows the lease', 'Unit A104 is vacant', ['Add an Active lease and synchronise', 'Terminate it and synchronise'],
    'A104 becomes Occupied, then Vacant', function () use ($db) {
        return in_rollback($db, function (PDO $db) {
            $unit = unit_id('A104');
            $lease = it_lease($db);
            sync_unit_occupancy($db, $unit);
            $occupied = $db->query("SELECT status FROM units WHERE id = $unit")->fetchColumn();
            $db->exec("UPDATE leases SET status = 'Terminated' WHERE id = $lease");
            sync_unit_occupancy($db, $unit);
            $vacant = $db->query("SELECT status FROM units WHERE id = $unit")->fetchColumn();
            check($occupied === 'Occupied' && $vacant === 'Vacant', "$occupied then $vacant");
            return "$occupied, then $vacant";
        });
    });
TestKit::add('IT-06', 'Deposit ledger limits', 'Daniel Kiptoo holds KSh 17,000; Jane Njeri has paid her full deposit', ['Check a KSh 20,000 deduction for Daniel', 'Check a KSh 1,000 deduction for Daniel', 'Check KSh 1 more received for Jane'],
    'The first and third are refused with messages; the second is allowed', function () {
        $daniel = lease_of('Daniel Kiptoo');
        $tooMuch = deposit_entry_error(db(), $daniel, 'Deduction', 20000);
        $fine = deposit_entry_error(db(), $daniel, 'Deduction', 1000);
        $overPaid = deposit_entry_error(db(), lease_of('Jane Njeri'), 'Received', 1);
        check($tooMuch !== null && $fine === null && $overPaid !== null, 'Ledger limits not enforced');
        return "Refused: \"$tooMuch\"; allowed KSh 1,000; refused: \"$overPaid\"";
    });
TestKit::add('IT-07', 'Sign-in throttling threshold', 'No failed attempts for it07@urbanescape.test', ['Record 4 failures and check', 'Record a 5th failure and check', 'Clear failures and check'],
    'Not blocked after 4; blocked after 5; not blocked after clearing', function () use ($db) {
        return in_rollback($db, function (PDO $db) {
            $email = 'it07@urbanescape.test';
            for ($i = 0; $i < 4; $i++) login_record_failure($db, $email, '10.0.0.7');
            $afterFour = login_is_throttled($db, $email, '10.0.0.7');
            login_record_failure($db, $email, '10.0.0.7');
            $afterFive = login_is_throttled($db, $email, '10.0.0.7');
            login_clear_failures($db, $email);
            $cleared = login_is_throttled($db, $email, '10.0.0.7');
            check(!$afterFour && $afterFive && !$cleared, 'Throttle states: ' . json_encode([$afterFour, $afterFive, $cleared]));
            return 'After 4: allowed; after 5: blocked; after clearing: allowed';
        });
    });
TestKit::add('IT-08', 'Arrears reminders are sent once a day', 'Brian, Kevin and Esther have overdue balances', ['Send reminders', 'Send reminders again'],
    'Each overdue tenant with a login is notified once; the second run sends nothing', function () use ($db) {
        return in_rollback($db, function (PDO $db) {
            [$sent] = send_arrears_reminders($db);
            [$again, $skipped] = send_arrears_reminders($db);
            check($sent >= 3 && $again === 0 && $skipped === $sent, "Sent $sent, then $again (skipped $skipped)");
            return "First run sent $sent; second sent 0 and skipped $skipped";
        });
    });

// ===========================================================================
// ST: system tests through HTTP, as each user would work
// ===========================================================================

TestKit::add('ST-01', 'Each role signs in to its own dashboard', 'Test accounts for all six roles', ['Sign in as Super Admin, Property Manager, Accountant, Landlord, Caretaker and Tenant'],
    'Each is redirected to the dashboard of its portal', function () use ($base) {
        $expect = ['admin' => '/admin/dashboard.php', 'manager' => '/admin/dashboard.php', 'accountant' => '/admin/dashboard.php',
            'landlord' => '/landlord/dashboard.php', 'caretaker' => '/caretaker/dashboard.php', 'tenant' => '/tenant/dashboard.php'];
        $seen = [];
        foreach ($expect as $account => $path) {
            $browser = new Browser($base);
            $seen[$account] = path_of($browser->login("$account@urbanescape.test", PW)['location']);
        }
        check($seen === $expect, 'Redirects: ' . json_encode($seen));
        return 'All six roles reached their dashboards (' . implode(', ', array_unique($seen)) . ')';
    });
TestKit::add('ST-02', 'Wrong password is refused', 'Tenant account exists', ['Sign in with a wrong password', 'Open the tenant dashboard'],
    'Generic error message; dashboard redirects to sign-in', function () use ($base) {
        $browser = new Browser($base);
        $response = $browser->login('tenant@urbanescape.test', 'wrong-password-1');
        $dashboard = $browser->get('/tenant/dashboard.php');
        check($response['status'] === 200 && str_contains($response['body'], 'Invalid email address or password.') && str_contains((string)$dashboard['location'], 'login.php'), 'Login was not refused');
        return 'Message "Invalid email address or password." shown; dashboard redirected to sign-in';
    });
TestKit::add('ST-03', 'Tenant self-registration with consent, linked to a staff-created profile', 'Staff created Mercy Wairimu\'s profile with no login', ['Register with her ID and email without ticking consent', 'Register again with consent', 'Sign in'],
    'First attempt refused; second creates the account linked to the existing profile; sign-in works', function () use ($base) {
        $browser = new Browser($base);
        $fields = ['full_name' => 'Mercy Wairimu', 'email' => 'mercy.wairimu@urbanescape.test', 'phone' => '+254700200007', 'national_id' => '35290041', 'gender' => 'Female', 'password' => PW];
        $noConsent = $browser->submit('/public/register.php', $fields);
        $withConsent = $browser->submit('/public/register.php', $fields + ['privacy_consent' => '1']);
        $linked = (int)one("SELECT user_id IS NOT NULL FROM tenants WHERE national_id = '35290041'");
        $login = (new Browser($base))->login('mercy.wairimu@urbanescape.test', PW);
        check(str_contains($noConsent['body'], 'tick the box') && str_contains($withConsent['body'], 'linked to your tenancy') && $linked === 1
            && path_of($login['location']) === '/tenant/dashboard.php', 'Registration flow failed');
        return 'Refused without consent; account created and linked to the existing profile; signed in to the tenant dashboard';
    });
TestKit::add('ST-04', 'Sign out ends the session', 'Tenant signed in', ['Open the dashboard', 'Sign out', 'Open the dashboard again'],
    'Dashboard opens, then redirects to sign-in after signing out', function () {
        $browser = signed_in('tenant@urbanescape.test');
        $before = $browser->get('/tenant/dashboard.php')['status'];
        $logout = $browser->submit('/api/logout.php', [], [], '/tenant/dashboard.php');
        $after = $browser->get('/tenant/dashboard.php');
        check($before === 200 && str_contains((string)$logout['location'], 'login.php') && str_contains((string)$after['location'], 'login.php'), 'Session survived sign-out');
        return 'Dashboard 200 before; after sign-out it redirects to the sign-in page';
    });
TestKit::add('ST-05', 'Password change in Account Settings', 'Lucy Chebet signed in', ['Submit with a wrong current password', 'Submit a valid change', 'Sign in with the new password'],
    'Wrong current password refused; change saved; new password works', function () use ($base) {
        $browser = signed_in('lucy.chebet@urbanescape.test');
        $wrong = $browser->submit('/account/settings.php', ['current_password' => 'not-my-password', 'new_password' => 'Lucy-New-Pass-2026', 'confirm_password' => 'Lucy-New-Pass-2026']);
        $right = $browser->submit('/account/settings.php', ['current_password' => PW, 'new_password' => 'Lucy-New-Pass-2026', 'confirm_password' => 'Lucy-New-Pass-2026']);
        $login = (new Browser($base))->login('lucy.chebet@urbanescape.test', 'Lucy-New-Pass-2026');
        db()->prepare('UPDATE users SET password_hash = ? WHERE email = ?')->execute([password_hash(PW, PASSWORD_DEFAULT), 'lucy.chebet@urbanescape.test']);
        check(str_contains($wrong['body'], 'current password is incorrect') && str_contains($right['after'], 'Password changed') && $login['status'] === 302, 'Password change flow failed');
        return 'Wrong password refused; "Password changed" shown; new password signed in (test password restored afterwards)';
    });
TestKit::add('ST-06', 'Register a property', 'Property Manager signed in', ['Create "ST Test Court" with valid details', 'Submit the form with the name left empty'],
    'Property created and listed; empty name refused with a field message', function () use (&$state) {
        $browser = signed_in('manager@urbanescape.test');
        $fields = ['_action' => 'save', 'property_type' => 'Apartment', 'landlord_id' => 1, 'location_id' => 1, 'physical_address' => 'Test Road, Kasarani',
            'number_of_units' => 4, 'status' => 'Active'];
        $created = $browser->submit('/admin/properties.php', $fields + ['name' => 'ST Test Court']);
        $empty = $browser->submit('/admin/properties.php', $fields + ['name' => '']);
        $state['property'] = (int)one("SELECT id FROM properties WHERE name = 'ST Test Court'");
        check(str_contains($created['after'], 'Property created.') && $state['property'] > 0 && $empty['status'] === 422 && str_contains($empty['body'], 'Property name is required.'), 'Property form failed');
        return "\"Property created.\" (id {$state['property']}); empty name returned 422 with \"Property name is required.\"";
    });
TestKit::add('ST-07', 'Unit numbers are unique within a property', 'ST-06 property exists', ['Add unit T101', 'Add unit T101 again'],
    'First saved; second refused with a duplicate message', function () use (&$state) {
        $browser = signed_in('manager@urbanescape.test');
        $fields = ['_action' => 'save', 'property_id' => $state['property'], 'unit_number' => 'T101', 'floor' => 'Ground', 'house_type' => '1 Bedroom', 'bedrooms' => 1,
            'bathrooms' => 1, 'monthly_rent' => 14000, 'deposit' => 14000, 'service_charge' => 500, 'status' => 'Vacant', 'water_billing_method' => 'Meter Reading', 'electricity_billing_method' => 'Prepaid'];
        $first = $browser->submit('/admin/units.php', $fields);
        $second = $browser->submit('/admin/units.php', $fields);
        check(str_contains($first['after'], 'Unit created.') && str_contains($second['body'], 'already has a unit with that number'), 'Duplicate unit not refused');
        return 'Unit created; duplicate refused: "This property already has a unit with that number."';
    });
TestKit::add('ST-08', 'A property with history cannot be deleted', 'Sunrise Apartments has units and expenses', ['Delete Sunrise Apartments'],
    'Delete refused with advice to archive; the property remains', function () {
        $browser = signed_in('manager@urbanescape.test');
        $response = $browser->submit('/admin/properties.php', ['_action' => 'delete', 'id' => 1]);
        check(str_contains($response['after'], 'Set its status to Archived') && (int)one('SELECT COUNT(*) FROM properties WHERE id = 1') === 1, 'Delete was not blocked');
        return 'Refused: "This property still has units or expenses. Set its status to Archived instead of deleting it."';
    });
TestKit::add('ST-09', 'A landlord\'s new property is always their own', 'Joseph Kamau (landlord) signed in', ['Create a property while submitting another landlord\'s id in the form'],
    'The property is saved under Joseph Kamau\'s landlord profile', function () {
        $browser = signed_in('landlord@urbanescape.test');
        $browser->submit('/landlord/properties.php', ['_action' => 'save', 'name' => 'Landlord Own Flats', 'property_type' => 'Flats', 'landlord_id' => 2, 'location_id' => 2,
            'physical_address' => 'Test Lane, Roysambu', 'number_of_units' => 2, 'status' => 'Active']);
        $owner = (int)one("SELECT landlord_id FROM properties WHERE name = 'Landlord Own Flats'");
        check($owner === 1, "Saved with landlord_id $owner");
        return 'Saved with landlord_id 1 (Joseph Kamau); the submitted id 2 was ignored';
    });
TestKit::add('ST-10', 'Tenant registration by staff, national ID unique', 'Property Manager signed in', ['Create tenant "Samuel Test" with ID 40000010', 'Create another tenant with the same ID'],
    'First created; second refused', function () use (&$state) {
        $browser = signed_in('manager@urbanescape.test');
        $fields = ['_action' => 'save', 'full_name' => 'Samuel Test', 'national_id' => '40000010', 'phone' => '+254700400010', 'email' => 'samuel.test@urbanescape.test', 'gender' => 'Male', 'status' => 'Active'];
        $first = $browser->submit('/admin/tenants.php', $fields);
        $second = $browser->submit('/admin/tenants.php', $fields);
        $state['tenant'] = (int)one("SELECT id FROM tenants WHERE national_id = '40000010'");
        check(str_contains($first['after'], 'Tenant created.') && str_contains($second['body'], 'national ID already exists'), 'Duplicate ID not refused');
        return 'Tenant created; duplicate refused: "A tenant with that national ID already exists."';
    });
TestKit::add('ST-11', 'Activating a lease occupies the unit', 'Unit A104 vacant; ST-10 tenant exists', ['Create an Active lease for Samuel Test on A104'],
    'Lease saved with the unit\'s rent and deposit; A104 becomes Occupied', function () use (&$state) {
        $browser = signed_in('manager@urbanescape.test');
        $response = $browser->submit('/admin/leases.php', ['_action' => 'save', 'tenant_id' => $state['tenant'], 'unit_id' => unit_id('A104'), 'start_date' => '2026-11-01',
            'end_date' => '2027-10-31', 'payment_due_day' => 5, 'notice_period_days' => 30, 'status' => 'Active']);
        $state['lease'] = (int)one('SELECT id FROM leases WHERE tenant_id = ?', [$state['tenant']]);
        $lease = row('SELECT monthly_rent, deposit FROM leases WHERE id = ?', [$state['lease']]);
        $unit = one('SELECT status FROM units WHERE id = ?', [unit_id('A104')]);
        check(str_contains($response['after'], 'Lease created.') && $unit === 'Occupied' && (float)$lease['monthly_rent'] === 16000.0, 'Lease or occupancy wrong');
        return 'Lease created with rent KSh 16,000 and deposit KSh 16,000 from the unit; A104 is Occupied';
    });
TestKit::add('ST-12', 'A unit cannot have two active leases', 'A104 has an active lease (ST-11)', ['Create another Active lease on A104 for Mercy Wairimu'],
    'Refused with a message', function () {
        $browser = signed_in('manager@urbanescape.test');
        $response = $browser->submit('/admin/leases.php', ['_action' => 'save', 'tenant_id' => (int)one("SELECT id FROM tenants WHERE full_name = 'Mercy Wairimu'"), 'unit_id' => unit_id('A104'),
            'start_date' => '2026-11-01', 'end_date' => '2027-10-31', 'payment_due_day' => 5, 'notice_period_days' => 30, 'status' => 'Active']);
        check(str_contains(text_of($response['body']), 'That unit already has an active lease'), 'Second active lease was not refused');
        return 'Refused: "That unit already has an active lease. End it before activating a new one."';
    });
TestKit::add('ST-13', 'Lease dates are checked', 'Property Manager signed in', ['Create a lease ending before it starts'],
    'Refused with a message', function () use (&$state) {
        $browser = signed_in('manager@urbanescape.test');
        $response = $browser->submit('/admin/leases.php', ['_action' => 'save', 'tenant_id' => $state['tenant'], 'unit_id' => unit_id('B203'), 'start_date' => '2026-12-01',
            'end_date' => '2026-11-01', 'payment_due_day' => 5, 'notice_period_days' => 30, 'status' => 'Pending']);
        check(str_contains($response['body'], 'The end date must be after the start date.'), 'Bad dates accepted');
        return 'Refused: "The end date must be after the start date."';
    });
TestKit::add('ST-14', 'Generate monthly charges for December 2026', 'Active leases exist; no December charges yet', ['Generate charges for 2026-12', 'Generate again'],
    'Charges created once; the repeat creates none', function () {
        $browser = signed_in('accountant@urbanescape.test');
        $first = text_of($browser->submit('/admin/rent-charges.php', ['_action' => 'generate', 'period' => '2026-12'])['after']);
        $second = text_of($browser->submit('/admin/rent-charges.php', ['_action' => 'generate', 'period' => '2026-12'])['after']);
        preg_match('/Created (\d+) charge\(s\) for Dec 2026/', $first, $created);
        check(($created[1] ?? 0) > 0 && str_contains($second, 'Created 0 charge(s)') && str_contains($second, 'already charged'), 'Generation messages unexpected');
        return "First: \"Created {$created[1]} charge(s) for Dec 2026.\"; second: 0 created, leases already charged were skipped";
    });
TestKit::add('ST-15', 'Record a payment and issue a receipt', 'Brian Mutua owes rent', ['Record KSh 5,000 by M-Pesa with reference ST15REF001'],
    'Payment saved with a receipt number; KSh 5,000 more allocated to his charges', function () use (&$state) {
        $lease = lease_of('Brian Mutua');
        $allocated = fn() => (float)one('SELECT COALESCE(SUM(pa.allocated_amount),0) FROM payment_allocations pa JOIN rent_charges rc ON rc.id = pa.rent_charge_id WHERE rc.lease_id = ?', [$lease]);
        $before = $allocated();
        $browser = signed_in('accountant@urbanescape.test');
        $response = $browser->submit('/admin/payments.php', ['_action' => 'save', 'lease_id' => $lease, 'amount' => 5000, 'payment_method' => 'M-Pesa',
            'transaction_reference' => 'ST15REF001', 'payment_date' => date('Y-m-d'), 'payment_period' => '2026-11']);
        $payment = row("SELECT id, receipt_number FROM payments WHERE transaction_reference = 'ST15REF001'");
        $state['payment'] = (int)($payment['id'] ?? 0);
        check(str_contains($response['after'], 'Payment created.') && preg_match('/^REC-\d{8}-[0-9A-F]{8}$/', $payment['receipt_number'] ?? '') && abs($allocated() - $before - 5000) < 0.01, 'Payment not recorded correctly');
        return "\"Payment created.\"; receipt {$payment['receipt_number']}; allocations rose by KSh 5,000";
    });
TestKit::add('ST-16', 'Duplicate transaction references are refused', 'ST-15 payment exists', ['Record another payment with reference ST15REF001'],
    'Refused with a duplicate-payment message', function () {
        $browser = signed_in('accountant@urbanescape.test');
        $response = $browser->submit('/admin/payments.php', ['_action' => 'save', 'lease_id' => lease_of('Brian Mutua'), 'amount' => 100, 'payment_method' => 'M-Pesa',
            'transaction_reference' => 'ST15REF001', 'payment_date' => date('Y-m-d'), 'payment_period' => '2026-11']);
        check(str_contains($response['body'], 'already been recorded'), 'Duplicate reference accepted');
        return 'Refused: "That transaction reference has already been recorded. Check for a duplicate payment."';
    });
TestKit::add('ST-17', 'Payments cannot be dated in the future', 'Accountant signed in', ['Record a payment dated tomorrow'],
    'Refused with a message', function () {
        $browser = signed_in('accountant@urbanescape.test');
        $response = $browser->submit('/admin/payments.php', ['_action' => 'save', 'lease_id' => lease_of('Brian Mutua'), 'amount' => 100, 'payment_method' => 'Cash',
            'transaction_reference' => 'ST17FUTURE', 'payment_date' => date('Y-m-d', strtotime('+1 day')), 'payment_period' => '2026-11']);
        check(str_contains($response['body'], 'cannot be in the future'), 'Future payment accepted');
        return 'Refused: "The payment date cannot be in the future."';
    });
TestKit::add('ST-18', 'Only Super Admin and Accountant may delete payments', 'ST-15 payment exists', ['As Property Manager, open Payments and post a delete', 'As Accountant, delete the payment'],
    'No Delete button and refusal for the manager; deletion and rebalancing for the accountant', function () use (&$state) {
        $manager = signed_in('manager@urbanescape.test');
        $list = $manager->open('/admin/payments.php')['body'];
        $refused = $manager->submit('/admin/payments.php', ['_action' => 'delete', 'id' => $state['payment']]);
        $accountant = signed_in('accountant@urbanescape.test');
        $deleted = $accountant->submit('/admin/payments.php', ['_action' => 'delete', 'id' => $state['payment']]);
        check(!str_contains($list, 'value="delete"') && str_contains($refused['after'], 'do not have permission') && str_contains($deleted['after'], 'Payment deleted.')
            && (int)one('SELECT COUNT(*) FROM payments WHERE id = ?', [$state['payment']]) === 0, 'Delete permissions wrong');
        return 'Manager: no Delete button, post refused; Accountant: "Payment deleted." and balances recalculated';
    });
TestKit::add('ST-19', 'Printable receipt', 'A fixture payment exists for Jane Njeri', ['Open the receipt of her latest payment'],
    'Receipt shows the receipt number, tenant and amount', function () {
        $payment = row("SELECT p.id, p.receipt_number, p.amount FROM payments p JOIN tenants t ON t.id = p.tenant_id WHERE t.full_name = 'Jane Njeri' ORDER BY p.payment_date DESC LIMIT 1");
        $page = signed_in('accountant@urbanescape.test')->open('/admin/receipt.php?id=' . $payment['id'])['body'];
        check(str_contains($page, $payment['receipt_number']) && str_contains($page, 'Jane Njeri') && str_contains($page, format_currency($payment['amount'])), 'Receipt incomplete');
        return "Receipt {$payment['receipt_number']} for Jane Njeri, " . format_currency($payment['amount']);
    });
TestKit::add('ST-20', 'Meter readings are checked and costed', 'Unit A101 has a November water reading', ['Record a December reading lower than the last one', 'Record a reading 9 m³ higher'],
    'Lower reading refused; valid reading stores 9 m³ and KSh 1,350 at the default rate', function () {
        $last = (float)one('SELECT current_reading FROM utility_readings WHERE unit_id = 1 AND utility_type_id = 1 ORDER BY billing_period DESC LIMIT 1');
        $browser = signed_in('accountant@urbanescape.test');
        $fields = ['_action' => 'save', 'unit_id' => 1, 'utility_type_id' => 1, 'billing_period' => '2026-12', 'reading_date' => date('Y-m-d')];
        $low = $browser->submit('/admin/utility-readings.php', $fields + ['current_reading' => $last - 5]);
        $ok = $browser->submit('/admin/utility-readings.php', $fields + ['current_reading' => $last + 9]);
        $saved = row("SELECT consumption, total_amount FROM utility_readings WHERE unit_id = 1 AND billing_period = '2026-12'");
        check(str_contains($low['body'], 'lower than the previous reading') && str_contains($ok['after'], 'Utility reading created.') && (float)$saved['consumption'] === 9.0 && (float)$saved['total_amount'] === 1350.0, 'Reading rules failed');
        return 'Lower reading refused; valid reading saved: 9 m³, KSh 1,350.00';
    });
TestKit::add('ST-21', 'Send arrears reminders', 'Tenants with overdue balances exist', ['Press Send reminders on Rent Charges'],
    'Reminders sent; Kevin Omondi receives an "Rent arrears reminder" notice', function () {
        $response = signed_in('accountant@urbanescape.test')->submit('/admin/rent-charges.php', ['_action' => 'remind']);
        preg_match('/Sent (\d+) arrears reminder/', text_of($response['after']), $sent);
        $kevin = (int)one("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND title = 'Rent arrears reminder'", [user_id('kevin.omondi@urbanescape.test')]);
        check(($sent[1] ?? 0) >= 1 && $kevin === 1, 'Reminders not sent');
        return "\"Sent {$sent[1]} arrears reminder(s).\"; Kevin Omondi notified";
    });
TestKit::add('ST-22', 'Tenant reports a repair with a photo', 'Brian Mutua signed in, active lease', ['Submit an Electrical request with a JPG photo', 'Open the photo as the Property Manager'],
    'Request saved with photo and a "Submitted" history entry; manager can view the photo', function () use (&$state, $photo) {
        $tenant = signed_in('brian.mutua@urbanescape.test');
        $response = $tenant->submit('/tenant/maintenance.php', ['category' => 'Electrical', 'priority' => 'High', 'description' => 'ST-22 kitchen light flickers'], ['photo' => $photo]);
        $request = row("SELECT id, image_path FROM maintenance_requests WHERE description = 'ST-22 kitchen light flickers'");
        $state['request'] = (int)($request['id'] ?? 0);
        $history = one('SELECT status FROM maintenance_updates WHERE request_id = ? ORDER BY id LIMIT 1', [$state['request']]);
        $image = signed_in('manager@urbanescape.test')->get('/account/attachment.php?type=maintenance&id=' . $state['request']);
        check(str_contains($response['after'], 'Maintenance request submitted') && !empty($request['image_path']) && $history === 'Submitted'
            && $image['status'] === 200 && str_contains($image['headers'], 'image/jpeg'), 'Request, history or photo missing');
        return "Request #{$state['request']} saved with photo; history starts \"Submitted\"; manager received the image (200, image/jpeg)";
    });
TestKit::add('ST-23', 'Assign a repair to a caretaker', 'ST-22 request is Submitted', ['Edit the request and assign John Mwangi (caretaker)', 'Open the caretaker dashboard'],
    'Status becomes Assigned; caretaker sees the job; tenant and caretaker are notified', function () use (&$state) {
        $manager = signed_in('manager@urbanescape.test');
        $manager->submit('/admin/maintenance.php', ['_action' => 'save', 'id' => $state['request'], 'tenant_id' => (int)one("SELECT id FROM tenants WHERE full_name = 'Brian Mutua'"),
            'category' => 'Electrical', 'priority' => 'High', 'status' => 'Submitted', 'assigned_staff' => 4, 'estimated_cost' => 800, 'actual_cost' => 0, 'description' => 'ST-22 kitchen light flickers']);
        $status = one('SELECT status FROM maintenance_requests WHERE id = ?', [$state['request']]);
        $board = signed_in('caretaker@urbanescape.test')->open('/caretaker/dashboard.php')['body'];
        $notices = (int)one("SELECT COUNT(*) FROM notifications WHERE (user_id = 4 AND title = 'New maintenance assignment') OR (user_id = ? AND title = 'Maintenance request update')", [user_id('brian.mutua@urbanescape.test')]);
        check($status === 'Assigned' && str_contains($board, 'ST-22 kitchen light flickers') && $notices >= 2, "Status $status, notices $notices");
        return 'Status Assigned; job listed on the caretaker dashboard; caretaker and tenant notified';
    });
TestKit::add('ST-24', 'Caretaker completes the repair', 'ST-23 request assigned to John Mwangi', ['As the caretaker, set status Completed with cost KSh 850'],
    'Completion date and cost saved; history ends "Completed"; tenant notified', function () use (&$state) {
        $caretaker = signed_in('caretaker@urbanescape.test');
        $caretaker->submit('/caretaker/dashboard.php', ['request_id' => $state['request'], 'status' => 'Completed', 'actual_cost' => 850]);
        $request = row('SELECT status, actual_cost, completed_date FROM maintenance_requests WHERE id = ?', [$state['request']]);
        $last = row('SELECT status, note FROM maintenance_updates WHERE request_id = ? ORDER BY id DESC LIMIT 1', [$state['request']]);
        check($request['status'] === 'Completed' && (float)$request['actual_cost'] === 850.0 && $request['completed_date'] && $last['status'] === 'Completed', 'Completion not recorded');
        return "Completed on {$request['completed_date']} at KSh 850; history note: \"{$last['note']}\"";
    });
TestKit::add('ST-25', 'Tenants may cancel only untouched requests', 'Brian has a Submitted request; Jane\'s request is In Progress', ['Brian cancels his Submitted request', 'Jane tries to cancel her In Progress request'],
    'Brian\'s request is Cancelled; Jane\'s is refused', function () {
        $brianRequest = (int)one("SELECT id FROM maintenance_requests WHERE description LIKE 'The socket next to the bed%'");
        $brian = signed_in('brian.mutua@urbanescape.test')->submit('/tenant/maintenance.php', ['_action' => 'cancel', 'request_id' => $brianRequest]);
        $janeRequest = (int)one("SELECT id FROM maintenance_requests WHERE description LIKE 'The kitchen sink%'");
        $jane = signed_in('tenant@urbanescape.test')->submit('/tenant/maintenance.php', ['_action' => 'cancel', 'request_id' => $janeRequest]);
        check(str_contains($brian['after'], 'Request cancelled.') && str_contains($jane['after'], 'can no longer be cancelled'), 'Cancellation rules failed');
        return 'Brian: "Request cancelled."; Jane: "That request can no longer be cancelled."';
    });
TestKit::add('ST-26', 'Record a move-in condition report', 'Mercy Wairimu has a Pending lease and a login (ST-03)', ['Create a Move-in report with a photo of the kitchen fittings', 'Create a second Move-in report for the same lease'],
    'Report saved with 8 checklist lines and a photo; tenant notified; duplicate refused', function () use (&$state, $photo) {
        $manager = signed_in('manager@urbanescape.test');
        $fields = ['_action' => 'save', 'lease_id' => lease_of('Mercy Wairimu'), 'report_type' => 'Move-in', 'inspection_date' => date('Y-m-d'), 'overall_condition' => 'Good',
            'general_notes' => 'ST-26 inspection', 'item_condition' => array_fill(0, 8, 'Good'), 'remarks' => array_fill(0, 8, '')];
        $fields['item_condition'][4] = 'Fair';
        $first = $manager->submit('/admin/condition-reports.php', $fields, ['photo[4]' => $photo], '/admin/condition-reports.php?action=create');
        $state['report'] = (int)one("SELECT id FROM condition_reports WHERE general_notes = 'ST-26 inspection'");
        $items = (int)one('SELECT COUNT(*) FROM condition_report_items WHERE report_id = ?', [$state['report']]);
        $photos = (int)one('SELECT COUNT(*) FROM condition_report_items WHERE report_id = ? AND photo_path IS NOT NULL', [$state['report']]);
        $notified = (int)one("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND title = 'Move-in condition report ready'", [user_id('mercy.wairimu@urbanescape.test')]);
        $duplicate = $manager->submit('/admin/condition-reports.php', $fields, [], '/admin/condition-reports.php?action=create');
        check(str_contains($first['after'], 'report saved') && $items === 8 && $photos === 1 && $notified === 1 && str_contains($duplicate['body'], 'already has a Move-in report'), "Items $items, photos $photos, notified $notified");
        return "Report #{$state['report']} saved with 8 lines and 1 photo; tenant notified; duplicate refused";
    });
TestKit::add('ST-27', 'Tenant disputes a condition report', 'ST-26 report awaits Mercy\'s response', ['Choose Dispute with no comment', 'Choose Dispute with a comment'],
    'First refused; second recorded and management notified', function () use (&$state) {
        $mercy = signed_in('mercy.wairimu@urbanescape.test');
        $blank = $mercy->submit('/tenant/deposit.php', ['report_id' => $state['report'], 'response' => 'Disputed', 'comment' => '']);
        $done = $mercy->submit('/tenant/deposit.php', ['report_id' => $state['report'], 'response' => 'Disputed', 'comment' => 'The kitchen cabinet was already loose.']);
        $saved = one('SELECT tenant_response FROM condition_reports WHERE id = ?', [$state['report']]);
        $alerted = (int)one("SELECT COUNT(*) FROM notifications WHERE user_id = 2 AND title = ?", ["Condition report #{$state['report']} disputed"]);
        check(str_contains($blank['after'], 'Explain what you disagree with') && str_contains($done['after'], 'dispute has been recorded') && $saved === 'Disputed' && $alerted === 1, 'Dispute flow failed');
        return 'Blank dispute refused; dispute saved as "Disputed"; Property Manager notified';
    });
TestKit::add('ST-28', 'Move-out report is compared with move-in', 'Daniel Kiptoo has move-in and move-out reports', ['Open the move-out report'],
    'Page shows an "At move-in" column and flags the two areas that got worse', function () {
        $id = (int)one("SELECT cr.id FROM condition_reports cr JOIN leases l ON l.id = cr.lease_id JOIN tenants t ON t.id = l.tenant_id WHERE t.full_name = 'Daniel Kiptoo' AND cr.report_type = 'Move-out'");
        $page = signed_in('manager@urbanescape.test')->open("/admin/condition-reports.php?action=view&id=$id")['body'];
        $flags = substr_count($page, 'Worse than at move-in');
        check(str_contains($page, 'At move-in') && $flags === 2, "Flags: $flags");
        return 'Comparison column shown; 2 areas flagged "Worse than at move-in" (windows, walls)';
    });
TestKit::add('ST-29', 'Deposit deduction needs a reason and is shown to the tenant', 'Daniel Kiptoo\'s deposit is held; move-out report exists', ['Record a KSh 1,000 deduction with no reason', 'Record it with a reason linked to the move-out report'],
    'First refused; second saved and Daniel notified', function () {
        $lease = lease_of('Daniel Kiptoo');
        $report = (int)one("SELECT id FROM condition_reports WHERE lease_id = ? AND report_type = 'Move-out'", [$lease]);
        $browser = signed_in('accountant@urbanescape.test');
        $fields = ['_action' => 'save', 'lease_id' => $lease, 'transaction_type' => 'Deduction', 'amount' => 1000, 'transaction_date' => date('Y-m-d'), 'condition_report_id' => $report];
        $noReason = $browser->submit('/admin/deposits.php', $fields);
        $saved = $browser->submit('/admin/deposits.php', $fields + ['reason' => 'ST-29 replace lost gate card']);
        $notified = (int)one("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND title = 'Deposit update'", [user_id('daniel.kiptoo@urbanescape.test')]);
        check(str_contains($noReason['body'], 'Give the reason for the deduction') && str_contains($saved['after'], 'Deposit entry created.') && $notified === 1, 'Deduction flow failed');
        return 'No-reason deduction refused; deduction saved and linked to the move-out report; Daniel notified';
    });
TestKit::add('ST-30', 'Deductions cannot exceed the deposit held', 'Daniel Kiptoo\'s held balance is below KSh 50,000', ['Record a KSh 50,000 deduction'],
    'Refused with the amount still held', function () {
        $browser = signed_in('accountant@urbanescape.test');
        $response = $browser->submit('/admin/deposits.php', ['_action' => 'save', 'lease_id' => lease_of('Daniel Kiptoo'), 'transaction_type' => 'Deduction', 'amount' => 50000,
            'transaction_date' => date('Y-m-d'), 'reason' => 'ST-30 test']);
        $message = preg_match('/Deductions and refunds cannot exceed the KSh [\d,.]+ still held/', text_of($response['body']), $m) ? $m[0] : '';
        check($message !== '', 'Over-deduction accepted');
        return "Refused: \"$message for this lease.\"";
    });
TestKit::add('ST-31', 'Public listings show vacant units and filter by rent', 'Several properties have vacant units', ['Open Available Properties', 'Filter with maximum rent KSh 10,000'],
    'Listings shown; the filter keeps Green View Residences and removes Lavington Court Homes', function () use ($base) {
        $browser = new Browser($base);
        $all = $browser->open('/public/properties.php')['body'];
        $cheap = $browser->open('/public/properties.php?max_rent=10000')['body'];
        check(str_contains($all, 'Lavington Court Homes') && str_contains($cheap, 'Green View Residences') && !str_contains($cheap, 'Lavington Court Homes'), 'Filter result unexpected');
        return 'Unfiltered list includes Lavington Court Homes; with max KSh 10,000 only cheaper listings such as Green View Residences remain';
    });
TestKit::add('ST-32', 'Visitor requests a viewing', 'Kileleshwa Ridge Villas is active with vacant units', ['Submit the viewing form for next week'],
    'Request stored as Pending; managers notified', function () use ($base) {
        $browser = new Browser($base);
        $response = $browser->submit('/public/property-details.php?id=5', ['visitor_name' => 'ST Visitor', 'visitor_phone' => '+254700500032', 'visitor_email' => 'st.visitor@example.com',
            'preferred_date' => date('Y-m-d', strtotime('+7 days')), 'preferred_time' => '10:30', 'message' => 'ST-32']);
        $status = one("SELECT status FROM viewing_requests WHERE visitor_name = 'ST Visitor'");
        check(str_contains($response['body'], 'Viewing request submitted') && $status === 'Pending' && (int)one("SELECT COUNT(*) FROM notifications WHERE user_id = 2 AND message LIKE 'ST Visitor%'") === 1, 'Viewing not stored');
        return 'Confirmation shown; request stored as Pending; Property Manager notified';
    });
TestKit::add('ST-33', 'Contact enquiry reaches the back office', 'Contact page available', ['Send a message from the Contact page', 'Open Enquiries as the Property Manager'],
    'Enquiry stored as New and listed in Enquiries', function () use ($base) {
        $visitor = new Browser($base);
        $visitor->submit('/public/contact.php', ['name' => 'ST Enquirer', 'email' => 'st.enquirer@example.com', 'phone' => '', 'message' => 'ST-33 Do you have parking?', 'website' => '']);
        $list = signed_in('manager@urbanescape.test')->open('/admin/enquiries.php')['body'];
        check(one("SELECT status FROM enquiries WHERE full_name = 'ST Enquirer'") === 'New' && str_contains($list, 'ST Enquirer'), 'Enquiry missing');
        return 'Enquiry stored with status New and listed for the Property Manager';
    });
TestKit::add('ST-34', 'All nine reports open and export to CSV', 'Accountant signed in', ['Open each report', 'Download each as CSV'],
    'Every report shows its title; every CSV starts with the column headings', function () {
        $browser = signed_in('accountant@urbanescape.test');
        $done = [];
        foreach (report_catalog() as $key => $definition) {
            $query = $key === 'statement' ? '&tenant_id=1' : '';
            $page = $browser->open("/admin/reports.php?report=$key$query")['body'];
            $csv = $browser->get("/admin/reports.php?report=$key$query&format=csv");
            $firstLine = strtok(ltrim($csv['body'], "\xEF\xBB\xBF"), "\n");
            check(str_contains($page, $definition['title']) && str_contains($csv['headers'], 'text/csv') && $firstLine !== '' && $firstLine !== false, "Report $key failed");
            $done[] = $definition['title'];
        }
        return count($done) . ' reports opened and exported: ' . implode(', ', $done);
    });
TestKit::add('ST-35', 'Landlord reports cover only their own properties', 'Green View Residences belongs to Mary Wambui', ['As Joseph Kamau, open the occupancy report', 'Ask for Green View by property id'],
    'Joseph\'s properties appear; Green View never does', function () {
        $browser = signed_in('landlord@urbanescape.test');
        $mine = $browser->open('/landlord/reports.php?report=occupancy')['body'];
        $other = $browser->open('/landlord/reports.php?report=occupancy&property_id=2')['body'];
        check(str_contains($mine, 'Sunrise Apartments') && !str_contains($mine, 'Green View Residences') && !str_contains($other, 'Green View Residences'), 'Landlord saw another landlord\'s property');
        return 'Sunrise Apartments listed; Green View Residences absent, even when requested by id';
    });
TestKit::add('ST-36', 'Notice to all tenants', 'Property Manager signed in', ['Send "ST-36 Lift service" to all active tenants'],
    'Notice delivered to each tenant with a login, including Jane Njeri', function () {
        $response = signed_in('manager@urbanescape.test')->submit('/admin/notices.php', ['_action' => 'send', 'audience' => 'all_tenants', 'property_id' => '', 'user_id' => '',
            'title' => 'ST-36 Lift service', 'message' => 'The lift will be serviced on Monday.']);
        preg_match('/Notice sent to (\d+) recipient/', text_of($response['after']), $sent);
        $jane = (int)one("SELECT COUNT(*) FROM notifications WHERE user_id = 6 AND title = 'ST-36 Lift service'");
        check(($sent[1] ?? 0) > 0 && $jane === 1, 'Notice not delivered');
        return "\"Notice sent to {$sent[1]} recipient(s).\"; Jane Njeri received it";
    });
TestKit::add('ST-37', 'Audit log shows sign-ins and changes, for Super Admin only', 'Earlier tests produced audit entries', ['As Super Admin, filter the Audit Log by action LOGIN, then by UPDATE', 'Open it as Property Manager'],
    'Super Admin sees LOGIN entries and UPDATE entries with old → new values; manager is refused', function () {
        $admin = signed_in('admin@urbanescape.test');
        $logins = $admin->open('/admin/audit-log.php?action=LOGIN')['body'];
        $updates = $admin->open('/admin/audit-log.php?action=UPDATE')['body'];
        $manager = signed_in('manager@urbanescape.test')->get('/admin/audit-log.php');
        $update = (int)one("SELECT COUNT(*) FROM audit_logs WHERE action = 'UPDATE' AND new_values IS NOT NULL");
        check(str_contains($logins, 'Signed in') && str_contains($updates, '→') && $update > 0 && str_contains((string)$manager['location'], 'forbidden'),
            'LOGIN entries: ' . (str_contains($logins, 'Signed in') ? 'yes' : 'no') . '; arrow shown: ' . (str_contains($updates, '→') ? 'yes' : 'no'));
        return "Entries listed with before → after values ($update updates logged); Property Manager redirected as forbidden";
    });

// ===========================================================================
// SEC: security tests aligned with the OWASP Top 10 (2021)
// ===========================================================================

TestKit::add('SEC-01', 'SQL injection in the sign-in form (A03)', 'Sign-in page open', ['Sign in with email "\' OR \'1\'=\'1" and the same password', 'Sign in with "admin@urbanescape.test\'-- "'],
    'Both refused like any wrong password; no database error', function () use ($base) {
        $a = (new Browser($base))->login("' OR '1'='1", "' OR '1'='1");
        $b = (new Browser($base))->login("admin@urbanescape.test'-- ", 'x');
        check($a['status'] === 200 && $b['status'] === 200 && !str_contains($a['body'] . $b['body'], 'SQL'), 'Injection changed behaviour');
        return 'Both attempts returned the normal sign-in error; no SQL error text';
    });
TestKit::add('SEC-02', 'SQL injection in list search (A03)', 'Property Manager signed in', ['Search tenants for "\' OR 1=1 -- "'],
    'Treated as plain text: no rows and no error', function () {
        $page = signed_in('manager@urbanescape.test')->open('/admin/tenants.php?q=' . urlencode("' OR 1=1 -- "))['body'];
        check(str_contains($page, 'No tenants found') && !str_contains($page, 'SQLSTATE'), 'Search was injectable');
        return '"No tenants found for these filters."; no SQL error';
    });
TestKit::add('SEC-03', 'Stored cross-site scripting is escaped (A03)', 'Jane Njeri signed in', ['Submit a complaint whose subject is <script>alert(1)</script>', 'Open Complaints as the Property Manager'],
    'The subject is shown as text (&lt;script&gt;), never as a script tag', function () {
        signed_in('tenant@urbanescape.test')->submit('/tenant/complaints.php', ['category' => 'Other', 'subject' => '<script>alert(1)</script>', 'message' => 'SEC-03']);
        $page = signed_in('manager@urbanescape.test')->open('/admin/complaints.php')['body'];
        check(str_contains($page, '&lt;script&gt;alert(1)&lt;/script&gt;') && !str_contains($page, '<script>alert(1)</script>'), 'Script not escaped');
        return 'Rendered as &lt;script&gt;alert(1)&lt;/script&gt;';
    });
TestKit::add('SEC-04', 'Cross-site request forgery is blocked (A01)', 'Property Manager signed in', ['Post a new property without a CSRF token'],
    'Request refused; nothing saved', function () {
        $browser = signed_in('manager@urbanescape.test');
        $response = $browser->submit('/admin/properties.php', ['csrf_token' => 'forged', '_action' => 'save', 'name' => 'SEC-04 Forged', 'property_type' => 'Other', 'landlord_id' => 1,
            'location_id' => 1, 'physical_address' => 'x', 'number_of_units' => 1, 'status' => 'Active']);
        check(str_contains($response['after'], 'Your session expired') && (int)one("SELECT COUNT(*) FROM properties WHERE name = 'SEC-04 Forged'") === 0, 'Forged request accepted');
        return '"Your session expired. Please try again."; no property created';
    });
TestKit::add('SEC-05', 'Forced browsing to staff pages is refused (A01)', 'Tenant and Accountant signed in', ['Tenant opens /admin/payments.php', 'Accountant opens /admin/audit-log.php and /admin/users.php'],
    'Each request is redirected as forbidden', function () {
        $tenant = signed_in('tenant@urbanescape.test')->get('/admin/payments.php');
        $accountant = signed_in('accountant@urbanescape.test');
        $audit = $accountant->get('/admin/audit-log.php');
        $users = $accountant->get('/admin/users.php');
        check(str_contains((string)$tenant['location'], 'forbidden') && str_contains((string)$audit['location'], 'forbidden') && str_contains((string)$users['location'], 'forbidden'), 'Access not refused');
        return 'All three redirected to /public/index.php?error=forbidden';
    });
TestKit::add('SEC-06', 'A landlord cannot open another landlord\'s property (A01)', 'Green View Residences belongs to Mary Wambui', ['As Joseph Kamau, open /landlord/properties.php?action=edit&id=2', 'Post a change to id 2'],
    '"Property not found." both times; the record is unchanged', function () {
        $browser = signed_in('landlord@urbanescape.test');
        $view = $browser->get('/landlord/properties.php?action=edit&id=2');
        $after = $browser->open((string)$view['location'])['body'];
        $post = $browser->submit('/landlord/properties.php', ['_action' => 'save', 'id' => 2, 'name' => 'Hijacked', 'property_type' => 'Flats', 'location_id' => 2, 'physical_address' => 'x', 'number_of_units' => 1, 'status' => 'Active']);
        check(str_contains($after, 'Property not found.') && str_contains($post['after'], 'Property not found.') && one('SELECT name FROM properties WHERE id = 2') === 'Green View Residences', 'Landlord reached another property');
        return 'Both returned "Property not found."; Green View Residences unchanged';
    });
TestKit::add('SEC-07', 'A script disguised as a photo is rejected (A04)', 'Kevin Omondi signed in', ['Upload a PHP file renamed to photo.jpg with a maintenance request'],
    'Upload refused by the content check; no request saved', function () {
        $fake = tempnam(sys_get_temp_dir(), 'sec07');
        file_put_contents($fake, "<?php echo 'owned'; ?>");
        $response = signed_in('kevin.omondi@urbanescape.test')->submit('/tenant/maintenance.php', ['category' => 'Other', 'priority' => 'Low', 'description' => 'SEC-07 upload'],
            ['photo' => ['path' => $fake, 'mime' => 'image/jpeg', 'name' => 'photo.jpg']]);
        unlink($fake);
        check(str_contains($response['body'], 'does not match an allowed type') && (int)one("SELECT COUNT(*) FROM maintenance_requests WHERE description = 'SEC-07 upload'") === 0, 'Disguised file accepted');
        return 'Refused: "Photo: The file content does not match an allowed type."; nothing saved';
    });
TestKit::add('SEC-08', 'Passwords are stored only as hashes (A02)', 'Test accounts exist', ['Inspect every password_hash in the users table'],
    'All are 60-character bcrypt hashes; none equals the password', function () {
        $hashes = db()->query('SELECT password_hash FROM users')->fetchAll(PDO::FETCH_COLUMN);
        $bcrypt = count(array_filter($hashes, fn($h) => str_starts_with($h, '$2y$') && strlen($h) === 60 && $h !== PW));
        check($bcrypt === count($hashes), "$bcrypt of " . count($hashes) . ' are bcrypt');
        return "All $bcrypt hashes are bcrypt (\$2y\$, 60 characters)";
    });
TestKit::add('SEC-09', 'Session identifier changes at sign-in (A07)', 'Sign-in page open', ['Note the session cookie', 'Sign in', 'Compare the cookie'],
    'A new session identifier is issued', function () use ($base) {
        $browser = new Browser($base);
        $browser->get('/public/login.php');
        $before = $browser->cookie('PHPSESSID');
        $browser->login('tenant@urbanescape.test', PW);
        $after = $browser->cookie('PHPSESSID');
        check($before && $after && $before !== $after, 'Session id unchanged');
        return 'Identifier changed from ' . substr($before, 0, 6) . '… to ' . substr($after, 0, 6) . '…';
    });
TestKit::add('SEC-10', 'Sign-out cannot be triggered by a link (A01)', 'Tenant signed in', ['Request /api/logout.php with GET', 'Open the dashboard'],
    '405 response; the session is still active', function () {
        $browser = signed_in('tenant@urbanescape.test');
        $logout = $browser->get('/api/logout.php');
        $still = $browser->get('/tenant/dashboard.php')['status'];
        check($logout['status'] === 405 && $still === 200, 'GET logout accepted');
        return 'GET returned 405; dashboard still 200';
    });
TestKit::add('SEC-11', 'Repeated failed sign-ins are throttled (A07)', 'No recent failures for Esther Akinyi', ['Enter a wrong password 5 times', 'Enter the correct password'],
    'The correct password is refused for 15 minutes with a clear message', function () use ($base) {
        $browser = new Browser($base);
        for ($i = 0; $i < 5; $i++) $browser->login('esther.akinyi@urbanescape.test', 'wrong-' . $i);
        $blocked = $browser->login('esther.akinyi@urbanescape.test', PW);
        db()->prepare('DELETE FROM login_attempts WHERE email = ?')->execute(['esther.akinyi@urbanescape.test']);
        check($blocked['status'] === 200 && str_contains($blocked['body'], 'Too many failed sign-in attempts'), 'Not throttled');
        return 'Sixth attempt refused: "Too many failed sign-in attempts. Please wait 15 minutes and try again." (attempts cleared afterwards)';
    });
TestKit::add('SEC-12', 'Private photos are served only to entitled users (A01)', 'Jane Njeri\'s request has a photo', ['Kevin Omondi requests the photo', 'Jane requests it'],
    '404 for Kevin; the image for Jane', function () {
        $id = (int)one("SELECT id FROM maintenance_requests WHERE description LIKE 'The kitchen sink%'");
        $kevin = signed_in('kevin.omondi@urbanescape.test')->get("/account/attachment.php?type=maintenance&id=$id")['status'];
        $jane = signed_in('tenant@urbanescape.test')->get("/account/attachment.php?type=maintenance&id=$id")['status'];
        check($kevin === 404 && $jane === 200, "Kevin $kevin, Jane $jane");
        return 'Kevin: 404; Jane: 200 with the image';
    });
TestKit::add('SEC-13', 'Web server blocks configuration, code and private uploads (A05)', 'Application served by Apache with its .htaccess rules', ['Request config/database.php, includes/crud.php, README.md, .gitignore, the uploads/maintenance and uploads/condition folders, and a sample file in uploads/'],
    'Every request is refused with 403', function () use ($apache) {
        if (!$apache) return 'NOT RUN: no --apache-url given';
        $paths = ['/config/database.php', '/includes/crud.php', '/README.md', '/.gitignore', '/uploads/maintenance/', '/uploads/condition/.htaccess', '/uploads/tenants.sample.txt'];
        $codes = [];
        foreach ($paths as $path) $codes[$path] = (new Browser($apache))->get($apache . $path)['status'];
        check(count(array_filter($codes, fn($c) => $c === 403)) === count($codes), 'Codes: ' . json_encode($codes));
        return 'All refused with 403: ' . implode(', ', array_keys($codes));
    });
TestKit::add('SEC-14', 'Exported CSV cannot run formulas (A03)', 'Property Manager signed in', ['Create a tenant named =HYPERLINK("http://x")', 'Export Tenants to CSV'],
    'The name is exported with a leading apostrophe', function () {
        $browser = signed_in('manager@urbanescape.test');
        $browser->submit('/admin/tenants.php', ['_action' => 'save', 'full_name' => '=HYPERLINK("http://x")', 'national_id' => '40000014', 'phone' => '+254700400014', 'email' => 'sec14@urbanescape.test', 'gender' => 'Other', 'status' => 'Active']);
        $csv = $browser->get('/admin/tenants.php?action=export')['body'];
        check(str_contains($csv, "'=HYPERLINK") , 'Formula not neutralised');
        return 'Exported as \'=HYPERLINK("http://x"), so spreadsheets show it as text';
    });

// ===========================================================================
// NFR: non-functional checks
// ===========================================================================

TestKit::add('NFR-01', 'Key pages respond within one second', 'Test server running locally', ['Request the home page, listings, management dashboard and income statement 10 times each'],
    'Median response time below 1 second for every page', function () use ($base) {
        $manager = signed_in('manager@urbanescape.test');
        $public = new Browser($base);
        $pages = ['/' => $public, '/public/properties.php' => $public, '/admin/dashboard.php' => $manager, '/admin/reports.php?report=income' => $manager];
        $medians = [];
        foreach ($pages as $path => $browser) {
            $times = [];
            for ($i = 0; $i < 10; $i++) $times[] = $browser->get($path)['seconds'];
            sort($times);
            $medians[$path] = round(($times[4] + $times[5]) / 2, 3);
        }
        check(max($medians) < 1.0, 'Medians: ' . json_encode($medians));
        return 'Medians (s): ' . implode('; ', array_map(fn($p, $s) => "$p $s", array_keys($medians), $medians));
    });
TestKit::add('NFR-04', 'Idle sessions expire after 30 minutes', 'Tenant signed in', ['Age the session\'s last activity by 31 minutes', 'Open the dashboard', 'Open the sign-in page'],
    'Redirected to sign-in, with the inactivity message', function () {
        $browser = signed_in('tenant@urbanescape.test');
        $file = rtrim(ini_get('session.save_path') ?: sys_get_temp_dir(), '\\/') . DIRECTORY_SEPARATOR . 'sess_' . $browser->cookie('PHPSESSID');
        check(is_file($file), "Session file not found: $file");
        file_put_contents($file, preg_replace('/last_activity\|i:\d+;/', 'last_activity|i:' . (time() - 1860) . ';', file_get_contents($file)));
        $dashboard = $browser->get('/tenant/dashboard.php');
        $login = $browser->open((string)$dashboard['location'])['body'];
        check(str_contains((string)$dashboard['location'], 'login.php') && str_contains($login, 'signed out after 30 minutes'), 'Session did not expire');
        return 'Redirected to sign-in with "You were signed out after 30 minutes without activity."';
    });

// ===========================================================================
// Runner
// ===========================================================================

$suites = isset($options['suite']) ? explode(',', strtoupper($options['suite'])) : null;
$only = isset($options['only']) ? explode(',', strtoupper($options['only'])) : null;
$results = [];
$counts = ['PASS' => 0, 'FAIL' => 0, 'ERROR' => 0, 'NOT RUN' => 0];
foreach (TestKit::$cases as $id => $case) {
    if (($suites && !in_array($case['suite'], $suites, true)) || ($only && !in_array($id, $only, true))) continue;
    $started = microtime(true);
    try {
        $actual = ($case['run'])();
        $status = str_starts_with((string)$actual, 'NOT RUN') ? 'NOT RUN' : 'PASS';
    } catch (TestFailure $e) {
        [$status, $actual] = ['FAIL', $e->getMessage()];
    } catch (Throwable $e) {
        [$status, $actual] = ['ERROR', get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')'];
    }
    $counts[$status]++;
    $results[] = array_diff_key($case, ['run' => 1]) + ['actual' => $actual, 'status' => $status, 'executed_at' => date('Y-m-d H:i:s'), 'ms' => (int)round((microtime(true) - $started) * 1000)];
    printf("%-7s %-7s %s\n        %s\n", $id, $status, $case['title'], mb_strimwidth((string)$actual, 0, 220, '…'));
}
printf("\n%d passed, %d failed, %d errors, %d not run (%d total)\n", $counts['PASS'], $counts['FAIL'], $counts['ERROR'], $counts['NOT RUN'], count($results));

if (!empty($options['json'])) {
    $meta = ['run_at' => date('Y-m-d H:i:s T'), 'php' => PHP_VERSION, 'database' => getenv('DB_NAME'), 'db_version' => db()->query('SELECT VERSION()')->fetchColumn(),
        'base_url' => $base, 'apache_url' => $apache, 'commit' => trim((string)@shell_exec('git -C ' . escapeshellarg($root) . ' rev-parse --short HEAD')), 'counts' => $counts];
    file_put_contents($options['json'], json_encode(['meta' => $meta, 'results' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    echo "Results written to {$options['json']}\n";
}
exit($counts['FAIL'] + $counts['ERROR'] > 0 ? 1 : 0);
