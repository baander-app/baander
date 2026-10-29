<?php

declare(strict_types=1);

namespace Baander\Tooling\Fixtures\Messaging;

use Baander\Tooling\Fixtures\Runtime;
use Baander\Tooling\Docker\Client;
use Baander\Tooling\Resources\ResourceKind;

final class Qualification
{
    private const array TESTS = [
        'MessengerJsonDeliveryTest',
        'MessengerRetentionTest',
        'MessengerDelayedPromotionTest',
        'MessengerAdmissionLifecycleTest',
        'NotificationRetryDeliveryTest',
        'CoverExtractionRetryTest',
        'OutboxLeaseTest',
        'OutboxNotificationReplayTest',
        'AccessTokenCacheTransactionTest',
        'PgroongaSearchCompatibilityTest',
        'WorkerDeploymentLeaseTest',
        'WorkerLeaseAgentTest',
        'WorkerDeploymentRetirementTest',
        'WorkerDeploymentInventoryTest',
        'RegisteredDeploymentRecoveryTest',
        'RegisteredDeploymentStartTest',
        'DockerDeploymentCreateTest',
    ];

    /** @return list<non-empty-list<string>> */
    public static function commands(string $mode): array
    {
        if ($mode === 'outbox') {
            return [
                ['php', 'tests/Fixtures/outbox-runtime.php', 'prepare'],
                ['php', 'bin/console', 'app:outbox:consume', '--once', '--no-interaction'],
                ['php', 'tests/Fixtures/outbox-runtime.php', 'verify'],
                ['php', 'tests/Fixtures/outbox-runtime.php', 'reset-ack'],
                ['php', 'bin/console', 'app:outbox:consume', '--once', '--no-interaction'],
                ['php', 'tests/Fixtures/outbox-runtime.php', 'verify'],
            ];
        }
        if ($mode === 'producer') {
            return [['php', 'tests/Fixtures/producer-runtime.php']];
        }
        Runtime::require($mode === 'messaging', 'Unknown messaging fixture.');
        $tests = array_map(
            static fn(string $name): string => 'tests/Integration/' . $name . '.php',
            self::TESTS,
        );
        return [[
            'php', 'vendor/bin/phpunit', '-c', 'phpunit.xml.dist', ...$tests,
            '--no-progress', '--colors=never', '--display-all-issues', '--fail-on-phpunit-notice', '--fail-on-skipped',
        ]];
    }

    public static function main(string $root, string $mode): int
    {
        $runtime = new Runtime($root, budget: $mode === 'messaging' ? 'messaging' : 'http');
        try {
            self::run($runtime, $mode);
            return 0;
        } finally {
            $runtime->close();
        }
    }

