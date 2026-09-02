<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$customer = ($customer ?? null) instanceof \App\Modules\CRM\Domain\CustomerProfile ? $customer : null;
$old = is_array($old ?? null) ? $old : [];
$errors = is_array($errors ?? null) ? $errors : [];
$shipments = is_array($shipments ?? null) ? $shipments : ['items' => [], 'page' => 1, 'totalRecords' => 0, 'totalPages' => 1];
$rewardAdjustments = is_array($rewardAdjustments ?? null) ? $rewardAdjustments : [];
$rewardRedemptions = is_array($rewardRedemptions ?? null) ? $rewardRedemptions : ['items' => [], 'page' => 1, 'totalRecords' => 0, 'totalPages' => 1];
$canEditCustomerNames = (bool) ($canEditCustomerNames ?? false);
$canAdjustRewards = (bool) ($canAdjustRewards ?? false);
$value = static fn (string $field, mixed $fallback = ''): mixed => array_key_exists($field, $old) ? $old[$field] : $fallback;
$status = (string) $value('status', $customer?->status ?? 'lead');
?>
<section class="pickup-view-workspace pickup-crm-workspace">
    <div class="container pickup-workspace-header">
        <strong class="pickup-wordmark">Pickupsheet CRM</strong>
        <div class="pickup-header-links">
            <span class="pickup-session-user"><?= $e($recordsFullName ?? $recordsUsername ?? '') ?> &middot; <?= $e($recordsRole ?? '') ?></span>
            <a class="pickup-back" href="<?= $e($basePath) ?>/dhl/pickupsheet/customers">Customer directory <span aria-hidden="true">&#8599;</span></a>
            <?php if (($recordsRole ?? '') === 'admin'): ?><a class="pickup-back" href="<?= $e($basePath) ?>/dhl/pickupsheet/dashboard">Dashboard <span aria-hidden="true">&#8599;</span></a><?php endif; ?>
            <a class="pickup-back" href="<?= $e($basePath) ?>/dhl/pickupsheet/submissions">Submitted sheets <span aria-hidden="true">&#8599;</span></a>
            <a class="pickup-back" href="<?= $e($basePath) ?>/dhl/pickupsheet/settings">User settings <span aria-hidden="true">&#8599;</span></a>
            <form method="post" action="<?= $e($basePath) ?>/dhl/pickupsheet/logout"><input type="hidden" name="_token" value="<?= $e($csrfToken) ?>"><button class="pickup-link-button" type="submit">Sign out</button></form>
        </div>
    </div>

    <div class="container pickup-customer-shell">
        <?php if (is_string($flash ?? null) && $flash !== ''): ?><div class="notice notice-success" role="status"><?= $e($flash) ?></div><?php endif; ?>
        <?php if ($errors !== []): ?><div class="notice notice-error" role="alert"><?php foreach ($errors as $error): ?><span><?= $e($error) ?></span><?php endforeach; ?></div><?php endif; ?>
        <header class="pickup-crm-heading pickup-customer-profile-heading">
            <div><p class="eyebrow eyebrow-red"><?= $customer === null ? 'New relationship' : 'Customer profile' ?></p><h1><?= $e($customer?->displayName ?? 'Add customer') ?></h1><p>Maintain contact details, relationship status, internal notes, and the next follow-up date.</p></div>
            <?php if ($customer !== null): ?><span class="pickup-customer-status is-<?= $e($customer->status) ?>"><?= $e($customer->status === 'attention' ? 'Needs attention' : ucfirst($customer->status)) ?></span><?php endif; ?>
        </header>

        <?php if ($customer !== null): ?>
            <section class="pickup-customer-metrics" aria-label="Customer shipment metrics">
                <article><span>Shipments</span><strong><?= $e(number_format($customer->shipmentCount)) ?></strong></article>
                <article><span>Cash value</span><strong><?= $e(number_format($customer->totalCashXaf)) ?> XAF</strong></article>
                <article><span>First shipment</span><strong><?= $e($customer->firstShipmentOn ?? '—') ?></strong></article>
                <article><span>Last shipment</span><strong><?= $e($customer->lastShipmentOn ?? '—') ?></strong></article>
            </section>

            <section class="pickup-customer-rewards" aria-labelledby="customer-rewards-title">
                <div class="pickup-card-heading"><div><span>Customer loyalty</span><h2 id="customer-rewards-title">Reward points</h2></div><small>10 points per 1 kg shipped</small></div>
                <div class="pickup-reward-summary">
                    <article><span>Total Points Balance</span><strong><?= $e(number_format($customer->rewardBalance())) ?></strong><small>Available to redeem</small></article>
                    <article><span>Lifetime Earned Points</span><strong><?= $e(number_format($customer->lifetimeEarnedPoints())) ?></strong><small>Cargo weight and bonus points</small></article>
                    <article><span>Loyalty Tier</span><strong><?= $e($customer->loyaltyTier()) ?></strong><small>Based on lifetime earned points</small></article>
                </div>
                <div class="pickup-reward-layout<?= $canAdjustRewards ? '' : ' is-read-only' ?>">
                    <?php if ($canAdjustRewards): ?><form class="pickup-reward-form" method="post" action="<?= $e($basePath) ?>/dhl/pickupsheet/customers/rewards">
                        <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                        <input type="hidden" name="customer_key" value="<?= $e($customer->customerKey) ?>">
                        <div><span>Adjust balance</span><h3>Bonus or redemption</h3><p>Every adjustment requires a reason and remains in the reward history. Redemptions cannot make the balance negative.</p></div>
                        <label><span>Operation</span><select name="operation" required><option value="bonus">Add bonus points</option><option value="redeem">Redeem points</option></select></label>
                        <label><span>Points</span><input type="number" name="points" min="1" max="100000" step="1" required inputmode="numeric"></label>
                        <label class="pickup-reward-reason"><span>Reason</span><input name="reason" maxlength="255" minlength="3" required placeholder="Promotion, service recovery, or reward redeemed"></label>
                        <button class="button" type="submit">Update points</button>
                    </form><?php endif; ?>
                    <div class="pickup-reward-history">
                        <h3>Adjustment history</h3>
                        <?php if ($rewardAdjustments === []): ?><p>No manual bonuses or redemptions have been recorded.</p><?php else: ?>
                            <ol><?php foreach ($rewardAdjustments as $adjustment): ?>
                                <?php $delta = (int) ($adjustment['pointsDelta'] ?? 0); ?>
                                <li><span class="<?= $delta < 0 ? 'is-redemption' : 'is-bonus' ?>"><?= $delta > 0 ? '+' : '' ?><?= $e(number_format($delta)) ?></span><div><strong><?= $e($adjustment['reason'] ?? '') ?></strong><small><?= $e($adjustment['createdAt'] ?? '') ?> UTC &middot; Admin <?= $e(substr((string) ($adjustment['actorId'] ?? ''), 0, 10)) ?></small></div></li>
                            <?php endforeach; ?></ol>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="pickup-redemption-log ajax-pager" data-ajax-pager data-ajax-pager-id="customer-redemptions" data-page-endpoint="<?= $e($basePath) ?>/dhl/pickupsheet/customers/redemptions/page" data-page-param="redemption_page" data-current-page="<?= $e($rewardRedemptions['page'] ?? 1) ?>" data-error-message="Redemption history could not be loaded. Please try again.">
                    <div class="ajax-pager-loading" data-ajax-pager-spinner role="status" hidden><span class="pickup-loading-spinner" aria-hidden="true"></span><span>Loading redemptions...</span></div>
                    <div class="pickup-redemption-log-content" data-ajax-pager-content aria-live="polite" aria-busy="false">
                        <?php require __DIR__ . '/_customer-redemptions.php'; ?>
                    </div>
                </div>
            </section>
        <?php endif; ?>

        <div class="pickup-customer-layout">
            <form class="pickup-customer-form" method="post" action="<?= $e($basePath) ?>/dhl/pickupsheet/customers/save">
                <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                <input type="hidden" name="customer_key" value="<?= $e($customer?->customerKey ?? '') ?>">
                <fieldset><legend>Organization</legend>
                    <label class="pickup-field pickup-field-wide"><span>Customer or organization name</span><input name="display_name" value="<?= $e($value('display_name', $customer?->displayName ?? '')) ?>" maxlength="160" required autocomplete="organization" <?= $canEditCustomerNames ? '' : 'readonly aria-readonly="true"' ?>><?php if (!$canEditCustomerNames): ?><small>Customer names can only be changed by an administrator.</small><?php endif; ?></label>
                    <label class="pickup-field"><span>Relationship status</span><select name="status" required><?php foreach (['lead' => 'Lead', 'active' => 'Active', 'attention' => 'Needs attention', 'inactive' => 'Inactive'] as $option => $label): ?><option value="<?= $e($option) ?>" <?= $status === $option ? 'selected' : '' ?>><?= $e($label) ?></option><?php endforeach; ?></select></label>
                    <label class="pickup-field"><span>Country</span><select name="country_code"><option value="">Not specified</option><option value="CM" <?= $value('country_code', $customer?->countryCode ?? '') === 'CM' ? 'selected' : '' ?>>Cameroon</option><option value="NG" <?= $value('country_code', $customer?->countryCode ?? '') === 'NG' ? 'selected' : '' ?>>Nigeria</option></select></label>
                    <label class="pickup-field"><span>City</span><input name="city" value="<?= $e($value('city', $customer?->city ?? '')) ?>" maxlength="100" autocomplete="address-level2"></label>
                    <label class="pickup-field pickup-field-wide"><span>Address</span><input name="address" value="<?= $e($value('address', $customer?->address ?? '')) ?>" maxlength="255" autocomplete="street-address"></label>
                </fieldset>
                <fieldset><legend>Primary contact</legend>
                    <label class="pickup-field"><span>Contact name</span><input name="contact_name" value="<?= $e($value('contact_name', $customer?->contactName ?? '')) ?>" maxlength="100" autocomplete="name" <?= $canEditCustomerNames ? '' : 'readonly aria-readonly="true"' ?>></label>
                    <label class="pickup-field"><span>Email</span><input type="email" name="email" value="<?= $e($value('email', $customer?->email ?? '')) ?>" maxlength="254" autocomplete="email"></label>
                    <label class="pickup-field"><span>Phone</span><input type="tel" name="phone" value="<?= $e($value('phone', $customer?->phone ?? '')) ?>" maxlength="32" autocomplete="tel"></label>
                    <label class="pickup-field"><span>Next follow-up</span><input type="date" name="next_follow_up_on" value="<?= $e($value('next_follow_up_on', $customer?->nextFollowUpOn ?? '')) ?>"></label>
                    <label class="pickup-field pickup-field-wide"><span>Internal notes</span><textarea name="notes" maxlength="2000" rows="7" placeholder="Relationship context, preferences, follow-up outcome, or service opportunity"><?= $e($value('notes', $customer?->notes ?? '')) ?></textarea><small>Visible to operators and administrators.</small></label>
                </fieldset>
                <div class="pickup-customer-form-actions"><button class="button" type="submit">Save customer</button><a href="<?= $e($basePath) ?>/dhl/pickupsheet/customers">Cancel</a></div>
            </form>

            <aside class="pickup-customer-context">
                <h2>Customer context</h2>
                <?php if ($customer === null): ?><p>Create a lead or customer profile. If the organization later appears as a shipment consignor, its shipment metrics are connected automatically by normalized name.</p><?php else: ?>
                    <dl><div><dt>Profile source</dt><dd><?= $customer->source === 'shipment' ? 'Shipment consignor' : 'Manual entry' ?></dd></div><div><dt>Updated</dt><dd><?= $e($customer->updatedAt ?? 'Not available') ?> UTC</dd></div><div><dt>Customer ID</dt><dd><?= $e(substr($customer->customerKey, 0, 12)) ?></dd></div></dl>
                    <?php if ($customer->email !== ''): ?><a href="mailto:<?= $e($customer->email) ?>">Email customer</a><?php endif; ?>
                    <?php if ($customer->phone !== ''): ?><a href="tel:<?= $e(preg_replace('/[^+0-9]/', '', $customer->phone) ?? '') ?>">Call customer</a><?php endif; ?>
                <?php endif; ?>
            </aside>
        </div>

        <?php if ($customer !== null): ?>
            <section class="pickup-customer-history ajax-pager" aria-labelledby="customer-history-title" data-ajax-pager data-ajax-pager-id="customer-shipments" data-page-endpoint="<?= $e($basePath) ?>/dhl/pickupsheet/customers/shipments/page" data-page-param="shipment_page" data-current-page="<?= $e($shipments['page'] ?? 1) ?>" data-error-message="Shipment history could not be loaded. Please try again.">
                <div class="ajax-pager-loading" data-ajax-pager-spinner role="status" hidden><span class="pickup-loading-spinner" aria-hidden="true"></span><span>Loading shipments...</span></div>
                <div class="pickup-customer-history-content" data-ajax-pager-content aria-live="polite" aria-busy="false">
                    <?php require __DIR__ . '/_customer-shipments.php'; ?>
                </div>
            </section>
        <?php endif; ?>
    </div>
</section>
