<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$summary = is_array($summary ?? null) ? $summary : [];
$activity = is_array($activity ?? null) ? $activity : [];
$destinations = is_array($destinations ?? null) ? $destinations : [];
$recentSheets = is_array($recentSheets ?? null) ? $recentSheets : [];
$accounts = is_array($accounts ?? null) ? $accounts : [];
$errors = is_array($errors ?? null) ? $errors : [];
$activeAccounts = count(array_filter($accounts, static fn ($account): bool => (bool) ($account->active ?? false)));
$operatorAccounts = count(array_filter($accounts, static fn ($account): bool => ($account->role ?? '') === 'operator'));
$viewerAccounts = count(array_filter($accounts, static fn ($account): bool => ($account->role ?? '') === 'viewer'));
$chartWidth = 760;
$chartHeight = 230;
$plotTop = 24;
$plotHeight = 146;
$plotBottom = $plotTop + $plotHeight;
$barGap = 6;
$barWidth = max(8, (int) floor(($chartWidth - 70) / max(1, count($activity))) - $barGap);
$maximumCash = max([1, ...array_map(static fn (array $row): int => (int) ($row['totalCashXaf'] ?? 0), $activity)]);
?>
<section class="pickup-admin-workspace">
    <div class="container pickup-workspace-header pickup-admin-header">
        <strong class="pickup-wordmark">Pickupsheet control</strong>
        <div class="pickup-header-links">
            <span class="pickup-session-user" title="<?= $e($recordsUsername) ?>"><?= $e($recordsFullName ?? $recordsUsername) ?> · admin</span>
            <form method="post" action="<?= $e($basePath) ?>/dhl/pickupsheet/logout"><input type="hidden" name="_token" value="<?= $e($csrfToken) ?>"><button class="pickup-link-button" type="submit">Sign out</button></form>
        </div>
    </div>

    <div class="container pickup-admin-shell">
        <header class="pickup-admin-heading">
            <div><p class="eyebrow eyebrow-red">Administration</p><h1>Activity dashboard</h1><p>Monitor cash-shipment performance, control staff access, and review every generated pickup sheet.</p></div>
            <span class="pickup-storage-state"><i aria-hidden="true"></i>Live control panel</span>
        </header>

        <?php if ($errors !== []): ?>
            <div class="notice notice-error" role="alert"><?php foreach ($errors as $error): ?><span><?= $e($error) ?></span><?php endforeach; ?></div>
        <?php endif; ?>

        <nav class="pickup-admin-actions" aria-label="Pickupsheet administration">
            <a href="<?= $e($basePath) ?>/dhl/pickupsheet/submissions"><span>Records</span><strong>View all pickup sheets</strong><i aria-hidden="true">&#8599;</i></a>
            <a href="<?= $e($basePath) ?>/dhl/pickupsheet/submissions/users"><span>Access</span><strong>Manage users and RBAC</strong><i aria-hidden="true">&#8599;</i></a>
            <a href="<?= $e($basePath) ?>/dhl/pickupsheet/"><span>Operations</span><strong>Create pickup sheet</strong><i aria-hidden="true">&#8599;</i></a>
        </nav>

        <section class="pickup-kpi-grid" aria-label="Pickupsheet KPIs">
            <article><span>Total sheets</span><strong><?= $e(number_format((int) ($summary['sheetCount'] ?? 0))) ?></strong><small>Generated records</small></article>
            <article><span>Shipments</span><strong><?= $e(number_format((int) ($summary['shipmentCount'] ?? 0))) ?></strong><small>Cash shipment lines</small></article>
            <article><span>Cash recorded</span><strong><?= $e(number_format((int) ($summary['totalCashXaf'] ?? 0))) ?></strong><small>XAF across all sheets</small></article>
            <article><span>Active users</span><strong><?= $e($activeAccounts) ?></strong><small><?= $e($operatorAccounts) ?> operators · <?= $e($viewerAccounts) ?> viewers</small></article>
        </section>

        <div class="pickup-dashboard-grid">
            <section class="pickup-chart-card" aria-labelledby="cash-activity-title">
                <div class="pickup-card-heading"><div><span>14-day activity</span><h2 id="cash-activity-title">Cash recorded by day</h2></div><small>XAF</small></div>
                <div class="pickup-chart-scroll">
                    <svg class="pickup-activity-chart" viewBox="0 0 <?= $e($chartWidth) ?> <?= $e($chartHeight) ?>" role="img" aria-label="Cash recorded during the last fourteen days">
                        <line x1="45" y1="<?= $e($plotBottom) ?>" x2="<?= $e($chartWidth - 15) ?>" y2="<?= $e($plotBottom) ?>"></line>
                        <?php foreach ($activity as $index => $row): ?>
                            <?php
                            $value = (int) ($row['totalCashXaf'] ?? 0);
                            $height = $value === 0 ? 2 : max(4, (int) round(($value / $maximumCash) * $plotHeight));
                            $x = 50 + $index * ($barWidth + $barGap);
                            $y = $plotBottom - $height;
                            ?>
                            <g><title><?= $e($row['date']) ?>: <?= $e(number_format($value)) ?> XAF</title><rect x="<?= $e($x) ?>" y="<?= $e($y) ?>" width="<?= $e($barWidth) ?>" height="<?= $e($height) ?>"></rect><text x="<?= $e($x + (int) floor($barWidth / 2)) ?>" y="<?= $e($plotBottom + 22) ?>"><?= $e(substr((string) $row['date'], 8, 2)) ?></text></g>
                        <?php endforeach; ?>
                    </svg>
                </div>
            </section>

            <section class="pickup-destination-card" aria-labelledby="destination-title">
                <div class="pickup-card-heading"><div><span>Routing</span><h2 id="destination-title">Top destinations</h2></div><small>By shipment count</small></div>
                <div class="pickup-destination-list">
                    <?php if ($destinations === []): ?><p>No destination activity yet.</p><?php endif; ?>
                    <?php $maxDestination = max([1, ...array_map(static fn (array $row): int => (int) ($row['shipmentCount'] ?? 0), $destinations)]); ?>
                    <?php foreach ($destinations as $destination): ?>
                        <div><span><strong><?= $e($destination['destination']) ?></strong><small><?= $e($destination['shipmentCount']) ?> shipments · <?= $e(number_format((int) $destination['totalCashXaf'])) ?> XAF</small></span><progress max="<?= $e($maxDestination) ?>" value="<?= $e($destination['shipmentCount']) ?>"><?= $e($destination['shipmentCount']) ?></progress></div>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>

        <section class="pickup-dashboard-recent">
            <div class="pickup-card-heading"><div><span>Recent activity</span><h2>Latest pickup sheets</h2></div><a href="<?= $e($basePath) ?>/dhl/pickupsheet/submissions">View all records</a></div>
            <div class="pickup-dashboard-table-wrap">
                <table><thead><tr><th>Reference</th><th>Status</th><th>Date</th><th>Agent</th><th>Shipments</th><th>Total</th><th>View</th></tr></thead><tbody>
                <?php if ($recentSheets === []): ?><tr><td colspan="7">No pickup sheets have been generated.</td></tr><?php endif; ?>
                <?php foreach ($recentSheets as $sheet): ?>
                    <tr><td><?= $e($sheet->referenceNumber) ?></td><td><?= $sheet->isPaid() ? 'Paid' : 'Open' ?></td><td><?= $e($sheet->collectionDate) ?></td><td><?= $e($sheet->agentName) ?></td><td><?= $e($sheet->shipmentCount()) ?></td><td><?= $e(number_format($sheet->totalCashReceivedXaf)) ?> XAF</td><td><a href="<?= $e($basePath) ?>/dhl/pickupsheet/submissions?reference=<?= $e(rawurlencode($sheet->referenceNumber)) ?>">Records</a></td></tr>
                <?php endforeach; ?>
                </tbody></table>
            </div>
        </section>
    </div>
</section>
