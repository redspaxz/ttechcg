<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<section class="pickup-view-workspace">
    <div class="container pickup-workspace-header">
        <strong class="pickup-wordmark">Pickupsheet</strong>
        <a class="pickup-back" href="<?= $e($basePath) ?>/dhl/pickupsheet/">New pickup sheet <span aria-hidden="true">&#8599;</span></a>
    </div>

    <div class="container pickup-submissions-shell">
        <header class="pickup-submissions-heading">
            <div>
                <p class="eyebrow eyebrow-red">Pickup records</p>
                <h1>Submitted sheets</h1>
                <p>Records are displayed 10 sheets per page. Expand a reference to review its shipment table.</p>
            </div>
        </header>

        <div
            class="pickup-records-frame"
            data-pickup-records
            data-page-endpoint="<?= $e($basePath) ?>/dhl/pickupsheet/submissions/page"
        >
            <div class="pickup-records-loading" data-pickup-records-spinner role="status" hidden>
                <span class="pickup-loading-spinner" aria-hidden="true"></span>
                <span>Loading pickup records...</span>
            </div>
            <div data-pickup-records-content aria-live="polite" aria-busy="false">
                <?php require __DIR__ . '/_submission-records.php'; ?>
            </div>
        </div>
    </div>
</section>
