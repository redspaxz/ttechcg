<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$pickupSheets = is_array($pickupSheets ?? null) ? $pickupSheets : [];
$errors = is_array($errors ?? null) ? $errors : [];
$pickupOperational = (bool) ($pickupOperational ?? false);
$canPrint = (bool) ($canPrint ?? false);
$canExport = (bool) ($canExport ?? false);
$canEdit = (bool) ($canEdit ?? false);
$canMarkPaid = (bool) ($canMarkPaid ?? false);
$canEditReceipt = (bool) ($canEditReceipt ?? false);
$canDelete = (bool) ($canDelete ?? false);
$canCrmView = (bool) ($canCrmView ?? false);
$pagination = is_array($pagination ?? null) ? $pagination : [];
$search = trim(is_string($search ?? null) ? $search : '');
$page = max(1, (int) ($pagination['page'] ?? 1));
$totalPages = max(1, (int) ($pagination['totalPages'] ?? 1));
$totalRecords = max(0, (int) ($pagination['totalRecords'] ?? 0));
// Paid times are stored in UTC; show them in the application's time zone.
$formatPaidAt = static function (?string $paidAt): string {
    if ($paidAt === null || trim($paidAt) === '') {
        return '';
    }
    try {
        return (new DateTimeImmutable($paidAt, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('j M Y, H:i');
    } catch (Exception) {
        return $paidAt;
    }
};
$pageUrl = static function (int $target) use ($basePath, $search): string {
    $query = ['page' => $target];
    if ($search !== '') {
        $query['q'] = $search;
    }

    return ($basePath ?? '') . '/dhl/pickupsheet/submissions?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
};
?>
<?php if (!$pickupOperational): ?>
    <div class="notice notice-error" role="alert">Pickup-sheet storage is unavailable. Check the MySQL connection.</div>
<?php endif; ?>
<?php if ($errors !== []): ?>
    <div class="notice notice-error" role="alert"><?php foreach ($errors as $error): ?><span><?= $e($error) ?></span><?php endforeach; ?></div>
<?php endif; ?>

<div class="pickup-record-list">
    <?php if ($pickupSheets === []): ?>
        <?php if ($search !== ''): ?>
            <div class="pickup-empty-state"><h2>No matching sheets.</h2><p>Try another reference, receipt, agent, consignor, AWB, destination, or checker.</p></div>
        <?php else: ?>
            <div class="pickup-empty-state"><h2>No submitted sheets yet.</h2><p>Saved pickup sheets will appear here.</p></div>
        <?php endif; ?>
    <?php endif; ?>

    <?php foreach ($pickupSheets as $pickupSheet): ?>
        <?php $referenceQuery = rawurlencode($pickupSheet->referenceNumber); $isPaid = $pickupSheet->isPaid(); ?>
        <article class="pickup-record">
            <div class="pickup-record-overview">
                <span class="pickup-record-reference"><span class="pickup-ref" title="<?= $e($pickupSheet->referenceNumber) ?>"><?= $e($pickupSheet->referenceNumber) ?></span><small class="pickup-record-status" data-status="<?= $isPaid ? 'paid' : 'open' ?>"><?= $isPaid ? 'Closed' : 'Open' ?></small><?php if ($isPaid && $pickupSheet->paymentReceiptNumber !== null): ?><small class="pickup-record-receipt">Receipt <?= $e($pickupSheet->paymentReceiptNumber) ?></small><?php endif; ?></span>
                <span><small>Date</small><?= $e($pickupSheet->collectionDate) ?></span>
                <span><small>Agent</small><?= $e($pickupSheet->agentName) ?></span>
                <span><small>Shipments</small><?= $e($pickupSheet->shipmentCount()) ?></span>
                <strong><?= $e(number_format($pickupSheet->totalCashReceivedXaf)) ?> XAF</strong>
                <?php if ($canPrint || $canExport || $canEdit || $canMarkPaid || $canEditReceipt || $canDelete): ?>
                    <div class="pickup-record-actions">
                        <?php if ($canEdit): ?>
                            <a href="<?= $e($basePath) ?>/dhl/pickupsheet/submissions/edit?reference=<?= $e($referenceQuery) ?>">Edit record</a>
                        <?php endif; ?>
                        <?php if ($canPrint): ?>
                            <a target="_blank" rel="noopener" href="<?= $e($basePath) ?>/dhl/pickupsheet/submissions/print?reference=<?= $e($referenceQuery) ?>">Print / PDF</a>
                        <?php endif; ?>
                        <?php if ($canExport): ?>
                            <a href="<?= $e($basePath) ?>/dhl/pickupsheet/submissions/export?reference=<?= $e($referenceQuery) ?>">Export Excel</a>
                        <?php endif; ?>
                        <?php if ($canMarkPaid && !$isPaid): ?>
                            <button class="pickup-record-paid" type="button" data-payment-dialog-open="payment-<?= $e($pickupSheet->referenceNumber) ?>" aria-haspopup="dialog">Mark paid</button>
                        <?php endif; ?>
                        <?php if ($canMarkPaid && !$canEditReceipt && $isPaid): ?>
                            <button class="pickup-record-view-receipt" type="button" data-payment-dialog-open="view-receipt-<?= $e($pickupSheet->referenceNumber) ?>" aria-haspopup="dialog">View receipt</button>
                        <?php endif; ?>
                        <?php if ($canEditReceipt && $isPaid): ?>
                            <button class="pickup-record-paid" type="button" data-payment-dialog-open="receipt-<?= $e($pickupSheet->referenceNumber) ?>" aria-haspopup="dialog">Edit receipt</button>
                        <?php endif; ?>
                        <?php if ($canDelete): ?>
                            <form method="post" action="<?= $e($basePath) ?>/dhl/pickupsheet/submissions/delete" data-pickup-delete><input type="hidden" name="_token" value="<?= $e($csrfToken) ?>"><input type="hidden" name="reference" value="<?= $e($pickupSheet->referenceNumber) ?>"><input type="hidden" name="return_page" value="<?= $e($page) ?>"><input type="hidden" name="return_search" value="<?= $e($search) ?>"><button class="pickup-record-delete" type="submit">Delete</button></form>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <?php if ($canMarkPaid && !$isPaid): ?>
                    <?php $paymentDialogId = 'payment-' . $pickupSheet->referenceNumber; ?>
                    <dialog class="pickup-payment-dialog" id="<?= $e($paymentDialogId) ?>" aria-labelledby="<?= $e($paymentDialogId) ?>-title">
                        <form method="post" action="<?= $e($basePath) ?>/dhl/pickupsheet/submissions/paid" data-pickup-payment data-captcha-endpoint="<?= $e($basePath) ?>/dhl/pickupsheet/submissions/captcha" data-expected-amount="<?= $e($pickupSheet->totalCashReceivedXaf) ?>">
                            <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>"><input type="hidden" name="reference" value="<?= $e($pickupSheet->referenceNumber) ?>"><input type="hidden" name="return_page" value="<?= $e($page) ?>"><input type="hidden" name="return_search" value="<?= $e($search) ?>">
                            <header>
                                <p>Confirm payment</p>
                                <h2 id="<?= $e($paymentDialogId) ?>-title"><span class="pickup-ref" title="<?= $e($pickupSheet->referenceNumber) ?>"><?= $e($pickupSheet->referenceNumber) ?></span></h2>
                            </header>
                            <dl class="pickup-payment-summary">
                                <div><dt>Collected</dt><dd><?= $e($pickupSheet->collectionDate) ?></dd></div>
                                <div><dt>Shipments</dt><dd><?= $e($pickupSheet->shipmentCount()) ?></dd></div>
                            </dl>
                            <label><span>Receipt number</span><span class="pickup-payment-field"><input type="text" name="receipt_number" minlength="6" maxlength="64" pattern="(?=(?:[^0-9]*[0-9]){6})[A-Za-z0-9][A-Za-z0-9._\/\-]{5,63}" placeholder="As shown on the receipt" autocomplete="off" required aria-describedby="<?= $e($paymentDialogId) ?>-receipt-status"><svg class="pickup-payment-tick" data-field-tick viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="11"/><path d="M7 12.5l3.2 3.2L17 9"/></svg></span></label>
                            <p class="pickup-payment-status" id="<?= $e($paymentDialogId) ?>-receipt-status" data-payment-receipt-status aria-live="polite">At least 6 digits. Letters, dots, slashes, underscores, and hyphens are also allowed.</p>
                            <label><span>Amount received (XAF)</span><span class="pickup-payment-field"><input type="text" name="receipt_amount" inputmode="numeric" maxlength="16" pattern="[0-9][0-9, ]{0,15}" placeholder="As shown on the receipt" autocomplete="off" required aria-describedby="<?= $e($paymentDialogId) ?>-amount-status"><svg class="pickup-payment-tick" data-field-tick viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="11"/><path d="M7 12.5l3.2 3.2L17 9"/></svg></span></label>
                            <p class="pickup-payment-status" id="<?= $e($paymentDialogId) ?>-amount-status" data-payment-amount-status aria-live="polite">Enter the amount shown on the receipt.</p>
                            <div class="pickup-payment-captcha" data-record-captcha>
                                <input type="hidden" name="captcha_nonce" value="" data-captcha-nonce>
                                <label><span>Security check: what is <span data-captcha-question>…</span>?</span><span class="pickup-payment-field"><input type="text" name="captcha_answer" inputmode="numeric" pattern="[0-9]{1,2}" maxlength="2" required autocomplete="off" aria-describedby="<?= $e($paymentDialogId) ?>-captcha-status" data-captcha-answer></span></label>
                                <p class="pickup-payment-status" id="<?= $e($paymentDialogId) ?>-captcha-status" data-captcha-status aria-live="polite">Answer the calculation to confirm you are human.</p>
                            </div>
                            <p class="pickup-payment-note">Paid status cannot be reversed. Only an administrator can change the receipt number afterwards.</p>
                            <div class="pickup-payment-actions">
                                <button type="button" data-payment-dialog-close>Cancel</button>
                                <button class="pickup-payment-confirm" type="submit">Confirm paid</button>
                            </div>
                        </form>
                        <div class="pickup-payment-result" data-payment-result hidden role="status" aria-live="assertive">
                            <svg class="pickup-payment-result-icon" data-result-icon="success" viewBox="0 0 96 96" aria-hidden="true"><circle cx="48" cy="48" r="44"/><path d="M28 50l14 14 27-30"/></svg>
                            <svg class="pickup-payment-result-icon" data-result-icon="failure" viewBox="0 0 96 96" aria-hidden="true"><circle cx="48" cy="48" r="44"/><path d="M33 33l30 30M63 33L33 63"/></svg>
                            <h3 data-payment-result-title></h3>
                            <p data-payment-result-message></p>
                            <div class="pickup-payment-actions">
                                <button type="button" data-payment-retry hidden>Try again</button>
                                <button class="pickup-payment-confirm" type="button" data-payment-done>Close</button>
                            </div>
                        </div>
                    </dialog>
                <?php endif; ?>
                <?php if ($canMarkPaid && !$canEditReceipt && $isPaid): ?>
                    <?php $viewReceiptDialogId = 'view-receipt-' . $pickupSheet->referenceNumber; ?>
                    <dialog class="pickup-payment-dialog" id="<?= $e($viewReceiptDialogId) ?>" aria-labelledby="<?= $e($viewReceiptDialogId) ?>-title">
                        <div class="app-confirm-body">
                            <header>
                                <p>Payment receipt</p>
                                <h2 id="<?= $e($viewReceiptDialogId) ?>-title"><span class="pickup-ref" title="<?= $e($pickupSheet->referenceNumber) ?>"><?= $e($pickupSheet->referenceNumber) ?></span></h2>
                            </header>
                            <dl class="pickup-payment-summary">
                                <div><dt>Receipt number</dt><dd><?= $e($pickupSheet->paymentReceiptNumber ?? '') ?></dd></div>
                                <div><dt>Paid on</dt><dd><?= $e($formatPaidAt($pickupSheet->paidAt)) ?></dd></div>
                                <div><dt>Collected</dt><dd><?= $e($pickupSheet->collectionDate) ?></dd></div>
                                <div><dt>Shipments</dt><dd><?= $e($pickupSheet->shipmentCount()) ?></dd></div>
                            </dl>
                            <p class="pickup-payment-note">Only an administrator can change a receipt number.</p>
                            <div class="pickup-payment-actions">
                                <button class="pickup-payment-confirm" type="button" data-payment-dialog-close>Close</button>
                            </div>
                        </div>
                    </dialog>
                <?php endif; ?>
                <?php if ($canEditReceipt && $isPaid): ?>
                    <?php $receiptDialogId = 'receipt-' . $pickupSheet->referenceNumber; ?>
                    <dialog class="pickup-payment-dialog" id="<?= $e($receiptDialogId) ?>" aria-labelledby="<?= $e($receiptDialogId) ?>-title">
                        <form method="post" action="<?= $e($basePath) ?>/dhl/pickupsheet/submissions/receipt" data-pickup-receipt-edit data-captcha-endpoint="<?= $e($basePath) ?>/dhl/pickupsheet/submissions/captcha" data-current-receipt="<?= $e($pickupSheet->paymentReceiptNumber ?? '') ?>">
                            <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>"><input type="hidden" name="reference" value="<?= $e($pickupSheet->referenceNumber) ?>"><input type="hidden" name="return_page" value="<?= $e($page) ?>"><input type="hidden" name="return_search" value="<?= $e($search) ?>">
                            <header>
                                <p>Edit receipt number</p>
                                <h2 id="<?= $e($receiptDialogId) ?>-title"><span class="pickup-ref" title="<?= $e($pickupSheet->referenceNumber) ?>"><?= $e($pickupSheet->referenceNumber) ?></span></h2>
                            </header>
                            <dl class="pickup-payment-summary">
                                <div><dt>Current receipt</dt><dd><?= $e($pickupSheet->paymentReceiptNumber ?? '') ?></dd></div>
                                <div><dt>Collected</dt><dd><?= $e($pickupSheet->collectionDate) ?></dd></div>
                            </dl>
                            <label><span>New receipt number</span><span class="pickup-payment-field"><input type="text" name="receipt_number" value="<?= $e($pickupSheet->paymentReceiptNumber ?? '') ?>" minlength="6" maxlength="64" pattern="(?=(?:[^0-9]*[0-9]){6})[A-Za-z0-9][A-Za-z0-9._\/\-]{5,63}" autocomplete="off" required aria-describedby="<?= $e($receiptDialogId) ?>-receipt-status"><svg class="pickup-payment-tick" data-field-tick viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="11"/><path d="M7 12.5l3.2 3.2L17 9"/></svg></span></label>
                            <p class="pickup-payment-status" id="<?= $e($receiptDialogId) ?>-receipt-status" data-payment-receipt-status aria-live="polite">At least 6 digits. Letters, dots, slashes, underscores, and hyphens are also allowed.</p>
                            <div class="pickup-payment-captcha" data-record-captcha>
                                <input type="hidden" name="captcha_nonce" value="" data-captcha-nonce>
                                <label><span>Security check: what is <span data-captcha-question>…</span>?</span><span class="pickup-payment-field"><input type="text" name="captcha_answer" inputmode="numeric" pattern="[0-9]{1,2}" maxlength="2" required autocomplete="off" aria-describedby="<?= $e($receiptDialogId) ?>-captcha-status" data-captcha-answer></span></label>
                                <p class="pickup-payment-status" id="<?= $e($receiptDialogId) ?>-captcha-status" data-captcha-status aria-live="polite">Answer the calculation to confirm you are human.</p>
                            </div>
                            <p class="pickup-payment-note">The previous receipt number is kept in the audit log. The paid status and paid time do not change.</p>
                            <div class="pickup-payment-actions">
                                <button type="button" data-payment-dialog-close>Cancel</button>
                                <button class="pickup-payment-confirm" type="submit">Save receipt</button>
                            </div>
                        </form>
                        <div class="pickup-payment-result" data-payment-result hidden role="status" aria-live="assertive">
                            <svg class="pickup-payment-result-icon" data-result-icon="success" viewBox="0 0 96 96" aria-hidden="true"><circle cx="48" cy="48" r="44"/><path d="M28 50l14 14 27-30"/></svg>
                            <svg class="pickup-payment-result-icon" data-result-icon="failure" viewBox="0 0 96 96" aria-hidden="true"><circle cx="48" cy="48" r="44"/><path d="M33 33l30 30M63 33L33 63"/></svg>
                            <h3 data-payment-result-title></h3>
                            <p data-payment-result-message></p>
                            <div class="pickup-payment-actions">
                                <button type="button" data-payment-retry hidden>Try again</button>
                                <button class="pickup-payment-confirm" type="button" data-payment-done>Close</button>
                            </div>
                        </div>
                    </dialog>
                <?php endif; ?>
            </div>
            <details class="pickup-record-details">
                <summary><span>View shipment table</span><i aria-hidden="true">+</i></summary>
                <div class="pickup-record-body">
                    <div class="pickup-record-table-wrap">
                        <table class="pickup-record-table">
                            <thead><tr><th>#</th><th>Consignor</th><th>AWB number</th><th>Dest</th><th>Amount</th><th>Pces</th><th>Wgt</th><th>Time coll</th><th>Check by</th></tr></thead>
                            <tbody>
                            <?php foreach ($pickupSheet->shipments as $shipment): ?>
                                <tr>
                                    <td data-label="Number"><?= $e($shipment->lineNumber) ?></td>
                                    <td data-label="Consignor"><?php if ($canCrmView): ?><a class="pickup-consignor-link" href="<?= $e($basePath) ?>/dhl/pickupsheet/customers/open?name=<?= $e(rawurlencode((string) $shipment->consignor)) ?>" aria-label="Open CRM profile for <?= $e($shipment->consignor) ?>"><?= $e($shipment->consignor) ?></a><?php else: ?><?= $e($shipment->consignor) ?><?php endif; ?></td>
                                    <td data-label="AWB number"><?= \App\Modules\Pickupsheet\UI\AwbLink::html((string) $shipment->awbNumber, $pickupSheet->collectionDate) ?></td>
                                    <td data-label="Destination"><?= $e($shipment->destination) ?></td>
                                    <td data-label="Amount"><?= $e(number_format($shipment->amountXaf)) ?></td>
                                    <td data-label="Pieces"><?= $e($shipment->pieces) ?></td>
                                    <td data-label="Weight"><?= $e(rtrim(rtrim($shipment->weightKg, '0'), '.')) ?> kg</td>
                                    <td data-label="Time collected"><?= $e($shipment->collectionTime) ?></td>
                                    <td data-label="Checked by"><?= $e($shipment->checkedBy) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                            <tfoot><tr><th colspan="4">Total</th><th><?= $e(number_format($pickupSheet->totalCashReceivedXaf)) ?> XAF</th><th colspan="4"><?= $e($pickupSheet->shipmentCount()) ?> shipment<?= $pickupSheet->shipmentCount() === 1 ? '' : 's' ?></th></tr></tfoot>
                        </table>
                    </div>
                </div>
            </details>
        </article>
    <?php endforeach; ?>
</div>

<?php
$pagerData = $pagination;
$pagerRecordLabel = $totalRecords === 1 ? 'record' : 'records';
$pagerAriaLabel = 'Submitted pickup-sheet pages';
$pagerSizeParam = 'per_page';
$pagerUrl = $pageUrl;
require __DIR__ . '/_pagination.php';
?>
