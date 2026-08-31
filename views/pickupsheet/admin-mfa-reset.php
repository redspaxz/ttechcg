<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$localReauthentication = ($requiresLocalReauthentication ?? false) === true;
$ssoFresh = ($ssoAuthenticationFresh ?? false) === true;
?>
<section class="pickup-view-workspace pickup-settings-workspace">
    <div class="container pickup-workspace-header">
        <strong class="pickup-wordmark">Security confirmation</strong>
        <div class="pickup-header-links">
            <span class="pickup-session-user"><?= $e($principal->fullName() ?? '') ?> &middot; admin</span>
            <a class="pickup-back" href="<?= $e($basePath) ?>/dhl/pickupsheet/submissions/users">Manage users <span aria-hidden="true">&#8599;</span></a>
            <form method="post" action="<?= $e($basePath) ?>/dhl/pickupsheet/logout"><input type="hidden" name="_token" value="<?= $e($csrfToken) ?>"><button class="pickup-link-button" type="submit">Sign out</button></form>
        </div>
    </div>

    <div class="container pickup-settings-shell pickup-security-confirm-shell">
        <header class="pickup-settings-heading">
            <p class="eyebrow eyebrow-red">High-risk account change</p>
            <h1>Confirm 2FA reset.</h1>
            <p>Resetting an authentication factor affects account recovery and must be authorized again immediately before the change.</p>
        </header>

        <section class="pickup-settings-card pickup-security-confirm-card" aria-labelledby="mfa-reset-account">
            <div class="pickup-card-heading"><div><span>Managed account</span><h2 id="mfa-reset-account"><?= $e($account->fullName() ?? '') ?></h2></div><small><?= $e($account->username ?? '') ?></small></div>
            <?php if (!$localReauthentication && !$ssoFresh): ?>
                <div class="pickup-settings-message"><strong>Fresh identity-provider sign-in required</strong><p>Sign out, sign in again with your identity provider, and return here within five minutes. This prevents a stolen long-lived session from resetting another user&rsquo;s factor.</p></div>
            <?php else: ?>
                <div class="pickup-settings-message"><strong>The user will need to enroll again</strong><p>The current authenticator and unused recovery codes will stop working. This action is recorded in the security audit log.</p></div>
                <form class="pickup-settings-form pickup-security-confirm-form" method="post" action="<?= $e($basePath) ?>/dhl/pickupsheet/submissions/users/mfa/reset">
                    <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                    <input type="hidden" name="id" value="<?= $e($account->id ?? '') ?>">
                    <?php if ($localReauthentication): ?>
                        <label><span>Current administrator password</span><input type="password" name="current_password" minlength="12" maxlength="128" autocomplete="current-password" required></label>
                        <label><span>Your authenticator or recovery code</span><input name="code" maxlength="17" autocomplete="one-time-code" inputmode="text" pattern="[A-Za-z2-7 0-9-]{6,17}" required></label>
                    <?php endif; ?>
                    <label class="pickup-security-confirm-check"><input type="checkbox" name="confirm_reset" value="1" required><span>I understand this removes the managed user&rsquo;s current 2FA enrollment.</span></label>
                    <button class="button button-red" type="submit">Reset managed-user 2FA</button>
                </form>
            <?php endif; ?>
        </section>
    </div>
</section>
