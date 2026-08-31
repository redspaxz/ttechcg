<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<section class="pickup-login-shell pickup-mfa-shell">
    <div class="pickup-login-panel pickup-mfa-panel">
        <a class="pickup-login-brand" href="<?= $e($basePath . '/') ?>">T&amp;Tech <span>Pickupsheet</span></a>
        <div class="pickup-login-copy">
            <p class="eyebrow eyebrow-red">2FA enabled</p>
            <h1>Save your recovery codes.</h1>
            <p>Store these one-time codes in a password manager or another secure location. They will not be displayed again.</p>
        </div>
        <div class="pickup-recovery-codes" data-recovery-code-list>
            <?php foreach ($recoveryCodes as $code): ?><code><?= $e($code) ?></code><?php endforeach; ?>
        </div>
        <div class="pickup-recovery-actions">
            <button class="button" type="button" data-copy-recovery-codes>Copy codes</button>
            <button class="button" type="button" data-print-recovery-codes>Print</button>
            <a class="button button-red" href="<?= $e($destination) ?>"><?= $e($destinationLabel ?? 'Continue to Pickupsheet') ?></a>
        </div>
        <p class="pickup-login-security">Each recovery code can be used once &middot; new codes require a fresh 2FA enrollment</p>
    </div>
    <aside class="pickup-login-aside pickup-mfa-aside" aria-hidden="true">
        <strong>Keep them<br>private.<br>Keep access.</strong>
        <span>One-time recovery credentials</span>
    </aside>
</section>
