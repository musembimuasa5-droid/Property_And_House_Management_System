<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/finance.php';
require_once __DIR__ . '/../includes/condition.php';

requireRole('Tenant');
$db = db();
$user = getCurrentUser();
$profile = $db->prepare('SELECT id FROM tenants WHERE user_id = ?');
$profile->execute([$user['id']]);
$tenantId = (int)$profile->fetchColumn();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tenantId) {
    $reportId = (int)filter_var($_POST['report_id'] ?? 0, FILTER_VALIDATE_INT);
    $response = $_POST['response'] ?? '';
    $comment = is_string($_POST['comment'] ?? null) ? trim($_POST['comment']) : '';
    // The lease join limits a tenant to reports on their own tenancies.
    $owned = $db->prepare("SELECT cr.id, cr.report_type FROM condition_reports cr JOIN leases l ON l.id = cr.lease_id WHERE cr.id = ? AND l.tenant_id = ? AND cr.tenant_response = 'Pending'");
    $owned->execute([$reportId, $tenantId]);
    $report = $owned->fetch();
    if (!csrf_validate()) {
        set_flash('error', 'Your session expired. Please reload the page and try again.');
    } elseif (!$report) {
        set_flash('error', 'That report is not waiting for your response.');
    } elseif (!in_array($response, ['Agreed', 'Disputed'], true)) {
        set_flash('error', 'Choose whether you agree with the report or dispute it.');
    } elseif ($response === 'Disputed' && $comment === '') {
        set_flash('error', 'Explain what you disagree with, so management can review it.');
    } elseif (mb_strlen($comment) > 2000) {
        set_flash('error', 'Keep your comment to 2,000 characters.');
    } else {
        $db->prepare('UPDATE condition_reports SET tenant_response = ?, tenant_comment = ?, responded_at = NOW() WHERE id = ?')->execute([$response, $comment ?: null, $reportId]);
        notify_roles($db, MANAGEMENT_ROLES, "Condition report #$reportId " . strtolower($response), "{$user['full_name']} " . strtolower($response) . " the {$report['report_type']} report" . ($comment ? ': ' . mb_strimwidth($comment, 0, 120, '…') : '.'));
        logAuditAction('RESPOND', 'condition_reports', $reportId, "Tenant $response the {$report['report_type']} report", ['tenant_response' => 'Pending'], ['tenant_response' => $response, 'tenant_comment' => $comment]);
        set_flash('success', $response === 'Agreed' ? 'Thank you. Your agreement has been recorded.' : 'Your dispute has been recorded and management has been notified.');
    }
    redirect('tenant/deposit.php');
}

