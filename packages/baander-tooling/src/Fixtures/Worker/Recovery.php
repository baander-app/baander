<?php

declare(strict_types=1);

namespace Baander\Tooling\Fixtures\Worker;

use Baander\Tooling\Fixtures\Runtime;

final class Recovery
{
    /** @param list<string> $arguments */
    public static function main(array $arguments, string $root): int
    {
        Runtime::require($arguments === [], 'Recovery qualification takes no arguments');
        Runtime::require(is_file($root . '/vendor/autoload.php'), 'Install Composer dependencies first');
        Runtime::require(extension_loaded('pdo_pgsql'), 'Host PHP needs pdo_pgsql');
        $runtime = new Runtime($root);
        $resources = new Resources($runtime, 'recovery');
        $docker = $resources->docker;
        $runtime->environment = array_replace(
            $runtime->environment,
            [
                'POSTGRES_USER' => 'worker_recovery',
                'POSTGRES_DB' => 'worker_recovery',
                'POSTGRES_PASSWORD' => bin2hex(random_bytes(24)),
                'PGCONNECT_TIMEOUT' => '5',
            ],
        );
        try {
            $resources->containers[] = $resources->id . '-pg';
            $id = $docker->run([
                'run',
                '--detach',
                '--name',
                $resources->id . '-pg',
                '--publish',
                '127.0.0.1::5432',
                '--memory',
                '512m',
                '--cpus',
                '1',
                '--pids-limit',
                '64',
                '--env',
                'POSTGRES_USER',
                '--env',
                'POSTGRES_PASSWORD',
                '--env',
                'POSTGRES_DB',
                $runtime->environment['BAANDER_TEST_POSTGRES_IMAGE'] ?? 'baander-database:latest',
            ])->stdout
                |> trim(...);
            $runtime->await(
                fn(): bool => $docker->run(
                    [
                        'exec',
                        $id,
                        'pg_isready',
                        '-h',
                        '127.0.0.1',
                        '-U',
                        'worker_recovery',
                        '-d',
                        'worker_recovery',
                    ],
                    check: false,
                )->exitCode === 0,
                30,
                1,
                'Disposable PostgreSQL failed to become ready',
            );
            $docker->run(
                [
                    'exec',
                    '-i',
                    $id,
                    'psql',
                    '-X',
                    '-v',
                    'ON_ERROR_STOP=1',
                    '-U',
                    'worker_recovery',
                    '-d',
                    'worker_recovery',
                ],
                input: file_get_contents($root . '/tests/Fixtures/postgres-extensions.sql'),
            );
            $mapping = $docker->run(['port', $id, '5432/tcp'])->stdout
                |> trim(...);
            Runtime::require(
                preg_match('/^127\.0\.0\.1:([0-9]+)$/D', $mapping, $match) === 1,
                'Unexpected PostgreSQL port mapping',
            );
            $runtime->environment['WORKER_RECOVERY_PG_PORT'] = $match[1];
            $endpoint = $docker->configuration['host'] ?? $docker->run([
                'context',
                'inspect',
                '--format',
                '{{.Endpoints.docker.Host}}',
            ])->stdout
                |> trim(...);
            $image = $docker->run([
                'image',
                'inspect',
                '--format',
                '{{.Id}}',
                $runtime->environment['BAANDER_TEST_IMAGE'] ?? 'martinjuul/baander-app:latest',
            ])->stdout
                |> trim(...);
            $runtime->run(
                [
                    PHP_BINARY,
                    'tests/Fixtures/Worker/recover-deployment.php',
                    $docker->configuration['executable'],
                    $endpoint,
                    $image,
                ],
                timeout: 120,
            );
            echo "PASS: real Docker + PostgreSQL paired external-controller recovery\n";
            return 0;
        } finally {
            $resources->close();
        }
    }
}
