<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="en" data-analytics-page-view="disabled">
<head>
    <script async src="https://www.googletagmanager.com/gtag/js?id=G-WVFXFB5H3M"></script>
    <script src="<?= $e($assetBase) ?>/google-tag.js?v=20260825-security-hardening"></script>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $e($pageTitle ?? 'Pickup sheet') ?> | Pickupsheet</title>
    <link rel="stylesheet" href="<?= $e($assetBase) ?>/print.css?v=20260825-print-dialog">
    <script src="<?= $e($assetBase) ?>/print.js?v=20260825-print-dialog" defer></script>
</head>
<body>
    <?= $content ?>
</body>
</html>
