<?php

declare(strict_types=1);

namespace Baander\Tooling\Fixtures\Web;

use Baander\Tooling\Development\Docker;
use Baander\Tooling\Fixtures\Runtime;
use Baander\Tooling\Process\ProcessResult;
use Throwable;

/**
 * Runs the delivery server against disposable PostgreSQL, Redis and mail resources.
 */
final class RuntimeWorkflow
{
    public readonly Runtime $runtime;
    public readonly Docker $docker;
    public readonly string $id;
    public readonly string $appDirectory;
    private readonly string $serveLog;
    private ?string $archive = null;
    private ?string $archiveDirectory = null;
    public function __construct(private readonly string $root, private readonly bool $security = false)
    {
        $this->runtime = new Runtime($root, $security ? 360 : 180);
        $this->docker = $this->runtime->docker();
        $this->id = ($security ? 'baander-security-' : 'baander-web-') . time() . '-' . getmypid();
        $this->appDirectory = ($this->runtime->environment['BAANDER_TEST_CHECKOUT_IN_IMAGE'] ?? '0') === '1'
            ? '/var/www/html'
            : '/tmp/baander-' . ($security ? 'security' : 'web') . '-runtime';
        $this->serveLog = '/tmp/' . ($security ? 'security' : 'web') . '-runtime-serve.log';
    }

    public static function main(string $root): int
    {
        $workflow = new self($root);
        return $workflow->execute($workflow->scenarios(...));
    }

    /**
     * @param callable(self):void $scenarios
     */
    public function execute(callable $scenarios): int
    {
        $failed = true;
        try {
            $this->prepare();
            $scenarios($this);
            $failed = false;
            return 0;
        } finally {
            $this->runtime->cleaning = true;
            if ($failed) {
                try {
                    if ($this->docker->run(['inspect', $this->id . '-app'], check: false)->exitCode === 0) {
                        fwrite(STDERR, "--- app:serve output (last 100 lines)\n");
                        $log = $this->docker->run(['exec', $this->id . '-app', 'tail', '-n', '100', $this->serveLog], check: false);
                        fwrite(STDERR, $log->stdout . $log->stderr);
                    }
                } catch (Throwable $diagnostic) {
                    fwrite(STDERR, $diagnostic->getMessage() . "\n");
                }
            }
            $containers = [$this->id . '-app'];
            if (!$this->security) {
                $containers[] = $this->id . '-mailpit';
            }
            array_push($containers, $this->id . '-redis', $this->id . '-postgres');
            foreach ([['rm', '-f', ...$containers], ['network', 'rm', $this->id]] as $command) {
                try {
                    $this->docker->run($command, check: false);
                } catch (Throwable $cleanup) {
                    fwrite(STDERR, $cleanup->getMessage() . "\n");
                }
            }
            if ($this->archive !== null && is_file($this->archive)) {
                unlink($this->archive);
            }
            if ($this->archiveDirectory !== null && is_dir($this->archiveDirectory)) {
                rmdir($this->archiveDirectory);
            }
            $this->runtime->close();
        }
    }

    /**
     * @param non-empty-list<string> $arguments
     */
    public function app(array $arguments, ?string $input = null, bool $check = true): ProcessResult
    {
        return $this->docker->run(
            ['exec', '-i', '-w', $this->appDirectory, $this->id . '-app', ...$arguments],
            input: $input,
            check: $check,
        );
    }

    /**
     * @param non-empty-list<string> $arguments
     */
    public function fixture(array $arguments): void
    {
        $result = $this->app($arguments);
        fwrite(STDOUT, $result->stdout);
        fwrite(STDERR, $result->stderr);
    }

    public function redisReady(): bool
    {
        $result = $this->docker->run(['exec', '-e', 'REDISCLI_AUTH=test-only', $this->id . '-redis', 'redis-cli', 'ping'], check: false);
        return $result->exitCode === 0 && trim($result->stdout) === 'PONG';
    }

    private function postgresReady(bool $check = false): bool
    {
        return $this->docker->run(
            ['exec', $this->id . '-postgres', 'pg_isready', '-h', '127.0.0.1', '-U', 'baander', '-d', 'web_test'],
            check: $check,
        )->exitCode === 0;
    }

    /**
     * Polls the same bounded attempt count as the container qualification scripts.
     */
    public function attempts(callable $condition, int $count, float $interval = 1): void
    {
        for ($attempt = 0; $attempt < $count; ++$attempt) {
            if ($condition()) {
                return;
            }
            $this->runtime->pause($interval);
        }
    }

