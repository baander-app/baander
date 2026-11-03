<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Worker;

use App\Shared\Application\DTO\DeploymentProfile;
use App\Transcode\Application\Port\AudioRenditionRuntimePort;
use App\Transcode\Application\Port\TranscodeExecutionOwnershipPort;
use Closure;

/** Each executable child owns a fresh kernel and at most one claimed media job. */
final class MediaWorkerRunner
{
    /** @var array<string, array{process: resource, kind: string, slot: string}> */
    private array $children = [];

    /** @var array<string, int> */
    private array $slotGenerations = [];

    public function __construct(
        private readonly TranscodeExecutionOwnershipPort $video,
        private readonly AudioRenditionRuntimePort $audio,
        private readonly MediaWorkerAuthorityInterface $authority,
        private readonly DeploymentProfile $deploymentProfile,
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
                $videos = $this->freeSlot('video') !== null ? $this->video->pending(100) : [];
                $audios = $this->freeSlot('audio') !== null ? $this->audio->pending(100) : [];
                // Interleave the queues so shared slots admit both kinds under backlog.
                for ($index = 0; $index < max(count($videos), count($audios)); ++$index) {
                    if ($this->shouldStop($stopRequested)) {
                        return;
                    }
                    if (isset($videos[$index])) {
                        $this->launch('video', $videos[$index]->jobId->toString(), $stopRequested);
                    }
                    if (isset($audios[$index])) {
                        $this->launch('audio', $audios[$index]->key, $stopRequested);
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
    private function launch(string $kind, string $key, Closure $stopRequested): void
    {
        $childKey = $kind . ':' . $key;
        if (isset($this->children[$childKey])) {
            return;
        }
        $slot = $this->freeSlot($kind);
        if ($slot === null) {
            return;
        }
        $this->authority->assertActive();
        if ($this->shouldStop($stopRequested)) {
            return;
        }
        $previous = $this->slotGenerations[$slot] ?? 0;
        if ($previous === PHP_INT_MAX) {
            throw new \RuntimeException('Media native client generation is exhausted.');
        }
        $generation = $this->slotGenerations[$slot] = $previous + 1;
        $environment = array_replace(getenv(), [
            'BAANDER_NATIVE_CLIENT_ID' => $slot,
            'BAANDER_NATIVE_CLIENT_GENERATION' => (string) $generation,
        ]);
        // Fresh children inherit authority, with a bounded native slot and launch fence.
        $process = proc_open([$this->phpBinary, $this->jobScript, $kind, $key], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w'], 3 => STDERR], $pipes, env_vars: $environment);
        if (!is_resource($process)) {
            throw new \RuntimeException('Media execution child could not launch.');
        }
        $this->children[$childKey] = ['process' => $process, 'kind' => $kind, 'slot' => $slot];
    }

    /** The first configured native client of the kind no running child holds; shared copy slots serve both kinds. */
    private function freeSlot(string $kind): ?string
    {
        $free = array_diff($this->deploymentProfile->mediaClientIds($kind), array_column($this->children, 'slot'));

        return $free === [] ? null : reset($free);
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
            if ($requireSuccess && ($signalled || $exit !== 0)) {
                fwrite(STDERR, json_encode([
                    'event' => 'worker_media_child_exit',
                    'kind' => $child['kind'],
                    'exitCode' => $exit,
                    'signalled' => $signalled,
                    'signal' => $status['termsig'],
                ], JSON_THROW_ON_ERROR) . "\n");
            }
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
