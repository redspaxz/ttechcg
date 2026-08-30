<?php

declare(strict_types=1);

$pagerEscape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$pagerData = is_array($pagerData ?? null) ? $pagerData : [];
$pagerPage = max(1, (int) ($pagerData['page'] ?? 1));
$pagerTotalPages = max(1, (int) ($pagerData['totalPages'] ?? 1));
$pagerTotalRecords = max(0, (int) ($pagerData['totalRecords'] ?? 0));
$pagerRecordLabel = is_string($pagerRecordLabel ?? null) && $pagerRecordLabel !== '' ? $pagerRecordLabel : 'records';
$pagerAriaLabel = is_string($pagerAriaLabel ?? null) && $pagerAriaLabel !== '' ? $pagerAriaLabel : 'Pages';
$pagerUrl = is_callable($pagerUrl ?? null) ? $pagerUrl : static fn (int $page): string => '?page=' . $page;
?>
<nav class="pickup-pagination" aria-label="<?= $pagerEscape($pagerAriaLabel) ?>">
    <?php if ($pagerPage > 1): ?>
        <a href="<?= $pagerEscape($pagerUrl($pagerPage - 1)) ?>" data-ajax-page="<?= $pagerEscape($pagerPage - 1) ?>" rel="prev">Previous</a>
    <?php else: ?>
        <span class="pickup-pagination-disabled" aria-disabled="true">Previous</span>
    <?php endif; ?>
    <span class="pickup-pagination-status" data-ajax-current-page="<?= $pagerEscape($pagerPage) ?>">Page <?= $pagerEscape($pagerPage) ?> of <?= $pagerEscape($pagerTotalPages) ?> · <?= $pagerEscape($pagerTotalRecords) ?> <?= $pagerEscape($pagerRecordLabel) ?></span>
    <?php if ($pagerPage < $pagerTotalPages): ?>
        <a href="<?= $pagerEscape($pagerUrl($pagerPage + 1)) ?>" data-ajax-page="<?= $pagerEscape($pagerPage + 1) ?>" rel="next">Next</a>
    <?php else: ?>
        <span class="pickup-pagination-disabled" aria-disabled="true">Next</span>
    <?php endif; ?>
</nav>
