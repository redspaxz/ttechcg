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
        <tr>
            <td colspan="7">
                <details class="pickup-table-row-details">
                    <summary>
                        <span data-label="Reference"><strong><?= $e($sheet->referenceNumber) ?></strong></span>
                        <span data-label="Status"><strong><?= $sheet->isPaid() ? 'Paid' : 'Open' ?></strong><?php if ($sheet->isPaid() && $sheet->paymentReceiptNumber !== null): ?><small>Receipt <?= $e($sheet->paymentReceiptNumber) ?></small><?php endif; ?></span>
                        <span data-label="Date"><strong><?= $e($sheet->collectionDate) ?></strong></span>
                        <span data-label="Agent"><strong><?= $e($sheet->agentName) ?></strong></span>
                        <span data-label="Shipments"><strong><?= $e($sheet->shipmentCount()) ?></strong></span>
                        <span data-label="Total"><strong><?= $e(number_format($sheet->totalCashReceivedXaf)) ?> XAF</strong></span>
                        <span data-label="View"><strong><a href="<?= $e($basePath) ?>/dhl/pickupsheet/submissions?reference=<?= $e(rawurlencode($sheet->referenceNumber)) ?>">Records</a></strong></span>
                        <i aria-hidden="true">+</i>
                    </summary>
                    <div class="pickup-table-row-detail">
                        <div class="pickup-table-row-grid">
                            <div><small>Reference</small><span><?= $e($sheet->referenceNumber) ?></span></div>
                            <div><small>Status</small><span><?= $sheet->isPaid() ? 'Paid' : 'Open' ?></span></div>
                            <div><small>Date</small><span><?= $e($sheet->collectionDate) ?></span></div>
                            <div><small>Agent</small><span><?= $e($sheet->agentName) ?></span></div>
                            <div><small>Shipments</small><span><?= $e($sheet->shipmentCount()) ?></span></div>
                            <div><small>Total</small><span><?= $e(number_format($sheet->totalCashReceivedXaf)) ?> XAF</span></div>
                            <div><small>Action</small><span><a href="<?= $e($basePath) ?>/dhl/pickupsheet/submissions?reference=<?= $e(rawurlencode($sheet->referenceNumber)) ?>">Open records</a></span></div>
                        </div>
                    </div>
                </details>
            </td>
        </tr>
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
