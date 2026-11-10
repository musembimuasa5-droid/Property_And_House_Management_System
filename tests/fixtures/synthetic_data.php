<?php
/**
 * Loads fictitious records into a TEST database (seed.sql must already be loaded), so the
 * screens, reports and tests have realistic data. Every person, phone number, ID number and
 * email address here is invented; emails use the reserved .test domain.
 *
 * Usage (PowerShell):  $env:DB_NAME='urban_escape_test'; C:\xampp\php\php.exe tests\fixtures\synthetic_data.php
 * Optional: $env:TEST_PASSWORD sets the password of every test account.
 */

if (PHP_SAPI !== 'cli') exit("Run this script from the command line.\n");
if (!preg_match('/_test$/', (string)getenv('DB_NAME'))) {
    fwrite(STDERR, "Refusing to run: set DB_NAME to a test database whose name ends in _test.\n");
    exit(1);
}

$root = dirname(__DIR__, 2);
require_once $root . '/config/config.php';
require_once $root . '/config/database.php';
require_once $root . '/config/auth.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/finance.php';
require_once $root . '/includes/condition.php';

$db = db();
if ((int)$db->query('SELECT COUNT(*) FROM leases')->fetchColumn() !== 1) {
    fwrite(STDERR, "Refusing to run: expected a freshly seeded database (exactly one lease).\n");
    exit(1);
}
mt_srand(2026);
$hash = password_hash(getenv('TEST_PASSWORD') ?: 'UrbanEscape-Test-2026', PASSWORD_DEFAULT);
const MANAGER = 2, ACCOUNTANT = 5, CARETAKER = 4;

function insert(PDO $db, string $table, array $row): int {
    $columns = array_keys($row);
    $db->prepare("INSERT INTO $table (" . implode(', ', $columns) . ') VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')')->execute(array_values($row));
    return (int)$db->lastInsertId();
}

function copy_photo(string $root, string $name, string $folder): string {
    $relative = "uploads/$folder/" . bin2hex(random_bytes(16)) . '.jpg';
    if (!is_dir("$root/uploads/$folder")) mkdir("$root/uploads/$folder", 0755, true);
    copy(__DIR__ . "/photos/$name", "$root/$relative");
    return $relative;
}

function mpesa_code(): string {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ0123456789';
    $code = 'T';
    for ($i = 0; $i < 9; $i++) $code .= $chars[mt_rand(0, strlen($chars) - 1)];
    return $code;
}

$db->beginTransaction();

