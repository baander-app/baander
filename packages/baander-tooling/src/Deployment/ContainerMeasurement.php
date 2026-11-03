<?php

declare(strict_types=1);

namespace Baander\Tooling\Deployment;

use Baander\Tooling\Filesystem\AtomicFile;
use Baander\Tooling\Process\ProcessResult;

final class ContainerMeasurement
{
    /** @param array<string,mixed> $context
     * @return array{bootstrap:string,onCompletion:\Closure,path:string}
     */
    public static function prepare(array $context, string $action, string $image): array
    {
        if (preg_match('/^[a-z][a-z0-9-]{0,47}$/D', $action) !== 1) {
            throw new \InvalidArgumentException('Invalid operator measurement action.');
        }
        $nonce = bin2hex(random_bytes(16));
        $path = $context['directory'] . '/' . $action . '-' . $nonce . '.json';
        $receipt = ['version' => 1, 'action' => $action, 'namespace' => $context['namespace'], 'bootId' => $context['boot_id'],
            'nonce' => $nonce, 'containerId' => null, 'imageId' => $image, 'status' => 'incomplete',
            'startedMonotonic' => null, 'finishedMonotonic' => null, 'startedEpochSeconds' => null, 'finishedEpochSeconds' => null,
            'docker' => null, 'cgroup' => null, 'errors' => ['completion-not-observed']];
        AtomicFile::writeJson($path, $receipt);
        $bootstrap = <<<'CODE'
(static function (): void {
    $startedMonotonic = hrtime(true) / 1e9;
    $startedEpoch = microtime(true);
    register_shutdown_function(static function () use ($startedMonotonic, $startedEpoch): void {
        $errors = [];
        $read = static function (string $name) use (&$errors): ?string {
            $value = @file_get_contents('/sys/fs/cgroup/' . $name, length: 16385);
            if ($value === false || strlen($value) > 16384) { $errors[] = 'missing-' . $name; return null; }
            return trim($value);
        };
        $scalar = static function (string $name, bool $unlimited = false) use ($read, &$errors): int|string|null {
            $value = $read($name);
            if ($value !== null && ctype_digit($value)) { return (int) $value; }
            if ($unlimited && $value === 'max') { return $value; }
            $errors[] = 'invalid-' . $name;
            return null;
        };
        $membership = @file_get_contents('/proc/self/cgroup');
        if (!is_string($membership) || trim($membership) !== '0::/') { $errors[] = 'unexpected-cgroup-membership'; }
        $identity = @stat('/sys/fs/cgroup');
        $events = [];
        foreach (explode("\n", $read('memory.events') ?? '') as $line) {
            if (preg_match('/^([a-z_][a-z0-9_]*) ([0-9]+)$/D', $line, $match) !== 1) { $errors[] = 'invalid-memory.events'; continue; }
            $events[$match[1]] = (int) $match[2];
        }
        if (!isset($events['oom'], $events['oom_kill'])) { $errors[] = 'missing-oom-events'; }
        $cgroup = [
            'identity' => $identity === false ? null : $identity['dev'] . ':' . $identity['ino'], 'path' => '/',
            'memoryCurrentBytes' => $scalar('memory.current'), 'memoryPeakBytes' => $scalar('memory.peak'), 'memoryMaxBytes' => $scalar('memory.max', true),
            'memoryEvents' => $events, 'swapCurrentBytes' => $scalar('memory.swap.current'), 'swapPeakBytes' => $scalar('memory.swap.peak'),
            'swapMaxBytes' => $scalar('memory.swap.max', true), 'pidsCurrent' => $scalar('pids.current'), 'pidsMax' => $scalar('pids.max', true),
            'pidsPeak' => is_file('/sys/fs/cgroup/pids.peak') ? $scalar('pids.peak') : null,
        ];
        if ($identity === false) { $errors[] = 'missing-cgroup-identity'; }
        $data = ['version' => 1, 'nonce' => __NONCE__, 'startedMonotonic' => $startedMonotonic, 'finishedMonotonic' => hrtime(true) / 1e9,
            'startedEpochSeconds' => $startedEpoch, 'finishedEpochSeconds' => microtime(true), 'cgroup' => $cgroup, 'errors' => array_values(array_unique($errors))];
        $encoded = json_encode($data, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        if (strlen($encoded) <= 8192) { fwrite(STDERR, "\nBAANDER_OPERATOR_MEASURE_V1:" . __NONCE__ . ':' . $encoded . "\n"); }
    });
})();
CODE;
        return ['bootstrap' => str_replace('__NONCE__', var_export($nonce, true), $bootstrap), 'path' => $path,
            'onCompletion' => static function (\Baander\Tooling\Docker\Client $docker, ?string $container, ?ProcessResult $result) use ($receipt, $path): void {
                self::completeOperatorMeasurement($docker, $container, $result, $receipt, $path);
            }];
    }

    /** @param array<string,mixed> $receipt */
    private static function completeOperatorMeasurement(\Baander\Tooling\Docker\Client $docker, ?string $container, ?ProcessResult $result, array $receipt, string $path): void
    {
        $receipt['containerId'] = $container;
        $errors = [];
        if ($container === null || $result === null) {
            $errors[] = 'execution-unconfirmed';
        }
        if ($result !== null) {
            $pattern = '/^BAANDER_OPERATOR_MEASURE_V1:' . preg_quote($receipt['nonce'], '/') . ':([^\r\n]+)$/m';
            if (strlen($result->stderr) > 1048576 || preg_match_all($pattern, $result->stderr, $matches) !== 1 || strlen($matches[1][0]) > 8192) {
                $errors[] = 'shutdown-measurement-missing';
            } else {
                try {
                    $measurement = json_decode($matches[1][0], true, 8, JSON_THROW_ON_ERROR);
                    if (!is_array($measurement) || ($measurement['version'] ?? null) !== 1 || ($measurement['nonce'] ?? null) !== $receipt['nonce']
                        || !is_array($measurement['errors'] ?? null) || $measurement['errors'] !== []
                        || !self::validOperatorCgroup($measurement['cgroup'] ?? null)) {
                        $errors[] = 'shutdown-measurement-invalid';
                    } else {
                        foreach (['startedMonotonic', 'finishedMonotonic', 'startedEpochSeconds', 'finishedEpochSeconds'] as $key) {
                            $value = $measurement[$key] ?? null;
                            if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || $value <= 0) {
                                throw new \RuntimeException('Invalid measurement time.');
                            }
                            $receipt[$key] = $value;
                        }
                        if ($receipt['finishedMonotonic'] < $receipt['startedMonotonic'] || $receipt['finishedEpochSeconds'] < $receipt['startedEpochSeconds']) {
                            throw new \RuntimeException('Measurement time moved backwards.');
                        }
                        $receipt['cgroup'] = array_intersect_key($measurement['cgroup'], array_flip(['identity', 'path', 'memoryCurrentBytes', 'memoryPeakBytes', 'memoryMaxBytes', 'memoryEvents', 'swapCurrentBytes', 'swapPeakBytes', 'swapMaxBytes', 'pidsCurrent', 'pidsPeak', 'pidsMax']));
                        $receipt['cgroup']['memoryEvents'] = array_intersect_key($receipt['cgroup']['memoryEvents'], array_flip(['low', 'high', 'max', 'oom', 'oom_kill', 'oom_group_kill']));
                    }
                } catch (\Throwable) {
                    $errors[] = 'shutdown-measurement-invalid';
                }
            }
        }
        if ($container !== null) {
            try {
                $format = '{"id":{{json .Id}},"image":{{json .Image}},"status":{{json .State.Status}},"running":{{json .State.Running}},"exitCode":{{json .State.ExitCode}},"oomKilled":{{json .State.OOMKilled}},"startedAt":{{json .State.StartedAt}},"finishedAt":{{json .State.FinishedAt}},"memory":{{json .HostConfig.Memory}},"memorySwap":{{json .HostConfig.MemorySwap}},"nanoCpus":{{json .HostConfig.NanoCpus}},"pidsLimit":{{json .HostConfig.PidsLimit}},"cpuSet":{{json .HostConfig.CpusetCpus}},"cgroupNamespace":{{json .HostConfig.CgroupnsMode}}}';
                $output = $docker->run(['inspect', '--format', $format, $container], timeout: 10)->stdout;
                if (strlen($output) > 16384) {
                    throw new \RuntimeException('Oversized container observation.');
                }
                $observed = json_decode($output, true, 6, JSON_THROW_ON_ERROR);
                if (!is_array($observed) || ($observed['id'] ?? null) !== $container || ($observed['image'] ?? null) !== $receipt['imageId']
                    || ($observed['status'] ?? null) !== 'exited' || ($observed['running'] ?? null) !== false
                    || !is_int($observed['exitCode'] ?? null) || !is_bool($observed['oomKilled'] ?? null)
                    || !is_string($observed['startedAt'] ?? null) || !is_string($observed['finishedAt'] ?? null)
                    || !is_int($observed['memory'] ?? null) || !is_int($observed['memorySwap'] ?? null)
                    || !is_int($observed['nanoCpus'] ?? null) || !array_key_exists('pidsLimit', $observed)
                    || ($observed['pidsLimit'] !== null && !is_int($observed['pidsLimit'])) || !is_string($observed['cpuSet'] ?? null)
                    || ($observed['cgroupNamespace'] ?? null) !== 'private' || ($result !== null && $observed['exitCode'] !== $result->exitCode)) {
                    throw new \RuntimeException('Invalid container observation.');
                }
                $receipt['docker'] = array_intersect_key($observed, array_flip(['status', 'running', 'exitCode', 'oomKilled', 'startedAt', 'finishedAt', 'cgroupNamespace']));
                // Operator containers run without resource limits, both as Docker records them and as the kernel enforces them.
                if ($observed['memory'] > 0 || $observed['memorySwap'] > 0 || $observed['nanoCpus'] > 0 || ($observed['pidsLimit'] ?? 0) > 0 || $observed['cpuSet'] !== ''
                    || ($receipt['cgroup'] !== null && ($receipt['cgroup']['memoryMaxBytes'] !== 'max' || $receipt['cgroup']['swapMaxBytes'] !== 'max'))) {
                    $errors[] = 'container-limit-present';
                }
            } catch (\Throwable) {
                $errors[] = 'container-state-unconfirmed';
            }
        }
        $receipt['errors'] = array_values(array_unique($errors));
        $receipt['status'] = $receipt['errors'] === [] ? 'complete' : 'incomplete';
        AtomicFile::writeJson($path, $receipt);
    }

    private static function validOperatorCgroup(mixed $group): bool
    {
        if (!is_array($group) || !is_string($group['identity'] ?? null) || preg_match('/^[0-9]+:[0-9]+$/D', $group['identity']) !== 1 || ($group['path'] ?? null) !== '/') {
            return false;
        }
        foreach (['memoryCurrentBytes', 'memoryPeakBytes', 'swapCurrentBytes', 'swapPeakBytes', 'pidsCurrent'] as $key) {
            if (!is_int($group[$key] ?? null) || $group[$key] < 0) {
                return false;
            }
        }
        foreach (['memoryMaxBytes', 'swapMaxBytes', 'pidsMax'] as $key) {
            if (($group[$key] ?? null) !== 'max' && (!is_int($group[$key] ?? null) || $group[$key] < 0)) {
                return false;
            }
        }
        if (($group['pidsPeak'] ?? null) !== null && (!is_int($group['pidsPeak']) || $group['pidsPeak'] < 0)) {
            return false;
        }
        foreach (['oom', 'oom_kill'] as $key) {
            if (!is_int($group['memoryEvents'][$key] ?? null) || $group['memoryEvents'][$key] < 0) {
                return false;
            }
        }
        return true;
    }
}
