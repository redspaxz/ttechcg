<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$sections = is_array($sections ?? null) ? $sections : [];
$sectionLabels = is_array($sectionLabels ?? null) ? $sectionLabels : [];
$summary = is_array($summary ?? null) ? $summary : [];
$marketAnalysis = is_array($marketAnalysis ?? null) ? $marketAnalysis : [];
$marketCurrent = is_array($marketAnalysis['current'] ?? null) ? $marketAnalysis['current'] : [];
$marketPrevious = is_array($marketAnalysis['previous'] ?? null) ? $marketAnalysis['previous'] : [];
$marketGrowth = is_array($marketAnalysis['growth'] ?? null) ? $marketAnalysis['growth'] : [];
$marketMetrics = is_array($marketAnalysis['metrics'] ?? null) ? $marketAnalysis['metrics'] : [];
$marketMonthly = is_array($marketAnalysis['monthly'] ?? null) ? $marketAnalysis['monthly'] : [];
$marketDestinations = is_array($marketAnalysis['destinations'] ?? null) ? $marketAnalysis['destinations'] : [];
$senders = is_array($senders ?? null) ? $senders : [];
$topCustomers = is_array($topCustomers ?? null) ? $topCustomers : [];
$errors = is_array($errors ?? null) ? $errors : [];
$periodDays = max(1, (int) ($periodDays ?? 90));
$generatedAt = $generatedAt instanceof DateTimeImmutable ? $generatedAt : new DateTimeImmutable('now');
$today = $generatedAt->setTime(0, 0);
$periodStart = $today->modify('-' . ($periodDays - 1) . ' days');
$previousEnd = $today->modify('-' . $periodDays . ' days');
$previousStart = $today->modify('-' . (($periodDays * 2) - 1) . ' days');
$includes = static fn (string $section): bool => in_array($section, $sections, true);
$number = static fn (mixed $value): string => number_format((int) $value);
$weight = static fn (mixed $value): string => number_format((float) $value, 1);
$percent = static fn (mixed $value): string => number_format((float) $value, 1) . '%';
$change = static function (mixed $value): string {
    if ($value === null) {
        return 'New baseline';
    }
    $numericValue = (float) $value;
    return ($numericValue > 0 ? '+' : '') . number_format($numericValue, 1) . '%';
};
$totalCashXaf = max(0, (int) ($summary['totalCashXaf'] ?? 0));
$unpaidBalanceXaf = max(0, (int) ($summary['unpaidBalanceXaf'] ?? 0));
$paidCashXaf = max(0, $totalCashXaf - $unpaidBalanceXaf);
$unpaidShare = $totalCashXaf > 0 ? ($unpaidBalanceXaf / $totalCashXaf) * 100 : 0.0;
$sectionNumber = 0;
?>
<div class="print-actions">
    <button type="button" data-print-pickup>Print / Save as PDF</button>
    <a href="<?= $e($basePath) ?>/dhl/pickupsheet/dashboard?tab=reports">Back to reports</a>
</div>

