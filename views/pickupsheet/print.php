<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$upper = static function (mixed $value) use ($e): string {
    $text = (string) $value;
    $text = function_exists('mb_strtoupper') ? mb_strtoupper($text, 'UTF-8') : strtoupper($text);

    return $e($text);
};
$date = DateTimeImmutable::createFromFormat('!Y-m-d', $pickupSheet->collectionDate);
$collectionDate = $date instanceof DateTimeImmutable ? $date->format('d/m/Y') : $pickupSheet->collectionDate;
$minimumRows = 22;
$blankRows = max(0, $minimumRows - $pickupSheet->shipmentCount());
?>
<div class="print-actions">
    <button type="button" onclick="window.print()">Print / Save as PDF</button>
    <a href="<?= $e($basePath) ?>/dhl/pickupsheet/submissions">Back to submitted sheets</a>
</div>

<main class="print-preview" aria-label="A4 pickup sheet preview">
<article class="print-sheet">
    <header class="paper-header">
        <div class="paper-identity">
            <h1>PICK-UP SHEET</h1>
            <div class="paper-field"><strong>AGENT NAME :</strong><span><?= $upper($pickupSheet->agentName) ?></span></div>
        </div>
        <div class="paper-document-meta">
            <div class="paper-field"><strong>DATE:</strong><span><?= $e($collectionDate) ?></span></div>
            <div class="paper-reference"><strong>REFERENCE:</strong><span><?= $upper($pickupSheet->referenceNumber) ?></span></div>
        </div>
    </header>

    <h2>CASH SHIPMENTS (CLIENTS CASH)</h2>

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
                <th>CONSIGNOR</th>
                <th>AWB. NUMBER</th>
                <th>DEST</th>
                <th>AMOUNT</th>
                <th>PCES</th>
                <th>WGT</th>
                <th>TIME<br>COLL</th>
                <th>CHECK BY</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($pickupSheet->shipments as $shipment): ?>
            <tr>
                <td><?= $upper($shipment->consignor) ?></td>
                <td><?= $e($shipment->awbNumber) ?></td>
                <td><?= $upper($shipment->destination) ?></td>
                <td><?= $e(number_format($shipment->amountXaf)) ?></td>
                <td><?= $e($shipment->pieces) ?></td>
                <td><?= $e(rtrim(rtrim($shipment->weightKg, '0'), '.')) ?>KG</td>
                <td><?= $e($shipment->collectionTime) ?></td>
                <td><?= $upper($shipment->checkedBy) ?></td>
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
        <div><strong>SHIPMENTS COLLECTED:</strong><span><?= $e($pickupSheet->shipmentCount()) ?></span></div>
        <div><strong>TOTAL CASH RECEIVED</strong><span><?= $e(number_format($pickupSheet->totalCashReceivedXaf)) ?>XAF</span></div>
    </footer>
</article>
</main>
