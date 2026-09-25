<?php

declare(strict_types=1);

namespace App\Modules\Pickupsheet\UI;

/**
 * Server-rendered SVG charts for the printable performance report.
 *
 * Charts are static inline SVG so they print without scripts. Each mark carries a
 * native <title> tooltip, and the report keeps a table view beside every chart.
 */
final class ReportCharts
{
    public const ACCENT = '#2a78d6';
    public const SECOND = '#eb6834';
    public const CONTEXT = '#898781';

    private const WIDTH = 680;
    private const INK = '#0b0b0b';
    private const INK_SECONDARY = '#52514e';
    private const INK_MUTED = '#898781';
    private const GRID = '#e1e0d9';
    private const BASELINE = '#c3c2b7';
    private const SURFACE = '#ffffff';

    /**
     * Ranked horizontal bars for a single series.
     *
     * @param list<array{label: string, value: int|float, display: string}> $rows
     */
    public static function horizontalBars(array $rows, string $description, string $color = self::ACCENT): string
    {
        if ($rows === []) {
            return '';
        }

        $labelWidth = 170;
        $valueWidth = 110;
        $barX = $labelWidth + 10;
        $barMaxWidth = self::WIDTH - $barX - $valueWidth;
        $rowHeight = 24;
        $barHeight = 12;
        $height = count($rows) * $rowHeight + 6;
        $maximum = max(array_map(static fn (array $row): float => (float) $row['value'], $rows));

        $marks = '';
        foreach ($rows as $index => $row) {
            $value = max(0.0, (float) $row['value']);
            $centerY = $index * $rowHeight + 3 + $rowHeight / 2;
            $width = $maximum > 0 ? $value / $maximum * $barMaxWidth : 0.0;
            if ($value > 0 && $width < 2) {
                $width = 2.0;
            }
            $label = self::shorten($row['label'], 30);
            $tooltip = self::e($row['label'] . ': ' . $row['display']);
            $marks .= '<g><title>' . $tooltip . '</title>'
                . '<text x="' . $labelWidth . '" y="' . self::n($centerY + 3.5) . '" text-anchor="end" font-size="10.5" fill="' . self::INK . '">' . self::e($label) . '</text>'
                . self::horizontalBarPath($barX, $centerY - $barHeight / 2, $width, $barHeight, $color)
                . '<text x="' . self::n($barX + $width + 6) . '" y="' . self::n($centerY + 3.5) . '" font-size="10" fill="' . self::INK_SECONDARY . '">' . self::e($row['display']) . '</text>'
                . '</g>';
        }

        return self::svg($height, $description,
            '<line x1="' . $barX . '" y1="0" x2="' . $barX . '" y2="' . $height . '" stroke="' . self::BASELINE . '" stroke-width="1"/>' . $marks);
    }

    /**
     * Columns over time for a single series; labels only the peak.
     *
     * @param list<array{label: string, value: int|float, display: string}> $rows
     */
    public static function columns(array $rows, string $description, bool $integerScale = false, string $color = self::ACCENT): string
    {
        if ($rows === []) {
            return '';
        }

        [$plot, $scaleTop, $axis] = self::verticalAxis($rows, $integerScale);
        $band = $plot['width'] / count($rows);
        $columnWidth = min(24.0, $band * 0.6);
        $peakIndex = self::peakIndex($rows);

        $marks = '';
        foreach ($rows as $index => $row) {
            $value = max(0.0, (float) $row['value']);
            $height = $scaleTop > 0 ? $value / $scaleTop * $plot['height'] : 0.0;
            $centerX = $plot['left'] + $band * $index + $band / 2;
            $x = $centerX - $columnWidth / 2;
            $y = $plot['bottom'] - $height;
            $marks .= '<g><title>' . self::e($row['label'] . ': ' . $row['display']) . '</title>'
                . '<rect x="' . self::n($centerX - $band / 2) . '" y="' . $plot['top'] . '" width="' . self::n($band) . '" height="' . $plot['height'] . '" fill="transparent"/>'
                . self::columnPath($x, $y, $columnWidth, $height, $color);
            if ($index === $peakIndex) {
                $marks .= '<text x="' . self::n($centerX) . '" y="' . self::n($y - 5) . '" text-anchor="middle" font-size="10" font-weight="700" fill="' . self::INK . '">' . self::e($row['display']) . '</text>';
            }
            $marks .= '</g>';
        }

        return self::svg($plot['svgHeight'], $description, $axis . self::monthLabels($rows, $plot, $band) . $marks);
    }

