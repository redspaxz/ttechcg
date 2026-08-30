<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$accounts = is_array($accounts ?? null) ? $accounts : [];
$errors = is_array($errors ?? null) ? $errors : [];
$old = is_array($old ?? null) ? $old : [];
$cloudflareIdentity = ($recordsIdentityProvider ?? 'local') === 'cloudflare_access';
$jumpCloudIdentity = ($recordsIdentityProvider ?? 'local') === 'jumpcloud' || $cloudflareIdentity;
$localLoginEnabled = (bool) ($config['local_login_enabled'] ?? true);
$jumpCloudDirectEnabled = (bool) ($config['jumpcloud_login_enabled'] ?? $config['jumpcloud_oidc_configured'] ?? false);
$jumpCloudEnabled = $jumpCloudDirectEnabled || $jumpCloudIdentity;
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
                <p><?= $jumpCloudEnabled ? ($localLoginEnabled ? 'JumpCloud group membership controls SSO roles. Local accounts can also be created and managed below.' : 'JumpCloud group membership controls SSO roles. Local accounts remain manageable below, but local sign-in is disabled.') : 'Create users, reset account passwords, and control the Pickupsheet access hierarchy.' ?></p>
            </div>
        </header>

        <?php if (is_string($flash ?? null) && $flash !== ''): ?>
            <div class="notice notice-success" role="status"><?= $e($flash) ?></div>
        <?php endif; ?>
        <?php if ($errors !== []): ?>
            <div class="notice notice-error" role="alert"><?php foreach ($errors as $error): ?><span><?= $e($error) ?></span><?php endforeach; ?></div>
        <?php endif; ?>

        <section class="records-login-methods" aria-labelledby="login-methods-title">
            <div class="records-login-methods-heading">
                <div><span>Authentication configuration</span><h2 id="login-methods-title">Sign-in methods</h2></div>
                <small>Managed in the server <code>.env</code> file</small>
            </div>
            <div class="records-login-method-grid">
                <article data-enabled="<?= $localLoginEnabled ? 'true' : 'false' ?>">
                    <div><strong>Local login</strong><span><?= $localLoginEnabled ? 'Enabled' : 'Disabled' ?></span></div>
                    <p>Username or email and password authentication for locally managed accounts.</p>
                    <code>PICKUPSHEET_LOCAL_LOGIN_ENABLED=<?= $localLoginEnabled ? 'true' : 'false' ?></code>
                </article>
                <article data-enabled="<?= $jumpCloudDirectEnabled ? 'true' : 'false' ?>">
                    <div><strong>JumpCloud login</strong><span><?= $jumpCloudDirectEnabled ? 'Enabled' : 'Disabled' ?></span></div>
                    <p>Direct OIDC sign-in using approved JumpCloud groups and directory identities.</p>
                    <code>JUMPCLOUD_OIDC_ENABLED=<?= $jumpCloudDirectEnabled ? 'true' : 'false' ?></code>
                </article>
            </div>
            <p class="records-login-method-note">Keep at least one direct method enabled unless Cloudflare Access is the required identity boundary. Changes take effect after the server configuration reloads.</p>
        </section>

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
                    <li><strong>Admin</strong><small><?= $jumpCloudEnabled ? $e($jumpCloudGroups['admin'] ?? '') . ' — ' : '' ?>Dashboard, KPIs, all records, editing, paid status, audited deletion, print/export, and user management.</small></li>
                    <li><strong>Operator</strong><small><?= $jumpCloudEnabled ? $e($jumpCloudGroups['operator'] ?? '') . ' — ' : '' ?>Create and view pickup sheets, print PDFs, and export Excel files. Cannot edit, change status, delete, or manage access.</small></li>
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

            <div class="records-user-table-wrap">
                <table class="records-user-table">
                    <thead><tr><th>Account details</th><th>Login ID</th><th>Role</th><th>Status</th><th>Password reset</th><th>Account history</th><th>Action</th></tr></thead>
                    <tbody>
                    <?php if ($accounts === []): ?>
                        <tr><td colspan="7"><div class="pickup-empty-state"><h2>No lower-tier accounts yet.</h2><p>Create an operator or viewer using the form above.</p></div></td></tr>
                    <?php endif; ?>
                    <?php foreach ($accounts as $account): ?>
                        <?php $accountFormId = 'records-user-' . (int) $account->id; ?>
                        <tr data-active="<?= $account->active ? 'true' : 'false' ?>">
                            <td>
                                <form id="<?= $e($accountFormId) ?>" method="post" action="<?= $e($basePath) ?>/dhl/pickupsheet/submissions/users/update">
                                    <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                                    <input type="hidden" name="id" value="<?= $e($account->id) ?>">
                                </form>
                                <div class="records-user-table-identity"><strong><?= $e($account->fullName()) ?></strong><small>Local account #<?= $e($account->id) ?></small></div>
                                <div class="records-user-name-fields">
                                    <label><span>First name</span><input form="<?= $e($accountFormId) ?>" name="first_name" value="<?= $e($account->firstName) ?>" maxlength="49" autocomplete="given-name" aria-label="First name for <?= $e($account->fullName()) ?>" required></label>
                                    <label><span>Last name</span><input form="<?= $e($accountFormId) ?>" name="last_name" value="<?= $e($account->lastName) ?>" maxlength="49" autocomplete="family-name" aria-label="Last name for <?= $e($account->fullName()) ?>" required></label>
                                </div>
                            </td>
                            <td><label><span class="records-table-mobile-label">Email or username</span><input form="<?= $e($accountFormId) ?>" name="username" value="<?= $e($account->username) ?>" minlength="3" maxlength="100" autocomplete="username" autocapitalize="none" spellcheck="false" aria-label="Email or username for <?= $e($account->fullName()) ?>" required></label></td>
                            <td><label><span class="records-table-mobile-label">Role</span><select form="<?= $e($accountFormId) ?>" name="role" aria-label="Role for <?= $e($account->fullName()) ?>" required><option value="viewer" <?= $account->role === 'viewer' ? 'selected' : '' ?>>Viewer</option><option value="operator" <?= $account->role === 'operator' ? 'selected' : '' ?>>Operator</option></select></label></td>
                            <td><label><span class="records-table-mobile-label">Status</span><select form="<?= $e($accountFormId) ?>" name="active" aria-label="Status for <?= $e($account->fullName()) ?>" required><option value="1" <?= $account->active ? 'selected' : '' ?>>Active</option><option value="0" <?= !$account->active ? 'selected' : '' ?>>Inactive</option></select></label><span class="records-user-table-status" data-active="<?= $account->active ? 'true' : 'false' ?>"><?= $account->active ? 'Can sign in' : 'Access blocked' ?></span></td>
                            <td><div class="records-user-password-fields"><label><span>New password <small>Optional</small></span><input form="<?= $e($accountFormId) ?>" type="password" name="password" minlength="12" maxlength="128" autocomplete="new-password"></label><label><span>Confirm password</span><input form="<?= $e($accountFormId) ?>" type="password" name="password_confirmation" minlength="12" maxlength="128" autocomplete="new-password"></label></div></td>
                            <td><dl class="records-user-history"><div><dt>Created</dt><dd><time datetime="<?= $e($account->createdAt) ?>"><?= $e($account->createdAt) ?> UTC</time></dd></div><div><dt>Updated</dt><dd><time datetime="<?= $e($account->updatedAt) ?>"><?= $e($account->updatedAt) ?> UTC</time></dd></div></dl></td>
                            <td><button class="button button-dark" type="submit" form="<?= $e($accountFormId) ?>">Save changes</button></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</section>
