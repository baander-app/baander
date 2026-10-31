<?php

declare(strict_types=1);

namespace Baander\Tooling\Website;

final class AacCharts
{
    private const array TITLES = ['lc' => 'AAC-LC', 'he' => 'HE-AAC v1', 'hev2' => 'HE-AAC v2', 'xhe' => 'xHE-AAC'];
    private const array VENDORS = ['ours' => 0, 'ffmpeg' => 1, 'fdk' => 2, 'libxaac' => 3, 'apple' => 4];
    private const array DASHES = ['', '8 4', '3 3', '10 3 2 3', '2 5'];

    /**
     * @param array<string, mixed> $evidence
     * @return array<string, mixed>
     */
    public function render(array $evidence): array
    {
        $result = ['quality' => [], 'speed' => [], 'lossless' => $evidence['lossless']];
        foreach (self::TITLES as $family => $title) {
            $result['quality'][$family] = ['title' => $title, 'charts' => []];
            foreach (['peaq_basic' => 'PEAQ Basic', 'visqol' => 'ViSQOL'] as $metric => $label) {
                $id = "baac-$family-$metric";
                $rows = $evidence['quality'][$family];
                $chartTitle = "$title: $label against measured bitrate";
                $result['quality'][$family]['charts'][] = ['id' => $id, 'title' => $chartTitle, 'svg' => $this->quality($id, $chartTitle, $rows, $metric, $label), 'rows' => $rows, 'metric' => $metric, 'unit' => 'score'];
            }
            $result['speed'][$family] = ['title' => $title, 'charts' => []];
            foreach (['encode' => 'Encode', 'decode' => 'Decode'] as $kind => $label) {
                $id = "baac-$family-$kind";
                $rows = $evidence['speed'][$family][$kind];
                $chartTitle = "$title: $label throughput";
                $result['speed'][$family]['charts'][] = ['id' => $id, 'title' => $chartTitle, 'svg' => $this->speed($id, $chartTitle, $rows), 'rows' => $rows, 'metric' => 'throughput', 'unit' => 'audio seconds / CPU second'];
            }
        }
        return $result;
    }

    /** @param list<array<string, mixed>> $rows */
    private function quality(string $id, string $title, array $rows, string $metric, string $label): string
    {
        $xs = array_column($rows, 'kbps');
        $ys = array_column($rows, $metric);
        $xMin = max(0, min($xs) * 0.9);
        $xMax = max($xs) * 1.05;
        $minimum = min($ys);
        $maximum = max($ys);
        $padding = max(0.15, ($maximum - $minimum) * 0.1);
        $yMin = $minimum - $padding;
        $yMax = $maximum + $padding;
        $svg = $this->start($id, $title, "$label score, higher is better. Mean measured file rate in kbit/s, including framing. Ten music excerpts. Each point is available in the adjacent data table.");
        $svg .= $this->axes($xMin, $xMax, $yMin, $yMax, 'Measured bitrate (kbit/s)', "$label score · higher is better");
        $series = [];
        foreach ($rows as $row) {
            $series[$row['encoder']][] = $row;
        }
        foreach (array_values($series) as $index => $points) {
            $vendor = $points[0]['encoder'];
            $styleIndex = self::VENDORS[$vendor];
            usort($points, static fn(array $a, array $b): int => $a['kbps'] <=> $b['kbps']);
            $coordinates = [];
            foreach ($points as $point) {
                $coordinates[] = $this->format($this->x($point['kbps'], $xMin, $xMax)) . ',' . $this->format($this->y($point[$metric], $yMin, $yMax));
            }
            $svg .= '<g class="baac-series baac-series-' . $vendor . '" data-codec="' . $vendor . '" style="color:var(--baac-series-' . $vendor . ',currentColor)">';
            $svg .= '<polyline fill="none" stroke="currentColor" stroke-width="2" stroke-dasharray="' . self::DASHES[$styleIndex] . '" points="' . implode(' ', $coordinates) . '"/>';
            foreach ($points as $point) {
                $x = $this->x($point['kbps'], $xMin, $xMax);
                $y = $this->y($point[$metric], $yMin, $yMax);
                $description = $point['label'] . ', nominal ' . $point['nominal'] . ' kbit/s, measured ' . $point['kbps'] . ' kbit/s, ' . $label . ' ' . $point[$metric];
                $svg .= '<g class="baac-point" data-config="' . $this->escape($point['config']) . '" data-value="' . $this->escape((string) $point[$metric]) . '"><title>' . $this->escape($description) . '</title>' . $this->marker($styleIndex, $x, $y) . '</g>';
            }
            $legendX = 78 + ($index % 3) * 204;
            $legendY = 365 + intdiv($index, 3) * 23;
            $svg .= '<line x1="' . $legendX . '" x2="' . ($legendX + 25) . '" y1="' . $legendY . '" y2="' . $legendY . '" stroke="currentColor" stroke-width="2" stroke-dasharray="' . self::DASHES[$styleIndex] . '"/>' . $this->marker($styleIndex, $legendX + 12, $legendY);
            $svg .= '<text x="' . ($legendX + 33) . '" y="' . ($legendY + 4) . '" fill="currentColor">' . $this->escape($points[0]['label']) . '</text></g>';
        }
        return $svg . '</svg>';
    }

