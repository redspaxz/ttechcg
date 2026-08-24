<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$errors = is_array($errors ?? null) ? $errors : [];
$loginConfigured = (bool) ($loginConfigured ?? false);
$csrfToken = (string) ($csrfToken ?? '');
?>
<section class="pickup-view-workspace">
    <div class="container pickup-workspace-header">
        <div class="product-brand product-brand-large">
            <img src="<?= $e($assetBase . '/dhl-logo.svg') ?>" alt="DHL">
            <span aria-hidden="true">—</span><strong>pickupsheet</strong>
        </div>
        <a class="pickup-back" href="<?= $e($basePath . '/') ?>">T&amp;Tech home <span aria-hidden="true">↗</span></a>
    </div>

    <div class="container pickup-submissions-shell">
        <div class="pickup-access-card">
            <p class="eyebrow eyebrow-red">Protected workspace</p>
            <h1>Operator login</h1>
            <p>Use your T&amp;Tech JumpCloud account to create, review, print, and export cash shipment pickup sheets.</p>

            <?php if ($errors !== []): ?>
                <div class="notice notice-error" role="alert"><?php foreach ($errors as $error): ?><span><?= $e($error) ?></span><?php endforeach; ?></div>
            <?php endif; ?>

            <?php if (!$loginConfigured): ?>
                <div class="notice notice-error" role="alert">
                    JumpCloud access is disabled until the OIDC client ID, client secret, issuer, and callback URI are configured in the server-managed <code>.env</code>.
                </div>
            <?php else: ?>
                <form class="pickup-access-form" method="post" action="<?= $e($basePath) ?>/pickupsheet/login">
                    <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                    <button class="button button-red pickup-idp-button" type="submit">Continue with JumpCloud <span aria-hidden="true">→</span></button>
                    <p class="pickup-idp-note">Authentication and access policy are managed by JumpCloud. This site never receives your JumpCloud password.</p>
                </form>
            <?php endif; ?>
        </div>
    </div>
</section>
