<?php

declare(strict_types=1);

/** Bounded, test-only observations inside the disposable execution container. */
final class MediaControlObservation
{
    public const LOG = '/var/www/html/storage/two-command/native-launches.jsonl';
    private const MAX_BYTES = 4_194_304;
    private const MAX_RECORDS = 1024;

    /**
     * @param list<string> $arguments
     * @return array{jobId:string,generation:int,outputDirectory:string}|null
     */
    public static function outputScope(array $arguments): ?array
    {
        // Source generation, decoding and subtitle extraction are not video encoder attempts.
        if (!in_array('hls', $arguments, true)) {
            return null;
        }
        foreach ($arguments as $argument) {
            if (preg_match('~^(/[^\x00]*/job-([a-f0-9-]{36})/generation-([1-9][0-9]*))/stream\.m3u8$~D', $argument, $match) === 1) {
                return ['jobId' => $match[2], 'generation' => (int) $match[3], 'outputDirectory' => $match[1]];
            }
        }
        return null;
    }

    /** @return array{pid:int,startTicks:string,pidNamespace:string,hostname:string}|null */
    private static function identity(int $pid): ?array
    {
        $stat = @file_get_contents('/proc/' . $pid . '/stat');
        if ($stat === false) {
            if (is_dir('/proc/' . $pid)) {
                throw new RuntimeException('Native process stat is unreadable.');
            }
            return null;
        }
        $close = strrpos($stat, ')');
        $fields = $close === false ? [] : explode(' ', trim(substr($stat, $close + 1)));
        $namespace = @readlink('/proc/' . $pid . '/ns/pid');
        if (!isset($fields[19]) || !ctype_digit($fields[19]) || $namespace === false) {
            throw new RuntimeException('Native process identity could not be observed.');
        }
        return ['pid' => $pid, 'startTicks' => $fields[19], 'pidNamespace' => $namespace, 'hostname' => (string) gethostname()];
    }

    /** @param array{pid:int,startTicks:string,pidNamespace:string,hostname:string} $identity */
    public static function identityAlive(array $identity): bool
    {
        $current = self::identity((int) $identity['pid']);
        return $current !== null && $current === $identity;
    }

    /** @return list<array{pid:int,startTicks:string,pidNamespace:string,hostname:string}> */
    public static function snapshot(string $jobId): array
    {
        $paths = glob('/proc/[0-9]*/cmdline');
        if ($paths === false || count($paths) > 4096) {
            throw new RuntimeException('Native process observation exceeded its bounded process inventory.');
        }
        $found = [];
        foreach ($paths as $path) {
            $raw = @file_get_contents($path, false, null, 0, 65_537);
            if ($raw === false) {
                if (is_dir(dirname($path))) {
                    throw new RuntimeException('Native process arguments are unreadable.');
                }
                continue; // The process may exit while /proc is being enumerated.
            }
            if ($raw === '') {
                continue;
            }
            if (strlen($raw) > 65_536) {
                throw new RuntimeException('Native process arguments exceeded the observation bound.');
            }
            $arguments = explode("\0", rtrim($raw, "\0"));
            $executable = basename($arguments[0]);
            $isWrapper = in_array($arguments[1] ?? '', ['/usr/bin/ffmpeg', __DIR__ . '/media-control-ffmpeg.php'], true);
            if ($executable !== 'ffmpeg' && !$isWrapper) {
                continue;
            }
            $scope = self::outputScope($arguments);
            if ($scope === null || $scope['jobId'] !== $jobId) {
                continue;
            }
            $identity = self::identity((int) basename(dirname($path)));
            if ($identity !== null) {
                $found[] = $identity;
            }
        }
        return $found;
    }

