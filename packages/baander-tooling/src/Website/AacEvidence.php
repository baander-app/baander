<?php

declare(strict_types=1);

namespace Baander\Tooling\Website;

final class AacEvidence
{
    private const array PROFILES = ['lc' => [64, 96, 128, 192, 256], 'he' => [32, 48, 64], 'hev2' => [24, 32], 'xhe' => [24, 32, 48, 64, 96]];
    private const array ENCODERS = ['lc' => ['ours', 'ffmpeg', 'fdk', 'libxaac', 'apple'], 'he' => ['ours', 'fdk', 'libxaac', 'apple'], 'hev2' => ['ours', 'fdk', 'libxaac'], 'xhe' => ['ours', 'fdk', 'libxaac']];
    private const array LABELS = ['ours' => 'Bånder Audio Core', 'ffmpeg' => 'FFmpeg', 'fdk' => 'FDK', 'libxaac' => 'libxaac', 'apple' => 'Apple'];
    private const array SPEED_RATES = ['lc' => 128, 'he' => 48, 'hev2' => 32, 'xhe' => 64];
    private const array LOSSLESS_ENCODERS = ['bac' => 'Bånder Audio Core', 'flac' => 'flac'];
    private const array LOSSLESS_REFERENCES = ['flac-ffmpeg-5' => 'FFmpeg FLAC level 5', 'flac-ffmpeg-8' => 'FFmpeg FLAC level 8', 'alac-ffmpeg' => 'FFmpeg ALAC', 'alac-apple-ref' => 'Apple ALAC (refalac)'];
    /** The harness does not time refalac, so its row has no speed figures whether it was measured or carried. */
    private const string UNTIMED_LOSSLESS = 'alac-apple-ref';

    /** @return array{quality: array<string, list<array<string, mixed>>>, speed: array<string, array<string, list<array<string, mixed>>>>, lossless: array{rows: list<array<string, mixed>>, run: ?array<string, mixed>}, version: string} */
    public function load(string $root): array
    {
        $package = rtrim($root, '/') . '/packages/baander-audio-core/';
        $directory = $package . 'bench/results/';
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
        return ['quality' => $quality, 'speed' => $this->speed($directory), 'lossless' => $this->lossless($directory), 'version' => $this->version($package . 'package.json')];
    }