    /**
     * A single-series line with a light area wash, labelling the peak and the latest point.
     *
     * @param list<array{label: string, value: int|float, display: string}> $rows
     */
    public static function line(array $rows, string $description, string $color = self::ACCENT): string
    {
        if ($rows === []) {
            return '';
        }

        [$plot, $scaleTop, $axis] = self::verticalAxis($rows, false);
        $band = $plot['width'] / count($rows);
        $points = [];
        foreach ($rows as $index => $row) {
            $value = max(0.0, (float) $row['value']);
            $points[] = [
                $plot['left'] + $band * $index + $band / 2,
                $plot['bottom'] - ($scaleTop > 0 ? $value / $scaleTop * $plot['height'] : 0.0),
            ];
        }

        $path = '';
        foreach ($points as $index => [$x, $y]) {
            $path .= ($index === 0 ? 'M' : 'L') . self::n($x) . ' ' . self::n($y);
        }
        $first = $points[0];
        $last = $points[count($points) - 1];
        $area = $path . 'L' . self::n($last[0]) . ' ' . $plot['bottom'] . 'L' . self::n($first[0]) . ' ' . $plot['bottom'] . 'Z';

        $marks = '<path d="' . $area . '" fill="' . $color . '" fill-opacity="0.1"/>'
            . '<path d="' . $path . '" fill="none" stroke="' . $color . '" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>';

        $peakIndex = self::peakIndex($rows);
        $lastIndex = count($rows) - 1;
        foreach ($points as $index => [$x, $y]) {
            $marks .= '<g><title>' . self::e($rows[$index]['label'] . ': ' . $rows[$index]['display']) . '</title>'
                . '<circle cx="' . self::n($x) . '" cy="' . self::n($y) . '" r="10" fill="transparent"/>';
            if ($index === $lastIndex || $index === $peakIndex) {
                $marks .= '<circle cx="' . self::n($x) . '" cy="' . self::n($y) . '" r="4" fill="' . $color . '" stroke="' . self::SURFACE . '" stroke-width="2"/>';
                $anchor = $index === $lastIndex ? 'end' : 'middle';
                $labelX = $index === $lastIndex ? $x + 4 : $x;
                $marks .= '<text x="' . self::n($labelX) . '" y="' . self::n($y - 9) . '" text-anchor="' . $anchor . '" font-size="10" font-weight="700" fill="' . self::INK . '">' . self::e($rows[$index]['display']) . '</text>';
            }
            $marks .= '</g>';
        }

        return self::svg($plot['svgHeight'], $description, $axis . self::monthLabels($rows, $plot, $band) . $marks);
    }

    /**
     * One metric as a small multiple: latest period (accent) against the previous period (context gray),
     * each metric on its own scale.
     */
    public static function periodPair(string $metric, int|float $current, int|float $previous, string $currentDisplay, string $previousDisplay): string
    {
        $labelWidth = 62;
        $valueWidth = 96;
        $barX = $labelWidth + 8;
        $barMaxWidth = 330 - $barX - $valueWidth;
        $maximum = max((float) $current, (float) $previous);
        $rows = [
            ['Latest', max(0.0, (float) $current), $currentDisplay, self::ACCENT],
            ['Previous', max(0.0, (float) $previous), $previousDisplay, self::CONTEXT],
        ];

        $marks = '<line x1="' . $barX . '" y1="2" x2="' . $barX . '" y2="50" stroke="' . self::BASELINE . '" stroke-width="1"/>';
        foreach ($rows as $index => [$label, $value, $display, $color]) {
            $centerY = 14 + $index * 24;
            $width = $maximum > 0 ? $value / $maximum * $barMaxWidth : 0.0;
            if ($value > 0 && $width < 2) {
                $width = 2.0;
            }
            $marks .= '<g><title>' . self::e($metric . ', ' . strtolower($label) . ' period: ' . $display) . '</title>'
                . '<text x="' . $labelWidth . '" y="' . self::n($centerY + 3.5) . '" text-anchor="end" font-size="10" fill="' . self::INK_SECONDARY . '">' . $label . '</text>'
                . self::horizontalBarPath($barX, $centerY - 6, $width, 12, $color)
                . '<text x="' . self::n($barX + $width + 6) . '" y="' . self::n($centerY + 3.5) . '" font-size="10" font-weight="' . ($index === 0 ? '700' : '400') . '" fill="' . ($index === 0 ? self::INK : self::INK_SECONDARY) . '">' . self::e($display) . '</text>'
                . '</g>';
        }

        return '<svg class="report-chart" viewBox="0 0 330 54" role="img" aria-label="' . self::e($metric . ': latest ' . $currentDisplay . ', previous ' . $previousDisplay) . '" xmlns="http://www.w3.org/2000/svg" font-family="Arial, Helvetica, sans-serif">' . $marks . '</svg>';
    }

