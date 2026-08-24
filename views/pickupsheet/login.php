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
            <p>Sign in to create, review, print, and export cash shipment pickup sheets.</p>

            <?php if ($errors !== []): ?>
                <div class="notice notice-error" role="alert"><?php foreach ($errors as $error): ?><span><?= $e($error) ?></span><?php endforeach; ?></div>
            <?php endif; ?>

            <?php if (!$loginConfigured): ?>
                <div class="notice notice-error" role="alert">
                    Operator access is disabled. Add <code>PICKUPSHEET_LOGIN_USERNAME</code>, <code>PICKUPSHEET_LOGIN_NAME</code>, and a <code>PICKUPSHEET_LOGIN_PASSWORD</code> of at least 16 characters to the server-managed <code>.env</code>.
                </div>
            <?php else: ?>
                <form class="pickup-access-form" method="post" action="<?= $e($basePath) ?>/pickupsheet/login">
                    <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                    <label>
                        <span>Username</span>
                        <input name="username" minlength="3" maxlength="60" required autocomplete="username" autofocus>
                    </label>
                    <label>
                        <span>Password</span>
                        <input type="password" name="password" minlength="16" maxlength="200" required autocomplete="current-password">
                    </label>
                    <button class="button button-red" type="submit">Sign in <span aria-hidden="true">→</span></button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</section>