    private function prepare(): void
    {
        $this->docker->run(['network', 'create', '--internal', $this->id]);
        $this->docker->run([
            'run',
            '-d',
            '--name',
            $this->id . '-redis',
            '--network',
            $this->id,
            '--network-alias',
            'redis',
            '-e',
            'REDIS_ARGS=--requirepass test-only',
            'redis/redis-stack-server:edge',
        ]);
        $this->docker->run([
            'run',
            '-d',
            '--name',
            $this->id . '-postgres',
            '--network',
            $this->id,
            '--network-alias',
            'postgres',
            '-e',
            'POSTGRES_USER=baander',
            '-e',
            'POSTGRES_PASSWORD=test-only',
            '-e',
            'POSTGRES_DB=web_test',
            $this->image('BAANDER_TEST_POSTGRES_IMAGE', 'baander-database:latest'),
        ]);
        if (!$this->security) {
            $this->docker->run([
                'run',
                '-d',
                '--name',
                $this->id . '-mailpit',
                '--network',
                $this->id,
                '--network-alias',
                'mailpit',
                'axllent/mailpit:v1.31.4',
            ]);
        }
        $this->attempts(fn(): bool => $this->postgresReady() && $this->redisReady() && $this->mailReady(), 30);
        $this->postgresReady(check: true);
        Runtime::require($this->redisReady(), 'Redis did not answer PONG.');
        $this->mailReady(check: true);
        $this->startApp();
        $this->stageCheckout();
        $this->app(['mkdir', '-p', 'storage/media', 'storage/conversions', ...$this->security ? ['var/log'] : []]);
        $this->app([
            'sh',
            '-c',
            'test -f config/secrets/oauth/private.key || (umask 077 && mkdir -p config/secrets/oauth && '
                . 'openssl genrsa -out config/secrets/oauth/private.key 2048 2>/dev/null && '
                . 'openssl rsa -in config/secrets/oauth/private.key -pubout -out config/secrets/oauth/public.key 2>/dev/null)',
        ]);
        $this->app(['php', 'bin/console', 'doctrine:migrations:migrate', '--no-interaction']);
        if (!$this->security) {
            $this->provisionUsers();
        }
        $this->docker->run([
            'exec',
            '-d',
            '-w',
            $this->appDirectory,
            $this->id . '-app',
            'sh',
            '-c',
            'exec php bin/console app:serve --no-interaction > ' . $this->serveLog . ' 2>&1',
        ]);
        $this->attempts(
            fn(): bool => $this->app(['curl', '-fsS', '-o', '/dev/null', 'http://127.0.0.1:9501/live'], check: false)->exitCode === 0,
            60,
        );
        $this->app(['curl', '-fsS', '-o', '/dev/null', 'http://127.0.0.1:9501/live']);
    }

    private function mailReady(bool $check = false): bool
    {
        return $this->security || $this->docker->run(['exec', $this->id . '-mailpit', '/mailpit', 'readyz'], check: $check)->exitCode === 0;
    }

    private function image(string $variable, string $fallback): string
    {
        $configured = $this->runtime->environment[$variable] ?? '';
        return $configured === '' ? $fallback : $configured;
    }

    private function startApp(): void
    {
        $kind = $this->security ? 'security' : 'web';
        $environment = [
            'APP_ENV=prod',
            'APP_DEBUG=0',
            'XDEBUG_MODE=off',
            'OTEL_ENABLED=false',
            "APP_SECRET={$kind}-runtime-test-only-secret",
            "STREAM_URL_HMAC_SECRET={$kind}-runtime-test-only-hmac",
            'APP_URL=https://baander.app',
            'DEFAULT_URI=https://baander.app',
            'APP_DOMAIN=http://127.0.0.1:9501',
            'HEALTH_MONITOR_INTERVAL_SECONDS=2',
            'MAILER_DSN=smtp://mailpit:1025',
            'MAIL_FROM_ADDRESS=noreply@baander.app',
            'MEDIA_STORAGE_PATH=' . $this->appDirectory . '/storage/media',
            'CONVERT_STORAGE_PATH=' . $this->appDirectory . '/storage/conversions',
            'REDIS_PASSWORD=test-only',
            'REDIS_URL=redis://default:test-only@redis:6379',
            'MESSENGER_TRANSPORT_DSN=redis://default:test-only@redis:6379/messages',
            'DATABASE_URL=postgresql://baander:test-only@postgres:5432/web_test?serverVersion=18&charset=utf8',
        ];
        $arguments = [
            'run',
            '-d',
            '--name',
            $this->id . '-app',
            '--privileged',
            '--network',
            $this->id,
            '--entrypoint',
            'sleep',
        ];
        foreach ($environment as $variable) {
            array_push($arguments, '-e', $variable);
        }
        $this->docker->run([
            ...$arguments,
            $this->image('BAANDER_TEST_IMAGE', 'martinjuul/baander-app:latest'),
            'infinity',
        ]);
    }

