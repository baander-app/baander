<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Worker;

use App\Shared\Application\DTO\WorkerAutoscalingProfile;
use App\Shared\Infrastructure\Messaging\WorkerTransportConfiguration;
use Redis;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Read-only observation inside the admitted worker's private PID namespace. */
#[Exclude]
final class WorkerReadinessObservation
{
    /** @return array<string,int> Role to current generation. */
    public static function observe(string $namespace, string $bootId, int $epoch, string $unit, string $profileJson, string $appHome): array
    {
        $lease = new DeploymentLease($namespace, $bootId, $epoch);
        $profile = WorkerAutoscalingProfile::fromJson($profileJson);
        $processes = [];
        foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $path) {
            $pid = (int) basename(dirname($path));
            if ($pid === 1 || $pid === getmypid()) {
                continue;
            }
            $command = @file_get_contents($path);
            $status = @file_get_contents(dirname($path) . '/status');
            $environment = @file_get_contents(dirname($path) . '/environ');
            if ($command !== false && $status !== false && $environment !== false) {
                $processes[] = ['command' => explode("\0", rtrim($command, "\0")), 'status' => $status, 'environment' => $environment];
            }
        }
        $roles = self::verifyProcesses($processes, $lease, $unit, $profile, $appHome);
        $environment = ['REDIS_URL' => getenv('REDIS_URL'), 'MESSENGER_TRANSPORT_DSN' => getenv('MESSENGER_TRANSPORT_DSN')];
        WorkerTransportConfiguration::validate($environment, true);
        foreach (['REDIS_URL' => 'scheduler_occurrences', 'MESSENGER_TRANSPORT_DSN' => 'messages'] as $key => $stream) {
            $parts = parse_url($environment[$key]);
            if (!is_array($parts)) {
                throw new RuntimeException('Worker dependency endpoint unavailable.');
            }
            $redis = new Redis();
            $host = ($parts['scheme'] === 'rediss' ? 'tls://' : '') . $parts['host'];
            $redis->connect($host, $parts['port'] ?? 6379, 1.0, null, 0, 1.0);
            if (isset($parts['pass'])) {
                $redis->auth([rawurldecode($parts['user'] ?? 'default'), rawurldecode($parts['pass'])]);
            }
            parse_str($parts['query'] ?? '', $options);
            if (isset($options['dbindex'])) {
                $redis->select((int) $options['dbindex']);
            }
            if (!$redis->ping()) {
                throw new RuntimeException('Worker dependency unavailable.');
            }
            if ($unit === 'execution') {
                $consumers = $redis->xInfo('CONSUMERS', $stream, 'baander');
                $expectedRoles = $stream === 'messages' ? $profile->consumerIds() : ['scheduled-consumer'];
                foreach ($expectedRoles as $role) {
                    $name = self::consumerName($lease, $role, $roles[$role]);
                    if (!is_array($consumers) || !in_array($name, array_column($consumers, 'name'), true)) {
                        throw new RuntimeException('Admitted worker consumer is unavailable.');
                    }
                }
            }
            $redis->close();
        }
        return $roles;
    }

    /**
     * @param list<array{command:list<string>,status:string,environment:string}> $processes
     * @return array<string,int>
     */
    public static function verifyProcesses(array $processes, DeploymentLease $lease, string $unit, WorkerAutoscalingProfile $profile, string $appHome): array
    {
        $roles = [];
        foreach ($processes as $process) {
            $arguments = $process['command'];
            $role = match (true) {
                in_array('messenger:consume', $arguments, true) => in_array('scheduler', $arguments, true) ? 'scheduled-consumer' : 'consumer',
                in_array('app:outbox:consume', $arguments, true) => 'relay',
                in_array($appHome . '/bin/worker-scheduler.php', $arguments, true) => 'scheduler',
                in_array($appHome . '/bin/worker-media.php', $arguments, true) => 'media',
                in_array($appHome . '/bin/worker-native.php', $arguments, true) => 'native',
                in_array($appHome . '/bin/worker-autoscaler.php', $arguments, true) => 'autoscaler',
                default => null,
            };
            if ($role === null) {
                continue;
            }
            if (preg_match('/^PPid:\s+1$/m', $process['status']) !== 1
                || preg_match('/^State:\s+[ZRXT]/m', $process['status']) === 1) {
                throw new RuntimeException('Worker role is not a live direct child of PID 1.');
            }
            $environment = [];
            foreach (explode("\0", $process['environment']) as $entry) {
                $pair = explode('=', $entry, 2);
                if (count($pair) === 2) {
                    if (array_key_exists($pair[0], $environment)) {
                        throw new RuntimeException('Duplicate worker launch identity.');
                    }
                    $environment[$pair[0]] = $pair[1];
                }
            }
            if ($role === 'consumer') {
                $role = $environment['BAANDER_WORKER_ID'] ?? '';
                if (!in_array($role, $profile->consumerIds(), true) || !in_array('async', $arguments, true) || in_array('scheduler', $arguments, true)) {
                    throw new RuntimeException('Unexpected ordinary consumer identity or transport.');
                }
            } elseif ($role === 'scheduled-consumer' && in_array('async', $arguments, true)) {
                throw new RuntimeException('Scheduler consumer uses the wrong transport.');
            }
            $generation = $environment['BAANDER_WORKER_GENERATION'] ?? '';
            if (preg_match('/\A[1-9][0-9]{0,8}\z/D', $generation) !== 1 || isset($roles[$role])) {
                throw new RuntimeException('Duplicate or unidentified worker generation.');
            }
            $expected = ['BAANDER_WORKER_NAMESPACE' => $lease->namespace, 'BAANDER_WORKER_BOOT_ID' => $lease->bootId,
                'BAANDER_WORKER_LEASE_EPOCH' => (string) $lease->epoch, 'BAANDER_WORKER_UNIT' => $unit,
                'BAANDER_WORKER_ID' => $role, 'BAANDER_WORKER_AUTOSCALING_PROFILE' => $profile->toJson()];
            if ($role !== 'native') {
                $expected['MESSENGER_CONSUMER_NAME'] = self::consumerName($lease, $role, (int) $generation);
            }
            foreach ($expected as $key => $value) {
                if (($environment[$key] ?? null) !== $value) {
                    throw new RuntimeException('Worker launch identity differs from admission.');
                }
            }
            $roles[$role] = (int) $generation;
        }
        $expectedRoles = match ($unit) {
            'control' => ['relay', 'scheduler', 'media', 'autoscaler'],
            'execution' => [...$profile->consumerIds(), 'scheduled-consumer', 'native'],
            default => throw new RuntimeException('Unknown worker unit.'),
        };
        if (count($roles) !== count($expectedRoles) || array_diff($expectedRoles, array_keys($roles)) !== []) {
            throw new RuntimeException('Worker process pair is incomplete.');
        }
        return $roles;
    }

    private static function consumerName(DeploymentLease $lease, string $role, int $generation): string
    {
        return 'worker-' . substr(hash('sha256', $lease->namespace), 0, 16) . '-' . $lease->bootId . '-' . $role . '-' . $generation;
    }
}
