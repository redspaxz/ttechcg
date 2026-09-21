<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$customers = is_array($customers ?? null) ? $customers : [];
$items = is_array($customers['items'] ?? null) ? $customers['items'] : [];
$queryForPage = static function (int $targetPage) use ($search, $statusFilter): string {
    return http_build_query(array_filter([
        'q' => $search ?? '',
        'status' => $statusFilter ?? '',
        'page' => $targetPage,
    ], static fn (mixed $value): bool => $value !== ''));
};
?>
<div class="pickup-card-heading"><div><span>Customer data</span><h2 id="customer-directory-title">Customer directory</h2></div><small><?= $e(number_format((int) ($customers['totalRecords'] ?? 0))) ?> profiles</small></div>
<div class="pickup-crm-table-wrap">
    <table>
        <thead><tr><th>Customer</th><th>Status</th><th>Contact</th><th>Shipment value</th><th>Rewards</th><th>Last shipment</th><th>Follow-up</th><th>Manage</th></tr></thead>
        <tbody>
        <?php if ($items === []): ?><tr><td colspan="8">No customers match the current filters.</td></tr><?php endif; ?>
        <?php foreach ($items as $customer): ?>
            <tr>
                <td colspan="8">
                    <details class="pickup-table-row-details">
                        <summary>
                            <span data-label="Customer"><strong><?= $e($customer->displayName) ?></strong><small><?= $customer->source === 'shipment' ? 'Created from shipment data' : 'Manual profile' ?></small></span>
                            <span data-label="Status"><span class="pickup-customer-status is-<?= $e($customer->status) ?>"><?= $e($customer->status === 'attention' ? 'Needs attention' : ucfirst($customer->status)) ?></span></span>
                            <span data-label="Contact"><strong><?= $e($customer->contactName !== '' ? $customer->contactName : 'Not assigned') ?></strong><small><?= $e($customer->email !== '' ? $customer->email : ($customer->phone !== '' ? $customer->phone : 'No contact details')) ?></small></span>
                            <span data-label="Shipment value"><strong><?= $e(number_format($customer->totalCashXaf)) ?> XAF</strong><small><?= $e(number_format($customer->shipmentCount)) ?> <?= $customer->shipmentCount === 1 ? 'shipment' : 'shipments' ?></small></span>
                            <span data-label="Rewards"><strong><?= $e(number_format($customer->rewardBalance())) ?> <?= $customer->rewardBalance() === 1 ? 'point' : 'points' ?></strong><small><?= $e(number_format($customer->cargoRewardPoints())) ?> earned</small></span>
                            <span data-label="Follow-up"><?php if ($customer->nextFollowUpOn !== null): ?><strong class="<?= $customer->followUpDue() ? 'pickup-follow-up-due' : '' ?>"><?= $e($customer->nextFollowUpOn) ?></strong><small><?= $customer->followUpDue() ? 'Due or overdue' : 'Scheduled' ?></small><?php else: ?><strong>Not scheduled</strong><small>No follow-up</small><?php endif; ?></span>
                            <i aria-hidden="true">+</i>
                        </summary>
                        <div class="pickup-table-row-detail">
                            <div class="pickup-table-row-grid">
                                <div><small>Customer</small><span><?= $e($customer->displayName) ?></span></div>
                                <div><small>Status</small><span class="pickup-customer-status is-<?= $e($customer->status) ?>"><?= $e($customer->status === 'attention' ? 'Needs attention' : ucfirst($customer->status)) ?></span></div>
                                <div><small>Contact</small><span><?= $e($customer->contactName !== '' ? $customer->contactName : 'Not assigned') ?></span></div>
                                <div><small>Email / phone</small><span><?= $e($customer->email !== '' ? $customer->email : ($customer->phone !== '' ? $customer->phone : 'No contact details')) ?></span></div>
                                <div><small>Shipment value</small><span><?= $e(number_format($customer->totalCashXaf)) ?> XAF</span></div>
                                <div><small>Rewards</small><span><?= $e(number_format($customer->rewardBalance())) ?> <?= $customer->rewardBalance() === 1 ? 'point' : 'points' ?></span></div>
                                <div><small>Last shipment</small><span><?= $e($customer->lastShipmentOn ?? 'No shipments') ?></span></div>
                                <div><small>Follow-up</small><span><?= $customer->nextFollowUpOn !== null ? $e($customer->nextFollowUpOn) : 'Not scheduled' ?></span></div>
                                <div><small>Manage</small><span><a href="<?= $e($basePath) ?>/dhl/pickupsheet/customers/edit?customer=<?= $e(rawurlencode($customer->customerKey)) ?>">Open profile</a></span></div>
                            </div>
                        </div>
                    </details>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php
$pagerData = $customers;
$pagerRecordLabel = ((int) ($customers['totalRecords'] ?? 0)) === 1 ? 'profile' : 'profiles';
$pagerAriaLabel = 'Customer pages';
$pagerUrl = static fn (int $targetPage): string => ($basePath ?? '') . '/dhl/pickupsheet/customers?' . $queryForPage($targetPage);
require __DIR__ . '/_pagination.php';
?>
