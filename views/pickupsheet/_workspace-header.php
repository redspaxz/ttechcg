<?php

declare(strict_types=1);

/**
 * The one Pickupsheet workspace header. Every page shows the same menu, filtered by role, with the current page marked.
 * Below 900px the links fold behind a Menu button (public/assets/app.js); without JavaScript they stay listed.
 *
 * Set before including: $navTitle, $navCurrent (dashboard|create|submissions|crm|access|settings),
 * $navName, $navRole, and optionally $navUsername and $navClass.
 */

$navEscape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$navRole = (string) ($navRole ?? '');
$navCurrent = (string) ($navCurrent ?? '');
$navBase = (string) ($basePath ?? '');
// Mirrors the role permissions in RecordsPrincipal: viewers create and list, operators add the CRM, administrators manage.
$navItems = [
    'dashboard' => ['Dashboard', '/dhl/pickupsheet/dashboard', $navRole === 'admin'],
    'create' => ['New pickup sheet', '/dhl/pickupsheet/', true],
    'submissions' => ['Submitted sheets', '/dhl/pickupsheet/submissions', true],
    'crm' => ['Customer CRM', '/dhl/pickupsheet/customers', in_array($navRole, ['operator', 'admin'], true)],
    'access' => ['Manage access', '/dhl/pickupsheet/submissions/users', $navRole === 'admin'],
    'settings' => ['User settings', '/dhl/pickupsheet/settings', true],
];
?>
    <div class="container pickup-workspace-header<?= isset($navClass) && $navClass !== '' ? ' ' . $navEscape($navClass) : '' ?>" data-pickup-nav>
        <strong class="pickup-wordmark"><?= $navEscape($navTitle ?? 'Pickupsheet') ?></strong>
        <button class="pickup-nav-toggle" type="button" aria-expanded="false" aria-controls="pickup-nav-menu" data-pickup-nav-toggle hidden><span class="pickup-nav-toggle-icon" aria-hidden="true"><i></i><i></i><i></i></span><span data-pickup-nav-label>Menu</span></button>
        <nav class="pickup-header-links" id="pickup-nav-menu" aria-label="Pickupsheet" data-pickup-nav-menu>
            <span class="pickup-session-user"<?= isset($navUsername) && $navUsername !== '' ? ' title="' . $navEscape($navUsername) . '"' : '' ?>><?= $navEscape($navName ?? '') ?> · <?= $navEscape($navRole) ?></span>
            <?php foreach ($navItems as $navKey => [$navLabel, $navPath, $navAllowed]): ?>
                <?php if ($navAllowed): ?><a class="pickup-back" href="<?= $navEscape($navBase . $navPath) ?>"<?= $navKey === $navCurrent ? ' aria-current="page"' : '' ?>><?= $navEscape($navLabel) ?></a><?php endif; ?>
            <?php endforeach; ?>
            <form class="pickup-sign-out" method="post" action="<?= $navEscape($navBase) ?>/dhl/pickupsheet/logout"><input type="hidden" name="_token" value="<?= $navEscape($csrfToken ?? '') ?>"><button class="pickup-link-button" type="submit">Sign out</button></form>
        </nav>
    </div>