    /** @param list<string> $arguments */
    public static function recordLaunch(array $arguments): void
    {
        $scope = self::outputScope($arguments);
        if ($scope === null) {
            return;
        }
        $handle = self::openLog();
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('Native launch observation lock failed.');
            }
            $stat = fstat($handle);
            if ($stat === false || $stat['size'] > self::MAX_BYTES) {
                throw new RuntimeException('Native launch evidence exceeded its bounded size.');
            }
            $identity = self::identity(getmypid()) ?? throw new RuntimeException('Native launch identity unavailable.');
            $predecessors = array_values(array_filter(self::snapshot($scope['jobId']), static fn (array $other): bool => $other !== $identity));
            $record = $scope + ['monotonic' => hrtime(true) / 1e9, 'time' => microtime(true), 'identity' => $identity, 'argv' => $arguments, 'predecessors' => $predecessors];
            $line = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
            if (strlen($line) > 65_536 || fwrite($handle, $line) !== strlen($line) || !fflush($handle)) {
                throw new RuntimeException('Native launch evidence could not be persisted.');
            }
        } finally {
            fclose($handle);
        }
    }

    /** @return resource */
    private static function openLog()
    {
        $mask = umask(0077);
        try {
            $handle = @fopen(self::LOG, 'a+b');
        } finally {
            umask($mask);
        }
        if ($handle === false || !chmod(self::LOG, 0600)) {
            throw new RuntimeException('Native launch evidence file is unavailable.');
        }
        return $handle;
    }

    /** @return list<array<string,mixed>> */
    public static function records(): array
    {
        $handle = @fopen(self::LOG, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Native launch evidence is missing.');
        }
        try {
            if (!flock($handle, LOCK_SH)) {
                throw new RuntimeException('Native launch evidence read lock failed.');
            }
            $raw = stream_get_contents($handle, self::MAX_BYTES + 1);
            if ($raw === false || strlen($raw) > self::MAX_BYTES || ($raw !== '' && !str_ends_with($raw, "\n"))) {
                throw new RuntimeException('Native launch evidence is truncated or exceeds its bound.');
            }
            $lines = $raw === '' ? [] : explode("\n", rtrim($raw, "\n"));
            if (count($lines) > self::MAX_RECORDS) {
                throw new RuntimeException('Native launch evidence exceeded its bounded record count.');
            }
            return array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), $lines);
        } finally {
            fclose($handle);
        }
    }

    /** @return list<array<string,mixed>> */
    public static function launchesForJob(string $jobId): array
    {
        return array_values(array_filter(self::records(), static fn (array $record): bool => $record['jobId'] === $jobId));
    }

    /** @param list<array<string,mixed>>|null $records */
    public static function assertNoOverlap(?array $records = null): void
    {
        $attempts = [];
        foreach ($records ?? self::records() as $record) {
            if (!isset($record['identity'], $record['jobId'], $record['generation'], $record['outputDirectory'], $record['predecessors']) || !is_array($record['predecessors'])) {
                throw new RuntimeException('Native launch evidence has an invalid record.');
            }
            $key = $record['jobId'] . ':' . $record['generation'];
            if (isset($attempts[$key])) {
                throw new RuntimeException('Duplicate native encoder launch for job generation ' . $key);
            }
            $attempts[$key] = true;
            if ($record['predecessors'] !== []) {
                throw new RuntimeException('Overlapping native encoder launch for job ' . $record['jobId']);
            }
        }
    }

    /** @return array{totalVideoLaunches:int,byJob:array<string,array<int,mixed>>,overlappingLaunches:int,duplicateGenerations:int} */
    public static function verify(): array
    {
        $records = self::records();
        self::assertNoOverlap($records);
        if ($records === []) {
            throw new RuntimeException('Native qualification recorded no encoder launches.');
        }
        $byJob = [];
        foreach ($records as $record) {
            $byJob[$record['jobId']][$record['generation']] = $record['identity'];
        }
        return ['totalVideoLaunches' => count($records), 'byJob' => $byJob, 'overlappingLaunches' => 0, 'duplicateGenerations' => 0];
    }

    public static function monitor(string $path, int $seconds): void
    {
        if ($seconds < 1 || $seconds > 600) {
            throw new RuntimeException('Native observation duration must be within 1..600 seconds.');
        }
        fclose(self::openLog());
        $deadline = hrtime(true) + $seconds * 1_000_000_000;
        while (hrtime(true) < $deadline) {
            $records = self::records();
            self::assertNoOverlap($records);
            $alive = [];
            foreach ($records as $record) {
                if (self::identityAlive($record['identity'])) {
                    $alive[] = $record['identity'];
                }
            }
            $json = json_encode(['monotonic' => hrtime(true) / 1e9, 'alive' => $alive], JSON_THROW_ON_ERROR);
            if (file_put_contents($path . '.part', $json) !== strlen($json) || !chmod($path . '.part', 0644) || !rename($path . '.part', $path)) {
                throw new RuntimeException('Native observation snapshot could not be published.');
            }
            usleep(50_000);
        }
    }
}

if (isset($argv) && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $mode = $argv[1] ?? '';
    $result = match ($mode) {
        'verify' => MediaControlObservation::verify(),
        'snapshot' => MediaControlObservation::snapshot($argv[2] ?? throw new RuntimeException('Missing job identity.')),
        'alive' => MediaControlObservation::identityAlive(json_decode(base64_decode($argv[2] ?? '', true) ?: '', true, flags: JSON_THROW_ON_ERROR)),
        'monitor' => MediaControlObservation::monitor($argv[2] ?? '', (int) ($argv[3] ?? 180)),
        default => throw new RuntimeException('Unknown native observation mode.'),
    };
    echo json_encode($result, JSON_THROW_ON_ERROR) . "\n";
}
