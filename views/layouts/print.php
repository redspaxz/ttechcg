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
        :root { color-scheme: light; font-family: Arial, Helvetica, sans-serif; background: #5b5b5b; }
        * { box-sizing: border-box; }
        html, body { min-height: 100%; }
        body { margin: 0; background: #5b5b5b; color: #151515; }
        .print-actions { display: flex; justify-content: center; gap: 12px; padding: 18px; }
        .print-actions button,
        .print-actions a {
            padding: 11px 18px;
            border: 1px solid #222;
            background: #fff;
            color: #111;
            font: inherit;
            font-weight: 700;
            text-decoration: none;
            cursor: pointer;
        }
        .print-actions button { background: #111; color: #fff; }
        .print-preview {
            display: grid;
            min-height: calc(100vh - 76px);
            place-items: start center;
            width: 100%;
            padding: 24px 24px 56px;
            overflow: auto;
            background: #5b5b5b;
        }
        .print-sheet {
            display: block;
            width: 210mm;
            min-width: 210mm;
            min-height: 297mm;
            margin: 0;
            padding: 13mm 9mm 12mm;
            border: 1px solid #c9c9c9;
            background: #fff !important;
            color: #000;
            box-shadow: 0 20px 65px rgba(0, 0, 0, 0.34);
            text-transform: uppercase;
        }
        .paper-header {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 16mm;
            align-items: start;
        }
        .paper-identity h1 {
            margin: 0 0 2mm;
            font-size: 12pt;
            line-height: 1;
        }
        .paper-field {
            display: flex;
            align-items: baseline;
            gap: 3mm;
            font-size: 10pt;
            white-space: nowrap;
        }
        .paper-field strong,
        .paper-reference strong { font-weight: 800; }
        .paper-field span {
            display: inline-block;
            min-width: 43mm;
            padding: 0 1mm 0.7mm;
            border-bottom: 0.3mm solid #222;
            font-weight: 700;
        }
        .paper-document-meta { padding-top: 3mm; }
        .paper-document-meta .paper-field span { min-width: 33mm; text-align: center; }
        .paper-reference {
            display: flex;
            justify-content: flex-end;
            gap: 2mm;
            margin-top: 3mm;
            font-size: 6.5pt;
            white-space: nowrap;
        }
        .paper-reference span { letter-spacing: 0.01em; }
        .print-sheet h2 {
            margin: 17mm 0 8mm;
            font-size: 13.5pt;
            font-weight: 500;
            line-height: 1;
        }
        .paper-shipment-table {
            width: 100%;
            border-collapse: collapse;
            border: 1pt solid #000;
            border-spacing: 0;
            outline: 1pt solid #000;
            outline-offset: -1pt;
            table-layout: fixed;
            font-size: 8pt;
        }
        .paper-shipment-table th,
        .paper-shipment-table td {
            height: 6.8mm;
            padding: 1.1mm 1.6mm;
            padding-left: 5px;
            border-width: 1pt;
            border-style: solid;
            border-color: #000;
            background: #fff;
            color: #000;
            overflow: hidden;
            text-align: left !important;
            text-overflow: clip;
            vertical-align: middle;
            white-space: nowrap;
        }
        .paper-shipment-table thead { display: table-header-group; }
        .paper-shipment-table thead th {
            height: 9.5mm;
            background: #fff;
            font-size: 8pt;
            font-weight: 800;
            line-height: 1.15;
            text-align: left !important;
            vertical-align: middle;
        }
        .paper-shipment-table tbody tr { break-inside: avoid; page-break-inside: avoid; }
        .paper-col-consignor { width: 25.5%; }
        .paper-col-awb { width: 16.5%; }
        .paper-col-destination { width: 9.5%; }
        .paper-col-amount { width: 13%; }
        .paper-col-pieces { width: 7%; }
        .paper-col-weight { width: 8%; }
        .paper-col-time { width: 8%; }
        .paper-col-checker { width: 12.5%; }
        .paper-totals {
            display: grid;
            grid-template-columns: 1fr 1.45fr;
            gap: 9mm;
            align-items: end;
            margin: 9mm 4mm 0 17mm;
            font-size: 9pt;
        }
        .paper-totals div { display: flex; align-items: end; gap: 4mm; white-space: nowrap; }
        .paper-totals strong { font-weight: 800; }
        .paper-totals span {
            flex: 1;
            min-width: 22mm;
            padding: 0 2mm 0.7mm;
            border-bottom: 0.3mm solid #222;
            font-size: 10pt;
            text-align: center;
        }
        @page { size: A4 portrait; margin: 10mm; }
        @media (max-width: 850px) {
            .print-preview { place-items: start; padding: 12px 8px 36px; }
        }
        @media print {
            :root,
            html,
            body { background: #fff !important; }
            body { print-color-adjust: exact; -webkit-print-color-adjust: exact; }
            .print-actions { display: none; }
            .print-preview {
                display: block;
                min-height: 0;
                padding: 0;
                overflow: visible;
                background: #fff !important;
            }
            .print-sheet {
                width: 100%;
                min-width: 0;
                min-height: 0;
                margin: 0;
                padding: 0;
                border: 0;
                background: #fff !important;
                box-shadow: none;
            }
            .paper-shipment-table {
                border: 1pt solid #000 !important;
                outline: 1pt solid #000 !important;
            }
            .paper-shipment-table th,
            .paper-shipment-table td {
                border: 1pt solid #000 !important;
                text-align: left !important;
                vertical-align: middle !important;
            }
        }
    </style>
</head>
<body>
    <?= $content ?>
</body>
</html>
