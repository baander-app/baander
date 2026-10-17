<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Worker;

use App\Transcode\Application\Port\AudioRenditionRuntimePort;
use App\Transcode\Application\Port\TranscodeExecutionOwnershipPort;
use Closure;

/** Each executable child owns a fresh kernel and at most one claimed media job. */
final class MediaWorkerRunner
{
    /** @var array<string, array{process: resource, kind: string}> */
    private array $children = [];

    public function __construct(
        private readonly TranscodeExecutionOwnershipPort $video,
        private readonly AudioRenditionRuntimePort $audio,
        private readonly MediaWorkerAuthorityInterface $authority,
        private readonly string $jobScript = __DIR__ . '/../../../../bin/worker-media-job.php',
        private readonly string $phpBinary = PHP_BINARY,
    ) {}

    /** @param Closure():void|null $ready */
    public function runUntilSignalled(?Closure $ready = null): void
    {
        $stopping = false;
        $previousAsync = pcntl_async_signals(true);
        $term = pcntl_signal_get_handler(SIGTERM);
        $interrupt = pcntl_signal_get_handler(SIGINT);
        $stop = static function () use (&$stopping): void { $stopping = true; };
        pcntl_signal(SIGTERM, $stop);
        pcntl_signal(SIGINT, $stop);
        try {
            $this->run(static function () use (&$stopping): bool { return $stopping; }, $ready);
        } finally {
            pcntl_signal(SIGTERM, $term);
            pcntl_signal(SIGINT, $interrupt);
            pcntl_async_signals($previousAsync);
        }
    }

    /** @param Closure():bool $stopRequested @param Closure():void|null $ready */
    public function run(Closure $stopRequested, ?Closure $ready = null): void
    {
        try {
            $this->authority->assertActive();
            // Both connections to runtime state must work before readiness is possible.
            $this->video->pending(1);
            $this->audio->pending(1);
            if ($this->shouldStop($stopRequested)) {
                return;
            }
            $ready?->__invoke();
            while (!$this->shouldStop($stopRequested)) {
                $this->reap(true);
                $this->authority->assertActive();
                if ($this->hasCapacity('video', 3)) {
                    foreach ($this->video->pending(100) as $runtime) {
                        if ($this->shouldStop($stopRequested)) {
                            return;
                        }
                        $this->launch('video', $runtime->jobId->toString(), 3, $stopRequested);
                    }
                }
                if ($this->hasCapacity('audio', 1)) {
                    foreach ($this->audio->pending(100) as $runtime) {
                        if ($this->shouldStop($stopRequested)) {
                            return;
                        }
                        $this->launch('audio', $runtime->key, 1, $stopRequested);
                    }
                }
                usleep(50000);
            }
        } finally {
            // Admission is already closed: no replacement may launch after this point.
            $this->drain();
        }
    }

    /** @param Closure():bool $stopRequested
     * @phpstan-impure
     */
    private function shouldStop(Closure $stopRequested): bool
    {
        return $stopRequested();
    }

    /** @param Closure():bool $stopRequested */
    private function launch(string $kind, string $key, int $capacity, Closure $stopRequested): void
    {
        $childKey = $kind . ':' . $key;
        if (isset($this->children[$childKey])) {
            return;
        }
        if (!$this->hasCapacity($kind, $capacity)) {
            return;
        }
        $this->authority->assertActive();
        if ($this->shouldStop($stopRequested)) {
            return;
        }
        // Array command bypasses a shell; all configuration and identity stay inherited.
        $process = proc_open([$this->phpBinary, $this->jobScript, $kind, $key], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('Media execution child could not launch.');
        }
        $this->children[$childKey] = ['process' => $process, 'kind' => $kind];
    }

    private function hasCapacity(string $kind, int $capacity): bool
    {
        $count = 0;
        foreach ($this->children as $child) {
            if ($child['kind'] === $kind) {
                ++$count;
            }
        }
        return $count < $capacity;
    }

    private function reap(bool $requireSuccess): void
    {
        $failed = false;
        foreach ($this->children as $key => $child) {
            $status = proc_get_status($child['process']);
            if ($status['running']) {
                continue;
            }
            $exit = $status['exitcode'];
            $signalled = $status['signaled'];
            proc_close($child['process']);
            unset($this->children[$key]);
            $failed = $failed || $signalled || $exit !== 0;
        }
        if ($requireSuccess && $failed) {
            throw new \RuntimeException('Media child exited unexpectedly; deployment containment is required.');
        }
    }

    private function drain(): void
    {
        foreach ($this->children as $child) {
            proc_terminate($child['process'], SIGTERM);
        }
        $deadline = hrtime(true) / 1e9 + 30;
        while ($this->children !== [] && hrtime(true) / 1e9 < $deadline) {
            $this->reap(false);
            if ($this->children !== []) {
                usleep(50000);
            }
        }
        if ($this->children !== []) {
            // Never replace or claim containment of a child whose descendants are uncertain.
            throw new \RuntimeException('Media children exceeded the drain deadline; deployment containment is required.');
        }
    }
}