    private function version(string $path): string
    {
        $version = $this->document($path)['version'] ?? null;
        if (!is_string($version) || !preg_match('/^\d+\.\d+\.\d+$/', $version)) {
            throw new \RuntimeException("Missing package version: $path");
        }
        return $version;
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
        $path = $directory . 'speed.json';
        foreach ($this->records($this->document($path), $path) as $row) {
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

    /**
     * The lossless rows in one order, whatever the file's order: every level of Bånder Audio Core and flac, then the
     * other encoders. A run of the lossless stage also yields what the page states about it; the list of
     * four references that lossless.json held before the stage measured Bånder Audio Core yields rows only.
     *
     * @return array{rows: list<array<string, mixed>>, run: ?array<string, mixed>}
     */
    private function lossless(string $directory): array
    {
        $path = $directory . 'lossless.json';
        $result = $this->document($path);
        if (array_is_list($result)) {
            $references = array_intersect_key(self::losslessLabels(), array_flip(['flac-5', 'flac-8', 'alac-ffmpeg', 'alac-apple-ref']));
            return ['rows' => $this->losslessRows($this->records($result, $path), $references, false), 'run' => null];
        }
        if (($result['schema'] ?? null) !== 2) {
            throw new \RuntimeException("Unsupported lossless result schema: $path");
        }
        return ['rows' => $this->losslessRows($this->records($result['rows'] ?? null, $path), self::losslessLabels(), true), 'run' => $this->losslessRun($result)];
    }

    /** @return array<string, string> */
    private static function losslessLabels(): array
    {
        $labels = [];
        foreach (self::LOSSLESS_ENCODERS as $encoder => $name) {
            foreach (range(0, 8) as $level) {
                $labels["$encoder-$level"] = "$name level $level" . ($encoder === 'bac' && $level === 5 ? ' (default)' : '');
            }
        }
        return $labels + self::LOSSLESS_REFERENCES;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, string> $labels
     * @return list<array<string, mixed>>
     */
    private function losslessRows(array $rows, array $labels, bool $frames): array
    {
        $selected = [];
        foreach ($rows as $row) {
            $codec = $this->string($row, 'codec', 'lossless.json');
            if (!isset($labels[$codec])) {
                throw new \RuntimeException("Unexpected lossless codec: $codec");
            }
            if (isset($selected[$codec])) {
                throw new \RuntimeException("Duplicate lossless codec: $codec");
            }
            $selected[$codec] = $this->losslessRow($row, $codec, $labels[$codec], $frames);
        }
        $this->complete($labels, $selected, 'lossless.json');
        return array_map(static fn(string $codec): array => $selected[$codec], array_keys($labels));
    }

    /**
     * Speeds are audio seconds per CPU second. Frame bytes, the file without its metadata blocks, belong
     * to FLAC streams only, and a carried row names the date of the run it comes from.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function losslessRow(array $row, string $codec, string $label, bool $frames): array
    {
        $seconds = $this->positive($row, 'seconds', $codec);
        $timed = $codec !== self::UNTIMED_LOSSLESS || isset($row['enc_cpu']) || isset($row['dec_cpu']);
        if (!is_bool($row['bit_exact'] ?? null)) {
            throw new \RuntimeException("Missing boolean bit_exact: $codec");
        }
        return [
            'codec' => $codec,
            'label' => $label,
            'kbps' => $this->positive($row, 'kbps', $codec),
            'frame_bytes' => $frames && !str_starts_with($codec, 'alac-') ? (int) $this->positive($row, 'frame_bytes', $codec) : null,
            'encode' => $timed ? $seconds / $this->positive($row, 'enc_cpu', $codec) : null,
            'decode' => $timed ? $seconds / $this->positive($row, 'dec_cpu', $codec) : null,
            'bit_exact' => $row['bit_exact'],
            'carried' => isset($row['carried']) ? $this->day($this->string($row, 'carried', $codec), 'Y-m-d', $codec) : null,
        ];
    }

    /**
     * What the page states about the run: its date, the processor and compiler, the reference versions,
     * how often each timed input repeats the corpus, and whether the run was the first to read the
     * held-out recordings.
     *
     * @param array<array-key, mixed> $result
     * @return array<string, mixed>
     */
    private function losslessRun(array $result): array
    {
        $host = $this->section($result, 'host');
        $tools = $this->section($result, 'tools');
        $heldOut = $result['held_out_run'] ?? null;
        if (!preg_match('/^ffmpeg version (\S+)/', $this->string($tools, 'ffmpeg', 'lossless.json tools'), $ffmpeg)) {
            throw new \RuntimeException('Unrecognized FFmpeg version in lossless.json tools');
        }
        return [
            'date' => $this->day($this->string($result, 'date', 'lossless.json'), DATE_ATOM, 'lossless.json'),
            'processor' => $this->string($host, 'cpu', 'lossless.json host'),
            'compiler' => is_string($host['compiler'] ?? null) ? trim($host['compiler']) : '',
            'flac' => $this->string($tools, 'flac', 'lossless.json tools'),
            'ffmpeg' => 'FFmpeg ' . $ffmpeg[1],
            'repetitions' => (int) $this->positive($this->section($result, 'method'), 'corpus_repetitions', 'lossless.json method'),
            'heldOutFirstRun' => is_array($heldOut) && ($heldOut['touched'] ?? null) === true && ($heldOut['first_run'] ?? null) === true,
        ];
    }

    /**
     * @param array<array-key, mixed> $result
     * @return array<string, mixed>
     */
    private function section(array $result, string $field): array
    {
        $section = $result[$field] ?? null;
        if (!is_array($section) || array_is_list($section)) {
            throw new \RuntimeException("Missing $field in lossless.json");
        }
        return $section;
    }

    /** A date in the given format, as the page writes it; one that does not exist is refused. */
    private function day(string $value, string $format, string $context): string
    {
        $date = \DateTimeImmutable::createFromFormat('!' . $format, $value);
        if ($date === false || $date->format($format) !== $value) {
            throw new \RuntimeException("Invalid date $value in $context");
        }
        return $date->format('j F Y');
    }

    /** @return list<array<string, mixed>> */
    private function csv(string $path): array
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException("Cannot read Bånder Audio Core evidence: $path");
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

    /** @return array<array-key, mixed> */
    private function document(string $path): array
    {
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException("Cannot read Bånder Audio Core evidence: $path");
        }
        try {
            $document = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new \RuntimeException("Invalid Bånder Audio Core evidence JSON: $path", 0, $error);
        }
        if (!is_array($document)) {
            throw new \RuntimeException("Expected a JSON object or list: $path");
        }
        return $document;
    }

    /** @return list<array<string, mixed>> */
    private function records(mixed $rows, string $path): array
    {
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

    /** @param array<string, mixed> $row */
    private function positive(array $row, string $field, string $context): float
    {
        $value = $this->number($row, $field, $context);
        if ($value <= 0) {
            throw new \RuntimeException("Non-positive $field in $context");
        }
        return $value;
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
            throw new \RuntimeException("Missing selected Bånder Audio Core evidence in $file: " . implode(', ', array_keys($missing)));
        }
    }
}
