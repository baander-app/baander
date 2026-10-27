<?php

declare(strict_types=1);

namespace Baander\Tooling\Fixtures\Worker;

use Baander\Tooling\Development\Docker;
use Baander\Tooling\Fixtures\Runtime;
use RuntimeException;

final class PostgresSampler
{
    private const array CGROUP = [
        'memory.current',
        'memory.events',
        'memory.stat',
        'memory.peak',
        'memory.max',
        'pids.current',
    ];
    private const array STATUS = [
        'Name',
        'State',
        'PPid',
        'Uid',
        'VmRSS',
        'RssAnon',
        'RssFile',
        'RssShmem',
        'VmSize',
    ];
    private const array ROLLUP = [
        'Rss',
        'Pss',
        'Pss_Anon',
        'Pss_File',
        'Pss_Shmem',
        'Private_Clean',
        'Private_Dirty',
        'Shared_Clean',
        'Shared_Dirty',
        'Swap',
    ];
    /** @var array<string,int> */
    private array $previous = [];
    private bool $contextRequested = false;

    public function __construct(
        private readonly Docker $docker,
        private readonly string $container,
        private readonly string $database,
    ) {}

    /** @return array<string,string> */
    public static function fields(?string $raw): array
    {
        $fields = [];
        foreach (explode("\n", $raw ?? '') as $line) {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $fields[$name] = trim($value);
            }
        }
        return $fields;
    }

    /** @return array<string,string|null> */
    public static function decode(string $output): array
    {
        $records = [];
        foreach (explode("\n.\n", $output) as $record) {
            if ($record === '') {
                continue;
            }
            $position = strpos($record, "\n");
            if ($position === false || !str_starts_with($record, '/')) {
                throw new RuntimeException('Malformed PostgreSQL namespace sample');
            }
            $path = substr($record, 0, $position);
            $encoded = substr($record, $position + 1);
            $decoded = base64_decode($encoded, true);
            if ($decoded === false) {
                throw new RuntimeException('Malformed PostgreSQL namespace sample encoding');
            }
            $records[$path] = $decoded;
        }
        return $records;
    }

    /** @param array<string,string|null> $records
     * @return array<string,mixed>
     */
    public function sample(array $records, float $monotonic): array
    {
        $sample = ['monotonic' => $monotonic, 'cgroup' => [], 'processes' => []];
        foreach (self::CGROUP as $name) {
            $sample['cgroup'][$name] = $records['/sys/fs/cgroup/' . $name] ?? null;
        }
        $growth = [];
        foreach ($records as $path => $raw) {
            if (preg_match('~^/proc/([0-9]+)/status$~D', $path, $match) !== 1) {
                continue;
            }
            $status = self::fields($raw);
            if (($status['Name'] ?? '') !== 'postgres') {
                continue;
            }
            $directory = '/proc/' . $match[1];
            $stat = $records[$directory . '/stat'] ?? '';
            $position = strrpos($stat, ') ');
            if ($position === false) {
                continue;
            }
            $suffix = preg_split('/\s+/', substr($stat, $position + 2)
                |> trim(...)) ?: [];
            if (count($suffix) < 20) {
                continue;
            }
            $pid = (int) $match[1];
            $startTicks = $suffix[19];
            $identity = $pid . ':' . $startTicks;
            $rollup = self::fields($records[$directory . '/smaps_rollup'] ?? null);
            $subset = static function (array $source, array $names): array {
                $selected = [];
                foreach ($names as $name) {
                    $selected[$name] = $source[$name] ?? null;
                }
                return $selected;
            };
            $sample['processes'][] = [
                'pid' => $pid,
                'startTicks' => $startTicks,
                'cmdline' => str_replace("\0", ' ', $records[$directory . '/cmdline'] ?? '')
                    |> trim(...),
                'status' => $subset($status, self::STATUS),
                'smapsRollup' => $subset($rollup, self::ROLLUP),
            ];
            $pss = preg_split('/\s+/', trim($rollup['Pss'] ?? '0 kB'))
                |> (static fn(array $parts): int => Reports::integer($parts[0]));
            $growth[] = [$pss - ($this->previous[$identity] ?? $pss), $pss, $pid];
            $this->previous[$identity] = $pss;
        }
        $current = $sample['cgroup']['memory.current'];
        if (!$this->contextRequested && $current !== null && Reports::integer($current) >= 1536 * 1024 * 1024 && $growth !== []) {
            $this->contextRequested = true;
            rsort($growth);
            $target = $growth[0][2];
            try {
                $result = $this->docker->run(
                    [
                        'exec',
                        '--user',
                        'postgres',
                        $this->container,
                        'psql',
                        '-X',
                        '-w',
                        '-U',
                        'baander',
                        '-d',
                        $this->database,
                        '-At',
                        '-c',
                        'SELECT pg_log_backend_memory_contexts(' . $target . ')',
                    ],
                    timeout: 2,
                    check: false,
                );
                $sample['memoryContextRequest'] = [
                    'pid' => $target,
                    'exitCode' => $result->exitCode,
                    'stdout' => substr($result->stdout, 0, 1024),
                    'stderr' => substr($result->stderr, 0, 1024),
                ];
            } catch (\Throwable $error) {
                $sample['memoryContextRequest'] = ['pid' => $target, 'error' => $error::class];
            }
        }
        return $sample;
    }

    /** @param array<string,mixed> $sample */
    public static function validateReady(array $sample): void
    {
        Runtime::require(
            is_array($sample['processes'] ?? null) && $sample['processes'] !== [],
            'PostgreSQL namespace sample has no postgres process identity',
        );
        foreach (['memory.current', 'memory.events', 'memory.stat', 'memory.max', 'pids.current'] as $name) {
            Runtime::require(
                is_string($sample['cgroup'][$name] ?? null) && trim($sample['cgroup'][$name]) !== '',
                'Missing PostgreSQL namespace metric: ' . $name,
            );
        }
        Runtime::require(
            Reports::integer($sample['cgroup']['pids.current']) > 0 && Reports::integer($sample['cgroup']['memory.current']) >= 0,
            'Invalid PostgreSQL namespace cgroup measurement',
        );
    }

    /** @param list<string> $arguments */
    public static function main(array $arguments, string $root): int
    {
        if (count($arguments) < 2 || count($arguments) > 5) {
            throw new \InvalidArgumentException('Expected disposable PostgreSQL container daemon-clock PHP container [seconds] [database] [ready-file]');
        }
        [$container, $clock, $seconds, $database, $ready] = array_pad($arguments, 5, null);
        $seconds = $seconds === null ? 180.0 : min(180.0, Reports::number($seconds));
        Runtime::require(is_finite($seconds) && $seconds > 0, 'PostgreSQL sampler duration must be positive');
        $database ??= 'worker_operator_test';
        $runtime = new Runtime($root, $seconds + 20);
        try {
            $docker = $runtime->docker(true);
            $units = json_decode($docker->run(['inspect', $container])->stdout, true, flags: JSON_THROW_ON_ERROR);
            Runtime::require(
                count($units) === 1 && ($units[0]['State']['Running'] ?? false) === true,
                'PostgreSQL sampler requires one running disposable container',
            );
            $identity = $units[0]['Id'];
            $name = ltrim($units[0]['Name'], '/');
            Runtime::require(
                preg_match('/^[a-f0-9]{64}$/D', $identity) === 1 && str_starts_with(
                    $name,
                    'baander-',
                ) && str_ends_with(
                    $name,
                    '-postgres',
                ),
                'PostgreSQL sampler target is not the intended disposable database',
            );
            $clocks = json_decode($docker->run(['inspect', $clock])->stdout, true, flags: JSON_THROW_ON_ERROR);
            Runtime::require(
                count($clocks) === 1 && ($clocks[0]['State']['Running'] ?? false) === true
                && str_starts_with(ltrim($clocks[0]['Name'], '/'), 'baander-')
                && preg_match('/^[a-f0-9]{64}$/D', $clocks[0]['Id']) === 1,
                'Sampler daemon-clock container is not disposable and running',
            );
            $clockId = $clocks[0]['Id'];
            $sampler = new self($docker, $identity, $database);
            $stop = Runtime::now() + $seconds;
            $readyWritten = false;
            while (Runtime::now() < $stop) {
                $cycle = Runtime::now();
                $collect = $runtime->start(
                    [
                        ...$docker->commandPrefix(),
                        'exec',
                        '--user',
                        'postgres',
                        $identity,
                        'sh',
                        '-ec',
                        self::COLLECT,
                    ],
                    timeout: 5,
                );
                $timestamp = $runtime->start(
                    [
                        ...$docker->commandPrefix(),
                        'exec',
                        $clockId,
                        'php',
                        '-r',
                        'echo hrtime(true) / 1e9;',
                    ],
                    timeout: 5,
                );
                $result = $runtime->wait($collect);
                $stamp = $runtime->wait($timestamp);
                $sample = $sampler->sample(self::decode($result->stdout), Reports::number($stamp->stdout
                    |> trim(...)));
                self::validateReady($sample);
                echo json_encode($sample, JSON_THROW_ON_ERROR), "\n";
                fflush(STDOUT);
                if (!$readyWritten && $ready !== null) {
                    Runtime::writeJson(
                        $ready,
                        [
                            'containerId' => $identity,
                            'containerName' => $name,
                            'database' => $database,
                            'clockContainerId' => $clockId,
                            'clockSource' => 'docker-daemon-php-monotonic',
                            'intervalSeconds' => .5,
                            'monotonic' => $sample['monotonic'],
                            'rawNamespaceMetrics' => true,
                        ],
                    );
                    $readyWritten = true;
                }
                $runtime->pause(max(0, .5 - (Runtime::now() - $cycle)));
            }
            return 0;
        } finally {
            $runtime->close();
        }
    }

    private const string COLLECT = <<<'SH'
        emit() {
            [ -r "$1" ] || return 0
            printf '%s\n' "$1"
            base64 < "$1" 2>/dev/null || true
            printf '\n.\n'
        }
        for name in memory.current memory.events memory.stat memory.peak memory.max pids.current; do
            emit "/sys/fs/cgroup/$name"
        done
        for directory in /proc/[0-9]*; do
            [ "$(cat "$directory/comm" 2>/dev/null)" = postgres ] || continue
            for name in status stat cmdline smaps_rollup; do emit "$directory/$name"; done
        done
        SH;
}
