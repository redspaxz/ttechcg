<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<section class="pickup-login-shell">
    <div class="pickup-login-panel">
        <a class="pickup-login-brand" href="<?= $e($basePath . '/') ?>">T&amp;Tech Consulting Group <span class="pickup-login-brand-portal">Cash Shipment Management Portal</span></a>
        <div class="pickup-login-card">
            <div class="pickup-login-copy">
                <p class="eyebrow eyebrow-red">Pickupsheet workspace</p>
                <h1>Welcome back.</h1>
                <p>Sign in to record cash shipments, confirm payments, and look after your customers.</p>
            </div>

            <?php if (is_string($error ?? null) && $error !== ''): ?>
                <div class="notice notice-error" role="alert"><?= $e($error) ?></div>
            <?php endif; ?>
            <?php if (is_string($flash ?? null) && $flash !== ''): ?>
                <div class="notice notice-success" role="status"><?= $e($flash) ?></div>
            <?php endif; ?>

            <?php if (($jumpCloudEnabled ?? false) === true): ?>
                <div class="pickup-sso-login">
                    <?php if (($localLoginEnabled ?? true) === true): ?><span class="pickup-sso-badge">Recommended</span><?php endif; ?>
                    <a class="button pickup-jumpcloud-button" href="<?= $e($basePath) ?>/dhl/pickupsheet/auth/jumpcloud"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 17l5-5-5-5"/><path d="M15 12H3"/><path d="M14 3h5a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-5"/></svg><span class="pickup-jumpcloud-label">Continue with JumpCloud</span><span aria-hidden="true">&#8594;</span></a>
                    <small>Use your company JumpCloud account. Your name and approved group set your role automatically.</small>
                </div>
            <?php endif; ?>

            <?php if (($jumpCloudEnabled ?? false) === true && ($localLoginEnabled ?? true) === true): ?><div class="pickup-login-divider"><span>Or sign in with a local account</span></div><?php endif; ?>
            <?php if (($localLoginEnabled ?? true) === true): ?>
                <form class="pickup-login-form<?= ($jumpCloudEnabled ?? false) === true ? ' is-secondary' : '' ?>" method="post" action="<?= $e($basePath) ?>/dhl/pickupsheet/login">
                    <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                    <label><span>Email or username</span><input name="username" value="<?= $e($username) ?>" maxlength="100" autocomplete="username" autocapitalize="none" spellcheck="false" autofocus required placeholder="name@company.com"></label>
                    <label><span>Password</span><span class="pickup-login-password"><input type="password" name="password" maxlength="128" autocomplete="current-password" required data-password-input><button class="pickup-login-reveal" type="button" data-password-toggle aria-pressed="false" aria-label="Show password" hidden>Show</button></span></label>
                    <button class="button button-red" type="submit">Sign in <span aria-hidden="true">&#8594;</span></button>
                    <?php if (($localMfaEnabled ?? false) === true): ?><small class="pickup-login-mfa-note">After your password, you will confirm with your authenticator app or a recovery code.</small><?php endif; ?>
                </form>
            <?php endif; ?>
            <?php if (($loginMethodsAvailable ?? true) === false): ?>
                <p class="pickup-login-unavailable">Authentication is disabled at the server configuration level.</p>
            <?php endif; ?>
        </div>
        <p class="pickup-login-security"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>Secure session &middot; signs out after 60 minutes of inactivity &middot; access is logged</p>
    </div>
    <aside class="pickup-login-aside pickup-login-welcome" aria-label="About Pickupsheet">
        <div>
            <p class="pickup-login-welcome-eyebrow">T&amp;Tech Consulting Group</p>
            <h2>Every pickup,<br>accounted for.</h2>
            <ul>
                <li><strong>Record cash shipments</strong><span>Build a pickup sheet in minutes, with totals worked out for you.</span></li>
                <li><strong>Confirm payments</strong><span>Match each receipt to its sheet before it is marked paid.</span></li>
                <li><strong>Know your customers</strong><span>Shipment history, rewards, and follow-ups in one profile.</span></li>
            </ul>
        </div>
        <span>Role-based access for DHL cash-shipment operations</span>
    </aside>
</section>
