<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$pickupSheets = is_array($pickupSheets ?? null) ? $pickupSheets : [];
$errors = is_array($errors ?? null) ? $errors : [];
$authorized = (bool) ($authorized ?? false);
$viewKeyConfigured = (bool) ($viewKeyConfigured ?? false);
$pickupOperational = (bool) ($pickupOperational ?? false);
$totalXaf = array_reduce(
    $pickupSheets,
    static fn (int $total, mixed $sheet): int => $total + (int) ($sheet->totalCashReceivedXaf ?? 0),
    0,
);
?>
<section class="pickup-view-workspace">
    <div class="container pickup-workspace-header">
        <div class="product-brand product-brand-large">
            <img src="<?= $e($assetBase . '/dhl-logo.svg') ?>" alt="DHL">
            <span aria-hidden="true">—</span><strong>pickupsheet</strong>
        </div>
        <a class="pickup-back" href="<?= $e($basePath) ?>/pickupsheet/">New pickup sheet <span aria-hidden="true">↗</span></a>
    </div>

    <div class="container pickup-submissions-shell">
        <?php if (!$authorized): ?>
            <div class="pickup-access-card">
                <p class="eyebrow eyebrow-red">Protected records</p>
                <h1>Submitted pickup sheets</h1>
                <p>Enter the private submissions access key to view consignor, AWB, checker, and cash information.</p>

                <?php if ($errors !== []): ?>
                    <div class="notice notice-error" role="alert"><?php foreach ($errors as $error): ?><span><?= $e($error) ?></span><?php endforeach; ?></div>
                <?php endif; ?>

                <?php if (!$viewKeyConfigured): ?>
                    <div class="notice notice-error" role="alert">Viewing is disabled until <code>PICKUPSHEET_VIEW_KEY</code> is added to the server-managed <code>.env</code> with at least 16 characters.</div>
                <?php else: ?>
                    <form class="pickup-access-form" method="post" action="<?= $e($basePath) ?>/pickupsheet/submissions/login">
                        <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                        <label><span>Access key</span><input type="password" name="access_key" minlength="16" maxlength="200" required autocomplete="current-password" autofocus></label>
                        <button class="button button-red" type="submit">View submitted sheets <span aria-hidden="true">→</span></button>
                    </form>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <header class="pickup-submissions-heading">
                <div><p class="eyebrow eyebrow-red">Protected records</p><h1>Submitted sheets</h1><p>The most recent 100 sheets are shown. Expand a reference to review its shipment table.</p></div>
                <form method="post" action="<?= $e($basePath) ?>/pickupsheet/submissions/logout">
                    <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                    <button class="pickup-logout" type="submit">Lock records</button>
                </form>
            </header>

            <?php if (!$pickupOperational): ?>
                <div class="notice notice-error" role="alert">Pickup-sheet storage is unavailable. Check the MySQL connection.</div>
            <?php endif; ?>
            <?php if ($errors !== []): ?>
                <div class="notice notice-error" role="alert"><?php foreach ($errors as $error): ?><span><?= $e($error) ?></span><?php endforeach; ?></div>
            <?php endif; ?>

            <div class="pickup-view-summary">
                <div><span>Sheets displayed</span><strong><?= $e(count($pickupSheets)) ?></strong></div>
                <div><span>Combined cash total</span><strong><?= $e(number_format($totalXaf)) ?> XAF</strong></div>
            </div>

            <div class="pickup-record-list">
                <?php if ($pickupSheets === []): ?>
                    <div class="pickup-empty-state"><h2>No submitted sheets yet.</h2><p>Saved pickup sheets will appear here.</p></div>
                <?php endif; ?>

                <?php foreach ($pickupSheets as $pickupSheet): ?>
                    <?php $referenceQuery = rawurlencode($pickupSheet->referenceNumber); ?>
                    <article class="pickup-record">
                        <div class="pickup-record-overview">
                            <span class="pickup-record-reference"><?= $e($pickupSheet->referenceNumber) ?></span>
                            <span><small>Date</small><?= $e($pickupSheet->collectionDate) ?></span>
                            <span><small>Agent</small><?= $e($pickupSheet->agentName) ?></span>
                            <span><small>Shipments</small><?= $e($pickupSheet->shipmentCount()) ?></span>
                            <strong><?= $e(number_format($pickupSheet->totalCashReceivedXaf)) ?> XAF</strong>
                            <div class="pickup-record-actions">
                                <a target="_blank" rel="noopener" href="<?= $e($basePath) ?>/pickupsheet/submissions/print?reference=<?= $e($referenceQuery) ?>">Print / PDF</a>
                                <a href="<?= $e($basePath) ?>/pickupsheet/submissions/export?reference=<?= $e($referenceQuery) ?>">Export Excel</a>
                            </div>
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
                                    <tfoot><tr><th colspan="4">Total</th><th><?= $e(number_format($pickupSheet->totalCashReceivedXaf)) ?> XAF</th><th colspan="4"><?= $e($pickupSheet->shipmentCount()) ?> shipment<?= $pickupSheet->shipmentCount() === 1 ? '' : 's' ?></th></tr></tfoot>
                                </table>
                            </div>
                            </div>
                        </details>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>