$leases = [];
if ($tenantId) {
    $stmt = $db->prepare("SELECT l.id, l.start_date, l.end_date, l.status, p.name AS property_name, u.unit_number FROM leases l JOIN properties p ON p.id = l.property_id JOIN units u ON u.id = l.unit_id
        WHERE l.tenant_id = ? AND l.status <> 'Pending' ORDER BY l.status = 'Active' DESC, l.start_date DESC");
    $stmt->execute([$tenantId]);
    $leases = $stmt->fetchAll();
}

$page_title = 'Deposit & Condition';
include __DIR__ . '/../includes/layout_top.php';
?>
<div>
    <h1 class="text-2xl font-bold text-gray-900">Deposit &amp; Condition</h1>
    <p class="text-sm text-gray-500 mt-1">Your deposit ledger and the move-in and move-out inspections of your home. Check each report and tell us whether you agree.</p>
</div>
<?php if (!$leases): ?><div class="bg-white p-6 rounded-xl border border-gray-100 text-sm text-gray-500">No tenancy records yet.</div><?php endif; ?>
<?php foreach ($leases as $lease):
    $deposit = deposit_summary($db, (int)$lease['id']);
    $entries = $db->prepare('SELECT transaction_date, transaction_type, amount, reason, reference FROM deposit_transactions WHERE lease_id = ? ORDER BY transaction_date, id');
    $entries->execute([$lease['id']]);
    $entries = $entries->fetchAll();
    $reports = $db->prepare("SELECT cr.id FROM condition_reports cr WHERE cr.lease_id = ? ORDER BY FIELD(cr.report_type, 'Move-in', 'Move-out')");
    $reports->execute([$lease['id']]);
    ?>
    <section class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden" aria-labelledby="lease-<?php echo (int)$lease['id']; ?>">
        <div class="p-5 border-b border-gray-100 flex flex-wrap justify-between gap-2">
            <h2 id="lease-<?php echo (int)$lease['id']; ?>" class="font-bold text-gray-900"><?php echo e($lease['property_name'] . ' / ' . $lease['unit_number']); ?></h2>
            <p class="text-sm text-gray-500"><?php echo e(format_date($lease['start_date']) . ' – ' . format_date($lease['end_date'])); ?> <?php echo render_status_badge($lease['status']); ?></p>
        </div>
        <div class="p-5 space-y-4">
            <div class="grid grid-cols-2 lg:grid-cols-5 gap-3">
                <?php foreach ([['Agreed deposit', $deposit['agreed']], ['Received', $deposit['received']], ['Deducted', $deposit['deducted']], ['Refunded', $deposit['refunded']], ['Held now', $deposit['held']]] as [$label, $amount]): ?>
                    <div class="bg-gray-50 rounded-lg p-3"><p class="text-xs font-semibold uppercase text-gray-500"><?php echo e($label); ?></p><p class="font-bold text-gray-900"><?php echo format_currency($amount); ?></p></div>
                <?php endforeach; ?>
            </div>
            <p class="text-sm">Deposit status: <?php echo render_status_badge($deposit['status']); ?></p>
            <div class="overflow-x-auto"><table class="w-full text-left text-sm">
                <caption class="text-left font-semibold text-gray-900 pb-2">Deposit ledger</caption>
                <thead><tr><th scope="col" class="p-3">Date</th><th scope="col" class="p-3">Entry</th><th scope="col" class="p-3">Amount</th><th scope="col" class="p-3">Reason</th><th scope="col" class="p-3">Reference</th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                <?php if (!$entries): ?><tr><td colspan="5" class="p-4 text-center text-gray-500">No deposit entries recorded yet.</td></tr><?php endif; ?>
                <?php foreach ($entries as $entry): ?>
                    <tr><td class="p-3"><?php echo e(format_date($entry['transaction_date'])); ?></td><td class="p-3"><?php echo render_status_badge($entry['transaction_type']); ?></td>
                        <td class="p-3 font-semibold"><?php echo format_currency($entry['amount']); ?></td><td class="p-3"><?php echo e($entry['reason'] ?? ''); ?></td><td class="p-3"><?php echo e($entry['reference'] ?? ''); ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        </div>
    </section>
    <?php foreach ($reports->fetchAll(PDO::FETCH_COLUMN) as $reportId):
        $report = condition_report_find($db, (int)$reportId);
        ?>
        <section class="bg-white p-5 rounded-xl border border-gray-100 shadow-sm space-y-4" aria-labelledby="report-<?php echo (int)$reportId; ?>">
            <h2 id="report-<?php echo (int)$reportId; ?>" class="font-bold text-gray-900"><?php echo e($report['report_type']); ?> inspection — <?php echo e($report['property_name'] . ' / ' . $report['unit_number']); ?></h2>
            <?php render_condition_report($report, condition_report_items($db, (int)$reportId), condition_baseline($db, $report)); ?>
            <?php render_condition_response($report); ?>
            <?php if ($report['tenant_response'] === 'Pending'): ?>
            <form method="post" class="bg-gray-50 rounded-lg p-4 space-y-3">
                <?php echo csrf_field(); ?><input type="hidden" name="report_id" value="<?php echo (int)$reportId; ?>">
                <fieldset><legend class="text-sm font-semibold text-gray-900 mb-2">Do you agree with this report?</legend>
                    <label class="flex items-center gap-2 text-sm"><input type="radio" name="response" value="Agreed" required> I agree it is accurate</label>
                    <label class="flex items-center gap-2 text-sm"><input type="radio" name="response" value="Disputed"> I dispute part of it</label>
                </fieldset>
                <div><label for="comment-<?php echo (int)$reportId; ?>" class="block text-xs font-semibold text-gray-500 uppercase mb-1">Comment (required if you dispute)</label>
                    <textarea id="comment-<?php echo (int)$reportId; ?>" name="comment" rows="3" maxlength="2000" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"></textarea></div>
                <button type="submit" class="btn btn-primary">Send response</button>
            </form>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
<?php endforeach; ?>
<?php include __DIR__ . '/../includes/layout_bottom.php'; ?>