// 1. Rename the seeded staff and tenant accounts and give them the test password.
$people = [
    1 => ['Grace Wanjiru', 'admin@urbanescape.test', '+254700100001'],
    2 => ['Peter Otieno', 'manager@urbanescape.test', '+254700100002'],
    3 => ['Joseph Kamau', 'landlord@urbanescape.test', '+254700100003'],
    4 => ['John Mwangi', 'caretaker@urbanescape.test', '+254700100004'],
    5 => ['Faith Achieng', 'accountant@urbanescape.test', '+254700100005'],
    6 => ['Jane Njeri', 'tenant@urbanescape.test', '+254700100006'],
];
$updateUser = $db->prepare('UPDATE users SET full_name = ?, email = ?, phone = ?, password_hash = ?, last_login = ? WHERE id = ?');
foreach ($people as $id => [$name, $email, $phone]) $updateUser->execute([$name, $email, $phone, $hash, '2026-11-09 08:30:00', $id]);
$db->prepare("UPDATE landlords SET full_name = 'Joseph Kamau', company_name = 'Kamau Properties Ltd', email = 'landlord@urbanescape.test', phone = '+254700100003',
    id_number = '27845119', address = 'P.O. Box 100, Nairobi', bank_details = 'Test Bank, account 0000000001', mpesa_number = '+254700100003' WHERE id = 1")->execute();
$db->prepare("UPDATE tenants SET full_name = 'Jane Njeri', email = 'tenant@urbanescape.test', phone = '+254700100006', national_id = '30458217',
    emergency_contact_name = 'Paul Njeri', emergency_contact_phone = '+254711200300', occupation = 'Nurse', employer = 'Test Hospital' WHERE id = 1")->execute();

$user = fn(int $role, string $name, string $email, string $phone) => insert($db, 'users', ['role_id' => $role, 'full_name' => $name, 'email' => $email, 'phone' => $phone, 'password_hash' => $hash, 'status' => 'Active']);
$samuel = $user(4, 'Samuel Kiprono', 'caretaker2@urbanescape.test', '+254700100007');
$maryUser = $user(3, 'Mary Wambui', 'landlord2@urbanescape.test', '+254700100008');
$mary = insert($db, 'landlords', ['user_id' => $maryUser, 'full_name' => 'Mary Wambui', 'company_name' => 'Wambui Homes', 'phone' => '+254700100008',
    'email' => 'landlord2@urbanescape.test', 'id_number' => '25510376', 'address' => 'P.O. Box 200, Nairobi', 'mpesa_number' => '+254700100008']);
$db->prepare('UPDATE properties SET landlord_id = ? WHERE id = 2')->execute([$mary]);

// 2. More units in the two seeded apartment blocks.
$unit = fn(int $property, string $number, string $floor, string $type, int $beds, float $rent, float $service) => insert($db, 'units', [
    'property_id' => $property, 'unit_number' => $number, 'floor' => $floor, 'house_type' => $type, 'bedrooms' => $beds, 'bathrooms' => 1,
    'monthly_rent' => $rent, 'deposit' => $rent, 'service_charge' => $service, 'water_billing_method' => 'Meter Reading', 'electricity_billing_method' => 'Prepaid', 'status' => 'Vacant',
    'description' => "$type on the $floor floor"]);
$u = ['A101' => 1, 'A102' => 2, 'B201' => 3, 'G1' => 4];
$u['A103'] = $unit(1, 'A103', '1st', '1 Bedroom', 1, 15000, 1000);
$u['A104'] = $unit(1, 'A104', '1st', '1 Bedroom', 1, 16000, 1000);
$u['A201'] = $unit(1, 'A201', '2nd', '2 Bedroom', 2, 22000, 1500);
$u['A202'] = $unit(1, 'A202', '2nd', '2 Bedroom', 2, 22000, 1500);
$u['B202'] = $unit(2, 'B202', '2nd', 'Bedsitter', 1, 9500, 500);
$u['B203'] = $unit(2, 'B203', '2nd', 'Bedsitter', 1, 9500, 500);
$db->prepare("UPDATE units SET water_billing_method = 'Meter Reading' WHERE id = 3")->execute();

// 3. Tenants (with portal logins, except Mercy, whose profile was created by staff).
$tenant = function (string $name, string $email, string $phone, string $nationalId, string $gender, bool $login) use ($db, $user) {
    $userId = $login ? $user(6, $name, $email, $phone) : null;
    return insert($db, 'tenants', ['user_id' => $userId, 'full_name' => $name, 'phone' => $phone, 'email' => $email, 'national_id' => $nationalId, 'gender' => $gender,
        'emergency_contact_name' => 'Next of kin', 'emergency_contact_phone' => '+254711000' . substr($nationalId, -3), 'occupation' => 'Employed', 'status' => 'Active']);
};
$t = ['jane' => 1];
$t['brian'] = $tenant('Brian Mutua', 'brian.mutua@urbanescape.test', '+254700200001', '31220458', 'Male', true);
$t['aisha'] = $tenant('Aisha Hassan', 'aisha.hassan@urbanescape.test', '+254700200002', '32871904', 'Female', true);
$t['kevin'] = $tenant('Kevin Omondi', 'kevin.omondi@urbanescape.test', '+254700200003', '29563310', 'Male', true);
$t['lucy'] = $tenant('Lucy Chebet', 'lucy.chebet@urbanescape.test', '+254700200004', '33019876', 'Female', true);
$t['daniel'] = $tenant('Daniel Kiptoo', 'daniel.kiptoo@urbanescape.test', '+254700200005', '28844120', 'Male', true);
$t['esther'] = $tenant('Esther Akinyi', 'esther.akinyi@urbanescape.test', '+254700200006', '34107762', 'Female', true);
$t['mercy'] = $tenant('Mercy Wairimu', 'mercy.wairimu@urbanescape.test', '+254700200007', '35290041', 'Female', false);

// 4. Leases. The seeded lease (Jane, A101) is kept; Daniel's ends at the end of September.
$db->prepare("UPDATE leases SET rent_review_date = '2027-01-01' WHERE id = 1")->execute();
$lease = function (int $tenantId, int $unitId, string $start, string $end, float $rent, string $status, ?string $review = null) use ($db) {
    $unit = $db->query('SELECT property_id, deposit FROM units WHERE id = ' . $unitId)->fetch();
    $id = insert($db, 'leases', ['tenant_id' => $tenantId, 'property_id' => $unit['property_id'], 'unit_id' => $unitId, 'start_date' => $start, 'end_date' => $end,
        'monthly_rent' => $rent, 'deposit' => $unit['deposit'], 'payment_due_day' => 5, 'notice_period_days' => 30, 'rent_review_date' => $review, 'status' => $status]);
    sync_unit_occupancy($db, $unitId);
    return $id;
};
$l = ['jane' => 1];
$l['brian'] = $lease($t['brian'], $u['A102'], '2026-06-01', '2027-05-31', 15000, 'Active', '2027-06-01');
$l['aisha'] = $lease($t['aisha'], $u['A103'], '2026-03-01', '2027-02-28', 15000, 'Active');
$l['kevin'] = $lease($t['kevin'], $u['B201'], '2026-02-01', '2027-01-31', 9500, 'Active');
$l['lucy'] = $lease($t['lucy'], $u['B202'], '2026-07-01', '2027-06-30', 9500, 'Active');
$l['daniel'] = $lease($t['daniel'], $u['A201'], '2026-04-01', '2027-03-31', 22000, 'Active');
$l['esther'] = $lease($t['esther'], $u['G1'], '2026-08-01', '2027-07-31', 45000, 'Active', '2027-08-01');
$l['mercy'] = $lease($t['mercy'], $u['A202'], '2026-12-01', '2027-11-30', 22000, 'Pending');
sync_unit_occupancy($db, $u['A101']);

// 5. Water readings for the metered units, then monthly charges for January to November.
$readings = ['A101' => 1200.0, 'A102' => 860.0, 'A103' => 515.0, 'A201' => 640.0];
$recordReadings = function (string $period) use ($db, &$readings, $u) {
    foreach ($readings as $unitNumber => &$previous) {
        if ($unitNumber === 'A201' && $period > '2026-09') continue;
        $used = mt_rand(6, 14);
        insert($db, 'utility_readings', ['unit_id' => $u[$unitNumber], 'utility_type_id' => 1, 'previous_reading' => $previous, 'current_reading' => $previous + $used,
            'consumption' => $used, 'rate' => 150, 'total_amount' => $used * 150, 'billing_period' => $period, 'reading_date' => $period . '-01', 'recorded_by' => ACCOUNTANT]);
        $previous += $used;
    }
};
foreach (['2026-01', '2026-02', '2026-03', '2026-04', '2026-05', '2026-06', '2026-07', '2026-08', '2026-09'] as $period) {
    if ($period >= '2026-08') $recordReadings($period);
    generate_rent_charges($db, $period);
}
$db->prepare("UPDATE leases SET status = 'Terminated', end_date = '2026-09-30' WHERE id = ?")->execute([$l['daniel']]);
sync_unit_occupancy($db, $u['A201']);
foreach (['2026-10', '2026-11'] as $period) {
    $recordReadings($period);
    generate_rent_charges($db, $period);
}

// 6. Payments: most tenants pay in full; Brian, Kevin and Esther are behind.
$charges = function (int $leaseId) use ($db) {
    $stmt = $db->prepare('SELECT billing_period, total_due FROM rent_charges WHERE lease_id = ? ORDER BY billing_period');
    $stmt->execute([$leaseId]);
    return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
};
$pay = function (int $leaseId, float $amount, string $date, string $period, string $method = 'M-Pesa') use ($db) {
    $lease = $db->query('SELECT tenant_id, property_id, unit_id FROM leases WHERE id = ' . $leaseId)->fetch();
    insert($db, 'payments', ['receipt_number' => 'REC-' . str_replace('-', '', $date) . '-' . strtoupper(bin2hex(random_bytes(4))), 'tenant_id' => $lease['tenant_id'],
        'property_id' => $lease['property_id'], 'unit_id' => $lease['unit_id'], 'lease_id' => $leaseId, 'amount' => $amount, 'payment_method' => $method,
        'transaction_reference' => $method === 'M-Pesa' ? mpesa_code() : strtoupper($method) . '-' . mt_rand(100000, 999999), 'payment_date' => $date,
        'payment_period' => $period, 'recorded_by' => ACCOUNTANT, 'notes' => null]);
};
$plan = [
    'jane' => ['day' => 3, 'skip' => [], 'method' => 'M-Pesa'],
    'brian' => ['day' => 5, 'skip' => ['2026-10', '2026-11'], 'method' => 'M-Pesa'],
    'aisha' => ['day' => 2, 'skip' => [], 'method' => 'Bank'],
    'kevin' => ['day' => 7, 'skip' => ['2026-10', '2026-11'], 'method' => 'M-Pesa'],
    'lucy' => ['day' => 4, 'skip' => [], 'method' => 'M-Pesa'],
    'daniel' => ['day' => 4, 'skip' => [], 'method' => 'M-Pesa'],
    'esther' => ['day' => 5, 'skip' => ['2026-11'], 'method' => 'Bank'],
];
foreach ($plan as $who => $rule) {
    foreach ($charges($l[$who]) as $period => $due) {
        if (in_array($period, $rule['skip'], true)) continue;
        $pay($l[$who], (float)$due, sprintf('%s-%02d', $period, $rule['day']), $period, $period === '2026-06' && $who === 'jane' ? 'Cash' : $rule['method']);
    }
}
$pay($l['brian'], 10000, '2026-10-08', '2026-10');
$pay($l['esther'], 20000, '2026-11-06', '2026-11', 'Bank');
$pay($l['aisha'], 2000, '2026-11-02', '2026-12', 'Bank'); // paid ahead; carried forward
foreach ($l as $leaseId) recalculate_lease_balances($db, $leaseId);

// 7. Condition reports with checklists (Jane, Daniel in and out, Esther awaiting her response).
$report = function (int $leaseId, string $type, string $date, string $overall, array $items, string $response, ?string $comment, ?string $respondedAt, ?string $notes = null) use ($db, $root) {
    $id = insert($db, 'condition_reports', ['lease_id' => $leaseId, 'report_type' => $type, 'inspection_date' => $date, 'overall_condition' => $overall, 'general_notes' => $notes,
        'inspected_by' => MANAGER, 'tenant_response' => $response, 'tenant_comment' => $comment, 'responded_at' => $respondedAt, 'created_at' => "$date 16:00:00"]);
    foreach (CONDITION_AREAS as $area) {
        [$state, $remarks, $photo] = $items[$area] ?? ['Good', null, null];
        insert($db, 'condition_report_items', ['report_id' => $id, 'area' => $area, 'item_condition' => $state, 'remarks' => $remarks,
            'photo_path' => $photo ? copy_photo($root, $photo, 'condition') : null]);
    }
    return $id;
};
$report($l['jane'], 'Move-in', '2025-12-31', 'Good', ['Keys and access cards' => ['Good', 'Two door keys and one gate card handed over', null]], 'Agreed', null, '2026-01-02 09:12:00');
$report($l['daniel'], 'Move-in', '2026-03-31', 'Good', ['Floors' => ['Fair', 'Minor scratches near the entrance', null]], 'Agreed', null, '2026-04-01 18:40:00');
$moveOut = $report($l['daniel'], 'Move-out', '2026-09-30', 'Fair', [
    'Windows and curtains' => ['Damaged', 'Cracked pane in the kitchen window', 'kitchen_window.jpg'],
    'Walls and paint' => ['Poor', 'Stain on the bedroom wall near the ceiling', 'bedroom_wall.jpg'],
    'Floors' => ['Fair', 'Scratches near the entrance, as at move-in', null],
    'Keys and access cards' => ['Good', 'All keys returned', null],
], 'Disputed', 'The bedroom wall stain came from the roof leak I reported in August (request #6), not from my use of the room.', '2026-10-02 11:05:00',
    'Unit vacated on 30 September. Tenant present during the inspection.');
$report($l['esther'], 'Move-in', '2026-07-31', 'Good', ['Kitchen fittings' => ['Fair', 'One cabinet hinge is loose', 'kitchen_cabinet.jpg']], 'Pending', null, null);

// 8. Deposit ledger.
$deposit = fn(int $leaseId, string $type, float $amount, string $date, ?string $reason = null, ?int $reportId = null, ?string $reference = null) => insert($db, 'deposit_transactions', [
    'lease_id' => $leaseId, 'transaction_type' => $type, 'amount' => $amount, 'transaction_date' => $date, 'reason' => $reason, 'condition_report_id' => $reportId,
    'reference' => $reference, 'recorded_by' => ACCOUNTANT]);
foreach (['jane' => '2025-12-28', 'brian' => '2026-05-29', 'aisha' => '2026-02-27', 'lucy' => '2026-06-28', 'daniel' => '2026-03-30', 'esther' => '2026-07-30'] as $who => $date) {
    $amount = (float)$db->query('SELECT deposit FROM leases WHERE id = ' . $l[$who])->fetchColumn();
    $deposit($l[$who], 'Received', $amount, $date, 'Deposit paid at signing', null, mpesa_code());
}
$deposit($l['kevin'], 'Received', 5000, '2026-01-30', 'First instalment of the deposit', null, mpesa_code());
$deposit($l['daniel'], 'Deduction', 3500, '2026-10-05', 'Replace cracked kitchen window pane', $moveOut, 'Glazier invoice 0412');
$deposit($l['daniel'], 'Deduction', 1500, '2026-10-05', 'Repaint stained section of bedroom wall', $moveOut, 'Painter quote 118');

// 9. Maintenance requests with their status history.
$request = function (string $who, string $category, string $description, string $priority, string $status, ?int $staff, array $history, float $cost = 0, ?string $photo = null) use ($db, $root, $l) {
    $lease = $db->query('SELECT tenant_id, property_id, unit_id FROM leases WHERE id = ' . $l[$who])->fetch();
    $completed = $status === 'Completed' ? end($history)[0] : null;
    $id = insert($db, 'maintenance_requests', ['tenant_id' => $lease['tenant_id'], 'property_id' => $lease['property_id'], 'unit_id' => $lease['unit_id'], 'category' => $category,
        'description' => $description, 'priority' => $priority, 'status' => $status, 'assigned_staff' => $staff, 'estimated_cost' => $cost, 'actual_cost' => $status === 'Completed' ? $cost : 0,
        'image_path' => $photo ? copy_photo($root, $photo, 'maintenance') : null, 'created_at' => $history[0][0], 'completed_date' => $completed]);
    foreach ($history as [$when, $state, $by, $note]) insert($db, 'maintenance_updates', ['request_id' => $id, 'status' => $state, 'note' => $note, 'updated_by' => $by, 'created_at' => $when]);
    return $id;
};
$daniel = (int)$db->query('SELECT user_id FROM tenants WHERE id = ' . $t['daniel'])->fetchColumn();
$userOf = fn(string $who) => (int)$db->query('SELECT user_id FROM tenants WHERE id = ' . $t[$who])->fetchColumn();
$request('jane', 'Plumbing', 'The kitchen sink is leaking under the cabinet and the floor stays wet.', 'High', 'In Progress', CARETAKER, [
    ['2026-11-02 08:15:00', 'Submitted', 6, 'Reported by the tenant with a photo'],
    ['2026-11-02 10:40:00', 'Assigned', MANAGER, 'Updated by management; assigned to John Mwangi'],
    ['2026-11-03 09:05:00', 'In Progress', CARETAKER, 'Updated by the caretaker'],
], 2500, 'sink_leak.jpg');
$request('brian', 'Electrical', 'The socket next to the bed does not work.', 'Medium', 'Submitted', null, [['2026-11-08 19:20:00', 'Submitted', $userOf('brian'), 'Reported by the tenant']]);
$request('kevin', 'Water', 'Low water pressure in the shower.', 'Low', 'Completed', $samuel, [
    ['2026-10-14 07:30:00', 'Submitted', $userOf('kevin'), 'Reported by the tenant'],
    ['2026-10-14 12:00:00', 'Assigned', MANAGER, 'Updated by management; assigned to Samuel Kiprono'],
    ['2026-10-16 15:00:00', 'Completed', $samuel, 'Updated by the caretaker; cost KSh 1,200.00'],
], 1200);
$request('aisha', 'Security', 'The main door lock is stiff and hard to open.', 'Medium', 'Assigned', $samuel, [
    ['2026-11-06 18:45:00', 'Submitted', $userOf('aisha'), 'Reported by the tenant'],
    ['2026-11-07 09:10:00', 'Assigned', MANAGER, 'Updated by management; assigned to Samuel Kiprono'],
]);
$request('esther', 'Structural', 'Hairline crack on the living room wall above the window.', 'High', 'Reviewed', null, [
    ['2026-11-05 17:25:00', 'Submitted', $userOf('esther'), 'Reported by the tenant'],
    ['2026-11-06 08:50:00', 'Reviewed', MANAGER, 'Updated by management'],
]);
$request('daniel', 'Plumbing', 'Water drips from the roof above the bedroom during heavy rain.', 'High', 'Completed', CARETAKER, [
    ['2026-08-12 06:55:00', 'Submitted', $daniel, 'Reported by the tenant'],
    ['2026-08-12 11:30:00', 'Assigned', MANAGER, 'Updated by management; assigned to John Mwangi'],
    ['2026-08-20 16:10:00', 'Completed', CARETAKER, 'Updated by the caretaker; cost KSh 4,500.00'],
], 4500);

// 10. Complaints, enquiries, viewing requests and expenses.
$complaint = fn(string $who, string $category, string $subject, string $text, string $status, ?string $notes, string $date) => insert($db, 'complaints', [
    'tenant_id' => $t[$who], 'property_id' => $db->query('SELECT property_id FROM leases WHERE id = ' . $l[$who])->fetchColumn(), 'category' => $category, 'subject' => $subject,
    'description' => $text, 'status' => $status, 'resolution_notes' => $notes, 'assigned_to' => $status === 'Open' ? null : MANAGER, 'created_at' => $date]);
$complaint('jane', 'Noise', 'Loud music from the upper floor after 11 p.m.', 'Music plays loudly most weeknights until after midnight.', 'Under Review', 'Spoke to the neighbour on 4 November; monitoring for a week.', '2026-11-01 21:40:00');
$complaint('kevin', 'Billing', 'Water charge for October looks too high', 'My October water bill is higher than usual although I travelled for a week.', 'Open', null, '2026-11-07 13:15:00');
$complaint('lucy', 'Security', 'Gate left open at night', 'The main gate was open after midnight on two nights.', 'Resolved', 'Guard roster changed; the gate is now locked at 10 p.m.', '2026-10-10 07:50:00');

insert($db, 'enquiries', ['full_name' => 'Wanjiku Maina', 'email' => 'wanjiku.maina@example.com', 'phone' => '+254700300001', 'message' => 'Is the three-bedroom bungalow in Rongai still available for December?', 'status' => 'New', 'created_at' => '2026-11-09 14:20:00']);
insert($db, 'enquiries', ['full_name' => 'Peter Ochieng', 'email' => 'peter.ochieng@example.com', 'phone' => null, 'message' => 'Do the Kileleshwa maisonettes allow pets?', 'status' => 'In Progress', 'handled_by' => MANAGER, 'response_notes' => 'Called him; booked a viewing for 14 November.', 'created_at' => '2026-11-05 10:05:00']);
insert($db, 'enquiries', ['full_name' => 'Amina Yusuf', 'email' => 'amina.yusuf@example.com', 'phone' => '+254700300003', 'message' => 'How much is the deposit for a bedsitter at Green View?', 'status' => 'Resolved', 'handled_by' => MANAGER, 'response_notes' => 'Replied by email: one month rent.', 'created_at' => '2026-10-28 16:45:00']);

insert($db, 'viewing_requests', ['property_id' => 3, 'unit_id' => 5, 'visitor_name' => 'Njoroge Kariuki', 'visitor_phone' => '+254700300004', 'visitor_email' => 'njoroge.k@example.com', 'preferred_date' => '2026-11-14', 'preferred_time' => '10:00:00', 'message' => 'I would like to see the unit with my family.', 'status' => 'Pending', 'created_at' => '2026-11-09 18:00:00']);
insert($db, 'viewing_requests', ['property_id' => 5, 'unit_id' => 13, 'visitor_name' => 'Halima Abdi', 'visitor_phone' => '+254700300005', 'visitor_email' => 'halima.abdi@example.com', 'preferred_date' => '2026-11-12', 'preferred_time' => '15:30:00', 'message' => null, 'status' => 'Approved', 'created_at' => '2026-11-06 09:30:00']);
insert($db, 'viewing_requests', ['property_id' => 4, 'unit_id' => 10, 'visitor_name' => 'Collins Barasa', 'visitor_phone' => '+254700300006', 'visitor_email' => 'collins.b@example.com', 'preferred_date' => '2026-11-03', 'preferred_time' => '11:00:00', 'message' => 'Evening viewings preferred.', 'status' => 'Completed', 'created_at' => '2026-10-30 20:10:00']);

foreach (['2026-08', '2026-09', '2026-10', '2026-11'] as $period) {
    insert($db, 'expenses', ['property_id' => 1, 'category' => 'Security', 'description' => 'Night guard services', 'amount' => 12000, 'expense_date' => "$period-01", 'vendor' => 'Test Security Services', 'recorded_by' => ACCOUNTANT]);
    insert($db, 'expenses', ['property_id' => 1, 'category' => 'Cleaning', 'description' => 'Common area cleaning', 'amount' => 4000, 'expense_date' => "$period-02", 'vendor' => 'Test Cleaners', 'recorded_by' => ACCOUNTANT]);
    insert($db, 'expenses', ['property_id' => 2, 'category' => 'Security', 'description' => 'Gate guard', 'amount' => 6000, 'expense_date' => "$period-01", 'vendor' => 'Test Security Services', 'recorded_by' => ACCOUNTANT]);
}
insert($db, 'expenses', ['property_id' => 1, 'category' => 'Repairs', 'description' => 'Roof leak repair above unit A201', 'amount' => 4500, 'expense_date' => '2026-08-20', 'vendor' => 'Test Roofing', 'recorded_by' => ACCOUNTANT]);
insert($db, 'expenses', ['property_id' => 3, 'category' => 'Maintenance', 'description' => 'Garden upkeep', 'amount' => 3000, 'expense_date' => '2026-10-15', 'vendor' => 'Test Gardeners', 'recorded_by' => ACCOUNTANT]);

// 11. Notices already sent, and recent alerts for management.
$notice = function (int $userId, string $title, string $message, string $date, int $read = 0) use ($db) {
    insert($db, 'notifications', ['user_id' => $userId, 'title' => $title, 'message' => $message, 'is_read' => $read, 'created_at' => $date]);
};
foreach ($db->query("SELECT user_id FROM tenants WHERE status = 'Active' AND user_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN) as $tenantUser) {
    $notice((int)$tenantUser, 'Water interruption on Saturday', 'Nairobi Water will cut supply on Saturday 14 November from 8 a.m. to 2 p.m. Please store water.', '2026-11-06 12:00:00');
}
foreach ([MANAGER, 1] as $staff) {
    $notice($staff, 'New maintenance request', 'Brian Mutua reported a Electrical issue (Medium priority): The socket next to the bed does not work.', '2026-11-08 19:20:00');
    $notice($staff, 'New enquiry from Wanjiku Maina', 'Is the three-bedroom bungalow in Rongai still available for December? (wanjiku.maina@example.com)', '2026-11-09 14:20:00');
    $notice($staff, 'Condition report #3 disputed', 'Daniel Kiptoo disputed the Move-out report: The bedroom wall stain came from the roof leak…', '2026-10-02 11:05:00', 1);
}

$db->commit();
$count = fn(string $table) => (int)$db->query("SELECT COUNT(*) FROM $table")->fetchColumn();
foreach (['users', 'tenants', 'units', 'leases', 'rent_charges', 'payments', 'payment_allocations', 'utility_readings', 'condition_reports', 'condition_report_items',
    'deposit_transactions', 'maintenance_requests', 'maintenance_updates', 'complaints', 'enquiries', 'viewing_requests', 'expenses', 'notifications'] as $table) {
    printf("%-24s %d\n", $table, $count($table));
}
echo "Synthetic data loaded into " . getenv('DB_NAME') . ".\n";
