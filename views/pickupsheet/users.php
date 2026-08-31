<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$accounts = is_array($accounts ?? null) ? $accounts : [];
$errors = is_array($errors ?? null) ? $errors : [];
$old = is_array($old ?? null) ? $old : [];
$cloudflareIdentity = ($recordsIdentityProvider ?? 'local') === 'cloudflare_access';
$jumpCloudIdentity = ($recordsIdentityProvider ?? 'local') === 'jumpcloud' || $cloudflareIdentity;
$localLoginConfigured = (bool) ($loginMethods->localLoginConfigured ?? $config['local_login_enabled'] ?? true);
$jumpCloudLoginConfigured = (bool) ($loginMethods->jumpCloudLoginConfigured ?? $config['jumpcloud_oidc_configured'] ?? false);
$localLoginEnabled = (bool) ($loginMethods->localLoginEnabled ?? $localLoginConfigured);
$jumpCloudDirectEnabled = (bool) ($loginMethods->jumpCloudLoginEnabled ?? $jumpCloudLoginConfigured);
$cloudflareAccessConfigured = (bool) ($loginMethods->cloudflareAccessConfigured ?? $config['cloudflare_access_configured'] ?? false);
$loginMethodsUpdatedAt = is_string($loginMethods->updatedAt ?? null) ? $loginMethods->updatedAt : null;
$mfaStatuses = is_array($mfaStatuses ?? null) ? $mfaStatuses : [];
$localMfaEnabled = ($localMfaEnabled ?? false) === true;
$localMfaConfigured = ($localMfaConfigured ?? false) === true;
$jumpCloudEnabled = $jumpCloudDirectEnabled || $jumpCloudIdentity;
$jumpCloudGroups = is_array($config['jumpcloud_role_groups'] ?? null) ? $config['jumpcloud_role_groups'] : [];
?>
<section class="pickup-view-workspace">
    <div class="container pickup-workspace-header">
        <strong class="pickup-wordmark">Pickupsheet</strong>
        <div class="pickup-header-links">
            <span class="pickup-session-user" title="<?= $e($recordsUsername ?? '') ?>"><?= $e($recordsFullName ?? $recordsUsername ?? '') ?> &middot; admin</span>
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
                <p><?= $jumpCloudEnabled ? ($localLoginEnabled ? 'JumpCloud group membership controls SSO roles. Local accounts can also be created and managed below.' : 'JumpCloud group membership controls SSO roles. Local accounts remain manageable below, but local sign-in is disabled.') : 'Create users, reset account passwords, and control the Pickupsheet access hierarchy.' ?></p>
            </div>
        </header>

        <?php if (is_string($flash ?? null) && $flash !== ''): ?>
            <div class="notice notice-success" role="status"><?= $e($flash) ?></div>
        <?php endif; ?>
        <?php if ($errors !== []): ?>
            <div class="notice notice-error" role="alert"><?php foreach ($errors as $error): ?><span><?= $e($error) ?></span><?php endforeach; ?></div>
        <?php endif; ?>

        <form class="records-login-methods" method="post" action="<?= $e($basePath) ?>/dhl/pickupsheet/submissions/users/login-methods" aria-labelledby="login-methods-title" data-login-method-form>
            <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
            <div class="records-login-methods-heading">
                <div><span>Authentication configuration</span><h2 id="login-methods-title">Sign-in methods</h2></div>
                <small><?= $loginMethodsUpdatedAt !== null ? 'Last changed ' . $e($loginMethodsUpdatedAt) . ' UTC' : 'Administrator controls' ?></small>
            </div>
            <div class="records-login-method-notice" role="status" data-login-method-notice hidden></div>
            <div class="records-login-method-grid">
                <article data-login-method-card="local" data-enabled="<?= $localLoginEnabled ? 'true' : 'false' ?>" data-configured="<?= $localLoginConfigured ? 'true' : 'false' ?>">
                    <div><strong>Local login</strong><span data-login-method-status="local"><?= !$localLoginConfigured ? 'Unavailable' : ($localLoginEnabled ? 'Enabled' : 'Disabled') ?></span></div>
                    <p>Username or email and password authentication for locally managed accounts.</p>
                    <label class="records-method-switch"><input type="hidden" name="local_login_enabled" value="0"><input type="checkbox" name="local_login_enabled" value="1" data-login-method-toggle="local" <?= $localLoginEnabled ? 'checked' : '' ?> <?= !$localLoginConfigured ? 'disabled' : '' ?>><span aria-hidden="true"></span><b><?= $localLoginConfigured ? 'Allow local sign-in' : 'Blocked by server configuration' ?></b></label>
                    <small class="records-local-mfa-state" data-enabled="<?= $localMfaConfigured ? 'true' : 'false' ?>">Authenticator 2FA: <?= !$localMfaEnabled ? 'disabled in .env' : ($localMfaConfigured ? 'required' : 'encryption key unavailable') ?></small>
                    <code>PICKUPSHEET_LOCAL_LOGIN_ENABLED=<?= $localLoginConfigured ? 'true' : 'false' ?></code>
                </article>
                <article data-login-method-card="jumpcloud" data-enabled="<?= $jumpCloudDirectEnabled ? 'true' : 'false' ?>" data-configured="<?= $jumpCloudLoginConfigured ? 'true' : 'false' ?>">
                    <div><strong>JumpCloud login</strong><span data-login-method-status="jumpcloud"><?= !$jumpCloudLoginConfigured ? 'Unavailable' : ($jumpCloudDirectEnabled ? 'Enabled' : 'Disabled') ?></span></div>
                    <p>Direct OIDC sign-in using approved JumpCloud groups and directory identities.</p>
                    <label class="records-method-switch"><input type="hidden" name="jumpcloud_login_enabled" value="0"><input type="checkbox" name="jumpcloud_login_enabled" value="1" data-login-method-toggle="jumpcloud" <?= $jumpCloudDirectEnabled ? 'checked' : '' ?> <?= !$jumpCloudLoginConfigured ? 'disabled' : '' ?>><span aria-hidden="true"></span><b><?= $jumpCloudLoginConfigured ? 'Allow JumpCloud sign-in' : 'OIDC configuration is incomplete' ?></b></label>
                    <code>JUMPCLOUD_OIDC_ENABLED=<?= $jumpCloudLoginConfigured ? 'true' : 'false' ?></code>
                </article>
            </div>
            <div class="records-login-method-actions"><p class="records-login-method-note">The <code>.env</code> values remain hard security limits. Keep at least one method enabled<?= $cloudflareAccessConfigured ? ', unless Cloudflare Access remains the required identity boundary' : '' ?>.</p><button class="button button-dark" type="submit" data-login-method-save>Save sign-in methods</button></div>
        </form>

        <?php if ($jumpCloudIdentity): ?>
            <section class="pickup-form records-idp-managed">
                <div><span><?= $cloudflareIdentity ? 'JumpCloud via Cloudflare Access' : 'JumpCloud identity' ?></span><h2>Password and access managed centrally</h2><p>Your displayed account name comes directly from JumpCloud. Password, MFA policy, account status, and group-based Pickupsheet role changes must be made in the JumpCloud administrator portal.</p></div>
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
                    <li><strong>Admin</strong><small><?= $jumpCloudEnabled ? $e($jumpCloudGroups['admin'] ?? '') . ' &mdash; ' : '' ?>Dashboard, KPIs, all records, editing, paid status, audited deletion, print/export, and user management.</small></li>
                    <li><strong>Operator</strong><small><?= $jumpCloudEnabled ? $e($jumpCloudGroups['operator'] ?? '') . ' &mdash; ' : '' ?>Create and view pickup sheets, print PDFs, and export Excel files. Cannot edit, change status, delete, or manage access.</small></li>
                    <li><strong>Viewer</strong><small><?= $jumpCloudEnabled ? $e($jumpCloudGroups['viewer'] ?? '') . ' &mdash; ' : '' ?>Create, view, and paginate pickup sheets. Cannot edit, print, or export records.</small></li>
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
                <div><span>Managed accounts</span><h2 id="managed-accounts-title">Local operators and viewers</h2><p>Review accounts at a glance, then select Edit only when a change is needed.</p></div>
                <strong><?= $e(count($accounts)) ?> account<?= count($accounts) === 1 ? '' : 's' ?></strong>
            </div>

            <div class="records-user-table-wrap">
                <table class="records-user-table">
                    <thead><tr><th>User</th><th>Login ID</th><th>Role</th><th>Status</th><th>2FA</th><th>Last updated</th><th>Actions</th></tr></thead>
                    <tbody>
                    <?php if ($accounts === []): ?>
                        <tr><td colspan="7"><div class="pickup-empty-state"><h2>No lower-tier accounts yet.</h2><p>Create an operator or viewer using the form above.</p></div></td></tr>
                    <?php endif; ?>
                    <?php foreach ($accounts as $account): ?>
                        <?php $editorId = 'user-editor-' . (int) $account->id; $mfaSubject = 'local-user:' . (int) $account->id; $mfaEnrolled = ($mfaStatuses[$mfaSubject] ?? false) === true; ?>
                        <tr class="records-user-summary-row" data-active="<?= $account->active ? 'true' : 'false' ?>">
                            <td><div class="records-user-table-identity"><strong><?= $e($account->fullName()) ?></strong><small>Local account #<?= $e($account->id) ?> &middot; created <?= $e($account->createdAt) ?> UTC</small></div></td>
                            <td><span class="records-user-login-id"><?= $e($account->username) ?></span></td>
                            <td><span class="records-user-role"><?= $e($account->role) ?></span></td>
                            <td><span class="records-user-status" data-active="<?= $account->active ? 'true' : 'false' ?>"><?= $account->active ? 'Active' : 'Inactive' ?></span></td>
                            <td><span class="records-user-mfa-status" data-enabled="<?= $mfaEnrolled ? 'true' : 'false' ?>"><?= !$localMfaEnabled ? 'Not required' : ($mfaEnrolled ? 'Enabled' : 'Not enrolled') ?></span></td>
                            <td><time class="records-user-updated" datetime="<?= $e($account->updatedAt) ?>"><?= $e($account->updatedAt) ?><small>UTC</small></time></td>
                            <td class="records-user-action-cell"><div class="records-user-row-actions"><button class="button button-dark" type="button" data-user-edit-toggle="<?= $e($editorId) ?>" aria-controls="<?= $e($editorId) ?>" aria-expanded="false">Edit</button><?php if ($localMfaConfigured && $mfaEnrolled): ?><form method="post" action="<?= $e($basePath) ?>/dhl/pickupsheet/submissions/users/mfa/reset" data-user-mfa-reset-form data-account-name="<?= $e($account->fullName()) ?>"><input type="hidden" name="_token" value="<?= $e($csrfToken) ?>"><input type="hidden" name="id" value="<?= $e($account->id) ?>"><input type="hidden" name="confirm_reset" value="0" data-confirm-mfa-reset><button class="button records-user-mfa-reset" type="submit">Reset 2FA</button></form><?php endif; ?><form method="post" action="<?= $e($basePath) ?>/dhl/pickupsheet/submissions/users/delete" data-user-delete-form data-account-name="<?= $e($account->fullName()) ?>"><input type="hidden" name="_token" value="<?= $e($csrfToken) ?>"><input type="hidden" name="id" value="<?= $e($account->id) ?>"><input type="hidden" name="confirm_delete" value="0" data-confirm-delete><button class="button records-user-delete" type="submit">Delete</button></form></div></td>
                        </tr>
                        <tr class="records-user-editor-row" id="<?= $e($editorId) ?>" data-user-editor hidden>
                            <td colspan="7">
                                <form class="records-user-editor" method="post" action="<?= $e($basePath) ?>/dhl/pickupsheet/submissions/users/update">
                                    <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                                    <input type="hidden" name="id" value="<?= $e($account->id) ?>">
                                    <div class="records-user-editor-heading"><div><span>Edit local account</span><h3><?= $e($account->fullName()) ?></h3></div><button type="button" class="records-user-editor-close" data-user-edit-cancel aria-label="Close account editor">&times;</button></div>
                                    <div class="records-user-editor-grid">
                                        <label><span>First name</span><input name="first_name" value="<?= $e($account->firstName) ?>" maxlength="49" autocomplete="given-name" required></label>
                                        <label><span>Last name</span><input name="last_name" value="<?= $e($account->lastName) ?>" maxlength="49" autocomplete="family-name" required></label>
                                        <label><span>Email or username</span><input name="username" value="<?= $e($account->username) ?>" minlength="3" maxlength="100" autocomplete="username" autocapitalize="none" spellcheck="false" required></label>
                                        <label><span>Role</span><select name="role" required><option value="viewer" <?= $account->role === 'viewer' ? 'selected' : '' ?>>Viewer</option><option value="operator" <?= $account->role === 'operator' ? 'selected' : '' ?>>Operator</option></select></label>
                                        <label><span>Status</span><select name="active" required><option value="1" <?= $account->active ? 'selected' : '' ?>>Active</option><option value="0" <?= !$account->active ? 'selected' : '' ?>>Inactive</option></select></label>
                                        <label><span>New password <small>optional</small></span><input type="password" name="password" minlength="12" maxlength="128" autocomplete="new-password"></label>
                                        <label><span>Confirm new password</span><input type="password" name="password_confirmation" minlength="12" maxlength="128" autocomplete="new-password"></label>
                                    </div>
                                    <div class="records-user-editor-actions"><button class="button" type="button" data-user-edit-cancel>Cancel</button><button class="button button-dark" type="submit">Save account</button></div>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</section>
