<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$pickupSheets = is_array($pickupSheets ?? null) ? $pickupSheets : [];
$errors = is_array($errors ?? null) ? $errors : [];
$pickupOperational = (bool) ($pickupOperational ?? false);
$canPrint = (bool) ($canPrint ?? false);
$canExport = (bool) ($canExport ?? false);
$canEdit = (bool) ($canEdit ?? false);
$canMarkPaid = (bool) ($canMarkPaid ?? false);
$canDelete = (bool) ($canDelete ?? false);
$pagination = is_array($pagination ?? null) ? $pagination : [];
$search = trim(is_string($search ?? null) ? $search : '');
$page = max(1, (int) ($pagination['page'] ?? 1));
$totalPages = max(1, (int) ($pagination['totalPages'] ?? 1));
$totalRecords = max(0, (int) ($pagination['totalRecords'] ?? 0));
$totalXaf = array_reduce(
    $pickupSheets,
    static fn (int $total, mixed $sheet): int => $total + (int) ($sheet->totalCashReceivedXaf ?? 0),
    0,
);
$pageUrl = static function (int $target) use ($basePath, $search): string {
    $query = ['page' => $target];
    if ($search !== '') {
        $query['q'] = $search;
    }

    return ($basePath ?? '') . '/dhl/pickupsheet/submissions?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
};
$trackingUrl = static fn (mixed $awbNumber): string => \App\Modules\Pickupsheet\Domain\DhlTrackingUrl::forAwb((string) $awbNumber);
?>
<?php if (!$pickupOperational): ?>
    <div class="notice notice-error" role="alert">Pickup-sheet storage is unavailable. Check the MySQL connection.</div>
<?php endif; ?>
<?php if ($errors !== []): ?>
    <div class="notice notice-error" role="alert"><?php foreach ($errors as $error): ?><span><?= $e($error) ?></span><?php endforeach; ?></div>
<?php endif; ?>

<div class="pickup-view-summary">
    <div><span>Sheets on this page</span><strong><?= $e(count($pickupSheets)) ?></strong></div>
    <div><span>Page cash total</span><strong><?= $e(number_format($totalXaf)) ?> XAF</strong></div>
</div>

<div class="pickup-record-list">
    <?php if ($pickupSheets === []): ?>
        <?php if ($search !== ''): ?>
            <div class="pickup-empty-state"><h2>No matching sheets.</h2><p>Try another reference, agent, consignor, AWB, destination, or checker.</p></div>
        <?php else: ?>
            <div class="pickup-empty-state"><h2>No submitted sheets yet.</h2><p>Saved pickup sheets will appear here.</p></div>
        <?php endif; ?>
    <?php endif; ?>

    <?php foreach ($pickupSheets as $pickupSheet): ?>
        <?php $referenceQuery = rawurlencode($pickupSheet->referenceNumber); $isPaid = $pickupSheet->isPaid(); ?>
        <article class="pickup-record">
            <div class="pickup-record-overview">
                <span class="pickup-record-reference"><?= $e($pickupSheet->referenceNumber) ?><small class="pickup-record-status" data-status="<?= $isPaid ? 'paid' : 'open' ?>"><?= $isPaid ? 'Paid' : 'Open' ?></small></span>
                <span><small>Date</small><?= $e($pickupSheet->collectionDate) ?></span>
                <span><small>Agent</small><?= $e($pickupSheet->agentName) ?></span>
                <span><small>Shipments</small><?= $e($pickupSheet->shipmentCount()) ?></span>
                <strong><?= $e(number_format($pickupSheet->totalCashReceivedXaf)) ?> XAF</strong>
                <?php if ($canPrint || $canExport || $canEdit || $canMarkPaid || $canDelete): ?>
                    <div class="pickup-record-actions">
                        <?php if ($canEdit): ?>
                            <a href="<?= $e($basePath) ?>/dhl/pickupsheet/submissions/edit?reference=<?= $e($referenceQuery) ?>">Edit record</a>
                        <?php endif; ?>
                        <?php if ($canPrint): ?>
                            <a target="_blank" rel="noopener" href="<?= $e($basePath) ?>/dhl/pickupsheet/submissions/print?reference=<?= $e($referenceQuery) ?>">Print / PDF</a>
                        <?php endif; ?>
                        <?php if ($canExport): ?>
                            <a href="<?= $e($basePath) ?>/dhl/pickupsheet/submissions/export?reference=<?= $e($referenceQuery) ?>">Export Excel</a>
                        <?php endif; ?>
                        <?php if ($canMarkPaid && !$isPaid): ?>
                            <form method="post" action="<?= $e($basePath) ?>/dhl/pickupsheet/submissions/paid"><input type="hidden" name="_token" value="<?= $e($csrfToken) ?>"><input type="hidden" name="reference" value="<?= $e($pickupSheet->referenceNumber) ?>"><button class="pickup-record-paid" type="submit">Mark paid</button></form>
                        <?php endif; ?>
                        <?php if ($canDelete): ?>
                            <form method="post" action="<?= $e($basePath) ?>/dhl/pickupsheet/submissions/delete" data-pickup-delete><input type="hidden" name="_token" value="<?= $e($csrfToken) ?>"><input type="hidden" name="reference" value="<?= $e($pickupSheet->referenceNumber) ?>"><button class="pickup-record-delete" type="submit">Delete</button></form>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
            <details class="pickup-record-details">
                <summary><span>View shipment table</span><i aria-hidden="true">+</i></summary>
                <div class="pickup-record-body">
                    <div class="pickup-record-table-wrap">
                        <table class="pickup-record-table">
                            <thead><tr><th>#</th><th>Consignor</th><th>AWB number</th><th>Dest</th><th>Amount</th><th>Pces</th><th>Wgt</th><th>Time coll</th><th>Check by</th></tr></thead>
                            <tbody>
                            <?php foreach ($pickupSheet->shipments as $shipment): ?>
                                <tr>
                                    <td><?= $e($shipment->lineNumber) ?></td>
                                    <td><?= $e($shipment->consignor) ?></td>
                                    <td><a class="pickup-awb-link" href="<?= $e($trackingUrl($shipment->awbNumber)) ?>" target="_blank" rel="noopener noreferrer" aria-label="Track AWB <?= $e($shipment->awbNumber) ?> with DHL"><?= $e($shipment->awbNumber) ?></a></td>
                                    <td><?= $e($shipment->destination) ?></td>
                                    <td><?= $e(number_format($shipment->amountXaf)) ?></td>
                                    <td><?= $e($shipment->pieces) ?></td>
                                    <td><?= $e(rtrim(rtrim($shipment->weightKg, '0'), '.')) ?> kg</td>
                                    <td><?= $e($shipment->collectionTime) ?></td>
                                    <td><?= $e($shipment->checkedBy) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                            <tfoot><tr><th colspan="4">Total</th><th><?= $e(number_format($pickupSheet->totalCashReceivedXaf)) ?> XAF</th><th colspan="4"><?= $e($pickupSheet->shipmentCount()) ?> shipment<?= $pickupSheet->shipmentCount() === 1 ? '' : 's' ?></th></tr></tfoot>
                        </table>
                    </div>
                </div>
            </details>
        </article>
    <?php endforeach; ?>
</div>

<?php
$pagerData = $pagination;
$pagerRecordLabel = $totalRecords === 1 ? 'record' : 'records';
$pagerAriaLabel = 'Submitted pickup-sheet pages';
$pagerUrl = $pageUrl;
require __DIR__ . '/_pagination.php';
?>
