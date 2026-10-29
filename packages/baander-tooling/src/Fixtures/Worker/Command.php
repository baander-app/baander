<?php

declare(strict_types=1);

namespace Baander\Tooling\Fixtures\Worker;

use Baander\Tooling\Fixtures\Runtime;
use Baander\Tooling\Process\ProcessResult;

final class Command
{
    private const string FIXTURE = 'tests/Fixtures/Worker/worker-command-check.php';
    private const string NAMESPACE = 'baander.app:commandtest';
    private const string INITIAL_BOOT = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private readonly Runtime $runtime;
    private readonly Resources $resources;
    private readonly string $image;
    private readonly string $profile;
    /** @var list<string> */
    private array $common = [];
    private string $uid;
    private string $gid;

    private function __construct(string $root)
    {
        $this->runtime = new Runtime($root, budget: 'fixture');
        $this->resources = new Resources($this->runtime, 'worker-command');
        $this->image = $this->runtime->image('application')->id;
        $this->profile = file_get_contents($root . '/tests/Fixtures/Worker/autoscaling-profile.json');
    }

    /** @param list<string> $arguments */
    public static function main(array $arguments, string $root): int
    {
        Runtime::require($arguments === [], 'Worker command qualification takes no arguments');
        $qualification = new self($root);
        try {
            $qualification->run();
            return 0;
        } finally {
            $qualification->resources->close();
        }
    }

    /** @param list<string> $arguments */
    private function docker(array $arguments, bool $check = true, ?float $seconds = 120): ProcessResult
    {
        return $this->resources->docker->run($arguments, check: $check, timeout: $seconds);
    }

    private function logs(string $container): string
    {
        $result = $this->docker(['logs', $container]);
        return $result->stdout . $result->stderr;
    }

    /** @param list<string> $arguments */
    private function journal(string $action, array $arguments = []): ProcessResult
    {
        return $this->resources->runContainer($this->resources->id . '-observer', [
            ...$this->common,
            '--entrypoint',
            'php',
            $this->image,
            '-d',
            'memory_limit=64M',
            self::FIXTURE,
            $action,
            ...$arguments,
        ]);
    }

    /** @param list<string> $arguments */
    private function check(string $container, string $action, array $arguments = [], bool $required = true): ProcessResult
    {
        return $this->docker(
            [
                'exec',
                $container,
                'php',
                '-d',
                'memory_limit=64M',
                self::FIXTURE,
                $action,
                ...$arguments,
            ],
            $required,
        );
    }

