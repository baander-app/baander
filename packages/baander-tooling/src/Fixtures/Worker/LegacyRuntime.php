<?php

declare(strict_types=1);

namespace Baander\Tooling\Fixtures\Worker;

use Baander\Tooling\Fixtures\Runtime;

final class LegacyRuntime
{
    /** @param list<string> $arguments */
    public static function main(array $arguments, string $root): int
    {
        Runtime::require($arguments === [], 'Historical worker transport qualification takes no arguments');
        $runtime = new Runtime($root, 400);
        $resources = new Resources($runtime, 'worker');
        $docker = $resources->docker;
        $id = $resources->id;
        $resources->networks[] = $id;
        $resources->containers = [$id . '-redis', $id . '-postgres', $id . '-app'];
        try {
            $docker->run(['network', 'create', '--internal', $id]);
            $docker->run([
                'run',
                '-d',
                '--name',
                $id . '-redis',
                '--network',
                $id,
                '--network-alias',
                'redis',
                '-e',
                'REDIS_ARGS=--requirepass test-only',
                'redis/redis-stack-server:edge',
            ]);
            $docker->run([
                'run',
                '-d',
                '--name',
                $id . '-postgres',
                '--network',
                $id,
                '--network-alias',
                'postgres',
                '-e',
                'POSTGRES_USER=baander',
                '-e',
                'POSTGRES_PASSWORD=test-only',
                '-e',
                'POSTGRES_DB=worker_test',
                $runtime->environment['BAANDER_TEST_POSTGRES_IMAGE'] ?? 'baander-database:latest',
            ]);
            $runtime->await(
                fn(): bool => $docker->run(
                    [
                        'exec',
                        $id . '-postgres',
                        'pg_isready',
                        '-h',
                        '127.0.0.1',
                        '-U',
                        'baander',
                        '-d',
                        'worker_test',
                    ],
                    check: false,
                )->exitCode === 0
                && $docker->run(
                    [
                        'exec',
                        '-e',
                        'REDISCLI_AUTH=test-only',
                        $id . '-redis',
                        'redis-cli',
                        'ping',
                    ],
                    check: false,
                )->stdout
                    |> trim(...) === 'PONG',
                30,
                1,
            );
            $docker->run(
                [
                    'exec',
                    '-i',
                    $id . '-postgres',
                    'psql',
                    '-X',
                    '-v',
                    'ON_ERROR_STOP=1',
                    '-U',
                    'baander',
                    '-d',
                    'worker_test',
                ],
                input: file_get_contents($root . '/tests/Fixtures/postgres-extensions.sql'),
            );
            $checkout = ($runtime->environment['BAANDER_TEST_CHECKOUT_IN_IMAGE'] ?? '0') === '1';
            $directory = $checkout ? '/var/www/html' : '/tmp/baander-worker-runtime';
            $docker->run([
                'create',
                '--name',
                $id . '-app',
                '--privileged',
                '--network',
                $id,
                '--workdir',
                $directory,
                '--entrypoint',
                'sh',
                '-e',
                'APP_ENV=prod',
                '-e',
                'APP_DEBUG=0',
                '-e',
                'XDEBUG_MODE=off',
                '-e',
                'REDIS_PASSWORD=test-only',
                '-e',
                'REDIS_URL=redis://default:test-only@redis:6379',
                '-e',
                'MESSENGER_TRANSPORT_DSN=redis://default:test-only@redis:6379/messages',
                '-e',
                'DATABASE_URL=postgresql://baander:test-only@postgres:5432/worker_test?serverVersion=18&charset=utf8',
                $runtime->environment['BAANDER_TEST_IMAGE'] ?? 'martinjuul/baander-app:latest',
                '-c',
                'exec sleep infinity',
            ]);
            $docker->run(['start', $id . '-app']);
            if (!$checkout) {
                $resources->stage(
                    $id . '-app',
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
                        'phpunit.xml.dist',
                        '.env',
                        '.env.test',
                        'composer.json',
                        'composer.lock',
                        'translations',
                    ],
                    $directory,
                );
                $docker->run(['exec', '--user', '0', $id . '-app', 'chown', '-R', 'www-data:www-data', $directory]);
            }
            $runtime->run(
                [
                    ...$docker->commandPrefix(),
                    'exec',
                    '-e',
                    'BAANDER_TEST_ARTIFACTS=/tmp/' . $id . '-supervisor',
                    $id . '-app',
                    'php',
                    'tests/Fixtures/Worker/legacy-runtime.php',
                ],
                timeout: 300,
                logPath: $resources->artifacts . '/runtime.log',
                terminationGrace: 40,
            );
            echo file_get_contents($resources->artifacts . '/runtime.log');
            return 0;
        } finally {
            $runtime->cleaning = true;
            try {
                $collected = $docker->run(
                    ['cp', $id . '-app:/tmp/' . $id . '-supervisor', $resources->artifacts . '/supervisor'],
                    check: false,
                    timeout: 15,
                );
                if ($collected->exitCode !== 0) {
                    fwrite(STDERR, 'Supervisor log collection failed: ' . $collected->stderr . "\n");
                }
            } catch (\Throwable $error) {
                fwrite(STDERR, 'Supervisor log collection failed: ' . $error->getMessage() . "\n");
            }
            $resources->close();
        }
    }
}
