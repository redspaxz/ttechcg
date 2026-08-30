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
