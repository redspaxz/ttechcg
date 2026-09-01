<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$summary = is_array($summary ?? null) ? $summary : [];
$activity = is_array($activity ?? null) ? $activity : [];
$destinations = is_array($destinations ?? null) ? $destinations : [];
$senders = is_array($senders ?? null) ? $senders : [];
$userActivity = is_array($userActivity ?? null) ? $userActivity : [];
$auditLogs = is_array($auditLogs ?? null) ? $auditLogs : [];
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
$plotLeft = 82;
$plotRight = $chartWidth - 15;
$barGap = 6;
$barWidth = max(8, (int) floor(($plotRight - $plotLeft) / max(1, count($activity))) - $barGap);
$maximumCash = max([0, ...array_map(static fn (array $row): int => (int) ($row['totalCashXaf'] ?? 0), $activity)]);
$cashScaleIntervals = 4;
$cashRawStep = max(1, $maximumCash) / $cashScaleIntervals;
$cashMagnitude = 10 ** floor(log10($cashRawStep));
$cashNormalizedStep = $cashRawStep / $cashMagnitude;
$cashNiceFactor = match (true) {
    $cashNormalizedStep <= 1 => 1,
    $cashNormalizedStep <= 2 => 2,
    $cashNormalizedStep <= 2.5 => 2.5,
    $cashNormalizedStep <= 5 => 5,
    default => 10,
};
$cashTickStep = max(1, (int) ceil($cashNiceFactor * $cashMagnitude));
$cashScaleMaximum = $cashTickStep * $cashScaleIntervals;
$totalCashRecordedXaf = max(0, (int) ($summary['totalCashXaf'] ?? 0));
$unpaidBalanceXaf = max(0, (int) ($summary['unpaidBalanceXaf'] ?? 0));
$settledCashXaf = max(0, $totalCashRecordedXaf - $unpaidBalanceXaf);
$unpaidPercentage = $totalCashRecordedXaf > 0
    ? min(100, ($unpaidBalanceXaf / $totalCashRecordedXaf) * 100)
    : 0;