    /**
     * A single 100% stacked bar for part-to-whole with a small number of parts.
     *
     * @param list<array{label: string, value: int|float, display: string, color: string}> $parts
     */
    public static function shareBar(array $parts, string $description): string
    {
        $total = array_sum(array_map(static fn (array $part): float => max(0.0, (float) $part['value']), $parts));
        $height = 22;
        if ($total <= 0) {
            return self::svg($height, $description, '<rect x="0" y="3" width="' . self::WIDTH . '" height="16" fill="' . self::GRID . '"/>');
        }

        $visible = array_values(array_filter($parts, static fn (array $part): bool => (float) $part['value'] > 0));
        $gap = 2;
        $available = self::WIDTH - $gap * (count($visible) - 1);
        $x = 0.0;
        $marks = '';
        foreach ($visible as $index => $part) {
            $width = (float) $part['value'] / $total * $available;
            $isLast = $index === count($visible) - 1;
            $share = number_format((float) $part['value'] / $total * 100, 1) . '%';
            $marks .= '<g><title>' . self::e($part['label'] . ': ' . $part['display'] . ' (' . $share . ')') . '</title>'
                . ($isLast ? self::horizontalBarPath($x, 3, $width, 16, $part['color']) : '<rect x="' . self::n($x) . '" y="3" width="' . self::n($width) . '" height="16" fill="' . $part['color'] . '"/>');
            $labelWidth = strlen($share) * 6.2 + 16;
            if ($width >= $labelWidth) {
                $marks .= '<text x="' . self::n($x + 8) . '" y="14.5" font-size="10" font-weight="700" fill="' . self::labelInkFor($part['color']) . '">' . $share . '</text>';
            }
            $marks .= '</g>';
            $x += $width + $gap;
        }

        return self::svg($height, $description, $marks);
    }

    /** @return list<int|float> */
    public static function niceTicks(float $maximum, bool $integerScale = false, int $targetCount = 4): array
    {
        if ($maximum <= 0) {
            return [0, 1];
        }

        $raw = $maximum / $targetCount;
        $magnitude = 10 ** floor(log10($raw));
        $normalized = $raw / $magnitude;
        $step = match (true) {
            $normalized <= 1 => 1,
            $normalized <= 2 => 2,
            $normalized <= 2.5 => 2.5,
            $normalized <= 5 => 5,
            default => 10,
        } * $magnitude;
        if ($integerScale) {
            $step = max(1, (int) ceil($step));
        }

        $top = ceil($maximum / $step) * $step;
        $ticks = [];
        for ($tick = 0.0; $tick <= $top + $step / 2; $tick += $step) {
            $ticks[] = $integerScale ? (int) round($tick) : round($tick, 6);
        }

        return $ticks;
    }

    public static function compact(int|float $value): string
    {
        $absolute = abs((float) $value);
        foreach ([[1_000_000_000, 'B'], [1_000_000, 'M'], [1_000, 'K']] as [$divisor, $suffix]) {
            if ($absolute >= $divisor) {
                return rtrim(rtrim(number_format($value / $divisor, 1, '.', ''), '0'), '.') . $suffix;
            }
        }

        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    }

    /**
     * @param list<array{label: string, value: int|float, display: string}> $rows
     * @return array{0: array{left: float, top: float, width: float, height: float, bottom: float, svgHeight: int}, 1: float, 2: string}
     */
    private static function verticalAxis(array $rows, bool $integerScale): array
    {
        $maximum = max(array_map(static fn (array $row): float => (float) $row['value'], $rows));
        $ticks = self::niceTicks($maximum, $integerScale);
        $scaleTop = (float) end($ticks);
        $plot = ['left' => 46.0, 'top' => 18.0, 'width' => self::WIDTH - 46.0 - 20.0, 'height' => 140.0];
        $plot['bottom'] = $plot['top'] + $plot['height'];
        $plot['svgHeight'] = (int) ($plot['bottom'] + 22);

        $axis = '';
        foreach ($ticks as $tick) {
            $y = $plot['bottom'] - ($scaleTop > 0 ? $tick / $scaleTop * $plot['height'] : 0);
            $stroke = $tick == 0 ? self::BASELINE : self::GRID;
            $axis .= '<line x1="' . $plot['left'] . '" y1="' . self::n($y) . '" x2="' . ($plot['left'] + $plot['width']) . '" y2="' . self::n($y) . '" stroke="' . $stroke . '" stroke-width="1"/>'
                . '<text x="' . ($plot['left'] - 6) . '" y="' . self::n($y + 3.5) . '" text-anchor="end" font-size="9.5" fill="' . self::INK_MUTED . '" style="font-variant-numeric: tabular-nums">' . self::compact($tick) . '</text>';
        }

        return [$plot, $scaleTop, $axis];
    }

