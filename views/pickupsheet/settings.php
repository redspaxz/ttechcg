<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$errors = is_array($errors ?? null) ? $errors : [];
$localIdentity = ($localIdentity ?? false) === true;
$mfaAvailable = ($mfaAvailable ?? false) === true;
$mfaEnrolled = ($mfaEnrolled ?? false) === true;
$mfaReplacing = ($mfaReplacing ?? false) === true;
?>
<section class="pickup-view-workspace pickup-settings-workspace">
    <div class="container pickup-workspace-header">
        <strong class="pickup-wordmark">User settings</strong>
        <div class="pickup-header-links">
            <span class="pickup-session-user" title="<?= $e($principal->username ?? '') ?>"><?= $e($principal->fullName() ?? '') ?> &middot; <?= $e($principal->role ?? '') ?></span>
            <?php if (($principal->role ?? '') === 'admin'): ?><a class="pickup-back" href="<?= $e($basePath) ?>/dhl/pickupsheet/dashboard">Dashboard <span aria-hidden="true">&#8599;</span></a><?php endif; ?>
            <a class="pickup-back" href="<?= $e($basePath) ?>/dhl/pickupsheet/submissions">Submitted sheets <span aria-hidden="true">&#8599;</span></a>
            <form method="post" action="<?= $e($basePath) ?>/dhl/pickupsheet/logout"><input type="hidden" name="_token" value="<?= $e($csrfToken) ?>"><button class="pickup-link-button" type="submit">Sign out</button></form>
        </div>
    </div>

    <div class="container pickup-settings-shell">
        <?php if (is_string($flash ?? null) && $flash !== ''): ?><div class="notice notice-success" role="status"><?= $e($flash) ?></div><?php endif; ?>
        <?php if ($errors !== []): ?><div class="notice notice-error" role="alert"><?php foreach ($errors as $error): ?><span><?= $e($error) ?></span><?php endforeach; ?></div><?php endif; ?>

        <header class="pickup-settings-heading">
            <p class="eyebrow eyebrow-red">Signed-in account</p>
            <h1>Your settings.</h1>
            <p>Review your Pickupsheet identity and manage the second factor protecting local access.</p>
        </header>

        <div class="pickup-settings-grid">
            <section class="pickup-settings-card" aria-labelledby="account-settings-title">
                <div class="pickup-card-heading"><div><span>Profile</span><h2 id="account-settings-title">Account details</h2></div><small><?= $e($principal->role ?? '') ?></small></div>
                <dl class="pickup-settings-details">
                    <div><dt>Name</dt><dd><?= $e($principal->fullName() ?? '') ?></dd></div>
                    <div><dt>Login ID</dt><dd><?= $e($principal->username ?? '') ?></dd></div>
                    <div><dt>Identity provider</dt><dd><?= $e($identityProviderLabel ?? '') ?></dd></div>
                    <div><dt>Access role</dt><dd><?= $e(ucfirst((string) ($principal->role ?? ''))) ?></dd></div>
                </dl>
            </section>

            <section class="pickup-settings-card pickup-settings-security" aria-labelledby="two-factor-settings-title">
                <div class="pickup-card-heading">
                    <div><span>Account security</span><h2 id="two-factor-settings-title">Two-factor authentication</h2></div>
                    <span class="pickup-settings-status" data-enabled="<?= $mfaEnrolled ? 'true' : 'false' ?>"><?= $mfaReplacing ? 'Replacement pending' : ($mfaEnrolled ? 'Enabled' : ($localIdentity ? 'Setup required' : 'Externally managed')) ?></span>
                </div>

                <?php if (!$localIdentity): ?>
                    <div class="pickup-settings-message"><strong>Managed by <?= $e($identityProviderLabel ?? 'your identity provider') ?></strong><p>Your password and multi-factor authentication policy are controlled by the provider used to sign in. No separate local authenticator is stored for this account.</p></div>
                <?php elseif (!$mfaAvailable): ?>
                    <div class="pickup-settings-message"><strong>Local 2FA is unavailable</strong><p><?= ($mfaEnabled ?? false) ? 'The administrator must complete the encryption-key, OpenSSL, and database configuration.' : 'Local 2FA is currently disabled by the administrator.' ?></p></div>
                <?php elseif (is_array($setup ?? null)): ?>
                    <div class="pickup-settings-message"><strong><?= $mfaReplacing ? 'Complete authenticator replacement' : 'Complete authenticator setup' ?></strong><p>Add the setup key to an authenticator app, then confirm the new six-digit code and your current password.<?= $mfaReplacing ? ' Your current authenticator remains active until this succeeds.' : '' ?></p></div>
                    <div class="pickup-mfa-setup pickup-settings-mfa-setup">
                        <span>Authenticator setup key</span>
                        <strong data-mfa-secret><?= $e($setup['formattedSecret'] ?? '') ?></strong>
                        <p>Use Google Authenticator, Microsoft Authenticator, 1Password, or another standards-compatible TOTP app.</p>
                        <a class="button button-dark" href="<?= $e($setup['otpauthUri'] ?? '') ?>">Open authenticator app</a>
                    </div>
                    <form class="pickup-settings-form" method="post" action="<?= $e($basePath) ?>/dhl/pickupsheet/settings/2fa/enroll">
                        <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                        <label><span>Current password</span><input type="password" name="current_password" minlength="12" maxlength="128" autocomplete="current-password" required></label>
                        <label><span>New six-digit code</span><input name="code" maxlength="6" autocomplete="one-time-code" inputmode="numeric" pattern="[0-9]{6}" required></label>
                        <button class="button button-red" type="submit"><?= $mfaReplacing ? 'Confirm replacement' : 'Enable 2FA' ?></button>
                    </form>
                <?php elseif ($mfaEnrolled): ?>
                    <div class="pickup-settings-message"><strong>Your local account is protected</strong><p>Local sign-in requires your password and a current authenticator or one-time recovery code. To move to a new authenticator, verify both factors below.</p></div>
                    <form class="pickup-settings-form" method="post" action="<?= $e($basePath) ?>/dhl/pickupsheet/settings/2fa/reset" data-self-mfa-reset data-account-name="<?= $e($principal->fullName() ?? '') ?>">
                        <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                        <input type="hidden" name="confirm_reset" value="0" data-confirm-self-mfa-reset>
                        <label><span>Current password</span><input type="password" name="current_password" minlength="12" maxlength="128" autocomplete="current-password" required></label>
                        <label><span>Authenticator or recovery code</span><input name="code" maxlength="17" autocomplete="one-time-code" inputmode="text" pattern="[A-Za-z2-7 0-9-]{6,17}" required></label>
                        <button class="button button-dark" type="submit">Replace authenticator</button>
                    </form>
                <?php endif; ?>
            </section>
        </div>
    </div>
</section>