    private function prepare(): void
    {
        $id = $this->resources->id;

        $this->resources->createNetwork($id, ['--internal']);
        $this->resources->createVolume($id . '-workspace');
        $this->resources->startContainer($id . '-redis', [
            '--network',
            $id,
            '--network-alias',
            'redis',
            '-e',
            'REDIS_ARGS=--requirepass test-only',
            $this->runtime->image('fixtureRedis')->id,
        ]);
        $this->resources->startContainer($id . '-postgres', [
            '--network',
            $id,
            '--network-alias',
            'postgres',
            '-e',
            'POSTGRES_USER=baander',
            '-e',
            'POSTGRES_PASSWORD=test-only',
            '-e',
            'POSTGRES_DB=worker_command_test',
            $this->runtime->image('postgres')->id,
            'postgres',
            '-c',
            'client_connection_check_interval=100ms',
        ]);
        $this->runtime->await(
            fn(): bool => $this->docker(
                [
                    'exec',
                    $id . '-postgres',
                    'pg_isready',
                    '-h',
                    '127.0.0.1',
                    '-U',
                    'baander',
                    '-d',
                    'worker_command_test',
                ],
                false,
            )->exitCode === 0
            && $this->docker(
                ['exec', '-e', 'REDISCLI_AUTH=test-only', $id . '-redis', 'redis-cli', 'ping'],
                false,
            )->stdout
                |> trim(...) === 'PONG',
            30,
            1,
            'Disposable worker command services did not become ready',
        );
        $this->common = [
            '--network',
            $id,
            '--volume',
            $id . '-workspace:/workspace',
            '--workdir',
            '/workspace',
            '-e',
            'APP_ENV=prod',
            '-e',
            'APP_DEBUG=0',
            '-e',
            'XDEBUG_MODE=off',
            '-e',
            'CONVERT_STORAGE_PATH=/workspace/storage/conversions',
            '-e',
            'REDIS_PASSWORD=test-only',
            '-e',
            'REDIS_URL=redis://default:test-only@redis:6379',
            '-e',
            'MESSENGER_TRANSPORT_DSN=redis://default:test-only@redis:6379/messages',
            '-e',
            'DATABASE_URL=postgresql://baander:test-only@postgres:5432/worker_command_test?serverVersion=18&charset=utf8',
        ];

        $this->uid = $this->resources->runContainer($id . '-uid', [
            '--entrypoint',
            'id',
            $this->image,
            '-u',
        ])->stdout
            |> trim(...);
        $this->gid = $this->resources->runContainer($id . '-gid', [
            '--entrypoint',
            'id',
            $this->image,
            '-g',
        ])->stdout
            |> trim(...);
        Runtime::require(ctype_digit($this->uid) && ctype_digit($this->gid), 'Invalid application UID/GID');
        $this->resources->runContainer($id . '-workspace-owner', [
            ...$this->common,
            '--user',
            '0',
            '--entrypoint',
            'sh',
            $this->image,
            '-ec',
            'touch /workspace/.fixture-ready; chown "$1:$2" /workspace',
            'fixture-owner',
            $this->uid,
            $this->gid,
        ]);
        $this->resources->createContainer($id . '-prepare', [
            ...$this->common,
            '--entrypoint',
            'sh',
            $this->image,
            '-c',
            'exec sleep infinity',
        ]);
        $this->docker(['start', $id . '-prepare']);
        if (($this->runtime->environment['BAANDER_TEST_CHECKOUT_IN_IMAGE'] ?? '0') === '1') {
            $this->docker([
                'exec',
                $id . '-prepare',
                'sh',
                '-ec',
                'cd ' . escapeshellarg($this->runtime->layout()->root) . '; tar -cf /tmp/worker-command-checkout.tar .; tar -xf /tmp/worker-command-checkout.tar -C /workspace; rm /tmp/worker-command-checkout.tar',
            ]);
        } else {
            $this->resources->stage(
                $id . '-prepare',
                [
                    'vendor',
                    'src',
                    'tests',
                    'config',
                    'packages',
                    'migrations',
                    'bin',
                    'docker/general',
                    'templates',
                    'public',
                    '.env',
                    '.env.test',
                    'composer.json',
                    'composer.lock',
                    'translations',
                ],
                '/workspace',
            );
            $this->docker([
                'exec',
                '--user',
                '0',
                $id . '-prepare',
                'chown',
                '-R',
                $this->uid . ':' . $this->gid,
                '/workspace',
            ]);
        }
        $this->docker([
            'exec',
            $id . '-prepare',
            'sh',
            '-ec',
            <<<'SH'
            mkdir -p storage/conversions
            cp tests/Fixtures/Worker/ConsumerAckFixture.php src/Shared/Infrastructure/Worker/ConsumerAckFixture.php
            php -d memory_limit=512M bin/console cache:clear --env=prod --no-debug
            php -d memory_limit=512M bin/console doctrine:migrations:migrate --no-interaction --env=prod
            php -d memory_limit=512M bin/console doctrine:migrations:migrate --no-interaction --env=prod
            php -d memory_limit=64M tests/Fixtures/Worker/worker-command-check.php prepare-consumer-ack baander.app:commandtest aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
            php -d memory_limit=256M tests/Fixtures/Worker/worker-command-check.php seed-outbox baander.app:commandtest aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
            SH,
        ]);
    }

