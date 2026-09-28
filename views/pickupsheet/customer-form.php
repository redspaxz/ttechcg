<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$customer = ($customer ?? null) instanceof \App\Modules\CRM\Domain\CustomerProfile ? $customer : null;
$old = is_array($old ?? null) ? $old : [];
$errors = is_array($errors ?? null) ? $errors : [];
$shipments = is_array($shipments ?? null) ? $shipments : ['items' => [], 'page' => 1, 'perPage' => 10, 'totalRecords' => 0, 'totalPages' => 1];
$rewardHistory = is_array($rewardHistory ?? null) ? $rewardHistory : ['items' => [], 'page' => 1, 'perPage' => 10, 'totalRecords' => 0, 'totalPages' => 1];
$canEditCustomerNames = (bool) ($canEditCustomerNames ?? false);
$canAdjustRewards = (bool) ($canAdjustRewards ?? false);
$value = static fn (string $field, mixed $fallback = ''): mixed => array_key_exists($field, $old) ? $old[$field] : $fallback;
$status = (string) $value('status', $customer?->status ?? 'lead');
$phoneValue = (string) $value('phone', $customer?->phone ?? '');
$phoneValue = preg_replace('/^(?:\+237|00237)[\s.-]*/', '', trim($phoneValue)) ?? '';
$editing = $customer === null || (bool) ($editing ?? false);
$canUpdateCustomer = (bool) ($canUpdateCustomer ?? false);
$profileUrl = $customer === null ? '' : $basePath . '/dhl/pickupsheet/customers/edit?customer=' . rawurlencode($customer->customerKey);
$statusLabels = ['lead' => 'Lead', 'active' => 'Active', 'attention' => 'Needs attention', 'inactive' => 'Inactive'];
$activities = is_array($activities ?? null) ? $activities : [];
$activityCount = (int) ($activityCount ?? count($activities));
$showAllActivity = (bool) ($showAllActivity ?? false);
$activityOld = is_array($activityOld ?? null) ? $activityOld : [];
$activityTypes = is_array($activityTypes ?? null) ? $activityTypes : [];
$ownerOptions = is_array($ownerOptions ?? null) ? $ownerOptions : [];
$canDeleteCustomer = (bool) ($canDeleteCustomer ?? false);
$followUpDue = $customer?->followUpDue() ?? false;
$phoneHref = $customer === null ? '' : (preg_replace('/[^+0-9]/', '', $customer->phone) ?? '');
$detail = static fn (?string $text): string => $text === null || trim($text) === ''
    ? '<span class="pickup-customer-detail-empty">Not recorded</span>'
    : htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