<main class="print-preview" aria-label="A4 performance report preview">
<article class="print-sheet report-sheet">
    <header class="report-header">
        <div>
            <p class="report-eyebrow">Pickupsheet control · Internal</p>
            <h1>Performance report</h1>
        </div>
        <dl class="report-meta">
            <div><dt>Reporting period</dt><dd><?= $e($periodStart->format('d/m/Y')) ?> – <?= $e($today->format('d/m/Y')) ?> (<?= $e($periodDays) ?> days)</dd></div>
            <div><dt>Compared with</dt><dd><?= $e($previousStart->format('d/m/Y')) ?> – <?= $e($previousEnd->format('d/m/Y')) ?></dd></div>
            <div><dt>Generated</dt><dd><?= $e($generatedAt->format('d/m/Y H:i')) ?></dd></div>
            <div><dt>Prepared by</dt><dd><?= $e($generatedBy ?? '') ?></dd></div>
        </dl>
    </header>

    <p class="report-scope">Indicators are calculated from internal, non-deleted Pickupsheet records by collection date. Cash figures are recorded collections, not accounting revenue, and do not represent the external logistics market.</p>

    <?php if ($errors !== []): ?>
        <div class="report-errors" role="alert"><?php foreach ($errors as $error): ?><p><?= $e($error) ?></p><?php endforeach; ?></div>
    <?php endif; ?>

    <?php if ($includes('kpi')): ?>
        <section class="report-section">
            <h2><?= $e(++$sectionNumber) ?>. <?= $e($sectionLabels['kpi'] ?? 'KPI summary') ?></h2>
            <div class="report-kpis">
                <div><span>Total sheets</span><strong><?= $e($number($summary['sheetCount'] ?? 0)) ?></strong><small>All generated records</small></div>
                <div><span>Shipments</span><strong><?= $e($number($summary['shipmentCount'] ?? 0)) ?></strong><small>Cash shipment lines</small></div>
                <div><span>Unpaid sheets</span><strong><?= $e($number($summary['unpaidSheetCount'] ?? 0)) ?></strong><small>Open payment records</small></div>
                <div><span>Unpaid balance</span><strong><?= $e($number($unpaidBalanceXaf)) ?></strong><small>XAF open, last 3 months</small></div>
            </div>
            <table class="report-table">
                <thead><tr><th>Cash settlement (last 3 months)</th><th class="is-number">XAF</th><th class="is-number">Share</th></tr></thead>
                <tbody>
                    <tr><td>Total cash recorded</td><td class="is-number"><?= $e($number($totalCashXaf)) ?></td><td class="is-number">100.0%</td></tr>
                    <tr><td>Paid cash</td><td class="is-number"><?= $e($number($paidCashXaf)) ?></td><td class="is-number"><?= $e($percent($totalCashXaf > 0 ? 100 - $unpaidShare : 0)) ?></td></tr>
                    <tr><td>Unpaid balance</td><td class="is-number"><?= $e($number($unpaidBalanceXaf)) ?></td><td class="is-number"><?= $e($percent($unpaidShare)) ?></td></tr>
                </tbody>
            </table>
        </section>
    <?php endif; ?>

    <?php if ($includes('market')): ?>
        <section class="report-section">
            <h2><?= $e(++$sectionNumber) ?>. <?= $e($sectionLabels['market'] ?? 'Market performance') ?></h2>
            <table class="report-table">
                <thead><tr><th>Indicator</th><th class="is-number">Latest <?= $e($periodDays) ?> days</th><th class="is-number">Previous <?= $e($periodDays) ?> days</th><th class="is-number">Change</th></tr></thead>
                <tbody>
                    <tr><td>Shipments</td><td class="is-number"><?= $e($number($marketCurrent['shipmentCount'] ?? 0)) ?></td><td class="is-number"><?= $e($number($marketPrevious['shipmentCount'] ?? 0)) ?></td><td class="is-number"><?= $e($change($marketGrowth['shipmentPercent'] ?? null)) ?></td></tr>
                    <tr><td>Cash recorded (XAF)</td><td class="is-number"><?= $e($number($marketCurrent['totalCashXaf'] ?? 0)) ?></td><td class="is-number"><?= $e($number($marketPrevious['totalCashXaf'] ?? 0)) ?></td><td class="is-number"><?= $e($change($marketGrowth['cashPercent'] ?? null)) ?></td></tr>
                    <tr><td>Cargo weight (kg)</td><td class="is-number"><?= $e($weight($marketCurrent['totalWeightKg'] ?? 0)) ?></td><td class="is-number"><?= $e($weight($marketPrevious['totalWeightKg'] ?? 0)) ?></td><td class="is-number"><?= $e($change($marketGrowth['weightPercent'] ?? null)) ?></td></tr>
                    <tr><td>Active senders</td><td class="is-number"><?= $e($number($marketCurrent['uniqueSenders'] ?? 0)) ?></td><td class="is-number"><?= $e($number($marketPrevious['uniqueSenders'] ?? 0)) ?></td><td class="is-number"><?= $e($change($marketGrowth['senderPercent'] ?? null)) ?></td></tr>
                    <tr><td>Pickup sheets</td><td class="is-number"><?= $e($number($marketCurrent['sheetCount'] ?? 0)) ?></td><td class="is-number"><?= $e($number($marketPrevious['sheetCount'] ?? 0)) ?></td><td class="is-number">–</td></tr>
                </tbody>
            </table>
            <table class="report-table">
                <thead><tr><th>Efficiency indicator</th><th class="is-number">Value</th><th>Basis</th></tr></thead>
                <tbody>
                    <tr><td>Pieces handled</td><td class="is-number"><?= $e($number($marketCurrent['totalPieces'] ?? 0)) ?></td><td>Latest <?= $e($periodDays) ?> days</td></tr>
                    <tr><td>Cash per shipment (XAF)</td><td class="is-number"><?= $e($number($marketMetrics['averageCashPerShipmentXaf'] ?? 0)) ?></td><td>Latest <?= $e($periodDays) ?> days</td></tr>
                    <tr><td>Cash per kilogram (XAF)</td><td class="is-number"><?= $e($number($marketMetrics['cashPerKgXaf'] ?? 0)) ?></td><td>Latest <?= $e($periodDays) ?> days</td></tr>
                    <tr><td>Payment conversion</td><td class="is-number"><?= $e($percent($marketMetrics['paymentRatePercent'] ?? 0)) ?></td><td>Sheets marked paid, latest <?= $e($periodDays) ?> days</td></tr>
                    <tr><td>Repeat sender rate</td><td class="is-number"><?= $e($percent($marketMetrics['repeatSenderRatePercent'] ?? 0)) ?></td><td>Senders with 2+ shipments, 12 months</td></tr>
                    <tr><td>Top lane concentration</td><td class="is-number"><?= $e($percent($marketMetrics['topDestinationSharePercent'] ?? 0)) ?></td><td>Leading destination share, 12 months</td></tr>
                </tbody>
            </table>
        </section>
    <?php endif; ?>

    <?php if ($includes('trend')): ?>
        <section class="report-section">
            <h2><?= $e(++$sectionNumber) ?>. <?= $e($sectionLabels['trend'] ?? 'Activity trend') ?></h2>
            <?php if ($marketMonthly === []): ?>
                <p class="report-empty">No trend data is available.</p>
            <?php else: ?>
                <table class="report-table">
                    <thead><tr><th>Month</th><th class="is-number">Shipments</th><th class="is-number">Cash (XAF)</th><th class="is-number">Weight (kg)</th><th class="is-number">Senders</th></tr></thead>
                    <tbody>
                        <?php foreach ($marketMonthly as $month): ?>
                            <?php
                            $monthDate = DateTimeImmutable::createFromFormat('!Y-m', (string) ($month['month'] ?? ''));
                            $monthLabel = $monthDate instanceof DateTimeImmutable ? $monthDate->format('M Y') : (string) ($month['month'] ?? '');
                            ?>
                            <tr><td><?= $e($monthLabel) ?></td><td class="is-number"><?= $e($number($month['shipmentCount'] ?? 0)) ?></td><td class="is-number"><?= $e($number($month['totalCashXaf'] ?? 0)) ?></td><td class="is-number"><?= $e($weight($month['totalWeightKg'] ?? 0)) ?></td><td class="is-number"><?= $e($number($month['uniqueSenders'] ?? 0)) ?></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <th>Total</th>
                            <th class="is-number"><?= $e($number(array_sum(array_column($marketMonthly, 'shipmentCount')))) ?></th>
                            <th class="is-number"><?= $e($number(array_sum(array_column($marketMonthly, 'totalCashXaf')))) ?></th>
                            <th class="is-number"><?= $e($weight(array_sum(array_column($marketMonthly, 'totalWeightKg')))) ?></th>
                            <th class="is-number">–</th>
                        </tr>
                    </tfoot>
                </table>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if ($includes('destinations')): ?>
        <section class="report-section">
            <h2><?= $e(++$sectionNumber) ?>. <?= $e($sectionLabels['destinations'] ?? 'Destination mix') ?></h2>
            <?php if ($marketDestinations === []): ?>
                <p class="report-empty">No destination activity is available.</p>
            <?php else: ?>
                <table class="report-table">
                    <thead><tr><th>#</th><th>Destination</th><th class="is-number">Shipments</th><th class="is-number">Share</th><th class="is-number">Cash (XAF)</th><th class="is-number">Weight (kg)</th></tr></thead>
                    <tbody>
                        <?php foreach ($marketDestinations as $index => $destination): ?>
                            <tr><td><?= $e($index + 1) ?></td><td><?= $e($destination['destination'] ?? '') ?></td><td class="is-number"><?= $e($number($destination['shipmentCount'] ?? 0)) ?></td><td class="is-number"><?= $e($percent($destination['shipmentSharePercent'] ?? 0)) ?></td><td class="is-number"><?= $e($number($destination['totalCashXaf'] ?? 0)) ?></td><td class="is-number"><?= $e($weight($destination['totalWeightKg'] ?? 0)) ?></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="report-note">Rolling 12 months, ranked by shipment count.</p>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if ($includes('senders')): ?>
        <section class="report-section">
            <h2><?= $e(++$sectionNumber) ?>. <?= $e($sectionLabels['senders'] ?? 'Top senders') ?></h2>
            <?php if ($senders === []): ?>
                <p class="report-empty">No sender activity is available.</p>
            <?php else: ?>
                <table class="report-table">
                    <thead><tr><th>#</th><th>Sender</th><th class="is-number">Shipments</th></tr></thead>
                    <tbody>
                        <?php foreach ($senders as $index => $sender): ?>
                            <tr><td><?= $e($index + 1) ?></td><td><?= $e($sender['sender'] ?? '') ?></td><td class="is-number"><?= $e($number($sender['shipmentCount'] ?? 0)) ?></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="report-note">Rolling 12 months, sender names grouped case-insensitively.</p>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if ($includes('loyalty')): ?>
        <section class="report-section">
            <h2><?= $e(++$sectionNumber) ?>. <?= $e($sectionLabels['loyalty'] ?? 'Customer loyalty') ?></h2>
            <?php if ($topCustomers === []): ?>
                <p class="report-empty">No customer loyalty data is available.</p>
            <?php else: ?>
                <table class="report-table">
                    <thead><tr><th>#</th><th>Customer</th><th class="is-number">Points</th><th>Tier</th></tr></thead>
                    <tbody>
                        <?php foreach ($topCustomers as $index => $customer): ?>
                            <tr><td><?= $e($index + 1) ?></td><td><?= $e($customer->displayName) ?></td><td class="is-number"><?= $e($number($customer->rewardBalance())) ?></td><td><?= $e($customer->loyaltyTier()) ?></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <footer class="report-footer">Confidential · Internal operational data · Generated <?= $e($generatedAt->format('d/m/Y H:i')) ?></footer>
</article>
</main>
