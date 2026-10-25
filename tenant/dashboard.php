<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('Tenant');
$db = db();
$user = getCurrentUser();

$tenantQuery = $db->prepare('SELECT id, full_name FROM tenants WHERE user_id = ? ORDER BY id LIMIT 1');
$tenantQuery->execute([$user['id']]);
$tenant = $tenantQuery->fetch();

$leases = [];
$balance = 0.0;
$overdueBalance = 0.0;
$chargeCount = 0;
$payments = [];
$charges = [];
$maintenanceRequests = [];
$notices = [];
$utilityReadings = [];
$documents = [];
$openTicketCount = 0;

if ($tenant) {
    $leaseQuery = $db->prepare("SELECT le.id, le.start_date, le.end_date, le.monthly_rent, le.deposit, le.payment_due_day,
            le.notice_period_days, le.status, le.special_terms, le.document_path, u.unit_number, u.floor, u.house_type,
            u.bedrooms, u.bathrooms, u.status AS unit_status, p.name AS property_name, p.physical_address,
            loc.estate, loc.sub_county
        FROM leases le
        JOIN units u ON u.id = le.unit_id
        JOIN properties p ON p.id = le.property_id
        LEFT JOIN locations loc ON loc.id = p.location_id
        WHERE le.tenant_id = ? AND le.status = 'Active'
        ORDER BY le.end_date ASC, le.id DESC");
    $leaseQuery->execute([$tenant['id']]);
    $leases = $leaseQuery->fetchAll();

    $balanceQuery = $db->prepare("SELECT COUNT(*) AS charge_count,
            COALESCE(SUM(GREATEST(rc.total_due - COALESCE(alloc.paid, 0), 0)), 0) AS balance,
            COALESCE(SUM(CASE WHEN rc.due_date < CURRENT_DATE() THEN GREATEST(rc.total_due - COALESCE(alloc.paid, 0), 0) ELSE 0 END), 0) AS overdue
        FROM rent_charges rc
        LEFT JOIN (SELECT rent_charge_id, SUM(allocated_amount) AS paid FROM payment_allocations GROUP BY rent_charge_id) alloc
            ON alloc.rent_charge_id = rc.id
        WHERE rc.tenant_id = ? AND rc.status <> 'Paid'");
    $balanceQuery->execute([$tenant['id']]);
    $balanceRow = $balanceQuery->fetch();
    $chargeCount = (int)$balanceRow['charge_count'];
    $balance = (float)$balanceRow['balance'];
    $overdueBalance = (float)$balanceRow['overdue'];

    $paymentQuery = $db->prepare("SELECT receipt_number, transaction_reference, amount, payment_method, payment_date, payment_period
        FROM payments WHERE tenant_id = ? ORDER BY payment_date DESC, id DESC LIMIT 6");
    $paymentQuery->execute([$tenant['id']]);
    $payments = $paymentQuery->fetchAll();

    $chargeQuery = $db->prepare("SELECT rc.billing_period, rc.total_due, rc.due_date, rc.status, rc.lease_id,
            GREATEST(rc.total_due - COALESCE(alloc.paid, 0), 0) AS remaining
        FROM rent_charges rc
        LEFT JOIN (SELECT rent_charge_id, SUM(allocated_amount) AS paid FROM payment_allocations GROUP BY rent_charge_id) alloc
            ON alloc.rent_charge_id = rc.id
        WHERE rc.tenant_id = ?
        ORDER BY rc.due_date DESC, rc.id DESC LIMIT 6");
    $chargeQuery->execute([$tenant['id']]);
    $charges = $chargeQuery->fetchAll();

    $maintenanceQuery = $db->prepare("SELECT mr.id, mr.category, mr.description, mr.priority, mr.status, mr.created_at,
            p.name AS property_name, u.unit_number,
            (SELECT a.description FROM audit_logs a WHERE a.module = 'Caretaker Maintenance' AND a.record_id = mr.id AND a.action = 'Repair note' ORDER BY a.id DESC LIMIT 1) AS caretaker_note
        FROM maintenance_requests mr
        JOIN properties p ON p.id = mr.property_id
        JOIN units u ON u.id = mr.unit_id
        WHERE mr.tenant_id = ?
        ORDER BY mr.created_at DESC LIMIT 6");
    $maintenanceQuery->execute([$tenant['id']]);
    $maintenanceRequests = $maintenanceQuery->fetchAll();
    $openTicketQuery = $db->prepare("SELECT COUNT(*) FROM maintenance_requests WHERE tenant_id = ? AND status NOT IN ('Completed', 'Cancelled')");
    $openTicketQuery->execute([$tenant['id']]);
    $openTicketCount = (int)$openTicketQuery->fetchColumn();

    $noticeQuery = $db->prepare('SELECT id, title, message, is_read, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 5');
    $noticeQuery->execute([$user['id']]);
    $notices = $noticeQuery->fetchAll();

    $utilityQuery = $db->prepare("SELECT r.billing_period, r.previous_reading, r.current_reading, r.consumption, r.total_amount,
            r.reading_date, ty.name AS utility_name, u.unit_number, p.name AS property_name
        FROM utility_readings r
        JOIN utility_types ty ON ty.id = r.utility_type_id
        JOIN units u ON u.id = r.unit_id
        JOIN properties p ON p.id = u.property_id
        WHERE r.unit_id IN (SELECT DISTINCT unit_id FROM leases WHERE tenant_id = ?)
        ORDER BY r.reading_date DESC, r.id DESC LIMIT 6");
    $utilityQuery->execute([$tenant['id']]);
    $utilityReadings = $utilityQuery->fetchAll();

    $documentQuery = $db->prepare("SELECT id, title, document_type, created_at FROM documents WHERE tenant_id = ? ORDER BY created_at DESC, id DESC LIMIT 6");
    $documentQuery->execute([$tenant['id']]);
    $documents = $documentQuery->fetchAll();
}

$paymentStatusText = 'No charges yet';
$paymentStatusClass = 'text-gray-700';
if ($tenant && $chargeCount > 0) {
    if ($balance <= 0) {
        $paymentStatusText = 'Fully paid';
        $paymentStatusClass = 'text-green-700';
    } elseif ($overdueBalance > 0) {
        $paymentStatusText = 'Arrears';
        $paymentStatusClass = 'text-red-700';
    } else {
        $paymentStatusText = 'Active balance';
        $paymentStatusClass = 'text-amber-700';
    }
}

$page_title = 'Tenant Dashboard';
include __DIR__ . '/../includes/header.php';
?>
<div class="flex h-screen bg-gray-50 overflow-hidden">
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>
    <div class="flex-1 flex flex-col overflow-y-auto">
        <?php include __DIR__ . '/../includes/navbar.php'; ?>
        <main id="main-content" class="flex-1 p-4 sm:p-6 space-y-6">
            <header class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Tenant workspace</p>
                    <h1 class="text-2xl font-bold text-gray-900">Welcome, <?php echo e($tenant['full_name'] ?? $user['full_name']); ?></h1>
                    <p class="text-sm text-gray-500">Your lease, rent records, requests, utilities, and property notices in one place.</p>
                </div>
                <a href="<?php echo BASE_URL; ?>/tenant/profile.php" class="inline-flex items-center justify-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2"><i class="fa-solid fa-user-pen" aria-hidden="true"></i> My profile</a>
            </header>

            <?php if (!$tenant): ?>
                <section class="rounded-xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-900" role="alert">
                    <h2 class="font-bold">Tenant profile not linked</h2>
                    <p class="mt-1">Your account is not connected to a tenant record yet. Contact your property administrator to link it; tenant-specific records are not available until then.</p>
                </section>
            <?php else: ?>
                <section aria-label="Tenant account summary" class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
                    <article class="bg-white p-5 rounded-xl border border-gray-100 shadow-sm"><p class="text-xs font-medium uppercase text-gray-500">Rent balance</p><h2 class="mt-2 text-2xl font-bold text-gray-900"><?php echo format_currency($balance); ?></h2><p class="mt-1 text-sm font-semibold <?php echo e($paymentStatusClass); ?>"><?php echo e($paymentStatusText); ?></p></article>
                    <article class="bg-white p-5 rounded-xl border border-gray-100 shadow-sm"><p class="text-xs font-medium uppercase text-gray-500">Overdue balance</p><h2 class="mt-2 text-2xl font-bold <?php echo $overdueBalance > 0 ? 'text-red-700' : 'text-gray-900'; ?>"><?php echo format_currency($overdueBalance); ?></h2><p class="mt-1 text-sm text-gray-500">Past-due unpaid rent charges</p></article>
                    <article class="bg-white p-5 rounded-xl border border-gray-100 shadow-sm"><p class="text-xs font-medium uppercase text-gray-500">Active lease(s)</p><h2 class="mt-2 text-2xl font-bold text-gray-900"><?php echo number_format(count($leases)); ?></h2><p class="mt-1 text-sm text-gray-500"><?php echo $leases ? e($leases[0]['property_name'] . ' · ' . $leases[0]['unit_number']) : 'No active lease recorded'; ?></p></article>
                    <article class="bg-white p-5 rounded-xl border border-gray-100 shadow-sm"><p class="text-xs font-medium uppercase text-gray-500">Open repair tickets</p><h2 class="mt-2 text-2xl font-bold text-gray-900"><?php echo number_format($openTicketCount); ?></h2><p class="mt-1 text-sm text-gray-500">Requests awaiting resolution</p></article>
                </section>

                <section class="rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900" aria-label="Online payment availability">
                    <strong>Online payment:</strong> This application currently records payments entered by management; M-Pesa STK push and self-service payment processing are not configured. Review recorded receipts or contact management before paying.
                </section>

                <div class="grid grid-cols-1 xl:grid-cols-3 gap-5">
                    <section class="xl:col-span-2 bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden" aria-labelledby="lease-heading">
                        <div class="p-5 border-b border-gray-100 flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">
                            <div><h2 id="lease-heading" class="font-bold text-gray-900">Lease and unit details</h2><p class="mt-1 text-sm text-gray-500">Terms and unit information from your active lease record.</p></div>
                            <a href="<?php echo BASE_URL; ?>/tenant/documents.php" class="text-sm font-semibold text-primary hover:underline">Lease documents</a>
                        </div>
                        <?php if (!$leases): ?><p class="p-5 text-sm text-gray-500">There is no active lease linked to your account.</p>
                        <?php else: ?><div class="divide-y divide-gray-100">
                            <?php foreach ($leases as $lease): ?>
                                <article class="p-5">
                                    <div class="flex flex-wrap items-start justify-between gap-3"><div><h3 class="font-semibold text-gray-900"><?php echo e($lease['property_name']); ?> · Unit <?php echo e($lease['unit_number']); ?></h3><p class="mt-1 text-sm text-gray-600"><?php echo e(implode(', ', array_filter([$lease['estate'], $lease['sub_county'], $lease['physical_address']]))); ?></p></div><?php echo render_status_badge($lease['unit_status']); ?></div>
                                    <dl class="mt-4 grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                                        <div><dt class="text-xs uppercase text-gray-500">House type</dt><dd class="mt-1 font-medium text-gray-900"><?php echo e($lease['house_type']); ?><?php echo (int)$lease['bedrooms'] > 0 ? ' · ' . (int)$lease['bedrooms'] . ' bedroom' : ''; ?></dd></div>
                                        <div><dt class="text-xs uppercase text-gray-500">Lease period</dt><dd class="mt-1 font-medium text-gray-900"><?php echo e(date('d M Y', strtotime($lease['start_date']))); ?> – <?php echo e(date('d M Y', strtotime($lease['end_date']))); ?></dd></div>
                                        <div><dt class="text-xs uppercase text-gray-500">Monthly rent</dt><dd class="mt-1 font-medium text-gray-900"><?php echo format_currency($lease['monthly_rent']); ?></dd></div>
                                        <div><dt class="text-xs uppercase text-gray-500">Rent due day</dt><dd class="mt-1 font-medium text-gray-900">Day <?php echo (int)$lease['payment_due_day']; ?> each month</dd></div>
                                        <div><dt class="text-xs uppercase text-gray-500">Security deposit</dt><dd class="mt-1 font-medium text-gray-900"><?php echo format_currency($lease['deposit']); ?></dd></div>
                                        <div><dt class="text-xs uppercase text-gray-500">Floor</dt><dd class="mt-1 font-medium text-gray-900"><?php echo e($lease['floor']); ?></dd></div>
                                        <div><dt class="text-xs uppercase text-gray-500">Bathrooms</dt><dd class="mt-1 font-medium text-gray-900"><?php echo (int)$lease['bathrooms']; ?></dd></div>
                                        <div><dt class="text-xs uppercase text-gray-500">Notice period</dt><dd class="mt-1 font-medium text-gray-900"><?php echo (int)$lease['notice_period_days']; ?> days</dd></div>
                                    </dl>
                                    <?php if ($lease['special_terms']): ?><p class="mt-4 text-sm text-gray-600"><strong>Lease terms:</strong> <?php echo nl2br(e($lease['special_terms'])); ?></p><?php endif; ?>
                                    <?php if ($lease['end_date'] < date('Y-m-d')): ?><p class="mt-3 text-sm text-amber-800">This lease is marked active but its recorded end date has passed. Please confirm its status with management.</p><?php endif; ?>
                                </article>
                            <?php endforeach; ?>
                        </div><?php endif; ?>
                    </section>

                    <section class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden" aria-labelledby="notices-heading">
                        <div class="p-5 border-b border-gray-100 flex items-center justify-between gap-3"><div><h2 id="notices-heading" class="font-bold text-gray-900">Notices</h2><p class="mt-1 text-sm text-gray-500">Recent management updates</p></div><a href="<?php echo BASE_URL; ?>/tenant/notices.php" class="text-sm font-semibold text-primary hover:underline">View all</a></div>
                        <div class="divide-y divide-gray-100"><?php if (!$notices): ?><p class="p-5 text-sm text-gray-500">No notices have been sent to your account.</p><?php else: foreach ($notices as $notice): ?><article class="p-4"><div class="flex items-start justify-between gap-2"><h3 class="font-semibold text-sm text-gray-900"><?php echo e($notice['title']); ?></h3><?php if (!(int)$notice['is_read']): ?><span class="shrink-0 rounded-full bg-blue-100 px-2 py-0.5 text-xs font-semibold text-blue-800">New</span><?php endif; ?></div><p class="mt-1 text-sm text-gray-600"><?php echo nl2br(e(mb_strimwidth($notice['message'], 0, 180, '…'))); ?></p><time class="mt-2 block text-xs text-gray-400" datetime="<?php echo e(date('c', strtotime($notice['created_at']))); ?>"><?php echo e(date('d M Y, H:i', strtotime($notice['created_at']))); ?></time></article><?php endforeach; endif; ?></div>
                    </section>
                </div>

                <div class="grid grid-cols-1 xl:grid-cols-2 gap-5">
                    <section class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden" aria-labelledby="charges-heading">
                        <div class="p-5 border-b border-gray-100 flex items-center justify-between gap-3"><div><h2 id="charges-heading" class="font-bold text-gray-900">Rent charges</h2><p class="mt-1 text-sm text-gray-500">Unpaid totals reflect recorded allocations.</p></div><a href="<?php echo BASE_URL; ?>/tenant/rent.php" class="text-sm font-semibold text-primary hover:underline">Full statement</a></div>
                        <div class="overflow-x-auto"><table class="w-full min-w-[600px] text-left"><thead><tr class="bg-gray-50 text-xs font-semibold uppercase text-gray-500"><th scope="col" class="p-4">Period</th><th scope="col" class="p-4">Due date</th><th scope="col" class="p-4">Total due</th><th scope="col" class="p-4">Remaining</th><th scope="col" class="p-4">Status</th></tr></thead><tbody class="divide-y divide-gray-100 text-sm">
                            <?php if (!$charges): ?><tr><td colspan="5" class="p-5 text-center text-gray-500">No rent charges have been recorded.</td></tr><?php else: foreach ($charges as $charge): ?><tr><td class="p-4 font-medium"><?php echo e($charge['billing_period']); ?></td><td class="p-4"><?php echo e(date('d M Y', strtotime($charge['due_date']))); ?></td><td class="p-4"><?php echo format_currency($charge['total_due']); ?></td><td class="p-4 font-semibold"><?php echo format_currency($charge['remaining']); ?></td><td class="p-4"><?php echo render_status_badge($charge['status']); ?></td></tr><?php endforeach; endif; ?>
                        </tbody></table></div>
                    </section>

                    <section class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden" aria-labelledby="payments-heading">
                        <div class="p-5 border-b border-gray-100 flex items-center justify-between gap-3"><div><h2 id="payments-heading" class="font-bold text-gray-900">Payment records</h2><p class="mt-1 text-sm text-gray-500">Receipts recorded by management.</p></div><a href="<?php echo BASE_URL; ?>/tenant/payments.php" class="text-sm font-semibold text-primary hover:underline">All receipts</a></div>
                        <div class="overflow-x-auto"><table class="w-full min-w-[560px] text-left"><thead><tr class="bg-gray-50 text-xs font-semibold uppercase text-gray-500"><th scope="col" class="p-4">Receipt / reference</th><th scope="col" class="p-4">Period</th><th scope="col" class="p-4">Amount</th><th scope="col" class="p-4">Date</th></tr></thead><tbody class="divide-y divide-gray-100 text-sm">
                            <?php if (!$payments): ?><tr><td colspan="4" class="p-5 text-center text-gray-500">No recorded payments yet.</td></tr><?php else: foreach ($payments as $payment): ?><tr><td class="p-4 font-medium text-gray-900"><?php echo e($payment['receipt_number']); ?><span class="block text-xs font-normal text-gray-500"><?php echo e($payment['transaction_reference']); ?></span></td><td class="p-4"><?php echo e($payment['payment_period']); ?></td><td class="p-4 font-semibold"><?php echo format_currency($payment['amount']); ?></td><td class="p-4 whitespace-nowrap"><?php echo e(date('d M Y', strtotime($payment['payment_date']))); ?></td></tr><?php endforeach; endif; ?>
                        </tbody></table></div>
                    </section>
                </div>

                <section class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden" aria-labelledby="maintenance-heading">
                    <div class="p-5 border-b border-gray-100 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3"><div><h2 id="maintenance-heading" class="font-bold text-gray-900">Maintenance requests</h2><p class="mt-1 text-sm text-gray-500">Track reported repair issues and caretaker updates.</p></div><a href="<?php echo BASE_URL; ?>/tenant/maintenance.php" class="inline-flex items-center justify-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-blue-800 focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2"><i class="fa-solid fa-plus" aria-hidden="true"></i> Submit a request</a></div>
                    <div class="divide-y divide-gray-100"><?php if (!$maintenanceRequests): ?><p class="p-5 text-sm text-gray-500">No maintenance requests have been submitted.</p><?php else: foreach ($maintenanceRequests as $request): ?><article class="p-4 sm:p-5"><div class="flex flex-wrap items-center justify-between gap-2"><h3 class="font-semibold text-gray-900"><?php echo e($request['category']); ?> · <?php echo e($request['property_name'] . ' / ' . $request['unit_number']); ?></h3><div class="flex gap-2"><?php echo render_status_badge($request['priority']); ?><?php echo render_status_badge($request['status']); ?></div></div><p class="mt-1 text-sm text-gray-600"><?php echo e(mb_strimwidth($request['description'], 0, 220, '…')); ?></p><?php if ($request['caretaker_note']): ?><p class="mt-2 text-sm text-gray-700"><strong>Latest caretaker update:</strong> <?php echo e($request['caretaker_note']); ?></p><?php endif; ?><p class="mt-2 text-xs text-gray-400"><?php echo e(date('d M Y, H:i', strtotime($request['created_at']))); ?></p></article><?php endforeach; endif; ?></div>
                </section>

                <div class="grid grid-cols-1 xl:grid-cols-2 gap-5">
                    <section class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden" aria-labelledby="utilities-heading">
                        <div class="p-5 border-b border-gray-100 flex items-center justify-between gap-3"><div><h2 id="utilities-heading" class="font-bold text-gray-900">Utility readings</h2><p class="mt-1 text-sm text-gray-500">Recorded meter consumption and charges.</p></div><a href="<?php echo BASE_URL; ?>/tenant/bills.php" class="text-sm font-semibold text-primary hover:underline">View bills</a></div>
                        <div class="overflow-x-auto"><table class="w-full min-w-[560px] text-left"><thead><tr class="bg-gray-50 text-xs font-semibold uppercase text-gray-500"><th scope="col" class="p-4">Period</th><th scope="col" class="p-4">Utility / unit</th><th scope="col" class="p-4">Consumption</th><th scope="col" class="p-4">Amount</th></tr></thead><tbody class="divide-y divide-gray-100 text-sm">
                            <?php if (!$utilityReadings): ?><tr><td colspan="4" class="p-5 text-center text-gray-500">No utility readings have been recorded for your units.</td></tr><?php else: foreach ($utilityReadings as $reading): ?><tr><td class="p-4"><?php echo e($reading['billing_period']); ?></td><td class="p-4"><?php echo e($reading['utility_name'] . ' · ' . $reading['unit_number']); ?></td><td class="p-4"><?php echo e(number_format((float)$reading['consumption'], 2)); ?><span class="block text-xs text-gray-500"><?php echo e(number_format((float)$reading['previous_reading'], 2) . ' → ' . number_format((float)$reading['current_reading'], 2)); ?></span></td><td class="p-4 font-semibold"><?php echo format_currency($reading['total_amount']); ?></td></tr><?php endforeach; endif; ?>
                        </tbody></table></div>
                    </section>

                    <section class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden" aria-labelledby="documents-heading">
                        <div class="p-5 border-b border-gray-100 flex items-center justify-between gap-3"><div><h2 id="documents-heading" class="font-bold text-gray-900">Documents</h2><p class="mt-1 text-sm text-gray-500">Lease and account files shared with your tenant profile.</p></div><a href="<?php echo BASE_URL; ?>/tenant/documents.php" class="text-sm font-semibold text-primary hover:underline">Document center</a></div>
                        <ul class="divide-y divide-gray-100"><?php if (!$documents): ?><li class="p-5 text-sm text-gray-500">No documents have been attached to your profile yet.</li><?php else: foreach ($documents as $document): ?><li class="flex items-center justify-between gap-3 p-4"><div><p class="font-medium text-sm text-gray-900"><?php echo e($document['title']); ?></p><p class="mt-1 text-xs text-gray-500"><?php echo e($document['document_type']); ?> · <?php echo e(date('d M Y', strtotime($document['created_at']))); ?></p></div><a class="text-sm font-semibold text-primary hover:underline" href="<?php echo BASE_URL; ?>/tenant/download_document.php?type=document&amp;id=<?php echo (int)$document['id']; ?>">Download</a></li><?php endforeach; endif; ?></ul>
                    </section>
                </div>

                <section class="rounded-xl border border-gray-200 bg-white p-5">
                    <h2 class="font-bold text-gray-900">Need help?</h2>
                    <p class="mt-1 text-sm text-gray-600">For billing questions, tenancy support, or issues that need management attention, submit a complaint. For repairs, use the maintenance request form.</p>
                    <div class="mt-4 flex flex-wrap gap-3">
                        <a href="<?php echo BASE_URL; ?>/tenant/complaints.php" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2">Contact management</a>
                        <a href="<?php echo BASE_URL; ?>/tenant/profile.php" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2">Update contact details</a>
                    </div>
                    <p class="mt-3 text-xs text-gray-500">Direct messaging and online M-Pesa payments are not available in the current application.</p>
                </section>
            <?php endif; ?>
        </main>
        <?php include __DIR__ . '/../includes/footer.php'; ?>
    </div>
</div>
