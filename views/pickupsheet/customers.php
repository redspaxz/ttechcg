<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$summary = is_array($summary ?? null) ? $summary : [];
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
            <article><span>Follow-ups due</span><strong><?= $e(number_format((int) ($summary['followUpsDue'] ?? 0))) ?></strong><small>Due today or overdue</small></article>
        </section>

        <form class="pickup-crm-filter" method="get" action="<?= $e($basePath) ?>/dhl/pickupsheet/customers" data-ajax-pager-form="customer-directory">
            <label><span>Search customers</span><input type="search" name="q" value="<?= $e($search ?? '') ?>" maxlength="100" placeholder="Name, contact, email, phone, or city"></label>
            <label><span>Relationship status</span><select name="status"><option value="">All statuses</option><?php foreach (['lead' => 'Lead', 'active' => 'Active', 'attention' => 'Needs attention', 'inactive' => 'Inactive'] as $value => $label): ?><option value="<?= $e($value) ?>" <?= ($statusFilter ?? '') === $value ? 'selected' : '' ?>><?= $e($label) ?></option><?php endforeach; ?></select></label>
            <button class="button" type="submit">Filter</button>
            <?php if (($search ?? '') !== '' || ($statusFilter ?? '') !== ''): ?><a href="<?= $e($basePath) ?>/dhl/pickupsheet/customers" data-ajax-pager-clear="customer-directory">Clear</a><?php endif; ?>
        </form>

        <section class="pickup-crm-directory ajax-pager" aria-labelledby="customer-directory-title" data-ajax-pager data-ajax-pager-id="customer-directory" data-page-endpoint="<?= $e($basePath) ?>/dhl/pickupsheet/customers/page" data-page-param="page" data-current-page="<?= $e($customers['page'] ?? 1) ?>" data-error-message="Customer profiles could not be loaded. Please try again.">
            <div class="ajax-pager-loading" data-ajax-pager-spinner role="status" hidden><span class="pickup-loading-spinner" aria-hidden="true"></span><span>Loading customers...</span></div>
            <div class="pickup-crm-directory-content" data-ajax-pager-content aria-live="polite" aria-busy="false">
                <?php require __DIR__ . '/_customer-directory.php'; ?>
            </div>
        </section>
    </div>
</section>
