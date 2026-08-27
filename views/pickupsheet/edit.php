<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$errors = is_array($errors ?? null) ? $errors : [];
$old = is_array($old ?? null) ? $old : [];
$shipmentRows = is_array($old['shipments'] ?? null)
    ? $old['shipments']
    : array_map(static fn ($shipment): array => [
        'consignor' => $shipment->consignor,
        'awb_number' => $shipment->awbNumber,
        'destination' => $shipment->destination,
        'amount' => (string) $shipment->amountXaf,
        'pieces' => (string) $shipment->pieces,
        'weight_kg' => $shipment->weightKg,
        'collection_time' => $shipment->collectionTime,
        'checked_by' => $shipment->checkedBy,
    ], $pickupSheet->shipments);
$shipmentRows = $shipmentRows === [] ? [[]] : $shipmentRows;
$renderEditRow = static function (string|int $index, array $values = []) use ($e): void {
    $number = is_int($index) ? $index + 1 : '__NUMBER__';
    ?>
    <tr class="shipment-row" data-shipment-row>
        <th scope="row" data-row-number><?= $e($number) ?></th>
        <td data-label="Consignor"><span class="sr-only" data-row-label>Shipment <?= $e($number) ?> consignor</span><input data-field="consignor" name="shipments[<?= $e($index) ?>][consignor]" value="<?= $e($values['consignor'] ?? '') ?>" maxlength="160" required></td>
        <td data-label="AWB number"><span class="sr-only" data-row-label>Shipment <?= $e($number) ?> AWB number</span><input data-field="awb_number" name="shipments[<?= $e($index) ?>][awb_number]" value="<?= $e($values['awb_number'] ?? '') ?>" inputmode="numeric" pattern="[0-9]{8,20}" maxlength="20" required></td>
        <td data-label="Destination"><span class="sr-only" data-row-label>Shipment <?= $e($number) ?> destination</span><input class="pickup-code-input" data-field="destination" name="shipments[<?= $e($index) ?>][destination]" value="<?= $e($values['destination'] ?? '') ?>" pattern="[A-Za-z]{3}" maxlength="3" required></td>
        <td data-label="Amount"><span class="sr-only" data-row-label>Shipment <?= $e($number) ?> amount</span><input data-field="amount" name="shipments[<?= $e($index) ?>][amount]" value="<?= $e($values['amount'] ?? '') ?>" inputmode="numeric" pattern="[0-9]{1,9}" required></td>
        <td data-label="Pieces"><span class="sr-only" data-row-label>Shipment <?= $e($number) ?> pieces</span><input data-field="pieces" name="shipments[<?= $e($index) ?>][pieces]" value="<?= $e($values['pieces'] ?? '') ?>" inputmode="numeric" pattern="[0-9]{1,3}" required></td>
        <td data-label="Weight"><span class="sr-only" data-row-label>Shipment <?= $e($number) ?> weight</span><input data-field="weight_kg" name="shipments[<?= $e($index) ?>][weight_kg]" value="<?= $e($values['weight_kg'] ?? '') ?>" inputmode="decimal" required></td>
        <td data-label="Time"><span class="sr-only" data-row-label>Shipment <?= $e($number) ?> collection time</span><input type="time" data-field="collection_time" name="shipments[<?= $e($index) ?>][collection_time]" value="<?= $e($values['collection_time'] ?? date('H:i')) ?>" required></td>
        <td data-label="Checked by"><span class="sr-only" data-row-label>Shipment <?= $e($number) ?> checked by</span><input data-field="checked_by" name="shipments[<?= $e($index) ?>][checked_by]" value="<?= $e($values['checked_by'] ?? '') ?>" maxlength="100" required></td>
        <td class="shipment-row-action"><button type="button" data-remove-shipment>Remove</button></td>
    </tr>
    <?php
};
?>
<section class="pickup-workspace">
    <div class="container pickup-workspace-header">
        <strong class="pickup-wordmark">Edit Pickupsheet</strong>
        <div class="pickup-header-links">
            <span class="pickup-session-user"><?= $e($recordsUsername) ?> · operator</span>
            <a class="pickup-back" href="<?= $e($basePath) ?>/dhl/pickupsheet/submissions">Submitted sheets <span aria-hidden="true">&#8599;</span></a>
            <form method="post" action="<?= $e($basePath) ?>/dhl/pickupsheet/logout"><input type="hidden" name="_token" value="<?= $e($csrfToken) ?>"><button class="pickup-link-button" type="submit">Sign out</button></form>
        </div>
    </div>
    <div class="container pickup-form-shell">
        <header class="pickup-form-heading"><div><p class="eyebrow eyebrow-red">Audited correction</p><h1>Edit record</h1><p>The reference, original consent, and creation timestamp remain unchanged. Every saved correction records before-and-after values.</p></div><span class="pickup-storage-state"><i aria-hidden="true"></i><?= $e($pickupSheet->referenceNumber) ?></span></header>
        <?php if (is_string($flash ?? null) && $flash !== ''): ?><div class="notice notice-success" role="status"><?= $e($flash) ?></div><?php endif; ?>
        <?php if ($errors !== []): ?><div class="notice notice-error" role="alert"><?php foreach ($errors as $error): ?><span><?= $e($error) ?></span><?php endforeach; ?></div><?php endif; ?>

        <form class="pickup-form" method="post" action="<?= $e($basePath) ?>/dhl/pickupsheet/submissions/edit" data-pickup-form>
            <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
            <input type="hidden" name="reference" value="<?= $e($pickupSheet->referenceNumber) ?>">
            <fieldset class="pickup-meta-fields"><legend>Sheet details</legend><label><span>Agent name</span><input name="agent_name" value="<?= $e($old['agent_name'] ?? $pickupSheet->agentName) ?>" maxlength="100" required></label><label><span>Collection date</span><input type="date" name="collection_date" value="<?= $e($old['collection_date'] ?? $pickupSheet->collectionDate) ?>" required></label><div class="pickup-reference-field"><span>Immutable reference</span><strong><?= $e($pickupSheet->referenceNumber) ?></strong></div></fieldset>
            <section class="shipment-editor" aria-labelledby="edit-shipment-title"><div class="shipment-editor-heading"><div><span>Editable register</span><h2 id="edit-shipment-title">Shipment details</h2></div><button class="pickup-add-row" type="button" data-add-shipment>+ Add shipment</button></div><div class="shipment-table-wrap"><table class="shipment-table shipment-table-edit"><thead><tr><th>#</th><th>Consignor</th><th>AWB number</th><th>Dest</th><th>Amount</th><th>Pces</th><th>Wgt</th><th>Time</th><th>Check by</th><th><span class="sr-only">Action</span></th></tr></thead><tbody data-shipment-rows><?php foreach ($shipmentRows as $index => $row) { $renderEditRow($index, is_array($row) ? $row : []); } ?></tbody></table></div></section>
            <div class="pickup-summary"><div><span>Shipments</span><output data-shipment-count><?= $e(count($shipmentRows)) ?></output></div><div><span>Total cash received</span><strong><output data-shipment-total><?= $e(number_format($pickupSheet->totalCashReceivedXaf)) ?></output> XAF</strong></div></div>
            <div class="pickup-submit-row"><p>This action changes the operational record and creates a permanent audit entry.</p><button class="button button-red pickup-submit" type="submit">Save audited changes <span aria-hidden="true">&#8594;</span></button></div>
        </form>
        <template data-shipment-template><?php $renderEditRow('__INDEX__'); ?></template>
    </div>
</section>