    /**
     * @param list<array{label: string, value: int|float, display: string}> $rows
     * @param array{left: float, bottom: float} $plot
     */
    private static function monthLabels(array $rows, array $plot, float $band): string
    {
        $labels = '';
        foreach ($rows as $index => $row) {
            $short = explode(' ', $row['label'])[0];
            $labels .= '<text x="' . self::n($plot['left'] + $band * $index + $band / 2) . '" y="' . self::n($plot['bottom'] + 15) . '" text-anchor="middle" font-size="9.5" fill="' . self::INK_MUTED . '">' . self::e($short) . '</text>';
        }

        return $labels;
    }

    /** @param list<array{value: int|float}> $rows */
    private static function peakIndex(array $rows): ?int
    {
        $peak = null;
        foreach ($rows as $index => $row) {
            if ((float) $row['value'] > 0 && ($peak === null || (float) $row['value'] > (float) $rows[$peak]['value'])) {
                $peak = $index;
            }
        }

        return $peak;
    }

    private static function horizontalBarPath(float $x, float $y, float $width, float $height, string $color): string
    {
        if ($width <= 0) {
            return '';
        }
        $radius = min(4.0, $width, $height / 2);

        return '<path d="M' . self::n($x) . ' ' . self::n($y)
            . 'h' . self::n($width - $radius)
            . 'a' . self::n($radius) . ' ' . self::n($radius) . ' 0 0 1 ' . self::n($radius) . ' ' . self::n($radius)
            . 'v' . self::n($height - 2 * $radius)
            . 'a' . self::n($radius) . ' ' . self::n($radius) . ' 0 0 1 ' . self::n(-$radius) . ' ' . self::n($radius)
            . 'h' . self::n(-($width - $radius)) . 'Z" fill="' . $color . '"/>';
    }

    private static function columnPath(float $x, float $y, float $width, float $height, string $color): string
    {
        if ($height <= 0) {
            return '';
        }
        $radius = min(4.0, $height, $width / 2);

        return '<path d="M' . self::n($x) . ' ' . self::n($y + $height)
            . 'v' . self::n(-($height - $radius))
            . 'a' . self::n($radius) . ' ' . self::n($radius) . ' 0 0 1 ' . self::n($radius) . ' ' . self::n(-$radius)
            . 'h' . self::n($width - 2 * $radius)
            . 'a' . self::n($radius) . ' ' . self::n($radius) . ' 0 0 1 ' . self::n($radius) . ' ' . self::n($radius)
            . 'v' . self::n($height - $radius) . 'Z" fill="' . $color . '"/>';
    }

    private static function labelInkFor(string $hex): string
    {
        $channels = array_map(static function (string $pair): float {
            $value = hexdec($pair) / 255;
            return $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }, str_split(ltrim($hex, '#'), 2));
        $luminance = 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];

        return (1.05 / ($luminance + 0.05)) >= (($luminance + 0.05) / 0.05) ? '#ffffff' : self::INK;
    }

    private static function svg(int|float $height, string $description, string $content): string
    {
        return '<svg class="report-chart" viewBox="0 0 ' . self::WIDTH . ' ' . self::n($height) . '" role="img" aria-label="' . self::e($description) . '" xmlns="http://www.w3.org/2000/svg" font-family="Arial, Helvetica, sans-serif">' . $content . '</svg>';
    }

    private static function shorten(string $text, int $length): string
    {
        return function_exists('mb_strimwidth') ? mb_strimwidth($text, 0, $length, '…', 'UTF-8') : (strlen($text) > $length ? substr($text, 0, $length - 1) . '…' : $text);
    }

    private static function n(float|int $number): string
    {
        return rtrim(rtrim(number_format((float) $number, 2, '.', ''), '0'), '.') ?: '0';
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
