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
$pagerSizeParam = is_string($pagerSizeParam ?? null) && $pagerSizeParam !== '' ? $pagerSizeParam : 'per_page';
$pagerSize = max(1, (int) ($pagerData['perPage'] ?? 10));
$pagerUrlWithSize = static function (int $page) use ($pagerUrl, $pagerSizeParam, $pagerSize): string {
    $url = $pagerUrl($page);
    $separator = str_contains($url, '?') ? '&' : '?';
    return $url . $separator . rawurlencode($pagerSizeParam) . '=' . $pagerSize;
};
?>
<nav class="pickup-pagination" aria-label="<?= $pagerEscape($pagerAriaLabel) ?>">
    <?php if ($pagerPage > 1): ?>
        <a href="<?= $pagerEscape($pagerUrlWithSize($pagerPage - 1)) ?>" data-ajax-page="<?= $pagerEscape($pagerPage - 1) ?>" rel="prev">Previous</a>
    <?php else: ?>
        <span class="pickup-pagination-disabled" aria-disabled="true">Previous</span>
    <?php endif; ?>
    <span class="pickup-pagination-status" data-ajax-current-page="<?= $pagerEscape($pagerPage) ?>">Page <?= $pagerEscape($pagerPage) ?> of <?= $pagerEscape($pagerTotalPages) ?> · <?= $pagerEscape($pagerTotalRecords) ?> <?= $pagerEscape($pagerRecordLabel) ?></span>
    <label class="pickup-pagination-size"><span>Rows</span><select data-ajax-page-size name="<?= $pagerEscape($pagerSizeParam) ?>" aria-label="Rows per page"><option value="10" <?= $pagerSize === 10 ? 'selected' : '' ?>>10</option><option value="25" <?= $pagerSize === 25 ? 'selected' : '' ?>>25</option><option value="50" <?= $pagerSize === 50 ? 'selected' : '' ?>>50</option></select></label>
    <?php if ($pagerPage < $pagerTotalPages): ?>
        <a href="<?= $pagerEscape($pagerUrlWithSize($pagerPage + 1)) ?>" data-ajax-page="<?= $pagerEscape($pagerPage + 1) ?>" rel="next">Next</a>
    <?php else: ?>
        <span class="pickup-pagination-disabled" aria-disabled="true">Next</span>
    <?php endif; ?>
</nav>
