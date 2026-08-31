<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<section class="pickup-login-shell pickup-mfa-shell">
    <div class="pickup-login-panel pickup-mfa-panel">
        <a class="pickup-login-brand" href="<?= $e($basePath . '/') ?>">T&amp;Tech <span>Pickupsheet</span></a>
        <div class="pickup-login-copy">
            <p class="eyebrow eyebrow-red">Local account security</p>
            <h1><?= ($enrolled ?? false) ? 'Verify it is you.' : 'Protect your account.' ?></h1>
            <p><?= ($enrolled ?? false) ? 'Enter the current code from your authenticator app or use one of your recovery codes.' : 'Add this Pickupsheet account to an authenticator app, then enter its current six-digit code.' ?></p>
        </div>

        <?php if (is_string($error ?? null) && $error !== ''): ?>
            <div class="notice notice-error" role="alert"><?= $e($error) ?></div>
        <?php endif; ?>

        <?php if (($enrolled ?? false) === false && is_array($setup ?? null)): ?>
            <div class="pickup-mfa-setup">
                <span>Authenticator setup key</span>
                <strong data-mfa-secret><?= $e($setup['formattedSecret'] ?? '') ?></strong>
                <p>In Google Authenticator, Microsoft Authenticator, 1Password, or another TOTP app, add an account manually and enter this key.</p>
                <a class="button button-dark" href="<?= $e($setup['otpauthUri'] ?? '') ?>">Open authenticator app</a>
            </div>
        <?php endif; ?>

        <form class="pickup-login-form pickup-mfa-form" method="post" action="<?= $e($basePath) ?>/dhl/pickupsheet/login/2fa">
            <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
            <label><span><?= ($enrolled ?? false) ? 'Authenticator or recovery code' : 'Six-digit authenticator code' ?></span><input name="code" inputmode="<?= ($enrolled ?? false) ? 'text' : 'numeric' ?>" autocomplete="one-time-code" maxlength="17" pattern="<?= ($enrolled ?? false) ? '[A-Za-z2-7 0-9-]{6,17}' : '[0-9]{6}' ?>" autofocus required></label>
            <button class="button button-red" type="submit"><?= ($enrolled ?? false) ? 'Verify and continue' : 'Enable 2FA' ?> <span aria-hidden="true">&#8594;</span></button>
        </form>
        <a class="pickup-mfa-restart" href="<?= $e($basePath) ?>/dhl/pickupsheet/login">Cancel and sign in again</a>
        <p class="pickup-login-security">Password accepted &middot; verification expires in five minutes &middot; attempts are rate-limited</p>
    </div>
    <aside class="pickup-login-aside pickup-mfa-aside" aria-hidden="true">
        <strong>Two steps.<br>One secure<br>workspace.</strong>
        <span>Authenticator-protected local access</span>
    </aside>
</section>
