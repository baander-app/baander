<?php

declare(strict_types=1);

namespace Baander\Tooling\Fixtures\Worker;

use Baander\Tooling\Fixtures\Runtime;
use Swoole\Process;

final class LegacySupervisor
{
    public static function configuration(string $source, string $root): string
    {
        $configuration = parse_ini_string($source, true, INI_SCANNER_RAW);
        Runtime::require(is_array($configuration), 'Malformed legacy Supervisor configuration');
        unset($configuration['program:swoole']);
        foreach ($configuration as &$section) {
            foreach (['command', 'directory'] as $option) {
                if (isset($section[$option])) {
                    $section[$option] = str_replace('/var/www/html', $root, $section[$option]);
                }
            }
        }
        unset($section);
        $configuration['program:messenger-worker']['autorestart'] = 'false';
        $configuration['unix_http_server'] = ['file' => '/tmp/worker-supervisord.sock'];
        $configuration['supervisorctl'] = ['serverurl' => 'unix:///tmp/worker-supervisord.sock'];
        $configuration['rpcinterface:supervisor'] = ['supervisor.rpcinterface_factory' => 'supervisor.rpcinterface:make_main_rpcinterface'];
        $output = '';
        foreach ($configuration as $name => $section) {
            $output .= '[' . $name . "]\n";
            foreach ($section as $key => $value) {
                $output .= $key . ' = ' . $value . "\n";
            }
            $output .= "\n";
        }
        return $output;
    }

    /** @param list<string> $arguments */
    public static function main(array $arguments, string $root): int
    {
        Runtime::require($arguments === [], 'Legacy Supervisor fixture takes no arguments');
        $runtime = new Runtime($root, 300);
        $command = [PHP_BINARY, 'tests/Fixtures/messaging-runtime.php'];
        $await = static function (string $mode, int $seconds, array $values = []) use ($runtime, $command): void {
            $runtime->await(
                fn(): bool => $runtime->run([...$command, $mode, ...$values], check: false)->exitCode === 0,
                $seconds,
                1,
                'Historical transport check failed: ' . $mode,
            );
        };
        try {
            $runtime->run([...$command, 'prepare']);
            file_put_contents(
                '/tmp/worker-supervisord.conf',
                self::configuration(
                    file_get_contents($root . '/tests/Fixtures/Worker/legacy-supervisord.conf'),
                    $root,
                ),
            );
            $supervisor = $runtime->start(
                ['supervisord', '-c', '/tmp/worker-supervisord.conf'],
                timeout: 280,
                logPath: '/tmp/worker-supervisord.log',
                terminationGrace: 40,
            );
            $await('ready', 30);
            $runtime->run([...$command, 'send']);
            $await('handled', 30, ['1']);
            $lock = $runtime->start([...$command, 'hold-lock'], timeout: 200);
            $runtime->await(static fn(): bool => is_file('/tmp/baander-worker-test-lock-held'), 30, 1);
            $runtime->run([...$command, 'send']);
            $await('busy', 30);
            $original = $runtime->run([...$command, 'consumer-pid'])->stdout
                |> trim(...);
            Runtime::require(
                ctype_digit($original) && Process::kill((int) $original, 9),
                'Cannot terminate historical consumer',
            );
            $killed = Runtime::now();
            $await('unhealthy', 100);
            $stale = (int) (Runtime::now() - $killed);
            Runtime::require($stale <= 95, 'Killed worker read healthy beyond busy window');
            $runtime->run(['supervisorctl', '-c', '/tmp/worker-supervisord.conf', 'start', 'messenger-worker:*']);
            $await('ready', 30);
            touch('/tmp/baander-worker-test-release-lock');
            $runtime->wait($lock);
            $replacement = $runtime->run([...$command, 'consumer-pid'])->stdout
                |> trim(...);
            Runtime::require($replacement !== $original, 'Historical consumer was not replaced');
            $await('handled', 30, ['2']);
            $runtime->run([...$command, 'one-row-per-job', '2']);
            Runtime::require(
                $runtime->stop($supervisor)->exitCode === 0,
                'Historical Supervisor failed graceful shutdown',
            );
            Runtime::require(
                $runtime->run([...$command, 'ready'], check: false)->exitCode !== 0,
                'Stopped worker reported ready',
            );
            $runtime->run([...$command, 'stopped']);
            echo 'Supervisor consumed jobs, the killed consumer read unhealthy after ', $stale, " s, its replacement recovered unacknowledged work, and it stopped cleanly.\n";
            return 0;
        } finally {
            $runtime->close();
        }
    }
}
