<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$accounts = is_array($accounts ?? null) ? $accounts : [];
$errors = is_array($errors ?? null) ? $errors : [];
$old = is_array($old ?? null) ? $old : [];
?>
<section class="pickup-view-workspace">
    <div class="container pickup-workspace-header">
        <strong class="pickup-wordmark">Pickupsheet</strong>
        <div class="pickup-header-links">
            <a class="pickup-back" href="<?= $e($basePath) ?>/dhl/pickupsheet/submissions">Submitted sheets <span aria-hidden="true">&#8599;</span></a>
            <a class="pickup-back" href="<?= $e($basePath) ?>/dhl/pickupsheet/">New pickup sheet <span aria-hidden="true">&#8599;</span></a>
        </div>
    </div>

    <div class="container records-users-shell">
        <header class="pickup-submissions-heading">
            <div>
                <p class="eyebrow eyebrow-red">Administrator access</p>
                <h1>Manage users</h1>
                <p>Create and adjust lower-tier records accounts. Server-managed administrators cannot be changed here.</p>
            </div>
        </header>

        <?php if (is_string($flash ?? null) && $flash !== ''): ?>
            <div class="notice notice-success" role="status"><?= $e($flash) ?></div>
        <?php endif; ?>
        <?php if ($errors !== []): ?>
            <div class="notice notice-error" role="alert"><?php foreach ($errors as $error): ?><span><?= $e($error) ?></span><?php endforeach; ?></div>
        <?php endif; ?>

        <div class="records-users-layout">
            <aside class="records-role-guide">
                <span>Access hierarchy</span>
                <ol>
                    <li><strong>Admin</strong><small>All records actions and lower-tier account management. Configured only on the server.</small></li>
                    <li><strong>Operator</strong><small>View, print, and export pickup sheets.</small></li>
                    <li><strong>Viewer</strong><small>View and paginate pickup sheets only.</small></li>
                </ol>
            </aside>

            <form class="pickup-form records-user-create" method="post" action="<?= $e($basePath) ?>/dhl/pickupsheet/submissions/users">
                <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                <fieldset>
                    <legend>Create lower-tier account</legend>
                    <label><span>Username</span><input name="username" value="<?= $e($old['username'] ?? '') ?>" minlength="3" maxlength="100" pattern="[a-z0-9][a-z0-9._@-]{2,99}" autocomplete="off" required></label>
                    <label><span>Role</span><select name="role" required><option value="viewer" <?= ($old['role'] ?? '') === 'viewer' ? 'selected' : '' ?>>Viewer</option><option value="operator" <?= ($old['role'] ?? '') === 'operator' ? 'selected' : '' ?>>Operator</option></select></label>
                    <label><span>Password</span><input type="password" name="password" minlength="12" maxlength="128" autocomplete="new-password" required></label>
                    <label><span>Confirm password</span><input type="password" name="password_confirmation" minlength="12" maxlength="128" autocomplete="new-password" required></label>
                    <button class="button button-dark" type="submit">Create account</button>
                </fieldset>
            </form>
        </div>

        <section class="records-user-list" aria-labelledby="managed-accounts-title">
            <div class="records-user-list-heading">
                <div><span>Managed accounts</span><h2 id="managed-accounts-title">Operators and viewers</h2></div>
                <strong><?= $e(count($accounts)) ?> account<?= count($accounts) === 1 ? '' : 's' ?></strong>
            </div>

            <?php if ($accounts === []): ?>
                <div class="pickup-empty-state"><h2>No lower-tier accounts yet.</h2><p>Create an operator or viewer using the form above.</p></div>
            <?php endif; ?>

            <?php foreach ($accounts as $account): ?>
                <form class="pickup-form records-user-card" method="post" action="<?= $e($basePath) ?>/dhl/pickupsheet/submissions/users/update">
                    <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                    <input type="hidden" name="id" value="<?= $e($account->id) ?>">
                    <div class="records-user-identity">
                        <strong><?= $e($account->username) ?></strong>
                        <span data-active="<?= $account->active ? 'true' : 'false' ?>"><?= $account->active ? 'Active' : 'Disabled' ?></span>
                    </div>
                    <label><span>Username</span><input name="username" value="<?= $e($account->username) ?>" minlength="3" maxlength="100" pattern="[a-z0-9][a-z0-9._@-]{2,99}" required></label>
                    <label><span>Role</span><select name="role" required><option value="viewer" <?= $account->role === 'viewer' ? 'selected' : '' ?>>Viewer</option><option value="operator" <?= $account->role === 'operator' ? 'selected' : '' ?>>Operator</option></select></label>
                    <label><span>New password <small>Optional</small></span><input type="password" name="password" minlength="12" maxlength="128" autocomplete="new-password"></label>
                    <label><span>Confirm new password</span><input type="password" name="password_confirmation" minlength="12" maxlength="128" autocomplete="new-password"></label>
                    <label class="records-user-active"><input type="checkbox" name="active" value="1" <?= $account->active ? 'checked' : '' ?>><span>Account enabled</span></label>
                    <button class="button button-dark" type="submit">Save changes</button>
                </form>
            <?php endforeach; ?>
        </section>
    </div>
</section>