    private function stageCheckout(): void
    {
        if ($this->appDirectory === '/var/www/html') {
            return;
        }
        $this->docker->run(['exec', $this->id . '-app', 'mkdir', '-p', $this->appDirectory]);
        $directory = sys_get_temp_dir() . '/baander-web-checkout-' . bin2hex(random_bytes(8));
        Runtime::require(mkdir($directory, 0700), 'Cannot reserve private checkout directory.');
        $this->archiveDirectory = $directory;
        $this->archive = $directory . '/checkout.tar';
        $paths = [
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
            ...$this->security ? [] : ['scripts/web-runtime'],
            'phpunit.xml.dist',
            '.env',
            '.env.test',
            'composer.json',
            'composer.lock',
            'translations',
        ];
        $this->runtime->run([
            'tar',
            ...$this->security ? ['--exclude=config/secrets/oauth', '--exclude=config/secrets/*.decrypt.private.php'] : [],
            '-cf',
            $this->archive,
            ...$paths,
        ]);
        Runtime::require(chmod($this->archive, 0644), 'Cannot make copied checkout readable in the app container.');
        $destination = '/tmp/baander-runtime-checkout.tar';
        $this->docker->run(['cp', $this->archive, $this->id . '-app:' . $destination]);
        $this->app(['tar', '-xf', $destination]);
    }

    private function provisionUsers(): void
    {
        foreach ([
            ['web-runtime-dansk@baander.app', 'Web Runtime Dansk', [], "Web-runtime-test-only-1\n"],
            [
                'web-runtime-admin@baander.app',
                'Web Runtime Admin',
                ['--role', 'admin', '--force'],
                "Web-runtime-test-only-2\n",
            ],
            [
                'web-runtime-super-admin@baander.app',
                'Web Runtime Super Admin',
                ['--role', 'super-admin', '--force'],
                "Web-runtime-test-only-3\n",
            ],
        ] as [$email, $name, $options, $password]) {
            $this->app(
                [
                    'php',
                    'bin/console',
                    'app:user:create',
                    '--no-interaction',
                    '--password',
                    ...$options,
                    $email,
                    $name,
                ],
                $password,
            );
            if ($email === 'web-runtime-dansk@baander.app') {
                $this->app(['php', 'bin/console', 'app:user:setting', 'set', $email, 'language', 'da', '--no-interaction']);
            }
        }
        $this->app(['php', 'bin/console', 'app:settings:set', 'transcode.enabled', 'true', '--no-interaction']);
        $this->app(['php', 'bin/console', 'app:auth:setup-clients', '--no-interaction']);
        $this->fixture(['php', 'tests/Fixtures/web-runtime.php', 'prepare']);
    }

