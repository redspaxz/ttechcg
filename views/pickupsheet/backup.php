<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$errors = is_array($errors ?? null) ? $errors : [];
$operational = (bool) ($operational ?? false);
?>
<section class="pickup-admin-workspace pickup-backup-workspace">
    <div class="container pickup-workspace-header pickup-admin-header">
        <strong class="pickup-wordmark">Pickupsheet control</strong>
        <div class="pickup-header-links">
            <span class="pickup-session-user" title="<?= $e($recordsUsername ?? '') ?>"><?= $e($recordsFullName ?? $recordsUsername ?? '') ?> &middot; admin</span>
            <a class="pickup-back" href="<?= $e($basePath) ?>/dhl/pickupsheet/dashboard">Dashboard <span aria-hidden="true">&#8599;</span></a>
            <a class="pickup-back" href="<?= $e($basePath) ?>/dhl/pickupsheet/settings">User settings <span aria-hidden="true">&#8599;</span></a>
            <form method="post" action="<?= $e($basePath) ?>/dhl/pickupsheet/logout"><input type="hidden" name="_token" value="<?= $e($csrfToken) ?>"><button class="pickup-link-button" type="submit">Sign out</button></form>
        </div>
    </div>

    <div class="container pickup-admin-shell pickup-backup-shell">
        <header class="pickup-admin-heading">
            <div><p class="eyebrow eyebrow-red">Data resilience</p><h1>Backup and restore</h1><p>Create a portable encrypted copy of Pickupsheet application data or restore a previously generated backup.</p></div>
            <span class="pickup-storage-state"><i aria-hidden="true"></i><?= $operational ? 'Encrypted backups ready' : 'Backup storage unavailable' ?></span>
        </header>

        <?php if (is_string($flash ?? null) && $flash !== ''): ?><div class="notice notice-success" role="status"><?= $e($flash) ?></div><?php endif; ?>
        <?php if ($errors !== []): ?><div class="notice notice-error" role="alert"><?php foreach ($errors as $error): ?><span><?= $e($error) ?></span><?php endforeach; ?></div><?php endif; ?>
        <?php if (!$operational): ?><div class="notice notice-error" role="alert">Backup and restore require an active MySQL connection and AES-256-GCM OpenSSL support.</div><?php endif; ?>

        <section class="pickup-backup-scope" aria-labelledby="backup-scope-title">
            <div><span>Backup scope</span><h2 id="backup-scope-title">Application data, protected at rest</h2></div>
            <p>The encrypted file includes pickup sheets, shipments, customers, rewards, inquiries, local account password hashes, encrypted 2FA enrollments, recovery-code hashes, and application audit records. It excludes the <code>.env</code> file, database password, 2FA encryption key, JumpCloud and Cloudflare secrets, uploaded assets, and PHP session files.</p>
        </section>

        <div class="pickup-backup-grid">
            <section class="pickup-backup-card" aria-labelledby="create-backup-title">
                <div class="pickup-card-heading"><div><span>Export</span><h2 id="create-backup-title">Create encrypted backup</h2></div><small>AES-256-GCM</small></div>
                <p>Choose a unique passphrase. It is never stored or logged and is required to restore this file.</p>
                <form method="post" action="<?= $e($basePath) ?>/dhl/pickupsheet/admin/backup/download">
                    <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                    <label><span>Backup passphrase</span><input type="password" name="passphrase" minlength="16" maxlength="200" required autocomplete="new-password" <?= $operational ? '' : 'disabled' ?>><small>At least 16 characters.</small></label>
                    <label><span>Confirm passphrase</span><input type="password" name="passphrase_confirmation" minlength="16" maxlength="200" required autocomplete="new-password" <?= $operational ? '' : 'disabled' ?>></label>
                    <button class="button" type="submit" <?= $operational ? '' : 'disabled' ?>>Download encrypted backup</button>
                </form>
            </section>

            <section class="pickup-backup-card pickup-restore-card" aria-labelledby="restore-backup-title">
                <div class="pickup-card-heading"><div><span>Restore</span><h2 id="restore-backup-title">Restore encrypted backup</h2></div><small>Transactional</small></div>
                <div class="pickup-restore-warning" role="note"><strong>This replaces current application data.</strong><p>The restore is validated first and committed as one MySQL transaction. If any table fails, no partial restore is retained. Restoring local account data may require administrators to sign in again.</p></div>
                <form method="post" enctype="multipart/form-data" action="<?= $e($basePath) ?>/dhl/pickupsheet/admin/backup/restore">
                    <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                    <input type="hidden" name="MAX_FILE_SIZE" value="<?= $e(((int) ($maxBackupMb ?? 12)) * 1048576) ?>">
                    <label><span>Encrypted backup file</span><input type="file" name="backup_file" accept="application/json,.json" required <?= $operational ? '' : 'disabled' ?>><small>Maximum <?= $e($maxBackupMb ?? 12) ?> MB.</small></label>
                    <label><span>Backup passphrase</span><input type="password" name="passphrase" minlength="16" maxlength="200" required autocomplete="current-password" <?= $operational ? '' : 'disabled' ?>></label>
                    <label><span>Confirmation</span><input name="confirmation" pattern="RESTORE" required autocomplete="off" placeholder="Type RESTORE" <?= $operational ? '' : 'disabled' ?>><small>Type RESTORE exactly.</small></label>
                    <button class="button pickup-restore-button" type="submit" <?= $operational ? '' : 'disabled' ?>>Validate and restore data</button>
                </form>
            </section>
        </div>
    </div>
</section>
