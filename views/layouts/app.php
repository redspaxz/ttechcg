<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$url = static fn (string $path): string => ($basePath ?? '') . ($path === '/' ? '/' : $path);
$activePage = $activePage ?? '';
$pageRobots = $pageRobots ?? 'index, follow';
$analyticsPageView = $activePage === 'pickupsheet' ? 'disabled' : 'enabled';
?>
<!doctype html>
<html lang="en" data-analytics-page-view="<?= $analyticsPageView ?>">
<head>
    <script async src="https://www.googletagmanager.com/gtag/js?id=G-WVFXFB5H3M"></script>
    <script src="<?= $e($assetBase) ?>/google-tag.js?v=20260825-security-hardening"></script>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#080808">
    <meta name="description" content="<?= $e($pageDescription ?? 'T&Tech Consulting Group') ?>">
    <meta name="robots" content="<?= $e($pageRobots) ?>">
    <title><?= $e($pageTitle ?? 'T&Tech Consulting Group') ?> | T&amp;Tech</title>
    <link rel="icon" href="<?= $e($assetBase) ?>/ttechcg-mark.svg" type="image/svg+xml">
    <link rel="stylesheet" href="<?= $e($assetBase) ?>/styles.css?v=20260827-pagination">
    <script src="<?= $e($assetBase) ?>/app.js?v=20260827-pagination" defer></script>
    <script src="<?= $e($assetBase) ?>/analytics.js?v=20260825-security-hardening" defer></script>
</head>
<body>
    <a class="skip-link" href="#main-content">Skip to content</a>
    <header class="site-header" data-site-header>
        <div class="container header-inner">
            <a class="site-logo" href="<?= $e($url('/')) ?>" aria-label="T and Tech Consulting Group home">
                <span class="company-name">T&amp;Tech Consulting Group</span>
            </a>

            <button class="nav-toggle" type="button" aria-expanded="false" aria-controls="site-navigation" data-nav-toggle>
                <span></span><span></span><span></span>
                <span class="sr-only">Toggle navigation</span>
            </button>

            <nav class="site-nav" id="site-navigation" aria-label="Primary navigation" data-navigation>
                <a href="<?= $e($url('/')) ?>" <?= $activePage === 'home' ? 'aria-current="page"' : '' ?>>Home</a>
                <a href="<?= $e($url('/services')) ?>" <?= $activePage === 'services' ? 'aria-current="page"' : '' ?>>Services</a>
                <a href="<?= $e($url('/products')) ?>" <?= $activePage === 'products' ? 'aria-current="page"' : '' ?>>Products</a>
                <a href="<?= $e($url('/about')) ?>" <?= $activePage === 'about' ? 'aria-current="page"' : '' ?>>About</a>
                <a class="nav-cta" href="<?= $e($url('/contact')) ?>" <?= $activePage === 'contact' ? 'aria-current="page"' : '' ?>>Start a project <span aria-hidden="true">↗</span></a>
            </nav>
        </div>
    </header>

    <main id="main-content">
        <?= $content ?>
    </main>

    <footer class="site-footer">
        <div class="container footer-top">
            <div class="footer-statement">
                <span class="eyebrow eyebrow-light">Keep business moving</span>
                <h2>Reliable networks.<br>Practical solutions.</h2>
            </div>
            <a class="circle-link" href="<?= $e($url('/contact')) ?>" aria-label="Start a conversation">
                <span>Let's talk</span><i aria-hidden="true">↗</i>
            </a>
        </div>
        <div class="container footer-bottom">
            <a class="footer-brand" href="<?= $e($url('/')) ?>">T&amp;Tech <span>Consulting Group</span></a>
            <nav aria-label="Footer navigation">
                <a href="<?= $e($url('/services')) ?>">Services</a>
                <a href="<?= $e($url('/products')) ?>">Products</a>
                <a href="<?= $e($url('/about')) ?>">About</a>
                <a href="<?= $e($url('/contact')) ?>">Contact</a>
                <a href="<?= $e($url('/privacy')) ?>">Privacy</a>
                <button class="footer-link-button" type="button" data-analytics-settings>Cookie settings</button>
            </nav>
            <p>© <?= $e(date('Y')) ?> T&amp;Tech Consulting Group</p>
        </div>
    </footer>

    <section class="analytics-consent" data-analytics-consent hidden role="dialog" aria-labelledby="analytics-consent-title" aria-describedby="analytics-consent-description">
        <div>
            <p class="eyebrow" id="analytics-consent-title">Analytics choice</p>
            <p id="analytics-consent-description">May we use Google Analytics cookies to understand site usage and improve this website? Analytics storage and cookies stay off unless you accept.</p>
        </div>
        <div class="analytics-consent-actions">
            <button class="button button-primary" type="button" data-analytics-accept>Accept analytics</button>
            <button class="button button-secondary" type="button" data-analytics-decline>Decline</button>
            <a href="<?= $e($url('/privacy')) ?>">Privacy details</a>
        </div>
    </section>
</body>
</html>