    private function scenarios(): void
    {
        echo "app:serve answers /live in the production environment.\n";
        $status = json_decode(
            $this->app(['curl', '-fsS', 'http://127.0.0.1:9200/api/server'])->stdout,
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        Runtime::require(
            ($status['server']['settings']['task_worker_num'] ?? 0) === 0,
            'Delivery server unexpectedly has task workers.',
        );
        $this->fixture(['php', 'tests/Fixtures/web-runtime.php', 'mail']);
        $this->fixture(['php', 'tests/Fixtures/web-runtime.php', 'stream']);
        $answers = $this->app(['php', 'tests/Fixtures/web-runtime.php', 'safari'])->stdout;
        $baseline = $this->root . '/tests/Fixtures/WebRuntime/safari-in-progress.txt';
        $actual = rtrim($answers, "\n") . "\n";
        if (file_get_contents($baseline) !== $actual) {
            $this->runtime->run(['diff', '-u', $baseline, '-'], input: $actual, check: false, interactive: false)
            |> static function (ProcessResult $difference): void {
                fwrite(STDERR, $difference->stdout . $difference->stderr);
            };
            throw new \RuntimeException('The answers to the Safari request sequence differ from the baseline (diff above).');
        }
        echo "The answers to the Safari request sequence match the baseline.\n";
        $this->postgresOutage();
        $this->redisOutage();
    }

    /**
     * @return list<string>
     */
    private function workers(): array
    {
        $master = trim($this->app(['pgrep', '-o', '-f', '^php bin/console app:serve'], check: false)->stdout);
        if ($master === '') {
            return [];
        }
        $manager = trim($this->app(['pgrep', '-P', $master, '-f', '^php bin/console app:serve'], check: false)->stdout);
        if ($manager === '') {
            return [];
        }
        $workers = trim($this->app(['pgrep', '-P', $manager], check: false)->stdout)
            |> (static fn(string $text): array => $text === '' ? [] : explode("\n", $text));
        sort($workers, SORT_STRING);
        return $workers;
    }

    private function degradationCount(string $component): int
    {
        return $this->app(['grep', '-F', 'Health degradation detected', 'var/log/prod.log'], check: false)->stdout
            |> (static fn(string $text): array => explode("\n", $text))
            |> (static fn(array $lines): array => array_filter($lines, static fn(string $line): bool => str_contains($line, '"component":"' . $component . '"')))
            |> count(...);
    }

    private function postgresOutage(): void
    {
        $stopped = time();
        $this->docker->run(['stop', $this->id . '-postgres']);
        $this->runtime->pause(4);
        $this->attempts(fn(): bool => $this->degradationCount('postgresql') >= 1, 10);
        Runtime::require(
            $this->degradationCount('postgresql') === 1,
            'The health monitor did not report PostgreSQL unhealthy while it was stopped.',
        );
        $old = $this->workers();
        Runtime::require($old !== [], 'The delivery server has no HTTP workers.');
        $reloaded = time();
        $this->app(['curl', '-fsS', '-o', '/dev/null', '-X', 'PATCH', 'http://127.0.0.1:9200/api/server']);
        $new = [];
        $this->attempts(
            function () use ($old, &$new): bool {
                $new = $this->workers();
                return count($new) === count($old) && array_intersect($old, $new) === [];
            },
            40,
            0.5,
        );
        Runtime::require(
            count($new) === count($old) && array_intersect($old, $new) === [],
            'The server did not replace its workers after the reload (before: ' . implode(' ', $old) . '; after: ' . implode(' ', $new) . ').',
        );
        echo "PostgreSQL stopped; the monitor saw it and the server reloaded its workers during the outage.\n";
        $started = time();
        $this->docker->run(['start', $this->id . '-postgres']);
        $this->attempts($this->postgresReady(...), 30);
        $this->postgresReady(check: true);
        $arguments = [
            'php',
            'tests/Fixtures/web-runtime.php',
            'alert',
            (string) $stopped,
            (string) $reloaded,
            (string) $started,
        ];
        $result = $this->app([...$arguments, 'wait']);
        echo $result->stdout;
        $this->runtime->pause(6);
        $this->app($arguments);
        echo "No second alert arrived in the next 6 seconds.\n";
    }

    private function redisAlerts(): string
    {
        return rtrim(
            $this->docker->run([
                'exec',
                '-e',
                'PGPASSWORD=test-only',
                $this->id . '-postgres',
                'psql',
                '-h',
                '127.0.0.1',
                '-U',
                'baander',
                '-d',
                'web_test',
                '-tA',
                '-c',
                "SELECT u.email FROM notifications n JOIN users u ON u.id = n.user_id\n"
                    . " WHERE n.event_type = 'admin.health_degraded' AND n.reference_data->>'component' = 'redis'\n ORDER BY u.email",
            ])->stdout,
            "\n",
        );
    }

    private function redisOutage(): void
    {
        $fixture = ['php', 'scripts/web-runtime/redis-outage.php'];
        $admins = "web-runtime-admin@baander.app\nweb-runtime-super-admin@baander.app";
        $this->fixture([...$fixture, 'login']);
        $this->docker->run(['stop', $this->id . '-redis']);
        $result = $this->app([...$fixture, 'outage']);
        echo $result->stdout;
        $this->attempts(fn(): bool => $this->degradationCount('redis') >= 1, 10);
        Runtime::require(
            $this->degradationCount('redis') === 1,
            'The health monitor did not report Redis unhealthy while it was stopped.',
        );
        echo "Redis stopped; the monitor saw it.\n";
        $this->docker->run(['start', $this->id . '-redis']);
        $this->attempts($this->redisReady(...), 30);
        Runtime::require($this->redisReady(), 'Redis did not recover.');
        $result = $this->app([...$fixture, 'recovered']);
        echo $result->stdout;
        $this->attempts(fn(): bool => $this->redisAlerts() === $admins, 20);
        Runtime::require(
            $this->redisAlerts() === $admins,
            'Expected one Redis alert for each of ' . str_replace("\n", ' ', $admins) . ', got: ' . $this->redisAlerts(),
        );
        $this->runtime->pause(6);
        Runtime::require($this->redisAlerts() === $admins, 'A second Redis alert arrived: ' . $this->redisAlerts());
        echo "Each administrator has one Redis alert, and no second alert arrived in the next 6 seconds.\n";
    }
}
