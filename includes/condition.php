<?php
/**
 * Move-in and move-out condition reports shared by the staff, landlord and tenant pages.
 *
 * A report is a fixed checklist of areas so that the move-out inspection can be compared
 * line by line with the move-in inspection: that comparison is the evidence behind any
 * deposit deduction.
 */

require_once __DIR__ . '/functions.php';

const CONDITION_REPORT_TYPES = ['Move-in', 'Move-out'];
const CONDITION_OVERALL = ['Excellent', 'Good', 'Fair', 'Poor'];
const CONDITION_ITEM_STATES = ['Good', 'Fair', 'Poor', 'Damaged', 'Missing'];
const CONDITION_AREAS = [
    'Walls and paint', 'Floors', 'Doors and locks', 'Windows and curtains',
    'Kitchen fittings', 'Bathroom and plumbing', 'Electrical fittings', 'Keys and access cards',
];

/** One report with its lease, tenant, unit and inspector; $landlordId limits it to that landlord's properties. */
function condition_report_find(PDO $db, int $id, ?int $landlordId = null): ?array {
    $sql = "SELECT cr.*, l.tenant_id, l.start_date, l.end_date, l.status AS lease_status, l.property_id, t.full_name AS tenant_name, t.user_id AS tenant_user_id,
            p.name AS property_name, u.unit_number, ins.full_name AS inspector_name
        FROM condition_reports cr JOIN leases l ON l.id = cr.lease_id JOIN tenants t ON t.id = l.tenant_id
        JOIN properties p ON p.id = l.property_id JOIN units u ON u.id = l.unit_id JOIN users ins ON ins.id = cr.inspected_by
        WHERE cr.id = ?" . ($landlordId ? ' AND p.landlord_id = ?' : '');
    $stmt = $db->prepare($sql);
    $stmt->execute($landlordId ? [$id, $landlordId] : [$id]);
    return $stmt->fetch() ?: null;
}

/** Checklist lines of a report keyed by area, in the standard order. */
function condition_report_items(PDO $db, int $reportId): array {
    $stmt = $db->prepare('SELECT * FROM condition_report_items WHERE report_id = ?');
    $stmt->execute([$reportId]);
    $items = [];
    foreach ($stmt->fetchAll() as $item) $items[$item['area']] = $item;
    $order = array_flip(CONDITION_AREAS);
    uksort($items, fn($a, $b) => ($order[$a] ?? 99) <=> ($order[$b] ?? 99));
    return $items;
}

/** The move-in checklist of the same lease, used as the baseline for a move-out report. */
function condition_baseline(PDO $db, array $report): ?array {
    if ($report['report_type'] !== 'Move-out') return null;
    $stmt = $db->prepare("SELECT id FROM condition_reports WHERE lease_id = ? AND report_type = 'Move-in'");
    $stmt->execute([$report['lease_id']]);
    $moveIn = $stmt->fetchColumn();
    return $moveIn ? condition_report_items($db, (int)$moveIn) : null;
}

/** True when an area is in a worse state at move-out than it was at move-in. */
function condition_got_worse(?string $before, string $after): bool {
    $rank = array_flip(CONDITION_ITEM_STATES);
    return $before !== null && isset($rank[$before], $rank[$after]) && $rank[$after] > $rank[$before];
}

/** Summary block and checklist table for one report. */
function render_condition_report(array $report, array $items, ?array $baseline): void {
    ?>
    <dl class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 text-sm">
        <div><dt class="text-xs font-semibold uppercase text-gray-500">Tenant</dt><dd class="font-semibold text-gray-900"><?php echo e($report['tenant_name']); ?></dd></div>
        <div><dt class="text-xs font-semibold uppercase text-gray-500">Property / unit</dt><dd><?php echo e($report['property_name'] . ' / ' . $report['unit_number']); ?></dd></div>
        <div><dt class="text-xs font-semibold uppercase text-gray-500">Inspected</dt><dd><?php echo e(format_date($report['inspection_date'])); ?> by <?php echo e($report['inspector_name']); ?></dd></div>
        <div><dt class="text-xs font-semibold uppercase text-gray-500">Overall condition</dt><dd><?php echo render_status_badge($report['overall_condition']); ?></dd></div>
    </dl>
    <?php if ($report['general_notes']): ?><p class="text-sm text-gray-700"><strong>Notes:</strong> <?php echo nl2br(e($report['general_notes'])); ?></p><?php endif; ?>
    <div class="overflow-x-auto"><table class="w-full text-left text-sm">
        <thead><tr><th scope="col" class="p-3">Area</th><?php if ($baseline !== null): ?><th scope="col" class="p-3">At move-in</th><?php endif; ?><th scope="col" class="p-3">Condition</th><th scope="col" class="p-3">Remarks</th><th scope="col" class="p-3">Photo</th></tr></thead>
        <tbody class="divide-y divide-gray-100">
        <?php foreach ($items as $area => $item): $before = $baseline[$area]['item_condition'] ?? null; ?>
            <tr>
                <td class="p-3 font-medium"><?php echo e($area); ?></td>
                <?php if ($baseline !== null): ?><td class="p-3"><?php echo $before ? render_status_badge($before) : '<span class="text-gray-400">—</span>'; ?></td><?php endif; ?>
                <td class="p-3"><?php echo render_status_badge($item['item_condition']); ?><?php if (condition_got_worse($before, $item['item_condition'])): ?> <span class="text-xs font-semibold text-red-700">Worse than at move-in</span><?php endif; ?></td>
                <td class="p-3"><?php echo $item['remarks'] !== null && $item['remarks'] !== '' ? e($item['remarks']) : '<span class="text-gray-400">—</span>'; ?></td>
                <td class="p-3"><?php if ($item['photo_path']): ?><a class="text-primary font-semibold" href="<?php echo e(attachment_url('condition', (int)$item['id'])); ?>" target="_blank" rel="noopener">View photo<span class="sr-only"> of <?php echo e($area); ?> (opens in a new tab)</span></a><?php else: ?><span class="text-gray-400">—</span><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php
}

/** Tenant's response to a report, shown to everyone who can see the report. */
function render_condition_response(array $report): void {
    echo '<p class="text-sm"><strong>Tenant response:</strong> ' . render_status_badge($report['tenant_response']);
    if ($report['responded_at']) echo ' <span class="text-xs text-gray-500">on ' . e(date('d M Y, H:i', strtotime($report['responded_at']))) . '</span>';
    echo '</p>';
    if ($report['tenant_comment']) echo '<p class="text-sm text-gray-700 bg-gray-50 rounded-lg p-3">' . nl2br(e($report['tenant_comment'])) . '</p>';
}
