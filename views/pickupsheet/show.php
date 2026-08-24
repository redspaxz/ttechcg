<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$old = is_array($old ?? null) ? $old : [];
$shipmentRows = is_array($old['shipments'] ?? null) ? array_values($old['shipments']) : [[]];
$shipmentRows = $shipmentRows !== [] ? array_slice($shipmentRows, 0, 50) : [[]];
$pickupOperational = (bool) ($pickupOperational ?? false);
$csrfToken = (string) ($csrfToken ?? '');
$captcha = is_array($captcha ?? null) ? $captcha : [];
$flash = $flash ?? null;
$errors = is_array($errors ?? null) ? $errors : [];
$field = static function (mixed $row, string $name) use ($e): string {
    return $e(is_array($row) ? ($row[$name] ?? '') : '');
};

$renderShipmentRow = static function (int|string $index, mixed $row = []) use ($field): void {
    $rowNumber = is_int($index) ? (string) ($index + 1) : '__NUMBER__';
    ?>
    <tr class="shipment-row" data-shipment-row>
        <th scope="row"><span data-row-number><?= $rowNumber ?></span></th>
        <td data-label="Consignor"><label class="sr-only" data-row-label>Shipment <?= $rowNumber ?> consignor</label><input data-field="consignor" name="shipments[<?= $index ?>][consignor]" value="<?= $field($row, 'consignor') ?>" maxlength="160" required autocomplete="off" placeholder="Client name"></td>
        <td data-label="AWB number"><label class="sr-only" data-row-label>Shipment <?= $rowNumber ?> AWB number</label><input data-field="awb_number" name="shipments[<?= $index ?>][awb_number]" value="<?= $field($row, 'awb_number') ?>" inputmode="numeric" pattern="[0-9]{8,20}" maxlength="20" required autocomplete="off" placeholder="10-digit AWB"></td>
        <td data-label="Destination"><label class="sr-only" data-row-label>Shipment <?= $rowNumber ?> destination</label><input class="pickup-code-input" data-field="destination" name="shipments[<?= $index ?>][destination]" value="<?= $field($row, 'destination') ?>" pattern="[A-Za-z]{3}" minlength="3" maxlength="3" required autocomplete="off" placeholder="DLA"></td>
        <td data-label="Amount (XAF)"><label class="sr-only" data-row-label>Shipment <?= $rowNumber ?> amount in XAF</label><input data-field="amount" name="shipments[<?= $index ?>][amount]" value="<?= $field($row, 'amount') ?>" type="number" inputmode="numeric" min="1" max="999999999" step="1" required autocomplete="off" placeholder="0"></td>
        <td data-label="Pieces"><label class="sr-only" data-row-label>Shipment <?= $rowNumber ?> pieces</label><input data-field="pieces" name="shipments[<?= $index ?>][pieces]" value="<?= $field($row, 'pieces') ?>" type="number" inputmode="numeric" min="1" max="999" step="1" required autocomplete="off" placeholder="1"></td>
        <td data-label="Weight (kg)"><label class="sr-only" data-row-label>Shipment <?= $rowNumber ?> weight in kilograms</label><input data-field="weight_kg" name="shipments[<?= $index ?>][weight_kg]" value="<?= $field($row, 'weight_kg') ?>" type="number" inputmode="decimal" min="0.001" max="9999.999" step="0.001" required autocomplete="off" placeholder="0.500"></td>
        <td data-label="Time collected"><label class="sr-only" data-row-label>Shipment <?= $rowNumber ?> collection time</label><input data-field="collection_time" name="shipments[<?= $index ?>][collection_time]" value="<?= $field($row, 'collection_time') ?>" type="time" required autocomplete="off"></td>
        <td data-label="Checked by"><label class="sr-only" data-row-label>Shipment <?= $rowNumber ?> checked by</label><input data-field="checked_by" name="shipments[<?= $index ?>][checked_by]" value="<?= $field($row, 'checked_by') ?>" maxlength="100" required autocomplete="off" placeholder="Checker name"></td>
        <td class="shipment-row-action" data-label="Row action"><button type="button" data-remove-shipment aria-label="Remove shipment <?= $rowNumber ?>">Remove</button></td>
    </tr>
    <?php
};
?>
<section class="pickup-workspace">
    <div class="container pickup-workspace-header">
        <strong class="pickup-wordmark">Pickupsheet</strong>
        <div class="pickup-header-links">
            <a class="pickup-back" href="<?= $e($basePath) ?>/dhl/pickupsheet/submissions">Submitted sheets <span aria-hidden="true">↗</span></a>
            <a class="pickup-back" href="<?= $e($basePath . '/') ?>">T&amp;Tech home <span aria-hidden="true">↗</span></a>
        </div>
    </div>

    <div class="container pickup-form-shell">
        <header class="pickup-form-heading">
            <div>
                <p class="eyebrow eyebrow-red">Cash shipments · clients cash</p>
                <h1>Pick-up sheet</h1>
                <p>Record each collected shipment. Shipment count and total cash received are calculated from the rows below.</p>
            </div>
            <span class="pickup-storage-state" <?= $pickupOperational ? '' : 'data-unavailable' ?>>
                <i aria-hidden="true"></i><?= $pickupOperational ? 'Secure entry ready' : 'Storage unavailable' ?>
            </span>
        </header>

        <?php if (!$pickupOperational): ?>
            <div class="notice notice-error" role="alert">Pickup-sheet storage is temporarily unavailable. Data entry is disabled until the MySQL connection is restored.</div>
        <?php endif; ?>
        <?php if (is_string($flash) && $flash !== ''): ?>
            <div class="notice notice-success" role="status"><?= $e($flash) ?></div>
        <?php endif; ?>
        <?php if ($errors !== []): ?>
            <div class="notice notice-error" role="alert">
                <?php foreach ($errors as $error): ?><span><?= $e($error) ?></span><?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form class="pickup-form" method="post" action="<?= $e($basePath) ?>/dhl/pickupsheet" data-pickup-form>
            <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
            <label class="honeypot" aria-hidden="true">Website<input name="website" tabindex="-1" autocomplete="off"></label>

            <fieldset class="pickup-meta-fields">
                <legend>Sheet details</legend>
                <label>
                    <span>Agent name</span>
                    <input name="agent_name" value="<?= $e($old['agent_name'] ?? '') ?>" maxlength="100" required autocomplete="name" placeholder="Collection agent">
                </label>
                <label>
                    <span>Date</span>
                    <input type="date" name="collection_date" value="<?= $e($old['collection_date'] ?? date('Y-m-d')) ?>" required>
                </label>
                <div class="pickup-reference-field">
                    <span>Reference number</span>
                    <strong>Assigned when saved</strong>
                </div>
            </fieldset>

            <section class="shipment-editor" aria-labelledby="shipment-editor-title">
                <div class="shipment-editor-heading">
                    <div><span>Shipment register</span><h2 id="shipment-editor-title">Cash shipments</h2></div>
                    <button class="pickup-add-row" type="button" data-add-shipment>+ Add shipment</button>
                </div>
                <p class="shipment-scroll-hint">On a small screen, each shipment appears as a separate entry card.</p>
                <div class="shipment-table-wrap">
                    <table class="shipment-table">
                        <thead>
                            <tr><th scope="col">#</th><th scope="col">Consignor</th><th scope="col">AWB number</th><th scope="col">Dest</th><th scope="col">Amount</th><th scope="col">Pces</th><th scope="col">Wgt</th><th scope="col">Time coll</th><th scope="col">Check by</th><th scope="col"><span class="sr-only">Action</span></th></tr>
                        </thead>
                        <tbody data-shipment-rows>
                            <?php foreach ($shipmentRows as $index => $row): ?><?php $renderShipmentRow($index, $row); ?><?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <div class="pickup-summary" aria-live="polite">
                <div><span>Shipments collected</span><output data-shipment-count>0</output></div>
                <div><span>Total cash received</span><strong><output data-shipment-total>0</output> XAF</strong></div>
            </div>

            <div class="pickup-security-grid">
                <fieldset class="captcha-fieldset">
                    <legend>Human verification</legend>
                    <input type="hidden" name="captcha_nonce" value="<?= $e($captcha['nonce'] ?? '') ?>">
                    <label for="pickup-captcha-answer">
                        <span>What is <?= $e($captcha['question'] ?? '') ?>?</span>
                        <input id="pickup-captcha-answer" name="captcha_answer" type="text" inputmode="numeric" pattern="[0-9]+" maxlength="2" required autocomplete="off" aria-describedby="pickup-captcha-help">
                    </label>
                    <p id="pickup-captcha-help">Answer this short calculation to confirm you are human.</p>
                </fieldset>
                <label class="consent-field pickup-consent">
                    <input type="checkbox" name="privacy_consent" value="1" required <?= ($old['privacy_consent'] ?? '') === '1' ? 'checked' : '' ?>>
                    <span>I consent to T&amp;Tech processing the agent, consignor, shipment, and checker information entered here to operate the pickup-sheet service, as described in the <a href="<?= $e($basePath) ?>/privacy" target="_blank" rel="noopener">privacy notice</a>.</span>
                </label>
            </div>

            <div class="pickup-submit-row">
                <p>Records and the consent timestamp are stored in the secured MySQL database.</p>
                <button class="button button-red pickup-submit" type="submit" <?= !$pickupOperational ? 'disabled' : '' ?>>Save pickup sheet <span aria-hidden="true">→</span></button>
            </div>
        </form>

        <template data-shipment-template><?php $renderShipmentRow('__INDEX__'); ?></template>
    </div>
</section>
