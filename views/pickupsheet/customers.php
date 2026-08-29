<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$summary = is_array($summary ?? null) ? $summary : [];
$customers = is_array($customers ?? null) ? $customers : [];
$items = is_array($customers['items'] ?? null) ? $customers['items'] : [];
$page = (int) ($customers['page'] ?? 1);
$totalPages = (int) ($customers['totalPages'] ?? 1);
$queryForPage = static function (int $targetPage) use ($search, $statusFilter): string {
    return http_build_query(array_filter([
        'q' => $search ?? '',
        'status' => $statusFilter ?? '',
        'page' => $targetPage,
    ], static fn (mixed $value): bool => $value !== ''));
};
?>
<section class="pickup-view-workspace pickup-crm-workspace">
    <div class="container pickup-workspace-header">
        <strong class="pickup-wordmark">Pickupsheet CRM</strong>
        <div class="pickup-header-links">
            <span class="pickup-session-user"><?= $e($recordsFullName ?? $recordsUsername ?? '') ?> &middot; admin</span>
            <a class="pickup-back" href="<?= $e($basePath) ?>/dhl/pickupsheet/dashboard">Dashboard <span aria-hidden="true">&#8599;</span></a>
            <form method="post" action="<?= $e($basePath) ?>/dhl/pickupsheet/logout"><input type="hidden" name="_token" value="<?= $e($csrfToken) ?>"><button class="pickup-link-button" type="submit">Sign out</button></form>
        </div>
    </div>

    <div class="container pickup-crm-shell">
        <?php if (is_string($flash ?? null) && $flash !== ''): ?><div class="notice notice-success" role="status"><?= $e($flash) ?></div><?php endif; ?>
        <?php if (is_string($error ?? null) && $error !== ''): ?><div class="notice notice-error" role="alert"><?= $e($error) ?></div><?php endif; ?>
        <header class="pickup-crm-heading">
            <div><p class="eyebrow eyebrow-red">Customer relationships</p><h1>Customer CRM</h1><p>Turn shipment senders into managed customer profiles, maintain contact data, and schedule follow-up.</p></div>
            <a class="button pickup-crm-add" href="<?= $e($basePath) ?>/dhl/pickupsheet/customers/new">Add customer</a>
        </header>

        <section class="pickup-crm-kpis" aria-label="Customer CRM summary">
            <article><span>Customers</span><strong><?= $e(number_format((int) ($summary['customerCount'] ?? 0))) ?></strong><small>Shipment and manual profiles</small></article>
            <article><span>Active</span><strong><?= $e(number_format((int) ($summary['activeCount'] ?? 0))) ?></strong><small>Current relationships</small></article>
            <article><span>Needs attention</span><strong><?= $e(number_format((int) ($summary['attentionCount'] ?? 0))) ?></strong><small>Flagged accounts</small></article>
            <article><span>Follow-ups due</span><strong><?= $e(number_format((int) ($summary['followUpsDue'] ?? 0))) ?></strong><small>Due today or overdue</small></article>
        </section>

        <form class="pickup-crm-filter" method="get" action="<?= $e($basePath) ?>/dhl/pickupsheet/customers">
            <label><span>Search customers</span><input type="search" name="q" value="<?= $e($search ?? '') ?>" maxlength="100" placeholder="Name, contact, email, phone, or city"></label>
            <label><span>Relationship status</span><select name="status"><option value="">All statuses</option><?php foreach (['lead' => 'Lead', 'active' => 'Active', 'attention' => 'Needs attention', 'inactive' => 'Inactive'] as $value => $label): ?><option value="<?= $e($value) ?>" <?= ($statusFilter ?? '') === $value ? 'selected' : '' ?>><?= $e($label) ?></option><?php endforeach; ?></select></label>
            <button class="button" type="submit">Filter</button>
            <?php if (($search ?? '') !== '' || ($statusFilter ?? '') !== ''): ?><a href="<?= $e($basePath) ?>/dhl/pickupsheet/customers">Clear</a><?php endif; ?>
        </form>

        <section class="pickup-crm-directory" aria-labelledby="customer-directory-title">
            <div class="pickup-card-heading"><div><span>Customer data</span><h2 id="customer-directory-title">Customer directory</h2></div><small><?= $e(number_format((int) ($customers['totalRecords'] ?? 0))) ?> profiles</small></div>
            <div class="pickup-crm-table-wrap">
                <table>
                    <thead><tr><th>Customer</th><th>Status</th><th>Contact</th><th>Shipment value</th><th>Rewards</th><th>Last shipment</th><th>Follow-up</th><th>Manage</th></tr></thead>
                    <tbody>
                    <?php if ($items === []): ?><tr><td colspan="8">No customers match the current filters.</td></tr><?php endif; ?>
                    <?php foreach ($items as $customer): ?>
                        <tr>
                            <td><strong><?= $e($customer->displayName) ?></strong><small><?= $customer->source === 'shipment' ? 'Created from shipment data' : 'Manual profile' ?></small></td>
                            <td><span class="pickup-customer-status is-<?= $e($customer->status) ?>"><?= $e($customer->status === 'attention' ? 'Needs attention' : ucfirst($customer->status)) ?></span></td>
                            <td><strong><?= $e($customer->contactName !== '' ? $customer->contactName : 'Not assigned') ?></strong><small><?= $e($customer->email !== '' ? $customer->email : ($customer->phone !== '' ? $customer->phone : 'No contact details')) ?></small></td>
                            <td><strong><?= $e(number_format($customer->totalCashXaf)) ?> XAF</strong><small><?= $e(number_format($customer->shipmentCount)) ?> <?= $customer->shipmentCount === 1 ? 'shipment' : 'shipments' ?></small></td>
                            <td><strong><?= $e(number_format($customer->rewardBalance())) ?> <?= $customer->rewardBalance() === 1 ? 'point' : 'points' ?></strong><small><?= $e(number_format($customer->shipmentRewardPoints())) ?> earned from shipments</small></td>
                            <td><?= $e($customer->lastShipmentOn ?? 'No shipments') ?></td>
                            <td><?php if ($customer->nextFollowUpOn !== null): ?><strong class="<?= $customer->followUpDue() ? 'pickup-follow-up-due' : '' ?>"><?= $e($customer->nextFollowUpOn) ?></strong><small><?= $customer->followUpDue() ? 'Due or overdue' : 'Scheduled' ?></small><?php else: ?>Not scheduled<?php endif; ?></td>
                            <td><a href="<?= $e($basePath) ?>/dhl/pickupsheet/customers/edit?customer=<?= $e(rawurlencode($customer->customerKey)) ?>">Open profile</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($totalPages > 1): ?>
                <nav class="pickup-pagination" aria-label="Customer pages">
                    <?php if ($page > 1): ?><a href="?<?= $e($queryForPage($page - 1)) ?>">Previous</a><?php else: ?><span class="pickup-pagination-disabled" aria-disabled="true">Previous</span><?php endif; ?>
                    <strong>Page <?= $e($page) ?> of <?= $e($totalPages) ?></strong>
                    <?php if ($page < $totalPages): ?><a href="?<?= $e($queryForPage($page + 1)) ?>">Next</a><?php else: ?><span class="pickup-pagination-disabled" aria-disabled="true">Next</span><?php endif; ?>
                </nav>
            <?php endif; ?>
        </section>
    </div>
</section>