    private function launch(string $container, string $namespace, string $boot, bool $denied = false): void
    {
        $ipc = $container . '-ipc';

        $this->resources->createVolume($ipc);
        $this->resources->runContainer($ipc . '-owner', [
            '--user',
            '0',
            '--volume',
            $ipc . ':/run/baander-worker',
            '--entrypoint',
            'sh',
            $this->image,
            '-ec',
            'chown "$1:$2" /run/baander-worker; chmod 700 /run/baander-worker',
            'fixture-ipc',
            $this->uid,
            $this->gid,
        ]);
        if (!$denied) {
            $this->journal('reserve-pair', [$namespace, $boot]);
        }
        foreach (['control', 'execution'] as $unit) {
            $name = $container . ($unit === 'execution' ? '-execution' : '');
            [$memory, $pids, $cpus, $console] = $unit === 'control' ? ['2944', '129', '0', '0'] : [
                '4480',
                '256',
                '1,2',
                '320',
            ];

            if (!$denied) {
                $this->journal('claim-unit', [$namespace, $boot, $unit]);
            }
            $identity = $this->resources->createContainer($name, [
                ...$this->common,
                '--volume',
                $ipc . ':/run/baander-worker',
                '-e',
                'BAANDER_WORKER_LEASE_EPOCH=9999',
                '-e',
                'BAANDER_WORKER_UNIT=' . $unit,
                '--memory=' . $memory . 'm',
                '--memory-swap=' . $memory . 'm',
                '--cpuset-cpus=' . $cpus,
                '--pids-limit=' . $pids,
                '--restart=no',
                '--entrypoint',
                'sh',
                $this->image,
                '-ec',
                <<<'SH'
                    mkdir -m 700 /tmp/baander-worker-locks
                    exec php -d memory_limit=256M bin/console app:worker --deployment="$1" --boot-id="$2" \
                        --unit="$3" --memory-mib="$4" --pids-limit="$5" --scheduled-console-mib="$6" \
                        --autoscaling-profile="$7" --lock-dir=/tmp/baander-worker-locks --ipc-dir=/run/baander-worker --no-interaction
                    SH,
                'worker-unit',
                $namespace,
                $boot,
                $unit,
                $memory,
                $pids,
                $console,
                $this->profile,
            ]);
            if (!$denied) {
                $this->journal('register-unit', [$namespace, $boot, $unit, $identity]);
            }
        }
        foreach (['control', 'execution'] as $unit) {
            $name = $container . ($unit === 'execution' ? '-execution' : '');
            if (!$denied) {
                $this->journal(
                    'start-unit',
                    [
                        $namespace,
                        $boot,
                        $unit,
                        $this->docker([
                            'inspect',
                            '--format',
                            '{{.Id}}',
                            $name,
                        ])->stdout
                            |> trim(...),
                    ],
                );
            }
            $this->docker(['start', $name]);
            if ($unit === 'control' && !$denied) {
                $this->runtime->await(
                    fn(): bool => $this->check(
                        $container,
                        'lease-ready',
                        [$namespace, $boot],
                        false,
                    )->exitCode === 0,
                    5,
                );
            }
        }
    }

    /** @return list<array<string,mixed>> */
    public static function frames(string $logs, string $event): array
    {
        $frames = [];
        foreach (explode("\n", $logs) as $line) {
            try {
                $frame = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }
            if (is_array($frame) && ($frame['event'] ?? null) === $event) {
                $frames[] = $frame;
            }
        }
        return $frames;
    }

