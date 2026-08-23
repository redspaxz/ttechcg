<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$url = static fn (string $path): string => ($basePath ?? '') . ($path === '/' ? '/' : $path);
$activePage = $activePage ?? '';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#001d39">
    <meta name="description" content="<?= $e($pageDescription ?? 'T&Tech Consulting Group') ?>">
    <title><?= $e($pageTitle ?? 'T&Tech Consulting Group') ?> | T&amp;Tech</title>
    <link rel="icon" href="<?= $e($assetBase) ?>/ttechcg-mark.svg" type="image/svg+xml">
    <link rel="stylesheet" href="<?= $e($assetBase) ?>/styles.css">
    <script src="<?= $e($assetBase) ?>/app.js" defer></script>
</head>
<body>
    <a class="skip-link" href="#main-content">Skip to content</a>
    <header class="site-header" data-site-header>
        <div class="container header-inner">
            <a class="site-logo" href="<?= $e($url('/')) ?>" aria-label="T and Tech Consulting Group home">
                <img src="<?= $e($assetBase) ?>/ttechcg-mark.svg" alt="">
                <span><strong>T&amp;Tech</strong><small>Consulting Group</small></span>
            </a>

            <button class="nav-toggle" type="button" aria-expanded="false" aria-controls="site-navigation" data-nav-toggle>
                <span></span><span></span><span></span>
                <span class="sr-only">Toggle navigation</span>
            </button>

            <nav class="site-nav" id="site-navigation" aria-label="Primary navigation" data-navigation>
                <a href="<?= $e($url('/')) ?>" <?= $activePage === 'home' ? 'aria-current="page"' : '' ?>>Home</a>
                <a href="<?= $e($url('/services')) ?>" <?= $activePage === 'services' ? 'aria-current="page"' : '' ?>>Services</a>
                <a href="<?= $e($url('/about')) ?>" <?= $activePage === 'about' ? 'aria-current="page"' : '' ?>>About</a>
                <a href="<?= $e($url('/pickupsheet')) ?>" <?= $activePage === 'pickupsheet' ? 'aria-current="page"' : '' ?>>Pickupsheet</a>
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
                <span class="eyebrow eyebrow-light">Build what matters</span>
                <h2>Useful technology.<br>Measurable progress.</h2>
            </div>
            <a class="circle-link" href="<?= $e($url('/contact')) ?>" aria-label="Start a conversation">
                <span>Let's talk</span><i aria-hidden="true">↗</i>
            </a>
        </div>
        <div class="container footer-bottom">
            <a class="footer-brand" href="<?= $e($url('/')) ?>">T&amp;Tech <span>Consulting Group</span></a>
            <nav aria-label="Footer navigation">
                <a href="<?= $e($url('/services')) ?>">Services</a>
                <a href="<?= $e($url('/about')) ?>">About</a>
                <a href="<?= $e($url('/pickupsheet')) ?>">Pickupsheet</a>
                <a href="<?= $e($url('/contact')) ?>">Contact</a>
            </nav>
            <p>© <?= $e(date('Y')) ?> T&amp;Tech Consulting Group</p>
        </div>
    </footer>
</body>
</html>