$eyebrow = match (true) {
    $customer === null => 'New relationship',
    $editing => 'Editing customer',
    default => 'Customer profile',
};
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
        <a class="pickup-customer-return" href="<?= $e($basePath) ?>/dhl/pickupsheet/customers"><span aria-hidden="true">&larr;</span> Back to customer directory</a>
        <header class="pickup-crm-heading pickup-customer-profile-heading">
            <div>
                <p class="eyebrow eyebrow-red"><?= $e($eyebrow) ?></p>
                <h1><?= $e($customer?->displayName ?? 'Add customer') ?></h1>
                <?php if ($customer === null): ?>
                    <p>Create a lead or customer profile. Start with the organization name; the form checks whether it already exists.</p>
                <?php elseif ($editing): ?>
                    <p>Update the details below, then save or cancel. Activity, shipments, and reward points are shown again after you leave the form.</p>
                <?php else: ?>
                    <ul class="pickup-customer-summary" aria-label="Customer summary">
                        <li><span class="pickup-customer-status is-<?= $e($customer->status) ?>"><?= $e($statusLabels[$customer->status] ?? ucfirst($customer->status)) ?></span></li>
                        <li><span>Owner</span> <?= $customer->assignedName !== '' ? $e($customer->assignedName) : 'Unassigned' ?></li>
                        <li><span>Next follow-up</span> <?php if ($customer->nextFollowUpOn === null): ?>Not scheduled<?php else: ?><?= $e($customer->nextFollowUpOn) ?><?php if ($followUpDue): ?> <strong class="pickup-follow-up-badge">Overdue</strong><?php endif; ?><?php endif; ?></li>
                        <li><span>Last shipment</span> <?= $e($customer->lastShipmentOn ?? 'None yet') ?></li>
                    </ul>
                <?php endif; ?>
            </div>
            <?php if ($customer !== null && !$editing && ($customer->phone !== '' || $customer->email !== '')): ?>
                <div class="pickup-customer-quick-actions">
                    <?php if ($customer->phone !== ''): ?><a class="button" href="tel:<?= $e($phoneHref) ?>">Call <?= $e($customer->phone) ?></a><?php endif; ?>
                    <?php if ($customer->email !== ''): ?><a class="pickup-customer-quick-email" href="mailto:<?= $e($customer->email) ?>">Email <?= $e($customer->email) ?></a><?php endif; ?>
                </div>
            <?php endif; ?>
        </header>
        <?php if (is_string($flash ?? null) && $flash !== ''): ?><div class="notice notice-success" role="status"><?= $e($flash) ?></div><?php endif; ?>
        <?php if ($errors !== []): ?><div class="notice notice-error" role="alert"><?php foreach ($errors as $error): ?><span><?= $e($error) ?></span><?php endforeach; ?><?php if (is_array($existingCustomer ?? null)): ?> <a class="pickup-crm-existing-link" href="<?= $e($existingCustomer['url'] ?? '') ?>">Open <?= $e($existingCustomer['name'] ?? 'the existing profile') ?></a><?php endif; ?></div><?php endif; ?>

        <div class="pickup-customer-layout">
            <?php if (!$editing && $customer !== null): ?>
            <section class="pickup-customer-details" aria-labelledby="customer-details-title">
                <div class="pickup-customer-details-heading"><h2 id="customer-details-title">Customer details</h2><?php if ($canUpdateCustomer): ?><a class="button" href="<?= $e($profileUrl) ?>&amp;mode=edit">Edit details</a><?php endif; ?></div>
                <h3 class="pickup-customer-detail-group">Organization</h3>
                <dl>
                    <div class="pickup-field-wide"><dt>Customer or organization name</dt><dd><?= $detail($customer->displayName) ?></dd></div>
                    <div><dt>Country</dt><dd>Cameroon</dd></div>
                    <div><dt>City</dt><dd><?= $detail($customer->city) ?></dd></div>
                    <div class="pickup-field-wide"><dt>Address</dt><dd><?= $detail($customer->address) ?></dd></div>
                </dl>
                <h3 class="pickup-customer-detail-group">Relationship</h3>
                <dl>
                    <div><dt>Relationship status</dt><dd><?= $detail($statusLabels[$customer->status] ?? ucfirst($customer->status)) ?></dd></div>
                    <div><dt>Owner</dt><dd><?= $customer->assignedName !== '' ? $e($customer->assignedName) : '<span class="pickup-customer-detail-empty">Unassigned</span>' ?></dd></div>
                    <div><dt>Next follow-up</dt><dd><?= $detail($customer->nextFollowUpOn) ?><?php if ($followUpDue): ?> <strong class="pickup-follow-up-badge">Overdue</strong><?php endif; ?></dd></div>
                    <div class="pickup-field-wide"><dt>Internal notes</dt><dd class="pickup-customer-detail-notes"><?= $detail($customer->notes) ?></dd></div>
                </dl>
                <h3 class="pickup-customer-detail-group">Primary contact</h3>
                <dl>
                    <div><dt>Contact name</dt><dd><?= $detail($customer->contactName) ?></dd></div>
                    <div><dt>Email</dt><dd><?= $detail($customer->email) ?></dd></div>
                    <div><dt>Phone</dt><dd><?= $detail($customer->phone) ?></dd></div>
                </dl>
            </section>
            <?php else: ?>
            <form class="pickup-customer-form" method="post" action="<?= $e($basePath) ?>/dhl/pickupsheet/customers/save" <?= $customer === null ? 'data-customer-autocomplete-form' : '' ?>>
                <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                <input type="hidden" name="customer_key" value="<?= $e($customer?->customerKey ?? '') ?>">
                <input type="hidden" name="expected_updated_at" value="<?= $e($customer?->updatedAt ?? '') ?>">
                <?php if ($customer === null): ?><datalist id="customer-name-suggestions" data-consignor-suggestions data-suggestion-label="Existing customer suggestions" data-search-endpoint="<?= $e($basePath) ?>/dhl/pickupsheet/customers/search"></datalist><?php endif; ?>
                <fieldset><legend>Organization</legend>
                    <label class="pickup-field pickup-field-wide"><span>Customer or organization name</span><input name="display_name" value="<?= $e($value('display_name', $customer?->displayName ?? '')) ?>" maxlength="160" required autocomplete="organization" <?= $customer === null ? 'data-consignor-input list="customer-name-suggestions" aria-autocomplete="list" aria-describedby="customer-name-suggestion-help"' : '' ?> <?= $canEditCustomerNames ? '' : 'readonly aria-readonly="true"' ?>><?php if (!$canEditCustomerNames): ?><small>Customer names can only be changed by an administrator.</small><?php elseif ($customer !== null): ?><small>Changing this name also updates the consignor name on this customer's existing pickup sheets. Other spellings are separate customers; merge them from Possible duplicate customers first so they are renamed too.</small><?php else: ?><small id="customer-name-suggestion-help">Start typing to check whether this customer already exists.</small><small class="pickup-crm-existing-hint" data-existing-customer-hint role="status" aria-live="polite" hidden></small><?php endif; ?></label>
                    <label class="pickup-field"><span>Country</span><input value="Cameroon" readonly aria-readonly="true"><input type="hidden" name="country_code" value="CM"><small>Cameroon is the default customer country.</small></label>
                    <label class="pickup-field"><span>City</span><input name="city" value="<?= $e($value('city', $customer?->city ?? '')) ?>" maxlength="100" autocomplete="address-level2"></label>
                    <label class="pickup-field pickup-field-wide"><span>Address</span><input name="address" value="<?= $e($value('address', $customer?->address ?? '')) ?>" maxlength="255" autocomplete="street-address"></label>
                </fieldset>
                <fieldset><legend>Relationship</legend>
                    <label class="pickup-field"><span>Relationship status</span><select name="status" required><?php foreach ($statusLabels as $option => $label): ?><option value="<?= $e($option) ?>" <?= $status === $option ? 'selected' : '' ?>><?= $e($label) ?></option><?php endforeach; ?></select></label>
                    <?php if ($ownerOptions !== []): ?>
                        <?php $currentOwner = (string) ($customer?->assignedActorId ?? ''); ?>
                        <label class="pickup-field"><span>Owner</span><select name="owner">
                            <option value="none" <?= $currentOwner === '' ? 'selected' : '' ?>>Unassigned</option>
                            <?php if ($currentOwner !== '' && !isset($ownerOptions[$currentOwner])): ?><option value="" selected><?= $e($customer->assignedName) ?> (current)</option><?php endif; ?>
                            <?php foreach ($ownerOptions as $ownerId => $ownerName): ?><option value="<?= $e($ownerId) ?>" <?= $currentOwner === $ownerId ? 'selected' : '' ?>><?= $e($ownerName) ?></option><?php endforeach; ?>
                        </select><small>The person responsible for this relationship.</small></label>
                    <?php endif; ?>
                    <label class="pickup-field"><span>Next follow-up</span><input type="date" name="next_follow_up_on" value="<?= $e($value('next_follow_up_on', $customer?->nextFollowUpOn ?? '')) ?>"><small>Logging an activity on the profile can also reschedule or close the follow-up.</small></label>
                    <label class="pickup-field pickup-field-wide"><span>Internal notes</span><textarea name="notes" maxlength="2000" rows="7" placeholder="Relationship context, preferences, follow-up outcome, or service opportunity"><?= $e($value('notes', $customer?->notes ?? '')) ?></textarea><small>Visible to operators and administrators. Log calls and visits under Activity instead.</small></label>
                </fieldset>
                <fieldset><legend>Primary contact</legend>
                    <label class="pickup-field"><span>Contact name</span><input name="contact_name" value="<?= $e($value('contact_name', $customer?->contactName ?? '')) ?>" maxlength="100" autocomplete="name" <?= $canEditCustomerNames ? '' : 'readonly aria-readonly="true"' ?>></label>
                    <label class="pickup-field"><span>Email</span><input type="email" name="email" value="<?= $e($value('email', $customer?->email ?? '')) ?>" maxlength="254" autocomplete="email"></label>
                    <label class="pickup-field"><span>Phone</span><div class="pickup-customer-phone"><span aria-label="Cameroon calling code">+237</span><input type="tel" name="phone" value="<?= $e($phoneValue) ?>" maxlength="20" inputmode="tel" autocomplete="tel-national" placeholder="6XX XXX XXX"></div><small>Enter the 9-digit local number. The country calling code is added automatically.</small></label>
                </fieldset>
                <div class="pickup-customer-form-actions"><button class="button" type="submit">Save customer</button><a href="<?= $e($customer === null ? $basePath . '/dhl/pickupsheet/customers' : $profileUrl) ?>">Cancel</a></div>
            </form>
            <?php endif; ?>

            <aside class="pickup-customer-context">
                <h2>Customer context</h2>
                <?php if ($customer === null): ?><p>Create a lead or customer profile. If the organization later appears as a shipment consignor, its shipment metrics are connected automatically by normalized name.</p><?php else: ?>
                    <dl><div><dt>Owner</dt><dd><?= $e($customer->assignedName !== '' ? $customer->assignedName : 'Unassigned') ?></dd></div><div><dt>Profile source</dt><dd><?= $customer->source === 'shipment' ? 'Shipment consignor' : 'Manual entry' ?></dd></div><div><dt>Updated</dt><dd><?= $e($customer->updatedAt ?? 'Not available') ?> UTC</dd></div><div><dt>Customer ID</dt><dd><?= $e(substr($customer->customerKey, 0, 12)) ?></dd></div></dl>
                <?php endif; ?>
            </aside>
        </div>

        <?php if ($customer !== null && !$editing): ?>
            <section class="pickup-customer-activity" id="customer-activity" aria-labelledby="customer-activity-title">
                <div class="pickup-card-heading"><div><span>Contact history</span><h2 id="customer-activity-title">Activity</h2></div><small><?= $activityCount > count($activities) ? $e('Latest ' . count($activities) . ' of ' . $activityCount) : $e($activityCount . ' ' . ($activityCount === 1 ? 'entry' : 'entries')) ?></small></div>
                <div class="pickup-customer-activity-layout<?= $canUpdateCustomer ? '' : ' is-read-only' ?>">
                    <?php if ($canUpdateCustomer): ?>
                    <form class="pickup-customer-activity-form" method="post" action="<?= $e($basePath) ?>/dhl/pickupsheet/customers/activities">
                        <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                        <input type="hidden" name="customer_key" value="<?= $e($customer->customerKey) ?>">
                        <label><span>Type</span><select name="activity_type" required><?php foreach ($activityTypes as $typeValue => $typeLabel): ?><option value="<?= $e($typeValue) ?>" <?= ($activityOld['activity_type'] ?? 'call') === $typeValue ? 'selected' : '' ?>><?= $e($typeLabel) ?></option><?php endforeach; ?></select></label>
                        <label><span>Date</span><input type="date" name="occurred_on" value="<?= $e($activityOld['occurred_on'] ?? gmdate('Y-m-d')) ?>" max="<?= $e(gmdate('Y-m-d')) ?>" required></label>
                        <label class="pickup-field-wide"><span>What happened</span><textarea name="summary" rows="3" maxlength="1000" minlength="3" required placeholder="Outcome of the call, visit, or message"><?= $e($activityOld['summary'] ?? '') ?></textarea></label>
                        <label class="pickup-field-wide"><span>Next follow-up</span><input type="date" name="next_follow_up_on" value="<?= $e($activityOld['next_follow_up_on'] ?? ($customer->nextFollowUpOn ?? '')) ?>"><small>Change the date to reschedule, or clear it to close the follow-up.</small></label>
                        <button class="button" type="submit">Log activity</button>
                    </form>
                    <?php endif; ?>
                    <div class="pickup-customer-activity-list">
                        <?php if ($activities === []): ?><p>No calls, visits, or messages have been logged yet.</p><?php else: ?>
                            <ol><?php foreach ($activities as $activity): ?>
                                <li><span class="pickup-activity-type"><?= $e($activityTypes[$activity['type']] ?? ucfirst((string) $activity['type'])) ?></span><div><p><?= $e($activity['summary']) ?></p><small><?= $e($activity['occurredOn']) ?> &middot; <?= $e($activity['actorName']) ?></small></div></li>
                            <?php endforeach; ?></ol>
                            <?php if (!$showAllActivity && $activityCount > count($activities)): ?><a class="pickup-customer-activity-more" href="<?= $e($profileUrl) ?>&amp;activity=all#customer-activity">Show all <?= $e($activityCount) ?> entries</a><?php elseif ($showAllActivity && $activityCount > 10): ?><a class="pickup-customer-activity-more" href="<?= $e($profileUrl) ?>#customer-activity">Show latest only</a><?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </section>

            <section class="pickup-customer-metrics" aria-label="Customer shipment metrics">
                <article><span>Shipments</span><strong><?= $e(number_format($customer->shipmentCount)) ?></strong></article>
                <article><span>Cash value</span><strong><?= $e(number_format($customer->totalCashXaf)) ?> XAF</strong></article>
                <article><span>First shipment</span><strong><?= $e($customer->firstShipmentOn ?? '—') ?></strong></article>
                <article><span>Last shipment</span><strong><?= $e($customer->lastShipmentOn ?? '—') ?></strong></article>
            </section>

            <section class="pickup-customer-history ajax-pager" aria-labelledby="customer-history-title" data-ajax-pager data-ajax-pager-id="customer-shipments" data-page-endpoint="<?= $e($basePath) ?>/dhl/pickupsheet/customers/shipments/page" data-page-param="shipment_page" data-page-size-param="shipment_per_page" data-current-page="<?= $e($shipments['page'] ?? 1) ?>" data-error-message="Shipment history could not be loaded. Please try again.">
                <div class="ajax-pager-loading" data-ajax-pager-spinner role="status" hidden><span class="pickup-loading-spinner" aria-hidden="true"></span><span>Loading shipments...</span></div>
                <div class="pickup-customer-history-content" data-ajax-pager-content aria-live="polite" aria-busy="false">
                    <?php require __DIR__ . '/_customer-shipments.php'; ?>
                </div>
            </section>

            <section class="pickup-customer-rewards" aria-labelledby="customer-rewards-title">
                <div class="pickup-card-heading"><div><span>Customer loyalty</span><h2 id="customer-rewards-title">Reward points</h2></div><small>10 points per 1 kg shipped</small></div>
                <div class="pickup-reward-summary">
                    <article><span>Total Points Balance</span><strong><?= $e(number_format($customer->rewardBalance())) ?></strong><small><?= $customer->rewardShortfall() > 0 ? $e(number_format($customer->rewardShortfall())) . ' points short: redeemed points exceed what the customer now holds, usually after a sheet was deleted. New bonuses cover this first.' : 'Available to redeem' ?></small></article>
                    <article><span>Lifetime Earned Points</span><strong><?= $e(number_format($customer->lifetimeEarnedPoints())) ?></strong><small>Cargo weight and bonus points</small></article>
                    <article><span>Loyalty Tier</span><strong><?= $e($customer->loyaltyTier()) ?></strong><small>Based on lifetime earned points</small></article>
                </div>
                <?php if ($canAdjustRewards): ?>
                <details class="pickup-reward-adjust"<?= (bool) ($rewardFormOpen ?? false) ? ' open' : '' ?>>
                    <summary>Adjust points</summary>
                    <form class="pickup-reward-form" method="post" action="<?= $e($basePath) ?>/dhl/pickupsheet/customers/rewards">
                        <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                        <input type="hidden" name="customer_key" value="<?= $e($customer->customerKey) ?>">
                        <div><p>Every adjustment requires a reason and is kept in the points history. Redemptions cannot exceed the available balance.</p></div>
                        <label><span>Operation</span><select name="operation" required><option value="bonus">Add bonus points</option><option value="redeem">Redeem points</option></select></label>
                        <label><span>Points</span><input type="number" name="points" min="1" max="100000" step="1" required inputmode="numeric"></label>
                        <label class="pickup-reward-reason"><span>Reason</span><input name="reason" maxlength="255" minlength="3" required placeholder="Promotion, service recovery, or reward redeemed"></label>
                        <button class="button" type="submit">Update points</button>
                    </form>
                </details>
                <?php endif; ?>
                <div class="pickup-points-log ajax-pager" data-ajax-pager data-ajax-pager-id="customer-points" data-page-endpoint="<?= $e($basePath) ?>/dhl/pickupsheet/customers/points/page" data-page-param="points_page" data-page-size-param="points_per_page" data-current-page="<?= $e($rewardHistory['page'] ?? 1) ?>" data-error-message="Points history could not be loaded. Please try again.">
                    <div class="ajax-pager-loading" data-ajax-pager-spinner role="status" hidden><span class="pickup-loading-spinner" aria-hidden="true"></span><span>Loading points history...</span></div>
                    <div class="pickup-points-log-content" data-ajax-pager-content aria-live="polite" aria-busy="false">
                        <?php require __DIR__ . '/_customer-points.php'; ?>
                    </div>
                </div>
            </section>

            <?php if ($canDeleteCustomer): ?>
            <section class="pickup-customer-danger" aria-labelledby="customer-delete-title">
                <div><h2 id="customer-delete-title">Delete customer</h2><p>Permanently removes this profile with its contact details, activity, reward adjustments, aliases, and merge records, for example to honour an erasure request. Pickup sheets keep the consignor name because they are operational records.</p></div>
                <form method="post" action="<?= $e($basePath) ?>/dhl/pickupsheet/customers/delete" data-crm-delete-form data-customer-name="<?= $e($customer->displayName) ?>"><input type="hidden" name="_token" value="<?= $e($csrfToken) ?>"><input type="hidden" name="customer_key" value="<?= $e($customer->customerKey) ?>"><button type="submit">Delete customer</button></form>
            </section>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>
