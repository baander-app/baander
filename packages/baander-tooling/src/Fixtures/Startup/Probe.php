<?php

declare(strict_types=1);

namespace Baander\Tooling\Fixtures\Startup;

use Baander\Tooling\Filesystem\{AtomicFile, PrivateDirectory};
use Baander\Tooling\Configuration\ApplicationLayout;
use Baander\Tooling\Fixtures\Runtime;

final class Probe
{
    private const string RM = <<<'PHP_CODE'
        <?php
        $case = getenv('PROBE_CASE');
        if (array_slice($_SERVER['argv'], 1) !== ['-rf', getenv('APP_HOME') . '/var/cache/dev']) {
            fwrite(STDERR, "Unexpected startup cleanup command\n");
            exit(91);
        }
        file_put_contents($case . '/events', "cleanup\n", FILE_APPEND);
        unlink($case . '/cache/dev/marker');
        rmdir($case . '/cache/dev');
        PHP_CODE;

    private const string PHP = <<<'PHP_CODE'
        <?php
        $case = getenv('PROBE_CASE');
        $arguments = array_slice($_SERVER['argv'], 1)
            |> (static fn(array $values): string => implode("\0", $values) . "\0");
        file_put_contents($case . '/arguments', $arguments);
        file_put_contents($case . '/pid', (string) getmypid());
        file_put_contents(
            $case . '/events',
            file_exists($case . '/cache/dev/marker') ? "cache-present\n" : "cache-absent\n",
            FILE_APPEND,
        );
        if (getenv('PROBE_MODE') === 'exit') {
            exit(47);
        }
        $terminated = false;
        Swoole\Process::signal(15, static function () use (&$terminated): void {
            $terminated = true;
        });
        $timer = Swoole\Timer::tick(10, static function (): void {});
        touch($case . '/ready');
        while (!$terminated) {
            Swoole\Event::dispatch();
        }
        Swoole\Timer::clear($timer);
        Swoole\Process::signal(15, null);
        Swoole\Event::wait();
        file_put_contents($case . '/signal', "TERM\n");
        exit(73);
        PHP_CODE;

    public static function main(string $root): int
    {
        $startup = $root . '/docker/general/start-web.sh';
        Runtime::require(is_file($startup), 'The product web entrypoint is missing.');
        $directory = rtrim(getenv('TMPDIR') ?: sys_get_temp_dir(), '/') . '/baander-startup-' . bin2hex(random_bytes(8));
        PrivateDirectory::prepare($directory . '/bin');
        $runtime = new Runtime($root, 40);
        try {
            foreach (['rm' => self::RM, 'php' => self::PHP] as $name => $code) {
                AtomicFile::write($directory . '/bin/' . $name, '#!' . PHP_BINARY . "\n" . $code . "\n", 0700);
            }
            foreach ([null, 'prod', 'dev', 'test', ''] as $index => $environment) {
                self::runCase($runtime, $startup, $directory, $index . '-exit', $environment, false);
            }
            self::runCase($runtime, $startup, $directory, 'prod-signal', 'prod', true);
            self::runCase($runtime, $startup, $directory, 'dev-signal', 'dev', true);
            fwrite(STDOUT, "Web startup preserves arguments, exec PID, signals and exit status; only dev cache is cleared.\n");
            return 0;
        } finally {
            $runtime->close();
            self::remove($directory);
        }
    }

    private static function runCase(
        Runtime $runtime,
        string $startup,
        string $directory,
        string $name,
        ?string $environment,
        bool $signal,
    ): void {
        $case = $directory . '/' . $name;
        PrivateDirectory::prepare($case . '/cache/dev');
        PrivateDirectory::prepare($case . '/cache/prod');
        touch($case . '/cache/dev/marker');
        touch($case . '/cache/prod/marker');
        $env = $runtime->environment;
        unset($env['APP_ENV']);
        $env['PATH'] = $directory . '/bin:' . ($env['PATH'] ?? '/usr/bin:/bin');
        $env['PROBE_CASE'] = $case;
        $env['PROBE_MODE'] = $signal ? 'signal' : 'exit';
        $env['APP_HOME'] = ApplicationLayout::load()->root;
        if ($environment !== null) {
            $env['APP_ENV'] = $environment;
        }
        $extra = ['--host', 'value with spaces', '', "line\nbreak", '*literal*'];
        // Record the initial shell PID before execing the unchanged product entrypoint.
        $process = $runtime->start(
            [
                '/bin/sh', '-c',
                'printf "%s" "$$" > "$PROBE_CASE/launched-pid"; exec /bin/sh "$@"',
                'startup-probe', $startup, ...$extra,
            ],
            env: $env,
            timeout: 5,
        );
        if ($signal) {
            $runtime->await(
                static function () use ($runtime, $process, $case): bool {
                    Runtime::require($runtime->alive($process), 'Startup exited before the PHP probe became ready.');
                    return is_file($case . '/ready');
                },
                5,
                .01,
            );
            $result = $runtime->stop($process);
        } else {
            $result = $runtime->wait($process, check: false);
        }
        Runtime::require(!$result->timedOut, 'Startup probe timed out: ' . $name);
        Runtime::require(is_file($case . '/pid'), 'The startup PHP probe did not run: ' . $result->stderr);
        Runtime::require(
            file_get_contents($case . '/pid') === file_get_contents($case . '/launched-pid'),
            'Startup did not exec PHP with the original PID.',
        );
        $expected = [$env['APP_HOME'] . '/bin/console', 'app:serve', '--no-interaction', ...$extra]
            |> (static fn(array $arguments): string => implode("\0", $arguments) . "\0");
        Runtime::require(file_get_contents($case . '/arguments') === $expected, 'Startup changed the forwarded arguments.');
        Runtime::require(is_file($case . '/cache/prod/marker'), 'Startup changed the production-cache fixture.');
        $events = $environment === 'dev' ? "cleanup\ncache-absent\n" : "cache-present\n";
        Runtime::require(
            file_get_contents($case . '/events') === $events,
            'Startup cleared cache outside the explicit dev environment.',
        );
        Runtime::require(
            $environment === 'dev' ? !file_exists($case . '/cache/dev') : is_file($case . '/cache/dev/marker'),
            'Startup changed the wrong cache fixture.',
        );
        Runtime::require(
            $result->exitCode === ($signal ? 73 : 47),
            'Startup changed the PHP exit status for ' . $name . ': ' . $result->exitCode . ' ' . $result->stderr,
        );
        if ($signal) {
            Runtime::require(file_get_contents($case . '/signal') === "TERM\n", 'Startup did not forward TERM to PHP.');
        }
    }

    private static function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $name) {
                if ($name !== '.' && $name !== '..') {
                    self::remove($path . '/' . $name);
                }
            }
            rmdir($path);
        } elseif (file_exists($path) || is_link($path)) {
            unlink($path);
        }
    }
}
