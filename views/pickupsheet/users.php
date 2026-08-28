<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$accounts = is_array($accounts ?? null) ? $accounts : [];
$errors = is_array($errors ?? null) ? $errors : [];
$old = is_array($old ?? null) ? $old : [];
$jumpCloudIdentity = ($recordsIdentityProvider ?? 'local') === 'jumpcloud';
$jumpCloudEnabled = (bool) ($config['jumpcloud_oidc_configured'] ?? false) || $jumpCloudIdentity;
$jumpCloudGroups = is_array($config['jumpcloud_role_groups'] ?? null) ? $config['jumpcloud_role_groups'] : [];
?>
<section class="pickup-view-workspace">
    <div class="container pickup-workspace-header">
        <strong class="pickup-wordmark">Pickupsheet</strong>
        <div class="pickup-header-links">
            <span class="pickup-session-user" title="<?= $e($recordsUsername ?? '') ?>"><?= $e($recordsFullName ?? $recordsUsername ?? '') ?> · admin</span>
            <a class="pickup-back" href="<?= $e($basePath) ?>/dhl/pickupsheet/dashboard">Dashboard <span aria-hidden="true">&#8599;</span></a>
            <a class="pickup-back" href="<?= $e($basePath) ?>/dhl/pickupsheet/submissions">Submitted sheets <span aria-hidden="true">&#8599;</span></a>
            <a class="pickup-back" href="<?= $e($basePath) ?>/dhl/pickupsheet/">New pickup sheet <span aria-hidden="true">&#8599;</span></a>
            <form method="post" action="<?= $e($basePath) ?>/dhl/pickupsheet/logout"><input type="hidden" name="_token" value="<?= $e($csrfToken) ?>"><button class="pickup-link-button" type="submit">Sign out</button></form>
        </div>
    </div>

    <div class="container records-users-shell">
        <header class="pickup-submissions-heading">
            <div>
                <p class="eyebrow eyebrow-red">Administrator access</p>
                <h1>Manage users</h1>
                <p><?= $jumpCloudEnabled ? 'JumpCloud group membership controls SSO roles. Local accounts can also be created and managed below.' : 'Create users, reset account passwords, and control the Pickupsheet access hierarchy.' ?></p>
            </div>
        </header>

        <?php if (is_string($flash ?? null) && $flash !== ''): ?>
            <div class="notice notice-success" role="status"><?= $e($flash) ?></div>
        <?php endif; ?>
        <?php if ($errors !== []): ?>
            <div class="notice notice-error" role="alert"><?php foreach ($errors as $error): ?><span><?= $e($error) ?></span><?php endforeach; ?></div>
        <?php endif; ?>

        <?php if ($jumpCloudIdentity): ?>
            <section class="pickup-form records-idp-managed">
                <div><span>JumpCloud identity</span><h2>Password and access managed centrally</h2><p>Your displayed account name comes directly from JumpCloud. Password, MFA policy, account status, and group-based Pickupsheet role changes must be made in the JumpCloud administrator portal.</p></div>
            </section>
        <?php else: ?>
            <form class="pickup-form records-admin-password" method="post" action="<?= $e($basePath) ?>/dhl/pickupsheet/submissions/users/admin-password">
                <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                <div><span>Administrator security</span><h2>Reset my admin password</h2><p>Confirm the current password, then choose a new password. You will be signed out after it is changed.</p></div>
                <label><span>Current password</span><input type="password" name="current_password" maxlength="128" autocomplete="current-password" required></label>
                <label><span>New password</span><input type="password" name="password" minlength="12" maxlength="128" autocomplete="new-password" required></label>
                <label><span>Confirm new password</span><input type="password" name="password_confirmation" minlength="12" maxlength="128" autocomplete="new-password" required></label>
                <button class="button button-dark" type="submit">Reset admin password</button>
            </form>
        <?php endif; ?>

        <div class="records-users-layout">
            <aside class="records-role-guide">
                <span>Access hierarchy</span>
                <ol>
                    <li><strong>Admin</strong><small><?= $jumpCloudEnabled ? $e($jumpCloudGroups['admin'] ?? '') . ' — ' : '' ?>Dashboard, KPIs, all records, editing, paid status, audited deletion, print/export, and user management.</small></li>
                    <li><strong>Operator</strong><small><?= $jumpCloudEnabled ? $e($jumpCloudGroups['operator'] ?? '') . ' — ' : '' ?>Create new pickup sheets and view submitted records. Cannot edit, change status, print, export, delete, or manage access.</small></li>
                    <li><strong>Viewer</strong><small><?= $jumpCloudEnabled ? $e($jumpCloudGroups['viewer'] ?? '') . ' — ' : '' ?>Create, view, and paginate pickup sheets. Cannot edit, print, or export records.</small></li>
                </ol>
            </aside>

            <form class="pickup-form records-user-create" method="post" action="<?= $e($basePath) ?>/dhl/pickupsheet/submissions/users">
                <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                <fieldset>
                    <legend>Create local account</legend>
                    <label><span>First name</span><input name="first_name" value="<?= $e($old['first_name'] ?? '') ?>" maxlength="49" autocomplete="given-name" required></label>
                    <label><span>Last name</span><input name="last_name" value="<?= $e($old['last_name'] ?? '') ?>" maxlength="49" autocomplete="family-name" required></label>
                    <label><span>Email or username</span><input name="username" value="<?= $e($old['username'] ?? '') ?>" minlength="3" maxlength="100" autocomplete="username" autocapitalize="none" spellcheck="false" required></label>
                    <label><span>Role</span><select name="role" required><option value="viewer" <?= ($old['role'] ?? '') === 'viewer' ? 'selected' : '' ?>>Viewer</option><option value="operator" <?= ($old['role'] ?? '') === 'operator' ? 'selected' : '' ?>>Operator</option></select></label>
                    <label><span>Account status</span><select name="active" required><option value="1" <?= ($old['active'] ?? '1') === '1' ? 'selected' : '' ?>>Active</option><option value="0" <?= ($old['active'] ?? '') === '0' ? 'selected' : '' ?>>Inactive</option></select></label>
                    <label><span>Password</span><input type="password" name="password" minlength="12" maxlength="128" autocomplete="new-password" required></label>
                    <label><span>Confirm password</span><input type="password" name="password_confirmation" minlength="12" maxlength="128" autocomplete="new-password" required></label>
                    <button class="button button-dark" type="submit">Create account</button>
                </fieldset>
            </form>
        </div>

        <section class="records-user-list" aria-labelledby="managed-accounts-title">
            <div class="records-user-list-heading">
                <div><span>Managed accounts</span><h2 id="managed-accounts-title">Local operators and viewers</h2></div>
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
                        <div><strong><?= $e($account->fullName()) ?></strong><small><?= $e($account->username) ?></small></div>
                        <span data-active="<?= $account->active ? 'true' : 'false' ?>"><?= $account->active ? 'Active' : 'Inactive' ?></span>
                    </div>
                    <label><span>First name</span><input name="first_name" value="<?= $e($account->firstName) ?>" maxlength="49" autocomplete="given-name" required></label>
                    <label><span>Last name</span><input name="last_name" value="<?= $e($account->lastName) ?>" maxlength="49" autocomplete="family-name" required></label>
                    <label><span>Email or username</span><input name="username" value="<?= $e($account->username) ?>" minlength="3" maxlength="100" autocomplete="username" autocapitalize="none" spellcheck="false" required></label>
                    <label><span>Role</span><select name="role" required><option value="viewer" <?= $account->role === 'viewer' ? 'selected' : '' ?>>Viewer</option><option value="operator" <?= $account->role === 'operator' ? 'selected' : '' ?>>Operator</option></select></label>
                    <label><span>Account status</span><select name="active" required><option value="1" <?= $account->active ? 'selected' : '' ?>>Active</option><option value="0" <?= !$account->active ? 'selected' : '' ?>>Inactive</option></select></label>
                    <label><span>Reset password <small>Optional</small></span><input type="password" name="password" minlength="12" maxlength="128" autocomplete="new-password"></label>
                    <label><span>Confirm new password</span><input type="password" name="password_confirmation" minlength="12" maxlength="128" autocomplete="new-password"></label>
                    <button class="button button-dark" type="submit">Save changes</button>
                </form>
            <?php endforeach; ?>
        </section>
    </div>
</section>
