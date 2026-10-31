<?php

declare(strict_types=1);

namespace Baander\Tooling\Website;

final class AacEvidence
{
    private const array PROFILES = ['lc' => [64, 96, 128, 192, 256], 'he' => [32, 48, 64], 'hev2' => [24, 32], 'xhe' => [24, 32, 48, 64, 96]];
    private const array ENCODERS = ['lc' => ['ours', 'ffmpeg', 'fdk', 'libxaac', 'apple'], 'he' => ['ours', 'fdk', 'libxaac', 'apple'], 'hev2' => ['ours', 'fdk', 'libxaac'], 'xhe' => ['ours', 'fdk', 'libxaac']];
    private const array LABELS = ['ours' => 'bAAC', 'ffmpeg' => 'FFmpeg', 'fdk' => 'FDK', 'libxaac' => 'libxaac', 'apple' => 'Apple'];
    private const array SPEED_RATES = ['lc' => 128, 'he' => 48, 'hev2' => 32, 'xhe' => 64];

    /** @return array{quality: array<string, list<array<string, mixed>>>, speed: array<string, array<string, list<array<string, mixed>>>>, lossless: list<array<string, mixed>>} */
    public function load(string $root): array
    {
        $directory = rtrim($root, '/') . '/packages/baander-aac/bench/results/';
        $expected = [];
        foreach (self::PROFILES as $family => $rates) {
            foreach (self::ENCODERS[$family] as $encoder) {
                foreach ($rates as $rate) {
                    if ($family === 'xhe' && $encoder === 'libxaac' && $rate < 64) {
                        continue;
                    }
                    $expected["$family-$encoder-cbr$rate"] = [$family, $encoder, $rate];
                }
            }
        }
        $selected = [];
        foreach ($this->csv($directory . 'quality_summary.csv') as $row) {
            $config = $this->string($row, 'config', 'quality_summary.csv');
            if (!isset($expected[$config])) {
                continue;
            }
            [$family, $encoder, $rate] = $expected[$config];
            $this->identity($row, ['family' => $family, 'encoder' => $encoder, 'mode' => 'cbr'], $config);
            if (isset($selected[$config])) {
                throw new \RuntimeException("Duplicate quality configuration: $config");
            }
            $record = ['config' => $config, 'family' => $family, 'encoder' => $encoder, 'label' => self::LABELS[$encoder], 'mode' => 'cbr'];
            foreach (['nominal', 'items', 'kbps', 'peaq_basic', 'visqol'] as $field) {
                $record[$field] = $this->number($row, $field, $config);
            }
            if ($record['nominal'] !== (float) $rate || $record['items'] !== 10.0 || $record['kbps'] <= 0) {
                throw new \RuntimeException("Invalid quality rate or item count: $config");
            }
            $selected[$config] = $record;
        }
        $this->complete($expected, $selected, 'quality_summary.csv');
        $support = [];
        foreach ($this->csv($directory . 'quality.csv') as $row) {
            $config = $this->string($row, 'config', 'quality.csv');
            if (!isset($expected[$config])) {
                continue;
            }
            [$family, $encoder, $rate] = $expected[$config];
            $this->identity($row, ['family' => $family, 'encoder' => $encoder, 'mode' => 'cbr'], $config);
            $item = $this->string($row, 'item', $config);
            if (isset($support[$config][$item])) {
                throw new \RuntimeException("Duplicate raw quality item: $config / $item");
            }
            foreach (['nominal', 'seconds', 'bytes', 'kbps', 'peaq_basic', 'visqol'] as $field) {
                $row[$field] = $this->number($row, $field, "$config / $item");
            }
            if ($row['nominal'] !== (float) $rate || $row['seconds'] <= 0 || $row['bytes'] <= 0 || $row['kbps'] <= 0) {
                throw new \RuntimeException("Invalid raw quality rate or duration: $config / $item");
            }
            $support[$config][$item] = $row;
        }
        $quality = [];
        $corpus = null;
        foreach ($expected as $config => [$family]) {
            $items = $support[$config] ?? [];
            $names = array_keys($items);
            sort($names);
            if (count($items) !== 10 || ($corpus !== null && $names !== $corpus)) {
                throw new \RuntimeException("Expected the same ten raw quality items: $config");
            }
            $corpus = $names;
            foreach (['kbps', 'peaq_basic', 'visqol'] as $metric) {
                $mean = array_sum(array_column($items, $metric)) / 10;
                if (abs($mean - $selected[$config][$metric]) > 0.00011) {
                    throw new \RuntimeException("Summary does not match raw quality $metric: $config");
                }
            }
            $quality[$family][] = $selected[$config];
        }
        return ['quality' => $quality, 'speed' => $this->speed($directory), 'lossless' => $this->lossless($directory)];
    }

