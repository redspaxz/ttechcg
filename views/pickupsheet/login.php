<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<section class="pickup-login-shell">
    <div class="pickup-login-panel">
        <a class="pickup-login-brand" href="<?= $e($basePath . '/') ?>">T&amp;Tech <span>Pickupsheet</span></a>
        <div class="pickup-login-copy">
            <p class="eyebrow eyebrow-red">Secure operations</p>
            <h1>Welcome back.</h1>
            <p>Sign in to create shipment records, review activity, or administer the Pickupsheet workspace.</p>
        </div>

        <?php if (is_string($error ?? null) && $error !== ''): ?>
            <div class="notice notice-error" role="alert"><?= $e($error) ?></div>
        <?php endif; ?>
        <?php if (is_string($flash ?? null) && $flash !== ''): ?>
            <div class="notice notice-success" role="status"><?= $e($flash) ?></div>
        <?php endif; ?>

        <?php if (($jumpCloudEnabled ?? false) === true): ?>
            <div class="pickup-sso-login">
                <a class="button button-dark pickup-jumpcloud-button" href="<?= $e($basePath) ?>/dhl/pickupsheet/auth/jumpcloud">Try JumpCloud again <span aria-hidden="true">&#8594;</span></a>
                <small>No separate Pickupsheet password is required. Your name and role come from JumpCloud.</small>
            </div>
        <?php else: ?>
            <form class="pickup-login-form" method="post" action="<?= $e($basePath) ?>/dhl/pickupsheet/login">
                <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                <label><span>Email or username</span><input name="username" value="<?= $e($username) ?>" maxlength="100" autocomplete="username" autocapitalize="none" spellcheck="false" autofocus required></label>
                <label><span>Password</span><input type="password" name="password" maxlength="128" autocomplete="current-password" required></label>
                <button class="button button-red" type="submit">Sign in <span aria-hidden="true">&#8594;</span></button>
            </form>
        <?php endif; ?>
        <p class="pickup-login-security">Protected session · 60-minute inactivity timeout · group-based access logged</p>
    </div>
    <aside class="pickup-login-aside" aria-hidden="true">
        <strong>Shipments.<br>Cash.<br>Control.</strong>
        <span>Role-based logistics operations</span>
    </aside>
</section>
