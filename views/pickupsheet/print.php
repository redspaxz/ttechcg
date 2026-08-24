<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$date = DateTimeImmutable::createFromFormat('!Y-m-d', $pickupSheet->collectionDate);
$collectionDate = $date instanceof DateTimeImmutable ? $date->format('d/m/Y') : $pickupSheet->collectionDate;
$minimumRows = 22;
$blankRows = max(0, $minimumRows - $pickupSheet->shipmentCount());
?>
<div class="print-actions">
    <button type="button" onclick="window.print()">Print / Save as PDF</button>
    <a href="<?= $e($basePath) ?>/pickupsheet/submissions">Back to submitted sheets</a>
</div>

<main class="print-preview" aria-label="A4 pickup sheet preview">
<article class="print-sheet">
    <header class="paper-header">
        <div class="paper-identity">
            <h1>Pick-up sheet</h1>
            <div class="paper-field"><strong>Agent name :</strong><span><?= $e($pickupSheet->agentName) ?></span></div>
        </div>
        <div class="paper-document-meta">
            <div class="paper-field"><strong>Date:</strong><span><?= $e($collectionDate) ?></span></div>
            <div class="paper-reference"><strong>Reference:</strong><span><?= $e($pickupSheet->referenceNumber) ?></span></div>
        </div>
    </header>

    <h2>Cash shipments (clients cash)</h2>

    <table class="paper-shipment-table">
        <colgroup>
            <col class="paper-col-consignor">
            <col class="paper-col-awb">
            <col class="paper-col-destination">
            <col class="paper-col-amount">
            <col class="paper-col-pieces">
            <col class="paper-col-weight">
            <col class="paper-col-time">
            <col class="paper-col-checker">
        </colgroup>
        <thead>
            <tr>
                <th>Consignor</th>
                <th>AWB. number</th>
                <th>Dest</th>
                <th>Amount</th>
                <th>Pces</th>
                <th>Wgt</th>
                <th>Time<br>coll</th>
                <th>Check by</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($pickupSheet->shipments as $shipment): ?>
            <tr>
                <td><?= $e($shipment->consignor) ?></td>
                <td><?= $e($shipment->awbNumber) ?></td>
                <td><?= $e($shipment->destination) ?></td>
                <td><?= $e(number_format($shipment->amountXaf)) ?></td>
                <td><?= $e($shipment->pieces) ?></td>
                <td><?= $e(rtrim(rtrim($shipment->weightKg, '0'), '.')) ?>KG</td>
                <td><?= $e($shipment->collectionTime) ?></td>
                <td><?= $e($shipment->checkedBy) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php for ($row = 0; $row < $blankRows; $row++): ?>
            <tr class="paper-empty-row" aria-hidden="true">
                <td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td>
                <td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td>
            </tr>
        <?php endfor; ?>
        </tbody>
    </table>

    <footer class="paper-totals">
        <div><strong>Shipments collected:</strong><span><?= $e($pickupSheet->shipmentCount()) ?></span></div>
        <div><strong>Total cash received</strong><span><?= $e(number_format($pickupSheet->totalCashReceivedXaf)) ?>XAF</span></div>
    </footer>
</article>
</main>
