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
$barGap = 6;
$barWidth = max(8, (int) floor(($chartWidth - 70) / max(1, count($activity))) - $barGap);
$maximumCash = max([1, ...array_map(static fn (array $row): int => (int) ($row['totalCashXaf'] ?? 0), $activity)]);
$maximumSenderShipments = max([1, ...array_map(static fn (array $row): int => (int) ($row['shipmentCount'] ?? 0), $senders)]);
$activeSessions = count(array_filter($userActivity, static fn (array $row): bool => (bool) ($row['activeNow'] ?? false)));
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
    'pickupsheet.country_access' => 'Country restriction',
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

        <section class="pickup-user-activity" aria-labelledby="user-activity-title">
            <div class="pickup-card-heading">
                <div><span>Account activity</span><h2 id="user-activity-title">User login frequency</h2></div>
                <small>Last 30 days &middot; <?= $e($activeSessions) ?> active now</small>
            </div>
            <div class="pickup-user-activity-table">
                <table>
                    <thead><tr><th>User</th><th>Role</th><th>Sign-in</th><th>Logins</th><th>Total session</th><th>Average</th><th>Last login (UTC)</th><th>Session</th></tr></thead>
                    <tbody>
                    <?php if ($userActivity === []): ?><tr><td colspan="8">No successful login activity has been recorded during the last 30 days.</td></tr><?php endif; ?>
                    <?php foreach ($userActivity as $user): ?>
                        <tr>
                            <td><strong><?= $e($user['fullName'] ?? $user['username'] ?? '') ?></strong><small><?= $e($user['username'] ?? '') ?></small></td>
                            <td><?= $e(ucfirst((string) ($user['role'] ?? ''))) ?></td>
                            <td><?= $e($identityProviderLabel((string) ($user['identityProvider'] ?? 'local'))) ?></td>
                            <td><strong><?= $e(number_format((int) ($user['loginCount'] ?? 0))) ?></strong></td>
                            <td><?= $e($formatDuration((int) ($user['totalSessionSeconds'] ?? 0))) ?></td>
                            <td><?= $e($formatDuration((int) ($user['averageSessionSeconds'] ?? 0))) ?></td>
                            <td><?= $e($user['lastLoginAt'] ?? '') ?></td>
                            <td><span class="pickup-session-state <?= ($user['activeNow'] ?? false) ? 'is-active' : '' ?>"><i aria-hidden="true"></i><?= ($user['activeNow'] ?? false) ? 'Active now' : 'Signed out' ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="pickup-audit-log" aria-labelledby="audit-log-title">
            <div class="pickup-card-heading">
                <div><span>Security and operations</span><h2 id="audit-log-title">Detailed user logs</h2></div>
                <small>Latest 50 events &middot; UTC</small>
            </div>
            <div class="pickup-audit-log-table">
                <table>
                    <thead><tr><th>Time</th><th>User</th><th>Event</th><th>Result</th><th>Details</th><th>Request</th></tr></thead>
                    <tbody>
                    <?php if ($auditLogs === []): ?><tr><td colspan="6">No detailed user events have been recorded yet.</td></tr><?php endif; ?>
                    <?php foreach ($auditLogs as $log): ?>
                        <?php
                        $outcome = (string) ($log['outcome'] ?? 'unknown');
                        $provider = (string) ($log['identityProvider'] ?? '');
                        ?>
                        <tr>
                            <td><time datetime="<?= $e($log['occurredAt'] ?? '') ?>"><?= $e($log['occurredAt'] ?? '') ?></time></td>
                            <td><strong><?= $e($log['actorName'] ?? 'Unauthenticated') ?></strong><?php if (($log['actorUsername'] ?? '') !== ''): ?><small><?= $e($log['actorUsername']) ?></small><?php endif; ?><small><?= $e(ucfirst((string) ($log['role'] ?? ''))) ?><?= $provider !== '' ? ' · ' . $e($identityProviderLabel($provider)) : '' ?></small></td>
                            <td><strong><?= $e($auditEventLabel((string) ($log['eventName'] ?? ''))) ?></strong><small><?= $e($log['eventName'] ?? '') ?></small></td>
                            <td><span class="pickup-audit-outcome <?= $e($auditOutcomeClass($outcome)) ?>"><?= $e(str_replace('_', ' ', ucfirst($outcome))) ?></span></td>
                            <td><?= $e($auditDetails($log)) ?></td>
                            <td><strong><?= $e($log['method'] ?? '') ?> <?= $e($log['path'] ?? '') ?></strong><small>Request <?= $e(substr((string) ($log['requestId'] ?? ''), 0, 16)) ?></small><small>Client <?= $e(substr((string) ($log['clientId'] ?? ''), 0, 12)) ?></small></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

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
