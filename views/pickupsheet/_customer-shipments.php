<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$shipments = is_array($shipments ?? null) ? $shipments : [];
$items = is_array($shipments['items'] ?? null) ? $shipments['items'] : [];
$customerKey = (string) ($customerKey ?? $customer?->customerKey ?? '');
$pointsPage = max(1, (int) ($currentPointsPage ?? $rewardHistory['page'] ?? 1));
$perPage = max(1, (int) ($shipments['perPage'] ?? 10));
?>
<div class="pickup-card-heading"><div><span>Operational history</span><h2 id="customer-history-title">Recent shipments</h2></div><small><?= $e($perPage) ?> per page</small></div>
<div class="pickup-crm-table-wrap pickup-shipments-table-wrap"><table class="pickup-shipments-table">
    <colgroup><col class="col-date"><col class="col-reference"><col class="col-awb"><col class="col-destination"><col class="col-amount"><col class="col-status"></colgroup>
    <thead><tr><th scope="col">Date</th><th scope="col">Reference</th><th scope="col">AWB</th><th scope="col">Destination</th><th scope="col">Amount</th><th scope="col">Status</th></tr></thead><tbody>
    <?php if ($items === []): ?><tr><td colspan="6">No shipment history is linked to this customer.</td></tr><?php endif; ?>
    <?php foreach ($items as $shipment): ?>
        <?php $shipmentStatus = (string) ($shipment['status'] ?? 'open'); ?>
        <tr>
            <td data-label="Date"><?= $e($shipment['collectionDate'] ?? '') ?></td>
            <td data-label="Reference"><a href="<?= $e($basePath) ?>/dhl/pickupsheet/submissions/print?reference=<?= $e(rawurlencode((string) ($shipment['referenceNumber'] ?? ''))) ?>&amp;customer=<?= $e(rawurlencode($customerKey)) ?>"><?= $e($shipment['referenceNumber'] ?? '') ?></a></td>
            <td data-label="AWB"><?= \App\Modules\Pickupsheet\UI\AwbLink::html((string) $shipment['awbNumber'] ?? '', (string) ($shipment['collectionDate'] ?? '')) ?></td>
            <td data-label="Destination"><?= $e($shipment['destination'] ?? '') ?></td>
            <td data-label="Amount" class="pickup-shipments-amount"><?= $e(number_format((int) ($shipment['amountXaf'] ?? 0))) ?> XAF</td>
            <td data-label="Status"><span class="pickup-shipment-status is-<?= $e($shipmentStatus === 'paid' ? 'paid' : 'open') ?>"><?= $e(ucfirst($shipmentStatus)) ?></span></td>
        </tr>
    <?php endforeach; ?>
</tbody></table></div>
<?php
$pagerData = $shipments;
$pagerRecordLabel = ((int) ($shipments['totalRecords'] ?? 0)) === 1 ? 'shipment' : 'shipments';
$pagerAriaLabel = 'Customer shipment pages';
$pagerSizeParam = 'shipment_per_page';
$pagerUrl = static fn (int $targetPage): string => ($basePath ?? '') . '/dhl/pickupsheet/customers/edit?' . http_build_query([
    'customer' => $customerKey,
    'shipment_page' => $targetPage,
    'points_page' => $pointsPage,
]);
require __DIR__ . '/_pagination.php';
?>
