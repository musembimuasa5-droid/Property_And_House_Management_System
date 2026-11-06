<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/finance.php';

requireRole('Tenant');

$db = db();
$user = getCurrentUser();

// Fetch tenant details linked to user
$stmt = $db->prepare("SELECT * FROM tenants WHERE user_id = ?");
$stmt->execute([$user['id']]);
$tenant = $stmt->fetch();

if (!$tenant) {
    http_response_code(403);
    exit('Tenant profile not linked. Please contact administrator.');
}

// Fetch active lease and unit
$lease_stmt = $db->prepare("
    SELECT l.*, u.unit_number, p.name as property_name
    FROM leases l
    JOIN units u ON l.unit_id = u.id
    JOIN properties p ON l.property_id = p.id
    WHERE l.tenant_id = ? AND l.status = 'Active'
");
$lease_stmt->execute([$tenant['id']]);
$lease = $lease_stmt->fetch();

$balance = $db->prepare('SELECT COALESCE(SUM(rc.total_due - COALESCE(pa.paid, 0)), 0) FROM rent_charges rc LEFT JOIN ' . CHARGE_PAID_SUBQUERY . ' pa ON pa.rent_charge_id = rc.id WHERE rc.tenant_id = ?');
$balance->execute([$tenant['id']]);
$balance = (float)$balance->fetchColumn();
$next = $db->prepare('SELECT rc.billing_period, rc.due_date, rc.total_due - COALESCE(pa.paid, 0) AS balance FROM rent_charges rc LEFT JOIN ' . CHARGE_PAID_SUBQUERY . ' pa ON pa.rent_charge_id = rc.id
    WHERE rc.tenant_id = ? AND rc.total_due - COALESCE(pa.paid, 0) > 0 ORDER BY rc.due_date LIMIT 1');
$next->execute([$tenant['id']]);
$nextCharge = $next->fetch();
$counts = $db->prepare("SELECT
    (SELECT COUNT(*) FROM maintenance_requests WHERE tenant_id = ? AND status NOT IN ('Completed', 'Cancelled')),
    (SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0)");
$counts->execute([$tenant['id'], $user['id']]);
[$openRequests, $unreadNotices] = $counts->fetch(PDO::FETCH_NUM);
$recent = $db->prepare('SELECT id, receipt_number, amount, payment_date, payment_method FROM payments WHERE tenant_id = ? ORDER BY payment_date DESC, id DESC LIMIT 3');
$recent->execute([$tenant['id']]);
$recentPayments = $recent->fetchAll();
$daysLeft = $lease ? (int)floor((strtotime($lease['end_date']) - strtotime(date('Y-m-d'))) / 86400) : null;

$page_title = 'Tenant Dashboard';
include __DIR__ . '/../includes/layout_top.php';
?>
<div>
    <p class="page-eyebrow"><?php echo e(date('l, d F Y')); ?></p>
    <h1 class="text-2xl font-bold text-gray-900">Welcome, <?php echo e($tenant['full_name']); ?></h1>
    <p class="text-sm text-gray-500">Manage your lease, view rent balances, and submit requests.</p>
</div>

<div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-5">
    <a class="stat-tile bg-white p-5 rounded-xl border border-gray-100 shadow-sm" href="<?php echo BASE_URL; ?>/tenant/rent.php">
        <p class="stat-label">Outstanding balance</p>
        <p class="stat-value <?php echo $balance > 0 ? 'text-red-700' : ''; ?>"><?php echo format_currency($balance); ?></p>
        <p class="stat-detail"><?php echo $nextCharge ? 'Next: ' . e(format_currency($nextCharge['balance'])) . ' for ' . e(format_period($nextCharge['billing_period'])) . ($nextCharge['due_date'] < date('Y-m-d') ? ' — <span class="delta-bad">overdue since ' . e(format_date($nextCharge['due_date'])) . '</span>' : ', due ' . e(format_date($nextCharge['due_date']))) : 'You are fully paid up.'; ?></p>
    </a>
    <article class="stat-tile bg-white p-5 rounded-xl border border-gray-100 shadow-sm">
        <p class="stat-label">Current unit</p>
        <p class="stat-value text-lg"><?php echo $lease ? e($lease['property_name'] . ' — ' . $lease['unit_number']) : 'No active lease'; ?></p>
        <p class="stat-detail"><?php echo $lease ? 'Rent ' . e(format_currency($lease['monthly_rent'])) . ', due on day ' . (int)$lease['payment_due_day'] : ''; ?></p>
    </article>
    <article class="stat-tile bg-white p-5 rounded-xl border border-gray-100 shadow-sm">
        <p class="stat-label">Lease</p>
        <div class="mt-2"><?php echo render_status_badge($lease['status'] ?? 'None'); ?></div>
        <p class="stat-detail"><?php echo $lease ? 'Ends ' . e(format_date($lease['end_date'])) . ($daysLeft !== null && $daysLeft <= 60 ? ' · <span class="delta-bad">' . $daysLeft . ' days left</span>' : '') : ''; ?></p>
    </article>
    <a class="stat-tile bg-white p-5 rounded-xl border border-gray-100 shadow-sm" href="<?php echo BASE_URL; ?>/tenant/maintenance.php">
        <p class="stat-label">Open requests</p>
        <p class="stat-value"><?php echo (int)$openRequests; ?></p>
        <p class="stat-detail"><?php echo (int)$unreadNotices; ?> unread notice(s)</p>
    </a>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 items-start">
    <section class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
        <div class="p-5 border-b border-gray-100 flex justify-between gap-3"><h2 class="font-bold text-gray-900">Recent payments</h2><a class="text-sm font-semibold text-primary" href="<?php echo BASE_URL; ?>/tenant/payments.php">All payments <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a></div>
        <ul class="divide-y divide-gray-100">
            <?php if (!$recentPayments): ?><li class="p-5 text-sm text-gray-500">No payments recorded yet.</li><?php endif; ?>
            <?php foreach ($recentPayments as $payment): ?>
                <li class="p-4 flex flex-wrap items-center justify-between gap-3 text-sm">
                    <span><strong><?php echo format_currency($payment['amount']); ?></strong> <span class="text-gray-500">· <?php echo e(format_date($payment['payment_date']) . ' · ' . $payment['payment_method']); ?></span></span>
                    <a class="btn btn-small btn-secondary" href="<?php echo BASE_URL; ?>/tenant/receipt.php?id=<?php echo (int)$payment['id']; ?>">Receipt</a>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
    <section class="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
        <h2 class="font-bold text-gray-900 mb-3">Quick actions</h2>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <a class="btn btn-secondary justify-start" href="<?php echo BASE_URL; ?>/tenant/maintenance.php"><i class="fa-solid fa-screwdriver-wrench" aria-hidden="true"></i> Report a repair</a>
            <a class="btn btn-secondary justify-start" href="<?php echo BASE_URL; ?>/tenant/complaints.php"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i> Raise a complaint</a>
            <a class="btn btn-secondary justify-start" href="<?php echo BASE_URL; ?>/tenant/rent.php"><i class="fa-solid fa-receipt" aria-hidden="true"></i> Rent statement</a>
            <a class="btn btn-secondary justify-start" href="<?php echo BASE_URL; ?>/account/notifications.php"><i class="fa-solid fa-bell" aria-hidden="true"></i> Notices<?php echo $unreadNotices ? ' (' . (int)$unreadNotices . ' new)' : ''; ?></a>
        </div>
        <p class="text-xs text-gray-500 mt-4">Pay rent to the M-Pesa or bank details management gave you, then keep your transaction code. Your receipt appears here once the payment is recorded.</p>
    </section>
</div>
<?php include __DIR__ . '/../includes/layout_bottom.php'; ?>