    public static function mediaReady(string $rolesJson, string $logs, string $namespace, string $boot): bool
    {
        try {
            $roles = json_decode($rolesJson, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }
        if (!is_array($roles) || !is_int($roles['media'] ?? null)) {
            return false;
        }
        $identity = [
            'deploymentId' => $namespace,
            'supervisorBootId' => $boot,
            'workerId' => 'media',
            'generation' => 1,
        ];
        foreach (self::frames($logs, 'worker_media_ready') as $frame) {
            if (($frame['identity'] ?? null) == $identity && ($frame['pid'] ?? null) === $roles['media']) {
                return true;
            }
        }
        return false;
    }

    public static function validateStopped(string $logs, int $exit, int $launches): void
    {
        $frames = self::frames($logs, 'worker_supervisor_stopped');
        Runtime::require(count($frames) === 1, 'Expected exactly one worker stop summary');
        $frame = $frames[0];
        Runtime::require(
            count($frame) === 3 && ($frame['launchAttempts'] ?? null) === $launches
            && ($frame['exitCode'] ?? null) === $exit,
            'Unexpected worker stop summary',
        );
    }

    private function ready(string $container, string $namespace, string $boot): void
    {
        $this->runtime->await(
            function () use ($container, $namespace, $boot): bool {
                $control = $this->check($container, 'ready', [$namespace, $boot], false);
                $execution = $this->check($container . '-execution', 'ready', [$namespace, $boot], false);
                if ($control->exitCode !== 0 || $execution->exitCode !== 0) {
                    return false;
                }
                return self::mediaReady($control->stdout, $this->logs($container), $namespace, $boot);
            },
            40,
            .2,
            'Production worker roles / initialized media / lease / Redis consumer did not become ready',
        );
    }

    private function exited(string $container, int $expected, int $launches = 6, float $seconds = 45): void
    {
        Runtime::require(in_array($launches, [0, 6], true), 'Unexpected paired launch count');
        foreach (['control', 'execution'] as $unit) {
            $name = $container . ($unit === 'execution' ? '-execution' : '');
            $code = $unit === 'control' ? $expected : 1;
            Runtime::require(
                $this->docker(['wait', $name], true, $seconds)->stdout
                    |> trim(...) === (string) $code,
                'Unexpected worker exit for ' . $unit,
            );
            self::validateStopped($this->logs($name), $code, $launches === 0 ? 0 : ($unit === 'control' ? 4 : 2));
            Runtime::require(
                $this->docker(['inspect', '--format', '{{.State.Pid}}', $name])->stdout
                    |> trim(...) === '0',
                'Stopped worker retained descendants',
            );
        }
    }

    /** @param list<string> $arguments */
    private function awaitCheck(string $container, string $action, array $arguments, float $seconds): string
    {
        $output = '';
        $this->runtime->await(
            function () use ($container, $action, $arguments, &$output): bool {
                $result = $this->check($container, $action, $arguments, false);
                $output = $result->stdout
                    |> trim(...);
                return $result->exitCode === 0;
            },
            $seconds,
            .2,
            'Worker check did not complete: ' . $action,
        );
        return $output;
    }

    private function gate(string $name, string $action, string $namespace, string $boot, string $message): void
    {

        $this->resources->startContainer($name, [
            ...$this->common,
            '--entrypoint',
            'php',
            $this->image,
            '-d',
            'memory_limit=64M',
            self::FIXTURE,
            $action,
            $namespace,
            $boot,
        ]);
        $this->runtime->await(
            fn(): bool => in_array($message, explode("\n", $this->logs($name)
                |> trim(...)), true),
            6,
            .2,
            'Acknowledgement gate did not arm',
        );
    }

    private function removeGate(string $name): void
    {
        $this->docker(['rm', '-f', $name]);

    }

    private function invalidTopology(): void
    {
        foreach (['path', 'query', 'scheduler'] as $case) {
            $container = $this->resources->id . '-invalid-' . $case;
            $namespace = 'baander.app:invalid-' . $case;
            $setting = $case === 'scheduler' ? 'REDIS_URL' : 'MESSENGER_TRANSPORT_DSN';
            $dsn = match ($case) {
                'path' => 'redis://default:fixture-secret@redis:6379/messages/baander/fixed',
                'query' => 'redis://default:fixture-secret@redis:6379/messages?consumer=fixed',
                'scheduler' => 'redis://default:fixture-secret@redis:6379/other',
            };

            $this->resources->startContainer($container, [
                ...$this->common,
                '-e',
                $setting . '=' . $dsn,
                '--entrypoint',
                'php',
                $this->image,
                '-d',
                'memory_limit=256M',
                'bin/console',
                'app:worker',
                '--deployment=' . $namespace,
                '--boot-id=' . str_repeat(
                    'f',
                    32,
                ),
                '--unit=control',
                '--memory-mib=2944',
                '--pids-limit=129',
                '--scheduled-console-mib=0',
                '--autoscaling-profile=' . $this->profile,
                '--ipc-dir=/run/baander-worker',
                '--lock-dir=/tmp/baander-invalid-lock',
                '--no-interaction',
            ]);
            Runtime::require(
                $this->docker(['wait', $container], true, 15)->stdout
                    |> trim(...) !== '0',
                'Invalid topology started worker',
            );
            $logs = $this->logs($container);
            Runtime::require(
                str_contains($logs, $setting) && !str_contains($logs, 'fixture-secret') && !str_contains(
                    $logs,
                    'worker_supervisor_stopped',
                ),
                'Invalid topology diagnostics leaked credentials or launched worker',
            );
            Runtime::require(
                !str_contains(
                    $this->docker(['diff', $container])->stdout,
                    '/tmp/baander-invalid-lock',
                ),
                'Invalid topology created lock directory',
            );
            $count = $this->docker([
                'exec',
                $this->resources->id . '-postgres',
                'psql',
                '-U',
                'baander',
                '-d',
                'worker_command_test',
                '-Atc',
                "SELECT count(*) FROM worker_deployment_leases WHERE namespace = '$namespace'",
            ])->stdout;
            Runtime::require(trim($count) === '0', 'Invalid topology acquired authority');
        }
        echo "Transport topology rejected before locks and authority; credentials redacted (3 cases).\n";
    }

    private function run(): void
    {
        $this->prepare();
        $this->invalidTopology();
        $id = $this->resources->id;
        $claimBoot = bin2hex(random_bytes(16));
        $claimNamespace = 'baander.app:commandclaimcrash';
        $this->gate($id . '-claim-gate', 'hold-relay-claim', $claimNamespace, $claimBoot, 'Claim gate armed.');
        $this->launch($id . '-claim-crash', $claimNamespace, $claimBoot);
        $this->ready($id . '-claim-crash', $claimNamespace, $claimBoot);
        $claim = json_decode(
            $this->awaitCheck(
                $id . '-claim-crash',
                'verify-claim-blocked',
                [
                    $claimNamespace,
                    $claimBoot,
                ],
                6,
            ),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        Runtime::require(preg_match('/^[a-f0-9]{64}$/D', $claim['lease_token']) === 1, 'Invalid claim token');
        $this->check($id . '-claim-crash', 'kill-relay-claimed', [$claimNamespace, $claimBoot]);
        $this->exited($id . '-claim-crash', 1);
        $this->journal('reserved', [$claimNamespace, $claimBoot]);
        $this->journal(
            'verify-claim-contained',
            [
                $claimNamespace,
                $claimBoot,
                $claim['lease_token'],
                $claim['lease_until'],
            ],
        );
        $this->removeGate($id . '-claim-gate');
        $this->journal('release-relay-claim', [$claimNamespace, $claimBoot]);

        $projectionBoot = bin2hex(random_bytes(16));
        $projectionNamespace = 'baander.app:commandprojectioncrash';
        $this->gate(
            $id . '-projection-gate',
            'hold-relay-projection',
            $projectionNamespace,
            $projectionBoot,
            'Projection gate armed.',
        );
        $this->launch($id . '-projection-crash', $projectionNamespace, $projectionBoot);
        $this->ready($id . '-projection-crash', $projectionNamespace, $projectionBoot);
        $this->check(
            $id . '-projection-crash',
            'verify-claim-contained',
            [
                $projectionNamespace,
                $projectionBoot,
                $claim['lease_token'],
                $claim['lease_until'],
            ],
        );
        $projection = json_decode(
            $this->awaitCheck(
                $id . '-projection-crash',
                'verify-projection-blocked',
                [
                    $projectionNamespace,
                    $projectionBoot,
                ],
                70,
            ),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        Runtime::require(
            preg_match('/^[a-f0-9]{64}$/D', $projection['lease_token']) === 1 && $projection['lease_token'] !== $claim['lease_token'],
            'Projection did not acquire distinct claim',
        );
        $this->check($id . '-projection-crash', 'kill-relay-projecting', [$projectionNamespace, $projectionBoot]);
        $this->exited($id . '-projection-crash', 1);
        $this->journal('reserved', [$projectionNamespace, $projectionBoot]);
        $this->journal(
            'verify-projection-contained',
            [
                $projectionNamespace,
                $projectionBoot,
                $projection['lease_token'],
                $projection['lease_until'],
            ],
        );
        $this->removeGate($id . '-projection-gate');
        $this->journal('release-relay-projection', [$projectionNamespace, $projectionBoot]);

        $deliveryBoot = bin2hex(random_bytes(16));
        $deliveryNamespace = 'baander.app:commanddeliveryackcrash';
        $this->gate(
            $id . '-delivery-ack-gate',
            'hold-relay-delivery-ack',
            $deliveryNamespace,
            $deliveryBoot,
            'Delivery acknowledgement gate armed.',
        );
        $this->launch($id . '-delivery-ack-crash', $deliveryNamespace, $deliveryBoot);
        $this->ready($id . '-delivery-ack-crash', $deliveryNamespace, $deliveryBoot);
        $this->check(
            $id . '-delivery-ack-crash',
            'verify-projection-contained',
            [
                $deliveryNamespace,
                $deliveryBoot,
                $projection['lease_token'],
                $projection['lease_until'],
            ],
        );
        $delivery = $this->awaitCheck(
            $id . '-delivery-ack-crash',
            'verify-delivery-ack-blocked',
            [
                $deliveryNamespace,
                $deliveryBoot,
            ],
            70,
        );
        $this->check(
            $id . '-delivery-ack-crash',
            'verify-claim-recovered',
            [
                $deliveryNamespace,
                $deliveryBoot,
                $projection['lease_until'],
            ],
        );
        $this->check($id . '-delivery-ack-crash', 'kill-relay-after-send', [$deliveryNamespace, $deliveryBoot]);
        $this->exited($id . '-delivery-ack-crash', 1);
        $this->journal('reserved', [$deliveryNamespace, $deliveryBoot]);
        $this->journal('verify-delivery-ack-contained', [$deliveryNamespace, $deliveryBoot, $delivery]);
        $this->removeGate($id . '-delivery-ack-gate');
        $this->journal('release-relay-delivery-ack', [$deliveryNamespace, $deliveryBoot]);

        $boot = bin2hex(random_bytes(16));
        $this->launch($id . '-crash', self::NAMESPACE, $boot);
        $this->ready($id . '-crash', self::NAMESPACE, $boot);
        $this->check($id . '-crash', 'verify-delivery-ack-contained', [self::NAMESPACE, $boot, $delivery]);
        $this->awaitCheck($id . '-crash', 'verify-outbox', [self::NAMESPACE, self::INITIAL_BOOT], 70);
        $this->check($id . '-crash', 'verify-delivery-ack-recovered', [self::NAMESPACE, $boot, $delivery]);
        $this->check($id . '-crash', 'replay-outbox', [self::NAMESPACE, $boot]);
        $this->awaitCheck($id . '-crash', 'verify-outbox', [self::NAMESPACE, self::INITIAL_BOOT], 6);
        $this->gate(
            $id . '-consumer-ack-gate',
            'hold-consumer-ack',
            self::NAMESPACE,
            $boot,
            'Consumer acknowledgement gate armed.',
        );
        $this->docker([
            'exec',
            $id . '-crash',
            'php',
            '-d',
            'memory_limit=256M',
            self::FIXTURE,
            'seed-scheduler',
            self::NAMESPACE,
            $boot,
        ]);
        $consumer = $this->awaitCheck($id . '-crash', 'verify-consumer-ack-blocked', [self::NAMESPACE, $boot], 10);
        $this->check($id . '-crash-execution', 'kill-consumer-before-ack', [self::NAMESPACE, $boot]);
        $this->exited($id . '-crash', 1);
        $this->journal('reserved', [self::NAMESPACE, $boot]);
        $this->journal('verify-consumer-ack-contained', [self::NAMESPACE, $boot, $consumer]);
        $this->removeGate($id . '-consumer-ack-gate');
        $this->journal('release-consumer-ack', [self::NAMESPACE, $boot]);
        $this->journal('age-consumer-ack-pending', [self::NAMESPACE, $boot, $consumer]);
        $recoveryBoot = bin2hex(random_bytes(16));
        $recoveryNamespace = 'baander.app:commandconsumerackrecovery';
        $this->launch($id . '-consumer-ack-recovery', $recoveryNamespace, $recoveryBoot);
        $this->ready($id . '-consumer-ack-recovery', $recoveryNamespace, $recoveryBoot);
        $this->awaitCheck($id . '-consumer-ack-recovery', 'verify-scheduler', [self::NAMESPACE, $boot], 10);
        $this->check(
            $id . '-consumer-ack-recovery',
            'verify-consumer-ack-recovered',
            [
                self::NAMESPACE,
                $boot,
                $consumer,
            ],
        );
        $this->check($id . '-consumer-ack-recovery', 'replay-scheduler', [self::NAMESPACE, $boot]);
        $this->awaitCheck($id . '-consumer-ack-recovery', 'verify-scheduler', [self::NAMESPACE, $boot], 10);
        $this->check(
            $id . '-consumer-ack-recovery-execution',
            'kill-consumer',
            [
                $recoveryNamespace,
                $recoveryBoot,
            ],
        );
        $this->exited($id . '-consumer-ack-recovery', 1);
        $this->journal('reserved', [$recoveryNamespace, $recoveryBoot]);
        $this->launch($id . '-denied', self::NAMESPACE, $boot, true);
        $this->exited($id . '-denied', 1, 0);
        $this->journal('reserved', [self::NAMESPACE, $boot]);
        foreach (['scheduler', 'term', 'expiry'] as $scenario) {
            $nextBoot = bin2hex(random_bytes(16));
            $namespace = 'baander.app:command' . ($scenario === 'scheduler' ? 'schedulercrash' : $scenario);
            $container = $id . '-' . ($scenario === 'scheduler' ? 'scheduler-crash' : $scenario);
            $this->launch($container, $namespace, $nextBoot);
            $this->ready($container, $namespace, $nextBoot);
            match ($scenario) {
                'scheduler' => $this->check($container, 'kill-scheduler', [$namespace, $nextBoot]),
                'term' => $this->docker(['kill', '--signal=TERM', $container]),
                'expiry' => $this->journal('expire', [$namespace, $nextBoot]),
            };
            $this->exited($container, $scenario === 'term' ? 0 : 1, 6, $scenario === 'expiry' ? 60 : 45);
            $this->journal('reserved', [$namespace, $nextBoot]);
        }
        echo "Paired app:worker claim, projection, transport handoff, pre-XACK consumer crash recovery, replay, and lifecycle acceptance passed.\n";
    }
}
