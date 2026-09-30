<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$summary = is_array($summary ?? null) ? $summary : [];
$duplicateSuggestions = is_array($duplicateSuggestions ?? null) ? $duplicateSuggestions : [];
$recentMerges = is_array($recentMerges ?? null) ? $recentMerges : [];
?>
<section class="pickup-view-workspace pickup-crm-workspace">
    <div class="container pickup-workspace-header">
        <strong class="pickup-wordmark">Pickupsheet CRM</strong>
        <div class="pickup-header-links">
            <span class="pickup-session-user"><?= $e($recordsFullName ?? $recordsUsername ?? '') ?> &middot; <?= $e($recordsRole ?? '') ?></span>
            <?php if (($recordsRole ?? '') === 'admin'): ?><a class="pickup-back" href="<?= $e($basePath) ?>/dhl/pickupsheet/dashboard">Dashboard <span aria-hidden="true">&#8599;</span></a><?php endif; ?>
            <a class="pickup-back" href="<?= $e($basePath) ?>/dhl/pickupsheet/submissions">Submitted sheets <span aria-hidden="true">&#8599;</span></a>
            <a class="pickup-back" href="<?= $e($basePath) ?>/dhl/pickupsheet/settings">User settings <span aria-hidden="true">&#8599;</span></a>
            <form method="post" action="<?= $e($basePath) ?>/dhl/pickupsheet/logout"><input type="hidden" name="_token" value="<?= $e($csrfToken) ?>"><button class="pickup-link-button" type="submit">Sign out</button></form>
        </div>
    </div>

    <div class="container pickup-crm-shell">
        <?php if (is_string($flash ?? null) && $flash !== ''): ?><div class="notice notice-success" role="status"><?= $e($flash) ?></div><?php endif; ?>
        <?php if (is_string($error ?? null) && $error !== ''): ?><div class="notice notice-error" role="alert"><?= $e($error) ?></div><?php endif; ?>
        <header class="pickup-crm-heading">
            <div><p class="eyebrow eyebrow-red">Customer relationships</p><h1>Customer CRM</h1><p>Turn shipment senders into managed customer profiles, maintain contact data, and schedule follow-up.</p></div>
            <?php if ((bool) ($canCreateCustomers ?? false)): ?><a class="button pickup-crm-add" href="<?= $e($basePath) ?>/dhl/pickupsheet/customers/new">Add customer</a><?php endif; ?>
        </header>

        <section class="pickup-crm-kpis" aria-label="Customer CRM summary">
            <article><span>Customers</span><strong><?= $e(number_format((int) ($summary['customerCount'] ?? 0))) ?></strong><small>Shipment and manual profiles</small></article>
            <article><span>Active</span><strong><?= $e(number_format((int) ($summary['activeCount'] ?? 0))) ?></strong><small>Current relationships</small></article>
            <article><span>Needs attention</span><strong><?= $e(number_format((int) ($summary['attentionCount'] ?? 0))) ?></strong><small>Flagged accounts</small></article>
            <article><span>Follow-ups due</span><strong><?= $e(number_format((int) ($summary['followUpsDue'] ?? 0))) ?></strong><small><a href="<?= $e($basePath) ?>/dhl/pickupsheet/customers?follow_up=due">Show due or overdue</a></small></article>
        </section>

        <?php if ($duplicateSuggestions !== []): ?>
            <section class="pickup-crm-duplicates" aria-labelledby="crm-duplicates-title">
                <div class="pickup-card-heading"><div><span>Data quality</span><h2 id="crm-duplicates-title">Possible duplicate customers</h2></div><small><?= $e(count($duplicateSuggestions)) ?> suggested <?= count($duplicateSuggestions) === 1 ? 'match' : 'matches' ?></small></div>
                <p class="pickup-crm-duplicates-intro">Review each suggestion before merging. The profile you keep retains its name; shipment history, rewards, and available contact details from the other profile are moved into it.</p>
                <div class="pickup-crm-duplicate-list">
                    <?php foreach ($duplicateSuggestions as $suggestion): ?>
                        <?php $primary = $suggestion['primary']; $duplicate = $suggestion['duplicate']; ?>
                        <article>
                            <div class="pickup-crm-duplicate-score"><?php if (($suggestion['reason'] ?? 'name') === 'name'): ?><strong><?= $e((int) $suggestion['confidence']) ?>%</strong><span>Name match</span><?php else: ?><strong>Same</strong><span><?= ($suggestion['reason'] ?? '') === 'email' ? 'email address' : 'phone number' ?></span><?php endif; ?></div>
                            <div class="pickup-crm-duplicate-profile"><strong><?= $e($primary->displayName) ?></strong><small><?= $e(number_format($primary->shipmentCount)) ?> shipments &middot; <?= $e($primary->contactName !== '' ? $primary->contactName : 'No contact') ?></small></div>
                            <span class="pickup-crm-duplicate-separator" aria-hidden="true">&harr;</span>
                            <div class="pickup-crm-duplicate-profile"><strong><?= $e($duplicate->displayName) ?></strong><small><?= $e(number_format($duplicate->shipmentCount)) ?> shipments &middot; <?= $e($duplicate->contactName !== '' ? $duplicate->contactName : 'No contact') ?></small></div>
                            <div class="pickup-crm-duplicate-actions">
                                <form method="post" action="<?= $e($basePath) ?>/dhl/pickupsheet/customers/merge" data-crm-merge-form data-keep-name="<?= $e($primary->displayName) ?>" data-merge-name="<?= $e($duplicate->displayName) ?>"><input type="hidden" name="_token" value="<?= $e($csrfToken) ?>"><input type="hidden" name="target_customer_key" value="<?= $e($primary->customerKey) ?>"><input type="hidden" name="source_customer_key" value="<?= $e($duplicate->customerKey) ?>"><button type="submit">Keep <?= $e($primary->displayName) ?></button></form>
                                <form method="post" action="<?= $e($basePath) ?>/dhl/pickupsheet/customers/merge" data-crm-merge-form data-keep-name="<?= $e($duplicate->displayName) ?>" data-merge-name="<?= $e($primary->displayName) ?>"><input type="hidden" name="_token" value="<?= $e($csrfToken) ?>"><input type="hidden" name="target_customer_key" value="<?= $e($duplicate->customerKey) ?>"><input type="hidden" name="source_customer_key" value="<?= $e($primary->customerKey) ?>"><button type="submit">Keep <?= $e($duplicate->displayName) ?></button></form>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($duplicateSuggestions === [] && (bool) ($canMergeCustomers ?? false)): ?>
            <section class="pickup-crm-duplicates" aria-labelledby="crm-duplicates-title">
                <div class="pickup-card-heading"><div><span>Data quality</span><h2 id="crm-duplicates-title">Possible duplicate customers</h2></div></div>
                <p class="pickup-crm-duplicates-empty">No likely duplicates were found. To merge two profiles with different names, open the customer you want to keep and use Merge a duplicate.</p>
            </section>
        <?php endif; ?>

        <?php if ($recentMerges !== []): ?>
            <section class="pickup-crm-duplicates pickup-crm-merge-history" aria-labelledby="crm-merge-history-title">
                <div class="pickup-card-heading"><div><span>Data quality</span><h2 id="crm-merge-history-title">Recent merges</h2></div><small>Undo restores the separate profile; Ignore keeps the merge</small></div>
                <p class="pickup-crm-duplicates-intro">Undo moves the merged profile's shipments, rewards, and aliases back and restores any retained-profile fields nobody has edited since. Shipments later entered under the old name stay with the retained profile.</p>
                <div class="pickup-crm-merge-list">
                    <?php foreach ($recentMerges as $merge): ?>
                        <article>
                            <div class="pickup-crm-duplicate-profile"><strong><?= $e($merge['sourceName']) ?> &rarr; <?= $e($merge['targetName']) ?></strong><small>Merged <?= $e($merge['mergedAt']) ?> UTC</small></div>
                            <div class="pickup-crm-merge-actions"><form method="post" action="<?= $e($basePath) ?>/dhl/pickupsheet/customers/merge/undo" data-crm-undo-merge-form data-merge-name="<?= $e($merge['sourceName']) ?>" data-keep-name="<?= $e($merge['targetName']) ?>"><input type="hidden" name="_token" value="<?= $e($csrfToken) ?>"><input type="hidden" name="merge_id" value="<?= $e($merge['id']) ?>"><button type="submit">Undo merge</button></form>
                            <form method="post" action="<?= $e($basePath) ?>/dhl/pickupsheet/customers/merge/dismiss" data-crm-dismiss-merge-form data-merge-name="<?= $e($merge['sourceName']) ?>" data-keep-name="<?= $e($merge['targetName']) ?>"><input type="hidden" name="_token" value="<?= $e($csrfToken) ?>"><input type="hidden" name="merge_id" value="<?= $e($merge['id']) ?>"><button type="submit" class="pickup-crm-ignore">Ignore</button></form></div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <form class="pickup-crm-filter" method="get" action="<?= $e($basePath) ?>/dhl/pickupsheet/customers" data-ajax-pager-form="customer-directory" data-customer-autocomplete-form>
            <datalist id="customer-search-suggestions" data-consignor-suggestions data-suggestion-label="Customer name suggestions" data-search-endpoint="<?= $e($basePath) ?>/dhl/pickupsheet/customers/search"></datalist>
            <label><span>Search customers</span><input type="search" name="q" value="<?= $e($search ?? '') ?>" maxlength="100" placeholder="Name, customer ID, contact, email, phone, or city" autocomplete="off" list="customer-search-suggestions" aria-autocomplete="list" data-consignor-input data-submit-on-suggestion></label>
            <label><span>Relationship status</span><select name="status"><option value="">All statuses</option><?php foreach (['lead' => 'Lead', 'active' => 'Active', 'attention' => 'Needs attention', 'inactive' => 'Inactive'] as $value => $label): ?><option value="<?= $e($value) ?>" <?= ($statusFilter ?? '') === $value ? 'selected' : '' ?>><?= $e($label) ?></option><?php endforeach; ?></select></label>
            <label><span>Follow-up</span><select name="follow_up"><option value="">Any follow-up</option><?php foreach (['due' => 'Due or overdue', 'scheduled' => 'Scheduled later', 'none' => 'Not scheduled'] as $value => $label): ?><option value="<?= $e($value) ?>" <?= ($followUpFilter ?? '') === $value ? 'selected' : '' ?>><?= $e($label) ?></option><?php endforeach; ?></select></label>
            <label><span>Owner</span><select name="owner"><option value="">Everyone</option><?php foreach (['me' => 'My customers', 'unassigned' => 'Unassigned'] as $value => $label): ?><option value="<?= $e($value) ?>" <?= ($ownerFilter ?? '') === $value ? 'selected' : '' ?>><?= $e($label) ?></option><?php endforeach; ?></select></label>
            <label><span>Sort by</span><select name="sort"><?php foreach (($sortOptions ?? []) as $value => $label): ?><option value="<?= $e($value === 'priority' ? '' : $value) ?>" <?= ($sortOrder ?? '') === ($value === 'priority' ? '' : $value) ? 'selected' : '' ?>><?= $e($label) ?></option><?php endforeach; ?></select></label>
            <div class="pickup-crm-filter-actions">
                <button class="button" type="submit">Filter</button>
                <?php if ((bool) ($canExportCustomers ?? false)): ?><button class="pickup-crm-export" type="submit" formaction="<?= $e($basePath) ?>/dhl/pickupsheet/customers/export" data-crm-export>Export to Excel</button><?php endif; ?>
                <?php if (($search ?? '') !== '' || ($statusFilter ?? '') !== '' || ($followUpFilter ?? '') !== '' || ($ownerFilter ?? '') !== '' || ($sortOrder ?? '') !== ''): ?><a href="<?= $e($basePath) ?>/dhl/pickupsheet/customers" data-ajax-pager-clear="customer-directory">Clear</a><?php endif; ?>
            </div>
        </form>

        <section class="pickup-crm-directory ajax-pager" aria-labelledby="customer-directory-title" data-ajax-pager data-ajax-pager-id="customer-directory" data-filter-params="q,status,follow_up,owner,sort" data-page-endpoint="<?= $e($basePath) ?>/dhl/pickupsheet/customers/page" data-page-param="page" data-page-size-param="per_page" data-current-page="<?= $e($customers['page'] ?? 1) ?>" data-error-message="Customer profiles could not be loaded. Please try again.">
            <div class="ajax-pager-loading" data-ajax-pager-spinner role="status" hidden><span class="pickup-loading-spinner" aria-hidden="true"></span><span>Loading customers...</span></div>
            <div class="pickup-crm-directory-content" data-ajax-pager-content aria-live="polite" aria-busy="false">
                <?php require __DIR__ . '/_customer-directory.php'; ?>
            </div>
        </section>
    </div>
</section>
