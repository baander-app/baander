<?php

declare(strict_types=1);

namespace Baander\Tooling\Fixtures\Worker;

use JsonException;
use RuntimeException;

final class Reports
{
    public static function require(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    public static function text(string $path): string
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Cannot read evidence: ' . $path);
        }
        return $contents;
    }

    /** @return array<mixed> */
    public static function json(string $path): array
    {
        $decoded = json_decode(self::text($path), true, flags: JSON_THROW_ON_ERROR);
        self::require(is_array($decoded), 'Expected JSON evidence object or list: ' . $path);
        return $decoded;
    }

    /** @return list<array<string,mixed>> */
    public static function samples(string $path, EvidenceMode $mode = EvidenceMode::Completed): array
    {
        $stream = @fopen($path, 'rb');
        self::require($stream !== false, 'Cannot read evidence: ' . $path);
        $samples = [];
        try {
            while (($line = fgets($stream)) !== false) {
                if (!str_ends_with($line, "\n")) {
                    self::require($mode === EvidenceMode::Live, 'Incomplete final sample in ' . basename($path));
                    break;
                }
                $samples[] = self::record($line, $path);
            }
            self::require(feof($stream), 'Cannot read evidence: ' . $path);
            return $samples;
        } finally {
            fclose($stream);
        }
    }

    /** @return list<array<string,mixed>> */
    public static function records(string $contents, EvidenceMode $mode): array
    {
        $records = [];
        $position = 0;
        while (($end = strpos($contents, "\n", $position)) !== false) {
            $records[] = self::record(substr($contents, $position, $end - $position), 'embedded evidence');
            $position = $end + 1;
        }
        self::require($position === strlen($contents) || $mode === EvidenceMode::Live, 'Incomplete final sample in embedded evidence');
        return $records;
    }

    /** @return array<string,mixed> */
    private static function record(string $line, string $path): array
    {
        try {
            $sample = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('Malformed complete sample in ' . basename($path), previous: $error);
        }
        self::require(is_array($sample) && !array_is_list($sample), 'Malformed sample object in ' . basename($path));
        return $sample;
    }

    /** @return array<string,int> */
    public static function counters(string $raw): array
    {
        $result = [];
        foreach (preg_split('/\r?\n/', trim($raw)) as $line) {
            if ($line === '') {
                continue;
            }
            $fields = preg_split('/\s+/', trim($line));
            self::require(count($fields) === 2, 'Malformed cgroup counter');
            $result[$fields[0]] = self::integer($fields[1]);
        }
        return $result;
    }

    /** @param list<int|float> $values
     * @return array{count:int,medianSeconds:float|int,p99Seconds:float|int,maxSeconds:float|int}
     */
    public static function timings(array $values): array
    {
        self::require($values !== [], 'Missing timing samples');
        sort($values, SORT_NUMERIC);
        $count = count($values);
        $middle = intdiv($count, 2);
        return [
            'count' => $count,
            'medianSeconds' => $count % 2 === 0 ? ($values[$middle - 1] + $values[$middle]) / 2 : $values[$middle],
            'p99Seconds' => $values[max(0, (int) ceil($count * .99) - 1)],
            'maxSeconds' => $values[$count - 1],
        ];
    }

    /** @param list<int|float> $values
     * @return array{count:int,p99Seconds:float|int,maxSeconds:float|int}
     */
    public static function bounds(array $values): array
    {
        $result = self::timings($values);
        unset($result['medianSeconds']);
        return $result;
    }

    /** @param list<array<string,mixed>> $values
     * @return list<array<string,mixed>>
     */
    public static function window(array $values, float $start, float $end): array
    {
        return $values
            |> (static fn(array $samples): array => array_filter(
                $samples,
                static fn(array $sample): bool => $start <= $sample['monotonic'] && $sample['monotonic'] <= $end,
            ))
            |> array_values(...);
    }

    /** @param array<mixed> $report */
    public static function publish(string $path, array $report): void
    {
        \Baander\Tooling\Filesystem\AtomicFile::writeJson($path, $report);
    }

    public static function truth(mixed $value): bool
    {
        return in_array($value, [true, 1, 't', '1'], true);
    }

    public static function integer(mixed $value): int
    {
        if (is_int($value) || is_bool($value)) {
            return (int) $value;
        }
        if (is_float($value)) {
            self::require(is_finite($value), 'Invalid integer evidence');
            return (int) $value;
        }
        self::require(
            is_string($value) && preg_match('/^[+-]?[0-9]+$/D', trim($value)) === 1,
            'Invalid integer evidence',
        );
        return (int) trim($value);
    }

    public static function number(mixed $value): float
    {
        self::require(
            is_int($value) || is_float($value) || (is_string($value) && is_numeric(trim($value))),
            'Invalid numeric evidence',
        );
        return (float) $value;
    }

    /** @param array<string,mixed> $actual
     * @param array<string,mixed> $identity
     */
    public static function identity(array $actual, array $identity): bool
    {
        return array_all(
            ['namespace', 'bootId', 'epoch'],
            static fn(string $key): bool => ($actual[$key] ?? null) === $identity[$key],
        );
    }
}
