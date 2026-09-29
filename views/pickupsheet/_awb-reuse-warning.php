<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$awbConflicts = is_array($awbConflicts ?? null) ? $awbConflicts : [];
$awbReuseDays = (int) ($awbReuseDays ?? 90);
$conflictNumbers = array_values(array_unique(array_map(static fn (array $conflict): string => (string) ($conflict['awbNumber'] ?? ''), $awbConflicts)));
if ($awbConflicts === []) {
    return;
}
?>
<div class="pickup-awb-warning" role="alert" data-awb-reuse-warning>
    <strong>Check these AWB numbers before saving</strong>
    <p>DHL reuses AWB numbers only after <?= $e($awbReuseDays) ?> days, and these are already on other pickup sheets from that period. This is usually a typing mistake. Correct the number, or confirm that DHL reissued it.</p>
    <ul>
        <?php foreach ($awbConflicts as $conflict): ?>
            <li>AWB <strong><?= $e($conflict['awbNumber'] ?? '') ?></strong> is on sheet <a href="<?= $e($basePath) ?>/dhl/pickupsheet/submissions?reference=<?= $e(rawurlencode((string) ($conflict['referenceNumber'] ?? ''))) ?>" target="_blank" rel="noopener"><?= $e($conflict['referenceNumber'] ?? '') ?></a>, collected <?= $e($conflict['collectionDate'] ?? '') ?></li>
        <?php endforeach; ?>
    </ul>
    <label class="pickup-awb-confirm"><input type="checkbox" name="confirmed_awb_reuse" value="<?= $e(implode(',', $conflictNumbers)) ?>"> <span>I confirm DHL reissued <?= count($conflictNumbers) === 1 ? 'this AWB number' : 'these AWB numbers' ?> for a new shipment</span></label>
</div>