$unpaidChartValue = number_format($unpaidPercentage, 2, '.', '');
$settledChartValue = number_format(100 - $unpaidPercentage, 2, '.', '');
$unpaidPercentageLabel = rtrim(rtrim(number_format($unpaidPercentage, 1, '.', ''), '0'), '.') . '%';
$maximumSenderShipments = max([1, ...array_map(static fn (array $row): int => (int) ($row['shipmentCount'] ?? 0), $senders)]);
$activeSessions = max(0, (int) ($userActivity['activeRecords'] ?? 0));
$formatDuration = static function (int $seconds): string {
    if ($seconds < 60) {
        return '<1m';
    }
    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    return $hours > 0 ? $hours . 'h ' . $minutes . 'm' : $minutes . 'm';
};
$identityProviderLabel = static fn (string $provider): string => match ($provider) {
    'jumpcloud' => 'JumpCloud',
    'cloudflare_access' => 'Cloudflare Access',
    default => 'Local',
};
$auditEventLabel = static fn (string $event): string => match ($event) {
    'pickupsheet.records_access' => 'Records access',
    'pickupsheet.login' => 'Local login',
    'pickupsheet.logout' => 'Logout',
    'pickupsheet.cloudflare_access' => 'Cloudflare login',
    'pickupsheet.jumpcloud_start' => 'JumpCloud started',
    'pickupsheet.jumpcloud_callback' => 'JumpCloud login',
    'pickupsheet.submission' => 'Pickup sheet created',
    'pickupsheet.record_edit' => 'Pickup sheet edited',
    'pickupsheet.record_paid' => 'Pickup sheet marked paid',
    'pickupsheet.record_delete' => 'Pickup sheet deleted',
    'pickupsheet.records_user_create' => 'User created',
    'pickupsheet.records_user_update' => 'User updated',
    'pickupsheet.admin_password_reset' => 'Admin password reset',
    'pickupsheet.crm_customer_save' => 'CRM customer saved',
    'pickupsheet.crm_reward_adjustment' => 'CRM rewards adjusted',
    'pickupsheet.backup_download' => 'Encrypted backup created',
    'pickupsheet.backup_restore' => 'Encrypted backup restored',
    default => ucwords(str_replace(['pickupsheet.', '_', '.'], ['', ' ', ' '], $event)),
};
$auditOutcomeClass = static fn (string $outcome): string => match ($outcome) {
    'accepted', 'granted' => 'is-success',
    'failed' => 'is-failed',
    'denied', 'forbidden', 'blocked', 'rate_limited', 'unavailable' => 'is-denied',
    default => '',
};
$auditDetails = static function (array $log): string {
    $context = is_array($log['context'] ?? null) ? $log['context'] : [];
    $details = [];
    if (($context['action'] ?? '') !== '') {
        $details[] = 'Action: ' . str_replace('_', ' ', (string) $context['action']);
    }
    if (($log['targetName'] ?? '') !== '') {
        $details[] = 'Target: ' . $log['targetName'];
    } elseif (($log['targetId'] ?? '') !== '') {
        $details[] = 'Target ID: ' . substr((string) $log['targetId'], 0, 10);
    }
    if (($log['resourceId'] ?? '') !== '') {
        $details[] = 'Record ID: ' . substr((string) $log['resourceId'], 0, 10);
    }
    if (isset($context['shipment_count'])) {
        $details[] = 'Shipments: ' . (int) $context['shipment_count'];
    }
    if (isset($context['active'])) {
        $details[] = 'Account: ' . ($context['active'] ? 'active' : 'inactive');
    }
    if (($context['target_role'] ?? '') !== '') {
        $details[] = 'Target role: ' . $context['target_role'];
    }
    if (isset($context['retry_after'])) {
        $details[] = 'Retry after: ' . (int) $context['retry_after'] . 's';
    }
    if (($context['country'] ?? '') !== '') {
        $details[] = 'Country: ' . $context['country'];
    }
    if (($context['customer_status'] ?? '') !== '') {
        $details[] = 'Customer status: ' . str_replace('_', ' ', (string) $context['customer_status']);
    }
    if (($context['source'] ?? '') !== '') {
        $details[] = 'Source: ' . str_replace('_', ' ', (string) $context['source']);
    }
    if (isset($context['reward_delta'])) {
        $delta = (int) $context['reward_delta'];
        $details[] = 'Reward change: ' . ($delta > 0 ? '+' : '') . $delta;
    }
    if (isset($context['reward_balance'])) {
        $details[] = 'Reward balance: ' . (int) $context['reward_balance'];
    }
    if (isset($context['table_count'])) {
        $details[] = 'Tables: ' . (int) $context['table_count'];
    }
    if (isset($context['row_count'])) {
        $details[] = 'Rows: ' . (int) $context['row_count'];
    }
    return $details === [] ? 'No additional metadata' : implode(' · ', $details);
};
?>
<section class="pickup-admin-workspace">
    <div class="container pickup-workspace-header pickup-admin-header">
        <strong class="pickup-wordmark">Pickupsheet control</strong>
        <div class="pickup-header-links">
            <span class="pickup-session-user" title="<?= $e($recordsUsername) ?>"><?= $e($recordsFullName ?? $recordsUsername) ?> · admin</span>
            <a class="pickup-back" href="<?= $e($basePath) ?>/dhl/pickupsheet/settings">User settings <span aria-hidden="true">&#8599;</span></a>
            <form method="post" action="<?= $e($basePath) ?>/dhl/pickupsheet/logout"><input type="hidden" name="_token" value="<?= $e($csrfToken) ?>"><button class="pickup-link-button" type="submit">Sign out</button></form>
        </div>
    </div>

    <div class="container pickup-admin-shell pickup-dashboard-shell">
        <header class="pickup-admin-heading">
            <div><p class="eyebrow eyebrow-red">Administration</p><h1>Activity dashboard</h1><p>Monitor cash-shipment performance, control staff access, and review every generated pickup sheet.</p></div>
            <span class="pickup-storage-state"><i aria-hidden="true"></i>Live control panel</span>
        </header>

        <?php if ($errors !== []): ?>
            <div class="notice notice-error" role="alert"><?php foreach ($errors as $error): ?><span><?= $e($error) ?></span><?php endforeach; ?></div>
        <?php endif; ?>

        <nav class="pickup-admin-actions" aria-label="Pickupsheet administration">
            <a href="<?= $e($basePath) ?>/dhl/pickupsheet/submissions"><span>Records</span><strong>View all pickup sheets</strong><i aria-hidden="true">&#8599;</i></a>
            <a href="<?= $e($basePath) ?>/dhl/pickupsheet/customers"><span>CRM</span><strong>Manage customer relationships</strong><i aria-hidden="true">&#8599;</i></a>
            <a href="<?= $e($basePath) ?>/dhl/pickupsheet/submissions/users"><span>Access</span><strong>Manage users and RBAC</strong><i aria-hidden="true">&#8599;</i></a>
            <a href="<?= $e($basePath) ?>/dhl/pickupsheet/admin/backup"><span>Resilience</span><strong>Backup and restore data</strong><i aria-hidden="true">&#8599;</i></a>
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
                        <desc>Daily cash totals in XAF, from 0 to <?= $e(number_format($cashScaleMaximum)) ?>.</desc>
                        <?php for ($tickIndex = 0; $tickIndex <= $cashScaleIntervals; $tickIndex++): ?>
                            <?php
                            $tickValue = $cashScaleMaximum - ($tickIndex * $cashTickStep);
                            $tickY = $plotTop + (($plotHeight / $cashScaleIntervals) * $tickIndex);
                            ?>
                            <g class="pickup-activity-axis-tick">
                                <line class="pickup-activity-grid-line" x1="<?= $e($plotLeft) ?>" y1="<?= $e($tickY) ?>" x2="<?= $e($plotRight) ?>" y2="<?= $e($tickY) ?>"></line>
                                <text class="pickup-activity-axis-label" x="<?= $e($plotLeft - 10) ?>" y="<?= $e($tickY + 3) ?>"><?= $e(number_format($tickValue)) ?></text>
                            </g>
                        <?php endfor; ?>
                        <?php foreach ($activity as $index => $row): ?>
                            <?php
                            $value = (int) ($row['totalCashXaf'] ?? 0);
                            $height = $value === 0 ? 2 : max(4, (int) round(($value / $cashScaleMaximum) * $plotHeight));
                            $x = $plotLeft + $index * ($barWidth + $barGap);
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

        <section class="pickup-cash-status-card" aria-labelledby="cash-status-title">
            <div class="pickup-card-heading">
                <div><span>Cash settlement</span><h2 id="cash-status-title">Recorded cash and unpaid balance</h2></div>
                <small>All-time XAF</small>
            </div>
            <div class="pickup-cash-status-layout">
                <div class="pickup-cash-pie-visual">
                    <svg class="pickup-cash-pie-chart" viewBox="0 0 120 120" role="img" aria-labelledby="cash-status-title cash-status-description">
                        <desc id="cash-status-description">Total cash recorded is <?= $e(number_format($totalCashRecordedXaf)) ?> XAF. Unpaid balance is <?= $e(number_format($unpaidBalanceXaf)) ?> XAF.</desc>
                        <circle class="pickup-cash-pie-track" cx="60" cy="60" r="48" pathLength="100"></circle>
                        <circle class="pickup-cash-pie-unpaid" cx="60" cy="60" r="48" pathLength="100" stroke-dasharray="<?= $e($unpaidChartValue) ?> <?= $e($settledChartValue) ?>" transform="rotate(-90 60 60)"></circle>
                    </svg>
                    <span class="pickup-cash-pie-center"><strong><?= $e($unpaidPercentageLabel) ?></strong><small>Unpaid</small></span>
                </div>
                <dl class="pickup-cash-status-values">
                    <div class="is-total"><dt>Total cash recorded</dt><dd><?= $e(number_format($totalCashRecordedXaf)) ?> XAF</dd></div>
                    <div class="is-unpaid"><dt>Unpaid balance</dt><dd><?= $e(number_format($unpaidBalanceXaf)) ?> XAF</dd></div>
                    <div class="is-settled"><dt>Settled cash</dt><dd><?= $e(number_format($settledCashXaf)) ?> XAF</dd></div>
                </dl>
            </div>
        </section>

        <section class="pickup-sender-performance" aria-labelledby="sender-performance-title">
            <div class="pickup-card-heading">
                <div><span>Rolling 12-month performance</span><h2 id="sender-performance-title">Top 10 senders</h2></div>
                <small>Most to least shipments</small>
            </div>
            <?php if ($senders === []): ?>
                <p class="pickup-sender-empty">No sender activity has been recorded during the last 12 months.</p>
            <?php else: ?>
                <ol class="pickup-sender-chart" aria-label="Top senders ranked by shipment frequency">
                    <?php foreach ($senders as $index => $sender): ?>
                        <?php $shipmentCount = (int) ($sender['shipmentCount'] ?? 0); ?>
                        <li>
                            <span class="pickup-sender-rank" aria-label="Rank <?= $e($index + 1) ?>"><?= $e($index + 1) ?></span>
                            <span class="pickup-sender-name" title="<?= $e($sender['sender'] ?? '') ?>"><?= $e($sender['sender'] ?? '') ?></span>
                            <progress max="<?= $e($maximumSenderShipments) ?>" value="<?= $e($shipmentCount) ?>" aria-label="<?= $e($sender['sender'] ?? '') ?>: <?= $e($shipmentCount) ?> shipments"><?= $e($shipmentCount) ?></progress>
                            <strong><?= $e(number_format($shipmentCount)) ?> <?= $shipmentCount === 1 ? 'shipment' : 'shipments' ?></strong>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
        </section>

        <section class="pickup-user-activity ajax-pager" aria-labelledby="user-activity-title" data-ajax-pager data-ajax-pager-id="dashboard-user-activity" data-page-endpoint="<?= $e($basePath) ?>/dhl/pickupsheet/dashboard/user-activity/page" data-page-param="login_page" data-current-page="<?= $e($userActivity['page'] ?? 1) ?>" data-error-message="User login activity could not be loaded. Please try again.">
            <div class="ajax-pager-loading" data-ajax-pager-spinner role="status" hidden><span class="pickup-loading-spinner" aria-hidden="true"></span><span>Loading user activity...</span></div>
            <div data-ajax-pager-content aria-live="polite" aria-busy="false"><?php require __DIR__ . '/_dashboard-user-activity.php'; ?></div>
        </section>

        <section class="pickup-audit-log ajax-pager" aria-labelledby="audit-log-title" data-ajax-pager data-ajax-pager-id="dashboard-audit-logs" data-page-endpoint="<?= $e($basePath) ?>/dhl/pickupsheet/dashboard/audit-logs/page" data-page-param="log_page" data-current-page="<?= $e($auditLogs['page'] ?? 1) ?>" data-error-message="Detailed user logs could not be loaded. Please try again.">
            <div class="ajax-pager-loading" data-ajax-pager-spinner role="status" hidden><span class="pickup-loading-spinner" aria-hidden="true"></span><span>Loading detailed logs...</span></div>
            <div data-ajax-pager-content aria-live="polite" aria-busy="false"><?php require __DIR__ . '/_dashboard-audit-logs.php'; ?></div>
        </section>

        <section class="pickup-dashboard-recent ajax-pager" aria-labelledby="recent-sheets-title" data-ajax-pager data-ajax-pager-id="dashboard-recent-sheets" data-page-endpoint="<?= $e($basePath) ?>/dhl/pickupsheet/dashboard/recent-sheets/page" data-page-param="recent_page" data-current-page="<?= $e($recentSheets['page'] ?? 1) ?>" data-error-message="Recent pickup sheets could not be loaded. Please try again.">
            <div class="ajax-pager-loading" data-ajax-pager-spinner role="status" hidden><span class="pickup-loading-spinner" aria-hidden="true"></span><span>Loading recent sheets...</span></div>
            <div data-ajax-pager-content aria-live="polite" aria-busy="false"><?php require __DIR__ . '/_dashboard-recent-sheets.php'; ?></div>
        </section>
    </div>
</section>
