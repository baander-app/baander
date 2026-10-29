<?php

declare(strict_types=1);

use Baander\Tooling\Development\Acceptance\Commands;

require dirname(__DIR__, 3) . '/bootstrap.php';

$argv = $_SERVER['argv'];

switch ($argv[1]) {
    case 'output':
        echo 'qualified-revision';
        fwrite(STDERR, 'command-diagnostic');
        break;
    case 'producer':
        for ($part = 0; $part < 256; $part++) {
            fwrite(STDOUT, str_repeat('o', 8192));
            fwrite(STDERR, str_repeat('e', 8192));
        }
        file_put_contents($argv[2], 'complete');
        break;
    case 'poll':
        $deadline = hrtime(true) + 4_000_000_000;
        while (!is_file($argv[2]) && hrtime(true) < $deadline) {
            usleep(5000);
        }
        if (!is_file($argv[2])) {
            fwrite(STDERR, 'Producer blocked while polling.');
            exit(19);
        }
        echo 'producer-observed';
        break;
    case 'silent':
        file_put_contents($argv[2], (string) getmypid());
        echo 'silent-child-ready';
        sleep(30);
        break;
    case 'pause-ownership-driver':
        $executor = new \Baander\Tooling\Process\EngineExecutor(new \Baander\Tooling\Process\ExecutionContext(getenv()));
        $commands = new Commands($argv[2], $argv[2] . '/logs', $executor, getenv());
        $commands->pause(10000);
        $commands->pause(10000);
        $executor->close();
        echo 'signal-ownership-intact';
        break;
    case 'polling-signal-driver':
        $root = $argv[2];
        $signal = constant($argv[3]);
        $executor = new \Baander\Tooling\Process\EngineExecutor(new \Baander\Tooling\Process\ExecutionContext(getenv()));
        $commands = new Commands($root, $root . '/logs', $executor, getenv());
        Swoole\Timer::after(25, static function () use ($signal): void {
            Swoole\Process::kill(getmypid(), $signal);
        });
        try {
            $commands->pause(500000);
        } catch (Throwable $error) {
            file_put_contents($root . '/failure-preserved', $error->getMessage());
            $timers = Swoole\Timer::stats()['num'];
            $repeatedStatus = null;
            try {
                $commands->pause(10000);
            } catch (\Baander\Tooling\Process\CommandFailure $repeated) {
                $repeatedStatus = $repeated->getCode();
            }
            $cleanupExecutor = new \Baander\Tooling\Process\EngineExecutor(\Baander\Tooling\Process\ExecutionContext::bounded(2, getenv(), ignoreCancellation: true));
            try {
                $cleanup = $cleanupExecutor->run(new \Baander\Tooling\Process\ProcessSpec([
                    PHP_BINARY, '-r', 'file_put_contents($argv[1], "complete"); echo "cleaned";', $root . '/cleanup-finished',
                ]))->stdout;
            } finally {
                $cleanupExecutor->close();
                $executor->close();
            }
            echo json_encode(['message' => $error->getMessage(), 'status' => $error->getCode(), 'repeatedStatus' => $repeatedStatus, 'timers' => $timers, 'cleanup' => $cleanup], JSON_THROW_ON_ERROR);
            exit($error->getCode() ?: 1);
        }
        exit(2);
    case 'pause-driver':
        $commands = new Commands($argv[2], $argv[2] . '/logs', new \Baander\Tooling\Process\EngineExecutor(new \Baander\Tooling\Process\ExecutionContext(getenv())), getenv());
        $started = hrtime(true);
        $commands->pause(10000);
        $idleSeconds = (hrtime(true) - $started) / 1000000000;
        $producer = $commands->start([PHP_BINARY, __FILE__, 'producer', $argv[2] . '/finished'], timeout: 2);
        $deadline = hrtime(true) + 2000000000;
        do {
            $commands->pause(20000);
        } while (!is_file($argv[2] . '/finished') && hrtime(true) < $deadline);
        $result = $commands->wait($producer);
        echo json_encode(['idleSeconds' => $idleSeconds, 'status' => $result->exitCode], JSON_THROW_ON_ERROR);
        break;
    case 'signal-driver':
        $commands = new Commands($argv[2], $argv[2] . '/logs', new \Baander\Tooling\Process\EngineExecutor(new \Baander\Tooling\Process\ExecutionContext(getenv())), getenv());
        $timer = Swoole\Timer::tick(
            10,
            static function () use ($argv, &$timer): void {
                if (is_file($argv[2] . '/child-pid')) {
                    Swoole\Timer::clear($timer);
                    Swoole\Process::kill(getmypid(), SIGTERM);
                }
            },
        );
        $cancelled = false;
        try {
            $commands->run([PHP_BINARY, __FILE__, 'silent', $argv[2] . '/child-pid']);
        } catch (RuntimeException) {
            $cancelled = true;
        } finally {
            if (Swoole\Timer::exists($timer)) {
                Swoole\Timer::clear($timer);
            }
        }
        echo json_encode(['cancelled' => $cancelled], JSON_THROW_ON_ERROR);
        break;
    default:
        throw new RuntimeException('Unknown acceptance command fixture mode.');
}
