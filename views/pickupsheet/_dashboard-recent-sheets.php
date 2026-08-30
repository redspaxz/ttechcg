<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$recentSheets = is_array($recentSheets ?? null) ? $recentSheets : [];
$items = is_array($recentSheets['items'] ?? null) ? $recentSheets['items'] : [];
$currentLoginPage = max(1, (int) ($currentLoginPage ?? $userActivity['page'] ?? 1));
$currentLogPage = max(1, (int) ($currentLogPage ?? $auditLogs['page'] ?? 1));
?>
<div class="pickup-card-heading"><div><span>Recent activity</span><h2 id="recent-sheets-title">Latest pickup sheets</h2></div><a href="<?= $e($basePath) ?>/dhl/pickupsheet/submissions">View all records</a></div>
<div class="pickup-dashboard-table-wrap">
    <table><thead><tr><th>Reference</th><th>Status</th><th>Date</th><th>Agent</th><th>Shipments</th><th>Total</th><th>View</th></tr></thead><tbody>
    <?php if ($items === []): ?><tr><td colspan="7">No pickup sheets have been generated.</td></tr><?php endif; ?>
    <?php foreach ($items as $sheet): ?>
        <tr><td><?= $e($sheet->referenceNumber) ?></td><td><?= $sheet->isPaid() ? 'Paid' : 'Open' ?></td><td><?= $e($sheet->collectionDate) ?></td><td><?= $e($sheet->agentName) ?></td><td><?= $e($sheet->shipmentCount()) ?></td><td><?= $e(number_format($sheet->totalCashReceivedXaf)) ?> XAF</td><td><a href="<?= $e($basePath) ?>/dhl/pickupsheet/submissions?reference=<?= $e(rawurlencode($sheet->referenceNumber)) ?>">Records</a></td></tr>
    <?php endforeach; ?>
    </tbody></table>
</div>
<?php
$pagerData = $recentSheets;
$pagerRecordLabel = ((int) ($recentSheets['totalRecords'] ?? 0)) === 1 ? 'sheet' : 'sheets';
$pagerAriaLabel = 'Recent pickup sheet pages';
$pagerUrl = static fn (int $targetPage): string => ($basePath ?? '') . '/dhl/pickupsheet/dashboard?' . http_build_query([
    'login_page' => $currentLoginPage,
    'log_page' => $currentLogPage,
    'recent_page' => $targetPage,
]);
require __DIR__ . '/_pagination.php';
?>
