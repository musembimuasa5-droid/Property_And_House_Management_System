<?php
/**
 * Printable payment receipt shared by the staff and tenant portals.
 * Callers check access first, then call render_receipt_page().
 */

require_once __DIR__ . '/functions.php';

function find_receipt(PDO $db, int $payment_id): ?array {
    $stmt = $db->prepare('SELECT pay.*, t.full_name AS tenant_name, t.phone AS tenant_phone, t.user_id AS tenant_user_id, p.name AS property_name, p.landlord_id,
            p.physical_address, u.unit_number, rb.full_name AS recorded_by_name
        FROM payments pay JOIN tenants t ON t.id = pay.tenant_id JOIN properties p ON p.id = pay.property_id
        JOIN units u ON u.id = pay.unit_id JOIN users rb ON rb.id = pay.recorded_by WHERE pay.id = ?');
    $stmt->execute([$payment_id]);
    return $stmt->fetch() ?: null;
}

function render_receipt_page(PDO $db, array $payment, string $back_url): void {
    $allocations = $db->prepare('SELECT rc.billing_period, pa.allocated_amount FROM payment_allocations pa JOIN rent_charges rc ON rc.id = pa.rent_charge_id WHERE pa.payment_id = ? ORDER BY rc.billing_period');
    $allocations->execute([$payment['id']]);
    $allocations = $allocations->fetchAll();
    $applied = array_sum(array_column($allocations, 'allocated_amount'));
    $page_title = 'Receipt ' . $payment['receipt_number'];
    include __DIR__ . '/layout_top.php';
    ?>
    <div class="flex flex-wrap gap-3 no-print">
        <a href="<?php echo e($back_url); ?>" class="btn btn-secondary"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Back</a>
        <button type="button" class="btn btn-primary" data-print><i class="fa-solid fa-print" aria-hidden="true"></i> Print / save as PDF</button>
    </div>
    <article class="receipt print-container bg-white p-8 rounded-xl border border-gray-100 shadow-sm max-w-2xl">
        <div class="flex flex-wrap justify-between gap-4 border-b border-gray-100 pb-5">
            <div><p class="text-lg font-bold text-gray-900"><?php echo e(APP_NAME); ?></p><p class="text-sm text-gray-500">Official rent receipt</p></div>
            <div class="text-right"><p class="text-xs uppercase text-gray-500">Receipt no.</p><p class="font-bold text-gray-900"><?php echo e($payment['receipt_number']); ?></p></div>
        </div>
        <dl class="receipt-details">
            <div><dt>Received from</dt><dd><?php echo e($payment['tenant_name']); ?><br><span class="text-gray-500"><?php echo e($payment['tenant_phone']); ?></span></dd></div>
            <div><dt>Property / unit</dt><dd><?php echo e($payment['property_name'] . ' / ' . $payment['unit_number']); ?><br><span class="text-gray-500"><?php echo e($payment['physical_address']); ?></span></dd></div>
            <div><dt>Date received</dt><dd><?php echo e(format_date($payment['payment_date'])); ?></dd></div>
            <div><dt>For period</dt><dd><?php echo e(format_period($payment['payment_period'])); ?></dd></div>
            <div><dt>Method</dt><dd><?php echo e($payment['payment_method']); ?></dd></div>
            <div><dt>Reference</dt><dd><?php echo e($payment['transaction_reference']); ?></dd></div>
        </dl>
        <div class="receipt-total"><span>Amount received</span><strong><?php echo format_currency($payment['amount']); ?></strong></div>
        <h2 class="font-bold text-gray-900 mt-6 mb-2 text-sm uppercase">Applied to</h2>
        <table class="w-full text-left table-no-scroll">
            <thead><tr><th scope="col" class="p-2">Charge</th><th scope="col" class="p-2 text-right">Amount</th></tr></thead>
            <tbody>
            <?php foreach ($allocations as $allocation): ?><tr class="border-t"><td class="p-2">Rent charge — <?php echo e(format_period($allocation['billing_period'])); ?></td><td class="p-2 text-right"><?php echo format_currency($allocation['allocated_amount']); ?></td></tr><?php endforeach; ?>
            <?php if ($payment['amount'] - $applied > 0.004): ?><tr class="border-t"><td class="p-2">Credit carried forward</td><td class="p-2 text-right"><?php echo format_currency($payment['amount'] - $applied); ?></td></tr><?php endif; ?>
            </tbody>
        </table>
        <?php if (!empty($payment['notes'])): ?><p class="text-sm text-gray-600 mt-4"><strong>Notes:</strong> <?php echo nl2br(e($payment['notes'])); ?></p><?php endif; ?>
        <p class="text-xs text-gray-500 mt-6 pt-4 border-t border-gray-100">Recorded by <?php echo e($payment['recorded_by_name']); ?> on <?php echo e(format_date($payment['created_at'])); ?>. Keep this receipt for your records.</p>
    </article>
    <?php
    include __DIR__ . '/layout_bottom.php';
}
