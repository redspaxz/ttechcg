<?php

declare(strict_types=1);

namespace App\Shared\Spreadsheet;

final class XlsxWriter
{
    /**
     * @param list<string> $headers
     * @param list<list<float|int|string>> $rows
     */
    public function create(array $headers, array $rows, string $totalLabel, int $totalColumn, int $total): string
    {
        $worksheet = $this->worksheet($headers, $rows, $totalLabel, $totalColumn, $total);

        return $this->zip([
            '[Content_Types].xml' => $this->contentTypes(),
            '_rels/.rels' => $this->packageRelationships(),
            'xl/workbook.xml' => $this->workbook(),
            'xl/_rels/workbook.xml.rels' => $this->workbookRelationships(),
            'xl/styles.xml' => $this->styles(),
            'xl/worksheets/sheet1.xml' => $worksheet,
        ]);
    }

    /**
     * @param list<string> $headers
     * @param list<list<float|int|string>> $rows
     */
    private function worksheet(array $headers, array $rows, string $totalLabel, int $totalColumn, int $total): string
    {
        $headerCells = '';
        foreach ($headers as $index => $header) {
            $headerCells .= $this->cell($index + 1, 1, $header, 1);
        }

        $shipmentRows = '';
        foreach ($rows as $rowIndex => $values) {
            $excelRow = $rowIndex + 2;
            $cells = '';
            foreach ($values as $columnIndex => $value) {
                $style = $columnIndex + 1 === $totalColumn ? 2 : 0;
                $cells .= $this->cell($columnIndex + 1, $excelRow, $value, $style);
            }
            $shipmentRows .= '<row r="' . $excelRow . '">' . $cells . '</row>';
        }

        $totalRow = count($rows) + 2;
        $totalLabelCell = $this->cell(1, $totalRow, $totalLabel, 3);
        $totalValueCell = $this->cell($totalColumn, $totalRow, $total, 3);
        $lastColumn = $this->columnName(count($headers));
        $labelEndColumn = $this->columnName(max(1, $totalColumn - 1));

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<dimension ref="A1:' . $lastColumn . $totalRow . '"/>'
            . '<sheetViews><sheetView workbookViewId="0">'
            . '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>'
            . '</sheetView></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="15"/>'
            . '<cols>'
            . '<col min="1" max="1" width="6" customWidth="1"/>'
            . '<col min="2" max="2" width="28" customWidth="1"/>'
            . '<col min="3" max="4" width="16" customWidth="1"/>'
            . '<col min="5" max="5" width="18" customWidth="1"/>'
            . '<col min="6" max="7" width="14" customWidth="1"/>'
            . '<col min="8" max="8" width="18" customWidth="1"/>'
            . '<col min="9" max="9" width="24" customWidth="1"/>'
            . '</cols>'
            . '<sheetData>'
            . '<row r="1">' . $headerCells . '</row>'
            . $shipmentRows
            . '<row r="' . $totalRow . '">' . $totalLabelCell . $totalValueCell . '</row>'
            . '</sheetData>'
            . '<mergeCells count="1"><mergeCell ref="A' . $totalRow . ':' . $labelEndColumn . $totalRow . '"/></mergeCells>'
            . '</worksheet>';
    }

    private function cell(int $column, int $row, float|int|string $value, int $style): string
    {
        $reference = $this->columnName($column) . $row;
        $styleAttribute = $style === 0 ? '' : ' s="' . $style . '"';

        if (is_int($value) || is_float($value)) {
            return '<c r="' . $reference . '"' . $styleAttribute . '><v>'
                . $this->xml((string) $value)
                . '</v></c>';
        }

        return '<c r="' . $reference . '"' . $styleAttribute . ' t="inlineStr"><is><t>'
            . $this->xml($value)
            . '</t></is></c>';
    }

    private function columnName(int $column): string
    {
        $name = '';
        while ($column > 0) {
            $column--;
            $name = chr(65 + ($column % 26)) . $name;
            $column = intdiv($column, 26);
        }

        return $name;
    }

    private function xml(string $value): string
    {
        $value = preg_replace('/[^\x{0009}\x{000A}\x{000D}\x{0020}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $value) ?? '';
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>';
    }

    private function packageRelationships(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private function workbook(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Cash Shipments" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';
    }

    private function workbookRelationships(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';
    }

    private function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2">'
            . '<font><sz val="11"/><name val="Calibri"/><family val="2"/></font>'
            . '<font><b/><sz val="11"/><name val="Calibri"/><family val="2"/></font>'
            . '</fonts>'
            . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="4">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '<xf numFmtId="3" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="3" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1" applyNumberFormat="1"/>'
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    /** @param array<string, string> $files */
    private function zip(array $files): string
    {
        $archive = '';
        $directory = '';
        $offset = 0;
        $entryCount = 0;

        foreach ($files as $filename => $contents) {
            $crc = crc32($contents);
            $size = strlen($contents);
            $filenameLength = strlen($filename);

            $localHeader = pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, 0, 0x21, $crc, $size, $size, $filenameLength, 0);
            $archive .= $localHeader . $filename . $contents;

            $directory .= pack(
                'VvvvvvvVVVvvvvvVV',
                0x02014b50,
                20,
                20,
                0,
                0,
                0,
                0x21,
                $crc,
                $size,
                $size,
                $filenameLength,
                0,
                0,
                0,
                0,
                0,
                $offset,
            ) . $filename;

            $offset = strlen($archive);
            $entryCount++;
        }

        return $archive
            . $directory
            . pack('VvvvvVVv', 0x06054b50, 0, 0, $entryCount, $entryCount, strlen($directory), strlen($archive), 0);
    }
}
