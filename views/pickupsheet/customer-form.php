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
$closeFollowUpForm = static function () use ($e, $basePath, $csrfToken, $customer): string {
    return '<form class="pickup-follow-up-close" method="post" action="' . $e($basePath) . '/dhl/pickupsheet/customers/follow-up/close" data-crm-close-follow-up-form data-follow-up-date="' . $e($customer?->nextFollowUpOn ?? '') . '">'
        . '<input type="hidden" name="_token" value="' . $e($csrfToken) . '">'
        . '<input type="hidden" name="customer_key" value="' . $e($customer?->customerKey ?? '') . '">'
        . '<button type="submit">Close follow-up</button></form>';
};
$phoneHref = $customer === null ? '' : (preg_replace('/[^+0-9]/', '', $customer->phone) ?? '');
$detail = static fn (?string $text): string => $text === null || trim($text) === ''
    ? '<span class="pickup-customer-detail-empty">Not recorded</span>'
    : htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
$initials = '';
if ($customer !== null) {
    $words = preg_split('/[^\p{L}\p{N}]+/u', $customer->displayName, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    foreach (array_slice($words, 0, 2) as $word) {
        $initials .= function_exists('mb_substr') ? mb_strtoupper(mb_substr($word, 0, 1, 'UTF-8'), 'UTF-8') : strtoupper($word[0]);
    }
}
$customerSince = $customer?->customerSince();
$eyebrow = match (true) {
    $customer === null => 'New relationship',
    $editing => 'Editing customer',
    default => 'Customer profile',
};
?>
<section class="pickup-view-workspace pickup-crm-workspace">
    <?php
    $navTitle = 'Pickupsheet CRM';
    $navCurrent = 'crm';
    $navName = (string) ($recordsFullName ?? $recordsUsername ?? '');
    $navRole = (string) ($recordsRole ?? '');
    $navUsername = (string) ($recordsUsername ?? '');
    $navClass = '';
    require __DIR__ . '/_workspace-header.php';
    ?>

    <div class="container pickup-customer-shell">
        <a class="pickup-customer-return" href="<?= $e($returnUrl ?? $basePath . '/dhl/pickupsheet/customers') ?>"><span aria-hidden="true">&larr;</span> <?= $e($returnLabel ?? 'Back to customer directory') ?></a>
        <header class="pickup-crm-heading pickup-customer-profile-heading<?= $customer !== null && !$editing ? ' is-profile' : '' ?>">
            <?php if ($customer !== null && !$editing): ?>
                <div class="pickup-profile-identity">
                    <span class="pickup-profile-avatar" aria-hidden="true"><?= $e($initials !== '' ? $initials : '?') ?></span>
                    <div>
                        <p class="eyebrow eyebrow-red"><?= $e($eyebrow) ?></p>
                        <h1><?= $e($customer->displayName) ?></h1>
                        <ul class="pickup-profile-meta" aria-label="Customer summary">
                            <li><span class="pickup-customer-id" title="Customer ID">ID <?= $e($customer->reference()) ?></span></li>
                            <li><span class="pickup-customer-status is-<?= $e($customer->status) ?>"><?= $e($statusLabels[$customer->status] ?? ucfirst($customer->status)) ?></span></li>
                            <?php if ($customer->isNew()): ?><li><span class="pickup-customer-new" title="Customer for <?= $e($customer->ageInDays()) ?> <?= $customer->ageInDays() === 1 ? 'day' : 'days' ?>">New</span></li><?php endif; ?>
                            <li><span class="sr-only">Owner: </span><?= $customer->assignedName !== '' ? $e($customer->assignedName) : 'Unassigned' ?></li>
                            <?php if ($customerSince !== null): ?><li>Customer since <?= $e($customerSince) ?></li><?php endif; ?>
                        </ul>
                    </div>
                </div>
                <div class="pickup-customer-quick-actions" aria-label="Customer actions">
                    <?php if ($customer->phone !== ''): ?><a class="button" href="tel:<?= $e($phoneHref) ?>">Call <?= $e($customer->phone) ?></a><?php endif; ?>
                    <?php if ($customer->email !== ''): ?><a class="pickup-customer-quick-email" href="mailto:<?= $e($customer->email) ?>">Email <?= $e($customer->email) ?></a><?php endif; ?>
                    <?php if ($canUpdateCustomer): ?><a class="pickup-profile-action" href="#customer-activity">Log activity</a><a class="pickup-profile-action" href="<?= $e($profileUrl) ?>&amp;mode=edit">Edit details</a><?php endif; ?>
                </div>
            <?php else: ?>
                <div>
                    <p class="eyebrow eyebrow-red"><?= $e($eyebrow) ?></p>
                    <h1><?= $e($customer?->displayName ?? 'Add customer') ?></h1>
                    <?php if ($customer === null): ?>
                        <p>Create a lead or customer profile. Start with the organization name; the form checks whether it already exists.</p>
                    <?php else: ?>
                        <p>Update the details below, then save or cancel. Activity, shipments, and reward points are shown again after you leave the form.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </header>
        <?php if (is_string($flash ?? null) && $flash !== ''): ?><div class="notice notice-success" role="status"><?= $e($flash) ?></div><?php endif; ?>
        <?php if ($errors !== []): ?><div class="notice notice-error" role="alert"><?php foreach ($errors as $error): ?><span><?= $e($error) ?></span><?php endforeach; ?><?php if (is_array($existingCustomer ?? null)): ?> <a class="pickup-crm-existing-link" href="<?= $e($existingCustomer['url'] ?? '') ?>">Open <?= $e($existingCustomer['name'] ?? 'the existing profile') ?></a><?php endif; ?></div><?php endif; ?>

        <?php if ($customer !== null && !$editing): ?>
            <?php if ($followUpDue): ?>
                <div class="pickup-profile-alert" role="status"><strong>Follow-up overdue.</strong> It was due on <?= $e($customer->nextFollowUpOn) ?>. <?php if ($canUpdateCustomer): ?><a href="#customer-activity">Log the outcome</a> or <?= $closeFollowUpForm() ?><?php endif; ?></div>
            <?php endif; ?>
            <section class="pickup-profile-kpis" aria-label="Customer at a glance">
                <article><span>Shipments</span><strong><?= $e(number_format($customer->shipmentCount)) ?></strong><small><?= $customer->firstShipmentOn !== null ? 'Since ' . $e($customer->firstShipmentOn) : 'No shipments yet' ?></small></article>
                <article><span>Shipment value</span><strong><?= $e(number_format($customer->totalCashXaf)) ?> <em>XAF</em></strong><small>Cash collected</small></article>
                <article><span>Reward points</span><strong><?= $e(number_format($customer->rewardBalance())) ?></strong><small><?= $e($customer->loyaltyTier()) ?> tier</small></article>
                <article><span>Next follow-up</span><strong><?= $e($customer->nextFollowUpOn ?? 'None') ?></strong><small><?php if ($followUpDue): ?><strong class="pickup-follow-up-badge">Overdue</strong><?php else: ?><?= $customer->nextFollowUpOn === null ? 'Not scheduled' : 'Scheduled' ?><?php endif; ?></small><?php if ($canUpdateCustomer && $customer->nextFollowUpOn !== null): ?><?= $closeFollowUpForm() ?><?php endif; ?></article>
                <article><span>Last shipment</span><strong><?= $e($customer->lastShipmentOn ?? 'None') ?></strong><small><?= $customer->lastShipmentOn !== null ? 'Most recent collection' : 'Awaiting first shipment' ?></small></article>
            </section>
            <nav class="pickup-profile-nav" aria-label="Customer profile sections">
                <a href="#customer-overview">Overview</a>
                <a href="#customer-activity">Activity <span><?= $e($activityCount) ?></span></a>
                <a href="#customer-shipments">Shipments <span><?= $e((int) ($shipments['totalRecords'] ?? 0)) ?></span></a>
                <a href="#customer-rewards">Rewards</a>
                <?php if ($canDeleteCustomer): ?><a href="#customer-data">Data management</a><?php endif; ?>
            </nav>
        <?php endif; ?>

        <div class="pickup-customer-layout" id="customer-overview">
            <?php if (!$editing && $customer !== null): ?>
            <section class="pickup-customer-details" aria-labelledby="customer-details-title">
                <div class="pickup-customer-details-heading"><h2 id="customer-details-title">Customer details</h2><?php if ($canUpdateCustomer): ?><a class="pickup-profile-edit-link" href="<?= $e($profileUrl) ?>&amp;mode=edit" aria-label="Edit customer details">Edit</a><?php endif; ?></div>
                <h3 class="pickup-customer-detail-group">Primary contact</h3>
                <dl>
                    <div><dt>Contact name</dt><dd><?= $detail($customer->contactName) ?></dd></div>
                    <div><dt>Phone</dt><dd><?= $customer->phone !== '' ? '<a href="tel:' . $e($phoneHref) . '">' . $e($customer->phone) . '</a>' : $detail('') ?></dd></div>
                    <div class="pickup-field-wide"><dt>Email</dt><dd><?= $customer->email !== '' ? '<a href="mailto:' . $e($customer->email) . '">' . $e($customer->email) . '</a>' : $detail('') ?></dd></div>
                </dl>
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
            </section>

            <?php else: ?>
            <form class="pickup-customer-form" method="post" action="<?= $e($basePath) ?>/dhl/pickupsheet/customers/save" <?= $customer === null ? 'data-customer-autocomplete-form' : '' ?>>
                <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                <input type="hidden" name="customer_key" value="<?= $e($customer?->customerKey ?? '') ?>">
                <input type="hidden" name="expected_updated_at" value="<?= $e($customer?->updatedAt ?? '') ?>">
                <?php if ($customer === null): ?><datalist id="customer-name-suggestions" data-consignor-suggestions data-suggestion-label="Existing customer suggestions" data-search-endpoint="<?= $e($basePath) ?>/dhl/pickupsheet/customers/search"></datalist><?php endif; ?>
                <fieldset><legend>Organization</legend>
                    <label class="pickup-field pickup-field-wide"><span>Customer or organization name</span><input name="display_name" value="<?= $e($value('display_name', $customer?->displayName ?? '')) ?>" maxlength="160" required autocomplete="organization" data-name-field <?= $customer === null ? 'data-consignor-input list="customer-name-suggestions" aria-autocomplete="list" aria-describedby="customer-name-suggestion-help"' : '' ?> <?= $canEditCustomerNames ? '' : 'readonly aria-readonly="true"' ?>><?php if (!$canEditCustomerNames): ?><small>Customer names can only be changed by an administrator.</small><?php elseif ($customer !== null): ?><small>Changing this name also updates the consignor name on this customer's existing pickup sheets. Other spellings are separate customers; merge them into this profile first (Merge a duplicate, below) so they are renamed too.</small><?php else: ?><small id="customer-name-suggestion-help">Start typing to check whether this customer already exists.</small><small class="pickup-crm-existing-hint" data-existing-customer-hint role="status" aria-live="polite" hidden></small><?php endif; ?></label>
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
                    <label class="pickup-field"><span>Contact name</span><input name="contact_name" value="<?= $e($value('contact_name', $customer?->contactName ?? '')) ?>" maxlength="100" autocomplete="name" data-name-field <?= $canEditCustomerNames ? '' : 'readonly aria-readonly="true"' ?>></label>
                    <label class="pickup-field"><span>Email</span><input type="email" name="email" value="<?= $e($value('email', $customer?->email ?? '')) ?>" maxlength="254" autocomplete="email"></label>
                    <label class="pickup-field"><span>Phone</span><div class="pickup-customer-phone"><span aria-label="Cameroon calling code">+237</span><input type="tel" name="phone" value="<?= $e($phoneValue) ?>" maxlength="20" inputmode="tel" autocomplete="tel-national" placeholder="6XX XXX XXX"></div><small>Enter the 9-digit local number. The country calling code is added automatically.</small></label>
                </fieldset>
                <div class="pickup-customer-form-actions"><button class="button" type="submit">Save customer</button><a href="<?= $e($customer === null ? $basePath . '/dhl/pickupsheet/customers' : $profileUrl) ?>">Cancel</a></div>
            </form>
            <?php endif; ?>

            <aside class="pickup-customer-context">
                <h2>Reference</h2>
                <?php if ($customer === null): ?><p>Create a lead or customer profile. If the organization later appears as a shipment consignor, its shipment metrics are connected automatically by normalized name.</p><?php else: ?>
                    <dl><div><dt>Customer ID</dt><dd><span class="pickup-customer-id"><?= $e($customer->reference()) ?></span></dd></div><div><dt>Owner</dt><dd><?= $e($customer->assignedName !== '' ? $customer->assignedName : 'Unassigned') ?></dd></div><div><dt>Profile source</dt><dd><?= $customer->source === 'shipment' ? 'Shipment consignor' : 'Manual entry' ?></dd></div><div><dt>Customer since</dt><dd><?= $e($customerSince ?? 'Not available') ?></dd></div><div><dt>Last updated</dt><dd><?= $e($customer->updatedAt ?? 'Not available') ?> UTC</dd></div></dl>
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
                        <label class="pickup-field-wide"><span>Next follow-up</span><input type="date" name="next_follow_up_on" value="<?= $e($activityOld['next_follow_up_on'] ?? ($customer->nextFollowUpOn ?? '')) ?>"><small>Change the date to reschedule. To close the follow-up, clear the date or use Close follow-up.</small></label>
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


            <section class="pickup-customer-history ajax-pager" id="customer-shipments" aria-labelledby="customer-history-title" data-ajax-pager data-ajax-pager-id="customer-shipments" data-page-endpoint="<?= $e($basePath) ?>/dhl/pickupsheet/customers/shipments/page" data-page-param="shipment_page" data-page-size-param="shipment_per_page" data-current-page="<?= $e($shipments['page'] ?? 1) ?>" data-error-message="Shipment history could not be loaded. Please try again.">
                <div class="ajax-pager-loading" data-ajax-pager-spinner role="status" hidden><span class="pickup-loading-spinner" aria-hidden="true"></span><span>Loading shipments...</span></div>
                <div class="pickup-customer-history-content" data-ajax-pager-content aria-live="polite" aria-busy="false">
                    <?php require __DIR__ . '/_customer-shipments.php'; ?>
                </div>
            </section>

            <section class="pickup-customer-rewards" id="customer-rewards" aria-labelledby="customer-rewards-title">
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
            <div class="pickup-profile-data" id="customer-data">
            <h2 class="pickup-profile-zone-title">Data management <small>Administrators only</small></h2>
            <section class="pickup-customer-merge" id="customer-merge" aria-labelledby="customer-merge-title">
                <div class="pickup-card-heading"><div><span>Data quality</span><h2 id="customer-merge-title">Merge a duplicate</h2></div><small>Undo anytime from Recent merges</small></div>
                <form class="pickup-merge-form" method="post" action="<?= $e($basePath) ?>/dhl/pickupsheet/customers/merge" data-crm-merge-form data-customer-autocomplete-form data-profile-merge data-keep-name="<?= $e($customer->displayName) ?>" data-keep-key="<?= $e($customer->customerKey) ?>" data-lookup-endpoint="<?= $e($basePath) ?>/dhl/pickupsheet/customers/search">
                    <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>"><input type="hidden" name="target_customer_key" value="<?= $e($customer->customerKey) ?>"><input type="hidden" name="return_to" value="profile">
                    <datalist id="customer-merge-suggestions" data-consignor-suggestions data-suggestion-label="Customer profiles to merge" data-search-endpoint="<?= $e($basePath) ?>/dhl/pickupsheet/customers/search"></datalist>
                    <p class="pickup-merge-intro">Use this when another profile is the same organization under a different name, including pairs that Possible duplicate customers does not detect.</p>
                    <div class="pickup-merge-flow">
                        <article class="pickup-merge-card is-source" data-merge-source-card data-state="empty">
                            <span class="pickup-merge-role">Merge and remove</span>
                            <label class="pickup-merge-search"><span class="sr-only">Duplicate customer name</span><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="6.5"/><path d="M16 16l4.5 4.5"/></svg><input name="source_customer_name" maxlength="160" required autocomplete="off" data-consignor-input list="customer-merge-suggestions" aria-autocomplete="list" aria-describedby="customer-merge-status" placeholder="Search customer name"></label>
                            <dl class="pickup-merge-facts" data-merge-source-facts hidden>
                                <div><dt>Shipments</dt><dd data-merge-fact="shipments"></dd></div>
                                <div><dt>Last shipment</dt><dd data-merge-fact="last"></dd></div>
                                <div><dt>Contact</dt><dd data-merge-fact="contact"></dd></div>
                                <div><dt>Status</dt><dd data-merge-fact="status"></dd></div>
                            </dl>
                            <p class="pickup-merge-status" id="customer-merge-status" data-merge-status aria-live="polite">Pick the duplicate profile from the suggestions.</p>
                        </article>
                        <div class="pickup-merge-arrow" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 12h15M13 6l6 6-6 6"/></svg></div>
                        <article class="pickup-merge-card is-target">
                            <span class="pickup-merge-role">Keep</span>
                            <strong class="pickup-merge-name"><?= $e($customer->displayName) ?></strong>
                            <dl class="pickup-merge-facts">
                                <div><dt>Shipments</dt><dd><?= $e(number_format($customer->shipmentCount)) ?></dd></div>
                                <div><dt>Last shipment</dt><dd><?= $e($customer->lastShipmentOn ?? 'None') ?></dd></div>
                                <div><dt>Contact</dt><dd><?= $e($customer->contactName !== '' ? $customer->contactName : 'Not recorded') ?></dd></div>
                                <div><dt>Status</dt><dd><?= $e($statusLabels[$customer->status] ?? ucfirst($customer->status)) ?></dd></div>
                            </dl>
                        </article>
                    </div>
                    <ul class="pickup-merge-outcome">
                        <li>Keeps the name <strong><?= $e($customer->displayName) ?></strong></li>
                        <li>Moves shipments, rewards, and aliases</li>
                        <li>Fills in missing contact details</li>
                        <li>Removes the duplicate profile</li>
                    </ul>
                    <div class="pickup-merge-actions">
                        <button class="pickup-merge-submit" type="submit" data-merge-submit disabled>Review merge</button>
                    </div>
                </form>
            </section>
            <section class="pickup-customer-danger" aria-labelledby="customer-delete-title">
                <div><h2 id="customer-delete-title">Delete customer</h2><p>Permanently removes this profile with its contact details, activity, reward adjustments, aliases, and merge records, for example to honour an erasure request. Pickup sheets keep the consignor name because they are operational records.</p></div>
                <form method="post" action="<?= $e($basePath) ?>/dhl/pickupsheet/customers/delete" data-crm-delete-form data-customer-name="<?= $e($customer->displayName) ?>"><input type="hidden" name="_token" value="<?= $e($csrfToken) ?>"><input type="hidden" name="customer_key" value="<?= $e($customer->customerKey) ?>"><button type="submit">Delete customer</button></form>
            </section>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>
