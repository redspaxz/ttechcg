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
                <td><strong><?= $e($customer->displayName) ?></strong><small><?= $customer->source === 'shipment' ? 'Created from shipment data' : 'Manual profile' ?></small></td>
                <td><span class="pickup-customer-status is-<?= $e($customer->status) ?>"><?= $e($customer->status === 'attention' ? 'Needs attention' : ucfirst($customer->status)) ?></span></td>
                <td><strong><?= $e($customer->contactName !== '' ? $customer->contactName : 'Not assigned') ?></strong><small><?= $e($customer->email !== '' ? $customer->email : ($customer->phone !== '' ? $customer->phone : 'No contact details')) ?></small></td>
                <td><strong><?= $e(number_format($customer->totalCashXaf)) ?> XAF</strong><small><?= $e(number_format($customer->shipmentCount)) ?> <?= $customer->shipmentCount === 1 ? 'shipment' : 'shipments' ?></small></td>
                <td><strong><?= $e(number_format($customer->rewardBalance())) ?> <?= $customer->rewardBalance() === 1 ? 'point' : 'points' ?></strong><small><?= $e(number_format($customer->cargoRewardPoints())) ?> earned from cargo weight</small></td>
                <td><?= $e($customer->lastShipmentOn ?? 'No shipments') ?></td>
                <td><?php if ($customer->nextFollowUpOn !== null): ?><strong class="<?= $customer->followUpDue() ? 'pickup-follow-up-due' : '' ?>"><?= $e($customer->nextFollowUpOn) ?></strong><small><?= $customer->followUpDue() ? 'Due or overdue' : 'Scheduled' ?></small><?php else: ?>Not scheduled<?php endif; ?></td>
                <td><a href="<?= $e($basePath) ?>/dhl/pickupsheet/customers/edit?customer=<?= $e(rawurlencode($customer->customerKey)) ?>">Open profile</a></td>
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
