<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Worker;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Blocking, bounded CLI transport for a trusted external controller, never the supervisor loop. */
#[Exclude]
final readonly class DockerWorkerCommand
{
    public function __construct(
        private string $binary,
        private string $endpoint = 'unix:///var/run/docker.sock',
        private float $timeoutSeconds = 10.0,
    ) {
        if (!str_starts_with($binary, '/') || !is_file($binary) || !is_executable($binary)
            || !preg_match('~\Aunix:///[^\x00-\x20]+\z~D', $endpoint)
            || !is_finite($timeoutSeconds) || $timeoutSeconds <= 0 || $timeoutSeconds > 60) {
            throw new \InvalidArgumentException('Docker controller requires an absolute executable, local Unix endpoint and bounded positive timeout.');
        }
    }

    /** @param list<string> $arguments Trusted adapter arguments, passed without a shell. */
    public function execute(array $arguments): string
    {
        $stdout = tmpfile();
        $stderr = tmpfile();
        if (!is_resource($stdout) || !is_resource($stderr)) {
            throw new \RuntimeException('Cannot allocate Docker command output.');
        }
        $child = null;
        $configDirectory = sys_get_temp_dir() . "/baander-docker-config-" . bin2hex(random_bytes(16));
        $configCreated = false;
        try {
            if (!mkdir($configDirectory, 0700)) {
                throw new \RuntimeException('Cannot isolate Docker command configuration.');
            }
            $configCreated = true;
            foreach ([$stdout, $stderr] as $stream) {
                if (!chmod(stream_get_meta_data($stream)['uri'], 0600)) {
                    throw new \RuntimeException('Cannot secure Docker command output.');
                }
            }
            $deadline = hrtime(true) / 1e9 + $this->timeoutSeconds;
            // Explicit endpoint and complete environment avoid ambient Docker contexts
            // selecting a different daemon between inspect and removal.
            $child = WorkerChildProcess::start([$this->binary, '--host', $this->endpoint, ...$arguments], '/', $stdout, $stderr,
                ['PATH' => '/usr/bin:/bin', 'DOCKER_CONFIG' => $configDirectory]);
            do {
                if (hrtime(true) / 1e9 >= $deadline) {
                    throw new \RuntimeException('Docker containment command timed out; outcome is uncertain.');
                }
                foreach ([$stdout, $stderr] as $stream) {
                    $stat = fstat($stream);
                    if ($stat === false || $stat['size'] > 16384) {
                        throw new \RuntimeException('Docker containment command exceeded its output limit.');
                    }
                }
                $running = $child->poll(hrtime(true) / 1e9);
                if ($running) {
                    usleep(1000);
                }
            } while ($running);
            if ($child->exitCode() !== 0 || $child->terminationSignal() !== null) {
                throw new \RuntimeException('Docker containment command failed; no retirement is confirmed.');
            }
            // Reopen independently: seeking the inherited descriptor could move a
            // still-writing child's offset. Read bounds also cover final append races.
            $output = '';
            foreach ([$stdout, $stderr] as $index => $stream) {
                $raw = file_get_contents(stream_get_meta_data($stream)['uri'], false, null, 0, 16385);
                if ($raw === false || strlen($raw) > 16384) {
                    throw new \RuntimeException('Docker containment command exceeded its output limit.');
                }
                if ($index === 0) {
                    $output = $raw;
                }
            }
            return $output;
        } finally {
            // Direct CLI cleanup only. A timed-out Docker operation may still run
            // in the daemon, so the caller must leave database ownership reserved.
            unset($child);
            fclose($stdout);
            fclose($stderr);
            if ($configCreated) {
                rmdir($configDirectory);
            }
        }
    }
}
