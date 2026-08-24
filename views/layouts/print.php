<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $e($pageTitle ?? 'Pickup sheet') ?> | Pickupsheet</title>
    <style>
        :root { color-scheme: light; font-family: Arial, Helvetica, sans-serif; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #f0f0f0; color: #161616; }
        .print-actions { display: flex; justify-content: center; gap: 12px; padding: 18px; }
        .print-actions button, .print-actions a { padding: 11px 18px; border: 1px solid #222; background: #fff; color: #111; font: inherit; font-weight: 700; text-decoration: none; cursor: pointer; }
        .print-actions button { border-color: #d40511; background: #d40511; color: #fff; }
        .print-sheet { width: min(1120px, calc(100% - 32px)); margin: 0 auto 32px; padding: 32px; background: #fff; }
        .print-heading { display: flex; align-items: flex-start; justify-content: space-between; gap: 30px; margin-bottom: 34px; }
        .print-brand { display: flex; align-items: center; gap: 14px; font-size: 1.25rem; font-weight: 800; }
        .print-brand img { width: 130px; height: auto; }
        .print-reference { text-align: right; }
        .print-reference span, .print-meta span { display: block; margin-bottom: 5px; color: #555; font-size: 0.66rem; font-weight: 800; letter-spacing: 0.08em; text-transform: uppercase; }
        .print-reference strong { font-size: 1rem; }
        .print-meta { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 24px; }
        .print-meta strong { font-size: 1rem; }
        h1 { margin: 0 0 22px; font-size: 1.35rem; text-transform: uppercase; }
        table { width: 100%; border-collapse: collapse; font-size: 0.72rem; }
        th, td { padding: 9px 8px; border: 1px solid #777; text-align: left; }
        thead th { background: #ffcc00; font-size: 0.65rem; text-transform: uppercase; }
        tfoot th { background: #fff7d1; }
        .print-totals { display: flex; justify-content: flex-end; gap: 40px; margin-top: 22px; font-size: 0.82rem; }
        .print-totals strong { font-size: 1rem; }
        @page { size: A4 landscape; margin: 10mm; }
        @media print {
            body { background: #fff; }
            .print-actions { display: none; }
            .print-sheet { width: 100%; margin: 0; padding: 0; }
        }
    </style>
</head>
<body>
    <?= $content ?>
</body>
</html>