    /** @return array<string, array<string, list<array<string, mixed>>>> */
    private function speed(string $directory): array
    {
        $expected = [];
        foreach (self::SPEED_RATES as $family => $rate) {
            foreach (['encode', 'decode'] as $kind) {
                $codecs = $kind === 'encode' ? self::ENCODERS[$family] : ($family === 'xhe' ? ['ours', 'fdk', 'libxaac'] : self::ENCODERS['lc']);
                foreach ($codecs as $codec) {
                    $expected["$family/$kind/$codec"] = [$family, $kind, $codec, $rate];
                }
            }
        }
        $selected = [];
        foreach ($this->json($directory . 'speed.json') as $row) {
            $kind = $this->string($row, 'kind', 'speed.json');
            if ($kind === 'baseline') {
                continue;
            }
            $family = $this->string($row, 'family', 'speed.json');
            $codec = $this->string($row, 'codec', 'speed.json');
            $key = "$family/$kind/$codec";
            if (!isset($expected[$key])) {
                continue;
            }
            $rate = self::SPEED_RATES[$family];
            $config = $kind === 'encode' ? "$family-$codec-cbr$rate" : "$family-fdk-cbr$rate" . ($family === 'xhe' ? '.loas' : '.aac');
            $this->identity($row, ['config' => $config], $key);
            if (isset($selected[$key])) {
                throw new \RuntimeException("Duplicate speed observation: $key");
            }
            $record = ['kind' => $kind, 'family' => $family, 'codec' => $codec, 'config' => $config, 'label' => self::LABELS[$codec], 'nominal' => $rate];
            foreach (['seconds', 'cpu', 'wall'] as $field) {
                $record[$field] = $this->number($row, $field, $key);
                if ($record[$field] <= 0) {
                    throw new \RuntimeException("Non-positive speed $field: $key");
                }
            }
            if ($kind === 'encode') {
                $record['kbps'] = $this->number($row, 'kbps', $key);
                if ($record['kbps'] <= 0) {
                    throw new \RuntimeException("Non-positive speed bitrate: $key");
                }
            }
            $record['throughput'] = $record['seconds'] / $record['cpu'];
            if (!is_finite($record['throughput'])) {
                throw new \RuntimeException("Non-finite throughput: $key");
            }
            $selected[$key] = $record;
        }
        $this->complete($expected, $selected, 'speed.json');
        $speed = [];
        foreach ($expected as $key => [$family, $kind]) {
            $speed[$family][$kind][] = $selected[$key];
        }
        return $speed;
    }

    /** @return list<array<string, mixed>> */
    private function lossless(string $directory): array
    {
        $labels = ['alac-ffmpeg' => 'FFmpeg ALAC', 'alac-apple-ref' => 'refalac ALAC', 'flac-5' => 'FLAC level 5', 'flac-8' => 'FLAC level 8'];
        $selected = [];
        foreach ($this->json($directory . 'lossless.json') as $row) {
            $codec = $this->string($row, 'codec', 'lossless.json');
            if (!isset($labels[$codec]) || isset($selected[$codec])) {
                throw new \RuntimeException("Unexpected or duplicate lossless codec: $codec");
            }
            $record = ['codec' => $codec, 'label' => $labels[$codec]];
            foreach (['seconds', 'kbps', 'enc_cpu', 'dec_cpu'] as $field) {
                $record[$field] = $this->number($row, $field, $codec);
                if ($record[$field] <= 0) {
                    throw new \RuntimeException("Non-positive lossless $field: $codec");
                }
            }
            if (!isset($row['bit_exact']) || !is_bool($row['bit_exact'])) {
                throw new \RuntimeException("Missing boolean bit_exact: $codec");
            }
            $record['bit_exact'] = $row['bit_exact'];
            $selected[$codec] = $record;
        }
        $this->complete($labels, $selected, 'lossless.json');
        return array_values($selected);
    }

    /** @return list<array<string, mixed>> */
    private function csv(string $path): array
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException("Cannot read bAAC evidence: $path");
        }
        try {
            $header = fgetcsv($handle, 0, ',', '"', '');
            if ($header === false || count(array_unique($header)) !== count($header)) {
                throw new \RuntimeException("Invalid CSV header: $path");
            }
            $rows = [];
            while (($fields = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                if (count($fields) !== count($header)) {
                    throw new \RuntimeException("Invalid CSV row: $path");
                }
                $rows[] = array_combine($header, $fields);
            }
            return $rows;
        } finally {
            fclose($handle);
        }
    }

    /** @return list<array<string, mixed>> */
    private function json(string $path): array
    {
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException("Cannot read bAAC evidence: $path");
        }
        try {
            $rows = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new \RuntimeException("Invalid bAAC evidence JSON: $path", 0, $error);
        }
        if (!is_array($rows) || !array_is_list($rows)) {
            throw new \RuntimeException("Expected JSON record list: $path");
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new \RuntimeException("Expected JSON record: $path");
            }
        }
        return $rows;
    }

    /** @param array<string, mixed> $row */
    private function string(array $row, string $field, string $context): string
    {
        if (!isset($row[$field]) || !is_string($row[$field]) || trim($row[$field]) === '') {
            throw new \RuntimeException("Missing string $field in $context");
        }
        return $row[$field];
    }

    /** @param array<string, mixed> $row */
    private function number(array $row, string $field, string $context): float
    {
        $value = $row[$field] ?? null;
        if ((!is_string($value) && !is_int($value) && !is_float($value)) || !is_numeric($value) || !is_finite((float) $value)) {
            throw new \RuntimeException("Missing or non-finite $field in $context");
        }
        return (float) $value;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, string> $identity
     */
    private function identity(array $row, array $identity, string $context): void
    {
        foreach ($identity as $field => $value) {
            if (($row[$field] ?? null) !== $value) {
                throw new \RuntimeException("Inconsistent $field in $context");
            }
        }
    }

    /**
     * @param array<string, mixed> $expected
     * @param array<string, mixed> $selected
     */
    private function complete(array $expected, array $selected, string $file): void
    {
        $missing = array_diff_key($expected, $selected);
        if ($missing !== []) {
            throw new \RuntimeException("Missing selected bAAC evidence in $file: " . implode(', ', array_keys($missing)));
        }
    }
}
