<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<section class="pickup-view-workspace">
    <div class="container pickup-workspace-header">
        <strong class="pickup-wordmark">Pickupsheet</strong>
        <div class="pickup-header-links">
            <span class="pickup-session-user" title="<?= $e($recordsUsername ?? '') ?>"><?= $e($recordsFullName ?? $recordsUsername ?? '') ?> · <?= $e($recordsRole ?? '') ?></span>
            <?php if ((bool) ($canManage ?? false)): ?>
                <a class="pickup-back" href="<?= $e($basePath) ?>/dhl/pickupsheet/dashboard">Dashboard <span aria-hidden="true">&#8599;</span></a>
                <a class="pickup-back" href="<?= $e($basePath) ?>/dhl/pickupsheet/submissions/users">Manage access <span aria-hidden="true">&#8599;</span></a>
            <?php endif; ?>
            <?php if ((bool) ($canCrmView ?? false)): ?><a class="pickup-back" href="<?= $e($basePath) ?>/dhl/pickupsheet/customers">Customer CRM <span aria-hidden="true">&#8599;</span></a><?php endif; ?>
            <a class="pickup-back" href="<?= $e($basePath) ?>/dhl/pickupsheet/">New pickup sheet <span aria-hidden="true">&#8599;</span></a>
            <form method="post" action="<?= $e($basePath) ?>/dhl/pickupsheet/logout"><input type="hidden" name="_token" value="<?= $e($csrfToken) ?>"><button class="pickup-link-button" type="submit">Sign out</button></form>
        </div>
    </div>

    <div class="container pickup-submissions-shell">
        <?php if (is_string($flash ?? null) && $flash !== ''): ?><div class="notice notice-success" role="status"><?= $e($flash) ?></div><?php endif; ?>
        <header class="pickup-submissions-heading">
            <div>
                <p class="eyebrow eyebrow-red">Pickup records</p>
                <h1>Submitted sheets</h1>
                <p>Signed in as <?= $e($recordsRole ?? 'viewer') ?>. Records are displayed 10 sheets per page. Expand a reference to review its shipment table.</p>
            </div>
        </header>

        <div
            class="pickup-records-frame"
            data-pickup-records
            data-ajax-pager
            data-ajax-pager-id="submitted-sheets"
            data-page-endpoint="<?= $e($basePath) ?>/dhl/pickupsheet/submissions/page"
            data-page-param="page"
            data-current-page="<?= $e($pagination['page'] ?? 1) ?>"
            data-error-message="Pickup records could not be loaded. Please try again."
        >
            <div class="pickup-records-loading ajax-pager-loading" data-pickup-records-spinner data-ajax-pager-spinner role="status" hidden>
                <span class="pickup-loading-spinner" aria-hidden="true"></span>
                <span>Loading pickup records...</span>
            </div>
            <div data-pickup-records-content data-ajax-pager-content aria-live="polite" aria-busy="false">
                <?php require __DIR__ . '/_submission-records.php'; ?>
            </div>
        </div>
    </div>
</section>
