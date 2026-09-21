<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$userActivity = is_array($userActivity ?? null) ? $userActivity : [];
$items = is_array($userActivity['items'] ?? null) ? $userActivity['items'] : [];
$activeSessions = max(0, (int) ($userActivity['activeRecords'] ?? 0));
$currentLogPage = max(1, (int) ($currentLogPage ?? $auditLogs['page'] ?? 1));
$currentRecentPage = max(1, (int) ($currentRecentPage ?? $recentSheets['page'] ?? 1));
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
?>
<div class="pickup-card-heading">
    <div><span>Account activity</span><h2 id="user-activity-title">User login frequency</h2></div>
    <small>Last 30 days &middot; <?= $e($activeSessions) ?> active now</small>
</div>
<div class="pickup-user-activity-table">
    <table>
        <thead><tr><th>User</th><th>Role</th><th>Sign-in</th><th>Logins</th><th>Total session</th><th>Average</th><th>Last login (UTC)</th><th>Session</th></tr></thead>
        <tbody>
        <?php if ($items === []): ?><tr><td colspan="8">No successful login activity has been recorded during the last 30 days.</td></tr><?php endif; ?>
        <?php foreach ($items as $user): ?>
            <tr>
                <td colspan="8">
                    <details class="pickup-table-row-details">
                        <summary>
                            <span data-label="User"><strong><?= $e($user['fullName'] ?? $user['username'] ?? '') ?></strong><small><?= $e($user['username'] ?? '') ?></small></span>
                            <span data-label="Role"><strong><?= $e(ucfirst((string) ($user['role'] ?? ''))) ?></strong></span>
                            <span data-label="Sign-in"><strong><?= $e($identityProviderLabel((string) ($user['identityProvider'] ?? 'local'))) ?></strong></span>
                            <span data-label="Logins"><strong><?= $e(number_format((int) ($user['loginCount'] ?? 0))) ?></strong></span>
                            <span data-label="Total session"><strong><?= $e($formatDuration((int) ($user['totalSessionSeconds'] ?? 0))) ?></strong></span>
                            <span data-label="Average"><strong><?= $e($formatDuration((int) ($user['averageSessionSeconds'] ?? 0))) ?></strong></span>
                            <span data-label="Last login"><strong><?= $e($user['lastLoginAt'] ?? '') ?></strong></span>
                            <span data-label="Session"><span class="pickup-session-state <?= ($user['activeNow'] ?? false) ? 'is-active' : '' ?>"><i aria-hidden="true"></i><?= ($user['activeNow'] ?? false) ? 'Active now' : 'Signed out' ?></span></span>
                            <i aria-hidden="true">+</i>
                        </summary>
                        <div class="pickup-table-row-detail">
                            <div class="pickup-table-row-grid">
                                <div><small>User</small><span><?= $e($user['fullName'] ?? $user['username'] ?? '') ?></span></div>
                                <div><small>Role</small><span><?= $e(ucfirst((string) ($user['role'] ?? ''))) ?></span></div>
                                <div><small>Sign-in</small><span><?= $e($identityProviderLabel((string) ($user['identityProvider'] ?? 'local'))) ?></span></div>
                                <div><small>Login count</small><span><?= $e(number_format((int) ($user['loginCount'] ?? 0))) ?></span></div>
                                <div><small>Total session</small><span><?= $e($formatDuration((int) ($user['totalSessionSeconds'] ?? 0))) ?></span></div>
                                <div><small>Average</small><span><?= $e($formatDuration((int) ($user['averageSessionSeconds'] ?? 0))) ?></span></div>
                                <div><small>Last login</small><span><?= $e($user['lastLoginAt'] ?? '') ?></span></div>
                                <div><small>Session</small><span><?= ($user['activeNow'] ?? false) ? 'Active now' : 'Signed out' ?></span></div>
                            </div>
                        </div>
                    </details>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php
$pagerData = $userActivity;
$pagerRecordLabel = ((int) ($userActivity['totalRecords'] ?? 0)) === 1 ? 'user' : 'users';
$pagerAriaLabel = 'User login activity pages';
$pagerUrl = static fn (int $targetPage): string => ($basePath ?? '') . '/dhl/pickupsheet/dashboard?' . http_build_query([
    'login_page' => $targetPage,
    'log_page' => $currentLogPage,
    'recent_page' => $currentRecentPage,
]);
require __DIR__ . '/_pagination.php';
?>
