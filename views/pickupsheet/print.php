<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<div class="print-actions">
    <button type="button" onclick="window.print()">Print / Save as PDF</button>
    <a href="<?= $e($basePath) ?>/pickupsheet/submissions">Back to submitted sheets</a>
</div>
<article class="print-sheet">
    <header class="print-heading">
        <div class="print-brand"><img src="<?= $e($assetBase . '/dhl-logo.svg') ?>" alt="DHL"><span aria-hidden="true">—</span><span>Pickupsheet</span></div>
        <div class="print-reference"><span>Reference number</span><strong><?= $e($pickupSheet->referenceNumber) ?></strong></div>
    </header>
    <div class="print-meta">
        <div><span>Agent name</span><strong><?= $e($pickupSheet->agentName) ?></strong></div>
        <div><span>Collection date</span><strong><?= $e($pickupSheet->collectionDate) ?></strong></div>
    </div>
    <h1>Cash shipments (clients cash)</h1>
    <table>
        <thead><tr><th>#</th><th>Consignor</th><th>AWB number</th><th>Dest</th><th>Amount</th><th>Pces</th><th>Wgt</th><th>Time coll</th><th>Check by</th></tr></thead>
        <tbody>
        <?php foreach ($pickupSheet->shipments as $shipment): ?>
            <tr>
                <td><?= $e($shipment->lineNumber) ?></td>
                <td><?= $e($shipment->consignor) ?></td>
                <td><?= $e($shipment->awbNumber) ?></td>
                <td><?= $e($shipment->destination) ?></td>
                <td><?= $e(number_format($shipment->amountXaf)) ?></td>
                <td><?= $e($shipment->pieces) ?></td>
                <td><?= $e(rtrim(rtrim($shipment->weightKg, '0'), '.')) ?> kg</td>
                <td><?= $e($shipment->collectionTime) ?></td>
                <td><?= $e($shipment->checkedBy) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><th colspan="4">Total cash received</th><th><?= $e(number_format($pickupSheet->totalCashReceivedXaf)) ?> XAF</th><th colspan="4"><?= $e($pickupSheet->shipmentCount()) ?> shipment<?= $pickupSheet->shipmentCount() === 1 ? '' : 's' ?> collected</th></tr></tfoot>
    </table>
    <div class="print-totals"><span>Shipments collected: <strong><?= $e($pickupSheet->shipmentCount()) ?></strong></span><span>Total cash received: <strong><?= $e(number_format($pickupSheet->totalCashReceivedXaf)) ?> XAF</strong></span></div>
</article>
