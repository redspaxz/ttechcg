<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$rewardHistory = is_array($rewardHistory ?? null) ? $rewardHistory : [];
$items = is_array($rewardHistory['items'] ?? null) ? $rewardHistory['items'] : [];
$actorNames = is_array($actorNames ?? null) ? $actorNames : [];
$customerKey = (string) ($customerKey ?? $customer?->customerKey ?? '');
$shipmentPage = max(1, (int) ($currentShipmentPage ?? $shipments['page'] ?? 1));
$perPage = max(1, (int) ($rewardHistory['perPage'] ?? 10));
// Entries made before names were recorded fall back to the known account for their code.
$actorName = static fn (array $entry): string => ($entry['actorName'] ?? '') !== ''
    ? (string) $entry['actorName']
    : (string) ($actorNames[$entry['actorId'] ?? ''] ?? 'Administrator');
?>
<div class="pickup-points-heading"><div><span>Audit trail</span><h3>Points history</h3></div><small>Bonuses and redemptions &middot; <?= $e($perPage) ?> per page</small></div>
<?php if ($items === []): ?>
    <p class="pickup-points-empty">No bonuses or redemptions have been recorded.</p>
<?php else: ?>
    <div class="pickup-points-table-wrap"><table><thead><tr><th>Change</th><th>Reason</th><th>Date</th><th>By</th></tr></thead><tbody>
        <?php foreach ($items as $entry): ?>
            <?php $delta = (int) ($entry['pointsDelta'] ?? 0); ?>
            <tr><td><strong class="<?= $delta < 0 ? 'is-redemption' : 'is-bonus' ?>"><?= $delta > 0 ? '+' : '' ?><?= $e(number_format($delta)) ?> points</strong></td><td><?= $e($entry['reason'] ?? '') ?></td><td><?= $e($entry['createdAt'] ?? '') ?> UTC</td><td><?= $e($actorName($entry)) ?></td></tr>
        <?php endforeach; ?>
    </tbody></table></div>
<?php endif; ?>
<?php
$pagerData = $rewardHistory;
$pagerRecordLabel = ((int) ($rewardHistory['totalRecords'] ?? 0)) === 1 ? 'entry' : 'entries';
$pagerAriaLabel = 'Customer points history pages';
$pagerSizeParam = 'points_per_page';
$pagerUrl = static fn (int $targetPage): string => ($basePath ?? '') . '/dhl/pickupsheet/customers/edit?' . http_build_query([
    'customer' => $customerKey,
    'shipment_page' => $shipmentPage,
    'points_page' => $targetPage,
]);
require __DIR__ . '/_pagination.php';
