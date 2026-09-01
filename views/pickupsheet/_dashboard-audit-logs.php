<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$auditLogs = is_array($auditLogs ?? null) ? $auditLogs : [];
$items = is_array($auditLogs['items'] ?? null) ? $auditLogs['items'] : [];
$currentLoginPage = max(1, (int) ($currentLoginPage ?? $userActivity['page'] ?? 1));
$currentRecentPage = max(1, (int) ($currentRecentPage ?? $recentSheets['page'] ?? 1));
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
    if (($context['action'] ?? '') !== '') $details[] = 'Action: ' . str_replace('_', ' ', (string) $context['action']);
    if (($log['targetName'] ?? '') !== '') $details[] = 'Target: ' . $log['targetName'];
    elseif (($log['targetId'] ?? '') !== '') $details[] = 'Target ID: ' . substr((string) $log['targetId'], 0, 10);
    if (($log['resourceId'] ?? '') !== '') $details[] = 'Record ID: ' . substr((string) $log['resourceId'], 0, 10);
    if (isset($context['shipment_count'])) $details[] = 'Shipments: ' . (int) $context['shipment_count'];
    if (isset($context['active'])) $details[] = 'Account: ' . ($context['active'] ? 'active' : 'inactive');
    if (($context['target_role'] ?? '') !== '') $details[] = 'Target role: ' . $context['target_role'];
    if (isset($context['retry_after'])) $details[] = 'Retry after: ' . (int) $context['retry_after'] . 's';
    if (($context['country'] ?? '') !== '') $details[] = 'Country: ' . $context['country'];
    if (($context['customer_status'] ?? '') !== '') $details[] = 'Customer status: ' . str_replace('_', ' ', (string) $context['customer_status']);
    if (($context['source'] ?? '') !== '') $details[] = 'Source: ' . str_replace('_', ' ', (string) $context['source']);
    if (isset($context['reward_delta'])) {
        $delta = (int) $context['reward_delta'];
        $details[] = 'Reward change: ' . ($delta > 0 ? '+' : '') . $delta;
    }
    if (isset($context['reward_balance'])) $details[] = 'Reward balance: ' . (int) $context['reward_balance'];
    if (isset($context['table_count'])) $details[] = 'Tables: ' . (int) $context['table_count'];
    if (isset($context['row_count'])) $details[] = 'Rows: ' . (int) $context['row_count'];
    return $details === [] ? 'No additional metadata' : implode(' · ', $details);
};
?>
<div class="pickup-card-heading">
    <div><span>Security and operations</span><h2 id="audit-log-title">Detailed user logs</h2></div>
    <small>UTC &middot; 10 per page</small>
</div>
<div class="pickup-audit-log-table">
    <table>
        <thead><tr><th>Time</th><th>User</th><th>Event</th><th>Result</th><th>Details</th><th>Request</th></tr></thead>
        <tbody>
        <?php if ($items === []): ?><tr><td colspan="6">No detailed user events have been recorded yet.</td></tr><?php endif; ?>
        <?php foreach ($items as $log): ?>
            <?php $outcome = (string) ($log['outcome'] ?? 'unknown'); $provider = (string) ($log['identityProvider'] ?? ''); ?>
            <tr>
                <td><time datetime="<?= $e($log['occurredAt'] ?? '') ?>"><?= $e($log['occurredAt'] ?? '') ?></time></td>
                <td><strong><?= $e($log['actorName'] ?? 'Unauthenticated') ?></strong><?php if (($log['actorUsername'] ?? '') !== ''): ?><small><?= $e($log['actorUsername']) ?></small><?php endif; ?><small><?= $e(ucfirst((string) ($log['role'] ?? ''))) ?><?= $provider !== '' ? ' &middot; ' . $e($identityProviderLabel($provider)) : '' ?></small></td>
                <td><strong><?= $e($auditEventLabel((string) ($log['eventName'] ?? ''))) ?></strong><small><?= $e($log['eventName'] ?? '') ?></small></td>
                <td><span class="pickup-audit-outcome <?= $e($auditOutcomeClass($outcome)) ?>"><?= $e(str_replace('_', ' ', ucfirst($outcome))) ?></span></td>
                <td><?= $e($auditDetails($log)) ?></td>
                <td><strong><?= $e($log['method'] ?? '') ?> <?= $e($log['path'] ?? '') ?></strong><small>Request <?= $e(substr((string) ($log['requestId'] ?? ''), 0, 16)) ?></small><small>Client <?= $e(substr((string) ($log['clientId'] ?? ''), 0, 12)) ?></small></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php
$pagerData = $auditLogs;
$pagerRecordLabel = ((int) ($auditLogs['totalRecords'] ?? 0)) === 1 ? 'event' : 'events';
$pagerAriaLabel = 'Detailed user log pages';
$pagerUrl = static fn (int $targetPage): string => ($basePath ?? '') . '/dhl/pickupsheet/dashboard?' . http_build_query([
    'login_page' => $currentLoginPage,
    'log_page' => $targetPage,
    'recent_page' => $currentRecentPage,
]);
require __DIR__ . '/_pagination.php';
?>
