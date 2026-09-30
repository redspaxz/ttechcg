<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$awbConflicts = is_array($awbConflicts ?? null) ? $awbConflicts : [];
$awbReuseDays = (int) ($awbReuseDays ?? 90);
if ($awbConflicts === []) {
    return;
}
?>
<div class="pickup-awb-warning" role="alert" data-awb-reuse-warning>
    <strong>These AWB numbers are already in use</strong>
    <p>An AWB can be entered only once within <?= $e($awbReuseDays) ?> days of its collection date, and these are already on other pickup sheets from that period. Correct the numbers to save this sheet.</p>
    <ul>
        <?php foreach ($awbConflicts as $conflict): ?>
            <li>AWB <strong><?= $e($conflict['awbNumber'] ?? '') ?></strong> is on sheet <a href="<?= $e($basePath) ?>/dhl/pickupsheet/submissions?reference=<?= $e(rawurlencode((string) ($conflict['referenceNumber'] ?? ''))) ?>" target="_blank" rel="noopener"><?= $e($conflict['referenceNumber'] ?? '') ?></a>, collected <?= $e($conflict['collectionDate'] ?? '') ?></li>
        <?php endforeach; ?>
    </ul>
</div>
