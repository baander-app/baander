<?php

declare(strict_types=1);

namespace Baander\Tooling\Fixtures\Worker;

use Baander\Tooling\Development\Docker;
use Baander\Tooling\Fixtures\Runtime;
use Throwable;

final class Resources
{
    /** @var list<string> */
    public array $containers = [];
    /** @var list<string> */
    public array $networks = [];
    /** @var list<string> */
    public array $volumes = [];
    public readonly string $id;
    public readonly string $artifacts;
    public readonly Docker $docker;

    public function __construct(public readonly Runtime $runtime, string $name)
    {
        $this->id = 'baander-' . $name . '-' . time() . '-' . bin2hex(random_bytes(4));
        $this->artifacts = Runtime::artifacts($runtime->root, $name);
        $this->docker = $runtime->docker();
    }

    /** @param list<string> $paths */
    public function stage(string $container, array $paths, string $destination = '/tmp'): void
    {
        $archive = $this->artifacts . '/checkout.tar';
        try {
            $this->runtime->run(['tar', '-cf', $archive, ...$paths]);
            $this->docker->run(['cp', $archive, $container . ':/tmp/fixture-checkout.tar']);
            $this->docker->run([
                'exec',
                '--user',
                '0',
                $container,
                'sh',
                '-ec',
                'mkdir -p "$1"; tar -xf /tmp/fixture-checkout.tar -C "$1"; rm /tmp/fixture-checkout.tar',
                'fixture-stage',
                $destination,
            ]);
        } finally {
            if (is_file($archive)) {
                unlink($archive);
            }
        }
    }

    public function close(): void
    {
        $this->runtime->cleaning = true;
        $errors = [];
        foreach (array_reverse($this->containers) as $container) {
            $exists = null;
            try {
                $existing = $this->docker->run(
                    ['ps', '-a', '-q', '--filter', 'name=^/' . $container . '$'],
                    timeout: 15,
                );
                $exists = $existing->stdout
                    |> trim(...) !== '';
            } catch (Throwable $error) {
                $errors[] = $error->getMessage();
            }
            if ($exists === false) {
                continue;
            }
            try {
                $this->docker->run(
                    ['logs', $container],
                    check: false,
                    timeout: 10,
                    logPath: $this->artifacts . '/' . preg_replace(
                        '/[^a-zA-Z0-9_.-]/',
                        '_',
                        $container,
                    ) . '.log',
                );
            } catch (Throwable $error) {
                fwrite(STDERR, 'Log collection failed: ' . $error->getMessage() . "\n");
            }
            try {
                $result = $this->docker->run(
                    ['rm', '--force', '--volumes', $container],
                    check: false,
                    timeout: 20,
                );
                if ($result->exitCode !== 0) {
                    $errors[] = 'Cannot remove container ' . $container . ': ' . $result->stderr;
                }
            } catch (Throwable $error) {
                $errors[] = $error->getMessage();
            }
        }
        foreach (['volume' => $this->volumes, 'network' => $this->networks] as $kind => $names) {
            foreach (array_reverse($names) as $name) {
                try {
                    $result = $this->docker->run([$kind, 'rm', $name], check: false, timeout: 20);
                    if ($result->exitCode !== 0) {
                        $errors[] = 'Cannot remove ' . $kind . ' ' . $name . ': ' . $result->stderr;
                    }
                } catch (Throwable $error) {
                    $errors[] = $error->getMessage();
                }
            }
        }
        $remaining = [];
        foreach ([
            'containers' => ['ps', '-a', '-q'],
            'volumes' => ['volume', 'ls', '-q'],
            'networks' => [
                'network',
                'ls',
                '-q',
            ],
        ] as $kind => $command) {
            try {
                $result = $this->docker->run([...$command, '--filter', 'name=' . $this->id], timeout: 15);
                $remaining[$kind] = array_filter(preg_split('/\s+/', $result->stdout
                    |> trim(...)) ?: [])
                    |> array_values(...);
                if ($remaining[$kind] !== []) {
                    $errors[] = 'Owned ' . $kind . ' remain after cleanup';
                }
            } catch (Throwable $error) {
                $errors[] = $error->getMessage();
            }
        }
        Runtime::writeJson(
            $this->artifacts . '/cleanup.json',
            [
                'cleanupVerified' => $errors === [],
                'errors' => $errors,
                'remaining' => $remaining,
            ],
        );
        $this->runtime->close();
        Runtime::require($errors === [], 'Worker fixture cleanup failed: ' . implode('; ', $errors));
    }
}
