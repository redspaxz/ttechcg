<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$rewardRedemptions = is_array($rewardRedemptions ?? null) ? $rewardRedemptions : [];
$items = is_array($rewardRedemptions['items'] ?? null) ? $rewardRedemptions['items'] : [];
$customerKey = (string) ($customerKey ?? $customer?->customerKey ?? '');
$shipmentPage = max(1, (int) ($currentShipmentPage ?? $shipments['page'] ?? 1));
?>
<div><span>Audit trail</span><h3>Redemption log</h3><p>Point redemptions are displayed 10 records per page.</p></div>
<div>
    <?php if ($items === []): ?>
        <p class="pickup-redemption-empty">No points have been redeemed.</p>
    <?php else: ?>
        <div class="pickup-redemption-table-wrap"><table><thead><tr><th>Points redeemed</th><th>Reason</th><th>Redeemed at</th><th>Administrator</th></tr></thead><tbody>
            <?php foreach ($items as $redemption): ?><tr><td><strong><?= $e(number_format(abs((int) ($redemption['pointsDelta'] ?? 0)))) ?> points</strong></td><td><?= $e($redemption['reason'] ?? '') ?></td><td><?= $e($redemption['createdAt'] ?? '') ?> UTC</td><td><?= $e(substr((string) ($redemption['actorId'] ?? ''), 0, 10)) ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>
    <?php
    $pagerData = $rewardRedemptions;
    $pagerRecordLabel = ((int) ($rewardRedemptions['totalRecords'] ?? 0)) === 1 ? 'redemption' : 'redemptions';
    $pagerAriaLabel = 'Customer redemption pages';
    $pagerUrl = static fn (int $targetPage): string => ($basePath ?? '') . '/dhl/pickupsheet/customers/edit?' . http_build_query([
        'customer' => $customerKey,
        'shipment_page' => $shipmentPage,
        'redemption_page' => $targetPage,
    ]);
    require __DIR__ . '/_pagination.php';
    ?>
</div>