    public static function run(Runtime $runtime, string $mode): void
    {
        $commands = self::commands($mode);
        $inImage = $mode !== 'messaging' && ($runtime->environment['BAANDER_TEST_CHECKOUT_IN_IMAGE'] ?? '0') === '1';
        Runtime::require($inImage || is_file($runtime->root . '/vendor/autoload.php'), 'Install Composer dependencies first.');
        $docker = $runtime->docker();
        $layout = $runtime->layout();
        $applicationImage = $runtime->image('application')->id;
        $redisImage = $runtime->image('fixtureRedis')->id;
        $postgresImage = $runtime->image('postgres')->id;
        $artifacts = Runtime::artifacts($runtime->root, $mode, $runtime->environment);
        $ledger = $runtime->resources($artifacts);
        fwrite(STDERR, 'Fixture evidence: ' . $artifacts . "\n");
        $ledger->scoped(static function () use ($runtime, $mode, $commands, $inImage, $docker, $layout, $applicationImage, $redisImage, $postgresImage, $artifacts, $ledger): void {
            $id = 'baander-' . $mode . '-' . bin2hex(random_bytes(8));
            $ledger->create(ResourceKind::Network, $id, $mode === 'messaging' ? [] : ['--internal']);
            $redis = $id . '-redis';
            $postgres = $id . '-postgres';
            $database = $mode . '_test';
            $redis = $ledger->create(ResourceKind::Container, $redis, [
                '--network', $id,
                '--network-alias', 'redis',
                ...($mode === 'messaging' ? [] : ['-e', 'REDIS_ARGS=--requirepass test-only']),
                $redisImage,
            ]);
            $docker->run(['start', $redis]);
            $postgres = $ledger->create(ResourceKind::Container, $postgres, [
                '--network', $id,
                '--network-alias', 'postgres',
                '-e', 'POSTGRES_USER=baander',
                '-e', 'POSTGRES_PASSWORD=test-only',
                '-e', 'POSTGRES_DB=' . $database,
                $postgresImage,
            ]);
            $docker->run(['start', $postgres]);
            $ready = false;
            for ($attempt = 0; $attempt < 30; ++$attempt) {
                $pg = $docker->run(
                    ['exec', $postgres, 'pg_isready', '-h', '127.0.0.1', '-U', 'baander', '-d', $database],
                    check: false,
                    timeout: null,
                );
                if ($pg->exitCode === 0) {
                    $ping = $docker->run(
                        [
                            'exec',
                            ...($mode === 'messaging' ? [] : ['-e', 'REDISCLI_AUTH=test-only']),
                            $redis, 'redis-cli', 'ping',
                        ],
                        check: false,
                        timeout: null,
                    );
                    if ($ping->exitCode === 0 && in_array($ping->stdout, ["PONG\n", 'PONG'], true)) {
                        $ready = true;
                        break;
                    }
                }
                $runtime->pause(1);
            }
            if (!$ready) {
                foreach ([$postgres, $redis] as $container) {
                    $diagnostic = $docker->run(['logs', '--tail', '40', $container], check: false, timeout: 10);
                    fwrite(STDERR, $diagnostic->stdout . $diagnostic->stderr);
                }
                throw new \RuntimeException(ucfirst($mode) . ' services did not become ready within 30 attempts.');
            }
            $sql = file_get_contents($runtime->root . '/tests/Fixtures/postgres-extensions.sql');
            Runtime::require($sql !== false, 'PostgreSQL extension fixture is missing.');
            $docker->run(
                ['exec', '-i', $postgres, 'psql', '-X', '-v', 'ON_ERROR_STOP=1', '-U', 'baander', '-d', $database],
                input: $sql,
                timeout: null,
            );
            $app = $id . '-app';
            $environment = $mode === 'messaging'
                ? [
                    'MESSENGER_TEST_REDIS_DSN=redis://redis:6379',
                    'OUTBOX_TEST_DATABASE_URL=postgresql://baander:test-only@postgres:5432/' . $database,
                ]
                : [
                    'BAANDER_TEST_CHECKOUT_IN_IMAGE=' . ($inImage ? '1' : '0'),
                    'APP_ENV=prod',
                    'APP_DEBUG=0',
                    'XDEBUG_MODE=off',
                    'REDIS_PASSWORD=test-only',
                    'REDIS_URL=redis://default:test-only@redis:6379',
                    'MESSENGER_TRANSPORT_DSN=redis://default:test-only@redis:6379/messages',
                    'DATABASE_URL=postgresql://baander:test-only@postgres:5432/' . $database . '?serverVersion=18&charset=utf8',
                ];
            $flags = [];
            foreach ($environment as $value) {
                array_push($flags, '-e', $value);
            }
            $app = $ledger->create(ResourceKind::Container, $app, [
                '--privileged',
                '--network', $id,
                '--entrypoint', 'sleep',
                ...$flags, $applicationImage, 'infinity',
            ]);
            $docker->run(['start', $app]);
            $directory = $inImage
                ? $layout->root
                : ($mode === 'messaging' ? '/tmp/baander-tests' : '/tmp/baander-' . $mode . '-runtime');
            if (!$inImage) {
                $paths = [
                    'vendor', 'src', 'tests', 'config', 'packages', 'migrations', 'bin',
                    'phpunit.xml.dist', '.env', '.env.test', 'composer.json', 'composer.lock',
                ];
                if ($mode !== 'messaging') {
                    array_push($paths, 'docker/general', 'templates', 'public', 'translations');
                }
                self::stage($runtime, $docker, $artifacts, $app, $paths, $directory);
            }
            foreach ($commands as $command) {
                $docker->run(['exec', '-w', $directory, $app, ...$command], timeout: null, interactive: true);
            }
            if ($mode === 'outbox') {
                fwrite(STDOUT, "Production outbox replay preserved projections and avoided duplicate Redis handoffs.\n");
            }
        }, $runtime->configuration->budgets->seconds('cleanup'));
    }

    /** @param list<string> $paths */
    private static function stage(
        Runtime $runtime,
        Client $docker,
        string $artifacts,
        string $container,
        array $paths,
        string $directory,
    ): void {
        $archive = $artifacts . '/checkout.tar';
        try {
            $runtime->run(['tar', '-cf', $archive, ...$paths], timeout: null);
            // The parent directory is private. Let the image's default user read
            // the copied archive and own the extracted checkout, as in the stream.
            Runtime::require(chmod($archive, 0644), 'Cannot prepare the private checkout archive.');
            $docker->run(['exec', $container, 'mkdir', '-p', $directory], timeout: null);
            $docker->run(['cp', $archive, $container . ':' . $directory . '/.fixture-checkout.tar'], timeout: null);
            $docker->run([
                'exec', $container, 'sh', '-ec',
                'tar -xf "$1/.fixture-checkout.tar" -C "$1"; rm "$1/.fixture-checkout.tar"',
                'fixture-stage', $directory,
            ], timeout: null);
        } finally {
            if (is_file($archive)) {
                unlink($archive);
            }
        }
    }
}
