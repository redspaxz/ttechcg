<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$shipments = is_array($shipments ?? null) ? $shipments : [];
$items = is_array($shipments['items'] ?? null) ? $shipments['items'] : [];
$customerKey = (string) ($customerKey ?? $customer?->customerKey ?? '');
$redemptionPage = max(1, (int) ($currentRedemptionPage ?? $rewardRedemptions['page'] ?? 1));
$trackingUrl = static fn (mixed $awbNumber): string => \App\Modules\Pickupsheet\Domain\DhlTrackingUrl::forAwb((string) $awbNumber);
?>
<div class="pickup-card-heading"><div><span>Operational history</span><h2 id="customer-history-title">Recent shipments</h2></div><small>10 per page</small></div>
<div class="pickup-crm-table-wrap"><table><thead><tr><th>Date</th><th>Reference</th><th>AWB</th><th>Destination</th><th>Amount</th><th>Status</th></tr></thead><tbody>
    <?php if ($items === []): ?><tr><td colspan="6">No shipment history is linked to this customer.</td></tr><?php endif; ?>
    <?php foreach ($items as $shipment): ?><tr><td><?= $e($shipment['collectionDate'] ?? '') ?></td><td><a href="<?= $e($basePath) ?>/dhl/pickupsheet/submissions?reference=<?= $e(rawurlencode((string) ($shipment['referenceNumber'] ?? ''))) ?>"><?= $e($shipment['referenceNumber'] ?? '') ?></a></td><td><a class="pickup-awb-link" href="<?= $e($trackingUrl($shipment['awbNumber'] ?? '')) ?>" target="_blank" rel="noopener noreferrer" aria-label="Track AWB <?= $e($shipment['awbNumber'] ?? '') ?> with DHL"><?= $e($shipment['awbNumber'] ?? '') ?></a></td><td><?= $e($shipment['destination'] ?? '') ?></td><td><?= $e(number_format((int) ($shipment['amountXaf'] ?? 0))) ?> XAF</td><td><?= $e(ucfirst((string) ($shipment['status'] ?? 'open'))) ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php
$pagerData = $shipments;
$pagerRecordLabel = ((int) ($shipments['totalRecords'] ?? 0)) === 1 ? 'shipment' : 'shipments';
$pagerAriaLabel = 'Customer shipment pages';
$pagerUrl = static fn (int $targetPage): string => ($basePath ?? '') . '/dhl/pickupsheet/customers/edit?' . http_build_query([
    'customer' => $customerKey,
    'shipment_page' => $targetPage,
    'redemption_page' => $redemptionPage,
]);
require __DIR__ . '/_pagination.php';
?>