    /** @param list<array<string, mixed>> $rows */
    private function speed(string $id, string $title, array $rows): string
    {
        $maximum = max(array_column($rows, 'throughput')) * 1.1;
        $svg = $this->start($id, $title, 'Audio seconds divided by CPU seconds, higher is better. Bars start at zero. Recorded single-threaded 130.3173125-second music workload. Each observation is available in the adjacent data table.');
        for ($i = 0; $i <= 4; ++$i) {
            $value = $maximum * $i / 4;
            $x = 155 + 510 * $i / 4;
            $svg .= '<line class="baac-grid" x1="' . $this->format($x) . '" x2="' . $this->format($x) . '" y1="35" y2="310" stroke="currentColor" opacity="0.15"/>';
            $svg .= '<text x="' . $this->format($x) . '" y="332" text-anchor="middle" fill="currentColor">' . $this->format($value) . '</text>';
        }
        foreach ($rows as $index => $row) {
            $vendor = $row['codec'];
            $y = 43 + $index * 52;
            $width = $row['throughput'] / $maximum * 510;
            $description = $row['label'] . ': ' . $row['throughput'] . ' audio seconds / CPU second';
            $svg .= '<text x="142" y="' . ($y + 20) . '" text-anchor="end" fill="currentColor">' . $this->escape($row['label']) . '</text>';
            $svg .= '<rect class="baac-bar baac-series-' . $vendor . '" x="155" y="' . $y . '" width="' . $this->format($width) . '" height="28" fill="var(--baac-series-' . $vendor . ',currentColor)" data-config="' . $this->escape($row['config']) . '" data-codec="' . $this->escape($row['codec']) . '" data-value="' . $this->escape((string) $row['throughput']) . '"><title>' . $this->escape($description) . '</title></rect>';
            $svg .= '<text x="' . $this->format(163 + $width) . '" y="' . ($y + 20) . '" fill="currentColor">' . $this->format($row['throughput']) . '</text>';
        }
        $svg .= '<line class="baac-axis" x1="155" x2="155" y1="35" y2="310" stroke="currentColor"/>';
        $svg .= '<text x="410" y="369" text-anchor="middle" fill="currentColor">Audio seconds / CPU second · higher is better</text>';
        return $svg . '</svg>';
    }

    private function axes(float $xMin, float $xMax, float $yMin, float $yMax, string $xLabel, string $yLabel): string
    {
        $svg = '';
        for ($i = 0; $i <= 4; ++$i) {
            $xValue = $xMin + ($xMax - $xMin) * $i / 4;
            $yValue = $yMin + ($yMax - $yMin) * $i / 4;
            $x = $this->format($this->x($xValue, $xMin, $xMax));
            $y = $this->format($this->y($yValue, $yMin, $yMax));
            $svg .= '<line class="baac-grid" x1="78" x2="680" y1="' . $y . '" y2="' . $y . '" stroke="currentColor" opacity="0.15"/>';
            $svg .= '<text x="66" y="' . $this->format((float) $y + 4) . '" text-anchor="end" fill="currentColor">' . $this->format($yValue) . '</text>';
            $svg .= '<text x="' . $x . '" y="318" text-anchor="middle" fill="currentColor">' . $this->format($xValue) . '</text>';
        }
        return $svg . '<path class="baac-axis" d="M78 35V298H680" fill="none" stroke="currentColor"/><text x="380" y="343" text-anchor="middle" fill="currentColor">' . $this->escape($xLabel) . '</text><text x="78" y="21" fill="currentColor">' . $this->escape($yLabel) . '</text>';
    }

    private function start(string $id, string $title, string $description): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" class="baac-chart" viewBox="0 0 720 410" role="img" aria-labelledby="' . $id . '-title ' . $id . '-description" style="font-family:inherit;font-size:13px"><title id="' . $id . '-title">' . $this->escape($title) . '</title><desc id="' . $id . '-description">' . $this->escape($description) . '</desc>';
    }

    private function marker(int $index, float $x, float $y): string
    {
        $x = round($x, 4);
        $y = round($y, 4);
        return match ($index) {
            0 => '<circle cx="' . $x . '" cy="' . $y . '" r="4" fill="currentColor"/>',
            1 => '<rect x="' . ($x - 4) . '" y="' . ($y - 4) . '" width="8" height="8" fill="currentColor"/>',
            2 => '<path d="M' . $x . ' ' . ($y - 5) . 'l5 9h-10z" fill="currentColor"/>',
            3 => '<path d="M' . $x . ' ' . ($y - 5) . 'l5 5-5 5-5-5z" fill="currentColor"/>',
            default => '<path d="M' . ($x - 4) . ' ' . ($y - 4) . 'l8 8m0-8-8 8" fill="none" stroke="currentColor" stroke-width="2"/>',
        };
    }

    private function x(float $value, float $minimum, float $maximum): float
    {
        return 78 + ($value - $minimum) / ($maximum - $minimum) * 602;
    }

    private function y(float $value, float $minimum, float $maximum): float
    {
        return 298 - ($value - $minimum) / ($maximum - $minimum) * 263;
    }

    private function format(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
