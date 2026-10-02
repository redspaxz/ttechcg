<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$customers = is_array($customers ?? null) ? $customers : [];
$items = is_array($customers['items'] ?? null) ? $customers['items'] : [];
$search = (string) ($search ?? '');
$statusFilter = (string) ($statusFilter ?? '');
$followUpFilter = (string) ($followUpFilter ?? '');
$ownerFilter = (string) ($ownerFilter ?? '');
$sortOrder = (string) ($sortOrder ?? '');
$queryForPage = static function (int $targetPage) use ($search, $statusFilter, $followUpFilter, $ownerFilter, $sortOrder): string {
    return http_build_query(array_filter([
        'q' => $search ?? '',
        'status' => $statusFilter ?? '',
        'follow_up' => $followUpFilter ?? '',
        'owner' => $ownerFilter ?? '',
        'sort' => $sortOrder ?? '',
        'page' => $targetPage,
    ], static fn (mixed $value): bool => $value !== ''));
};
?>
<div class="pickup-card-heading"><div><span>Customer data</span><h2 id="customer-directory-title">Customer directory</h2></div><small><?= $e(number_format((int) ($customers['totalRecords'] ?? 0))) ?> profiles</small></div>
<div class="pickup-crm-table-wrap pickup-crm-directory-table-wrap">
    <table class="pickup-crm-directory-table">
        <colgroup><col class="col-id"><col class="col-customer"><col class="col-status"><col class="col-contact"><col class="col-shipments"><col class="col-rewards"><col class="col-follow-up"><col class="col-open"></colgroup>
        <thead><tr><th scope="col">Customer ID</th><th scope="col">Customer</th><th scope="col">Status</th><th scope="col">Contact</th><th scope="col">Shipments</th><th scope="col">Rewards</th><th scope="col">Follow-up</th><th scope="col"><span class="sr-only">Profile</span></th></tr></thead>
        <tbody>
        <?php if ($items === []): ?><tr><td colspan="8">No customers match the current filters.</td></tr><?php endif; ?>
        <?php foreach ($items as $customer): ?>
            <?php $contactDetail = $customer->email !== '' ? $customer->email : ($customer->phone !== '' ? $customer->phone : 'No contact details'); ?>
            <tr>
                <td class="pickup-crm-id-cell" data-label="Customer ID"><span class="pickup-customer-id"><?= $e($customer->reference()) ?></span></td>
                <td data-label="Customer"><strong><?= $e($customer->displayName) ?><?php if ($customer->isNew()): ?> <span class="pickup-customer-new" title="Customer for <?= $e($customer->ageInDays()) ?> <?= $customer->ageInDays() === 1 ? 'day' : 'days' ?>">New</span><?php endif; ?></strong><small><?= $customer->assignedName !== '' ? 'Owner: ' . $e($customer->assignedName) : ($customer->source === 'shipment' ? 'From shipment data' : 'Manual profile') ?></small></td>
                <td data-label="Status"><span class="pickup-customer-status is-<?= $e($customer->status) ?>"><?= $e($customer->status === 'attention' ? 'Needs attention' : ucfirst($customer->status)) ?></span></td>
                <td data-label="Contact"><strong><?= $e($customer->contactName !== '' ? $customer->contactName : 'Not assigned') ?></strong><small title="<?= $e($contactDetail) ?>"><?= $e($contactDetail) ?></small></td>
                <td data-label="Shipments"><strong><?= $e(number_format($customer->totalCashXaf)) ?> XAF</strong><small><?= $e(number_format($customer->shipmentCount)) ?> <?= $customer->shipmentCount === 1 ? 'shipment' : 'shipments' ?><?= $customer->lastShipmentOn !== null ? ' &middot; last ' . $e($customer->lastShipmentOn) : '' ?></small></td>
                <td data-label="Rewards"><strong><?= $e(number_format($customer->rewardBalance())) ?> <?= $customer->rewardBalance() === 1 ? 'point' : 'points' ?></strong><small><?= $e($customer->loyaltyTier()) ?></small></td>
                <td data-label="Follow-up"><?php if ($customer->nextFollowUpOn !== null): ?><strong class="<?= $customer->followUpDue() ? 'pickup-follow-up-due' : '' ?>"><?= $e($customer->nextFollowUpOn) ?></strong><small><?= $customer->followUpDue() ? 'Due or overdue' : 'Scheduled' ?></small><?php else: ?><span class="pickup-crm-muted">Not scheduled</span><?php endif; ?></td>
                <td class="pickup-crm-open-cell"><a href="<?= $e($basePath) ?>/dhl/pickupsheet/customers/edit?customer=<?= $e(rawurlencode($customer->customerKey)) ?>" aria-label="Open profile for <?= $e($customer->displayName) ?>">Open</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php
$pagerData = $customers;
$pagerRecordLabel = ((int) ($customers['totalRecords'] ?? 0)) === 1 ? 'profile' : 'profiles';
$pagerAriaLabel = 'Customer pages';
$pagerSizeParam = 'per_page';
$pagerUrl = static fn (int $targetPage): string => ($basePath ?? '') . '/dhl/pickupsheet/customers?' . $queryForPage($targetPage);
require __DIR__ . '/_pagination.php';
?>
