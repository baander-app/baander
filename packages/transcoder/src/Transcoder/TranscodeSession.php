<?php

namespace Baander\Transcoder\Transcoder;

use Amp\DeferredCancellation;
use Amp\Promise;
use Amp\Sync\LocalMutex;

class TranscodeSession
{
    private ?TranscodeProcess $activeProcess = null;
    private DeferredCancellation $cancellation;

    public function __construct(
        private TranscodeBuilder $builder,
        private LocalMutex       $mutex = new LocalMutex,
    )
    {
        $this->cancellation = new DeferredCancellation();
    }

    /**
     * Starts a new transcode session with optional seek time and resolution.
     */
    private function start(?float $seekTime = null, ?array $resolution = null, bool $directPlay = false)
    {
        // Ensure the builder options are updated to include direct play
        $this->builder->options->directPlay = $directPlay;

        if ($this->activeProcess) {
            $this->stop(); // Stop any existing process
        }

        $process = $this->builder->makeProcess($seekTime, $resolution);
        $this->activeProcess = $process;

        return $process->run();
    }

    public function startAsync(?float $seekTime = null, ?array $resolution = null, bool $directPlay = false)
    {
        return \Amp\async(function () use ($seekTime, $resolution, $directPlay) {
            $lock = yield $this->mutex->acquire();
            try {
                if ($this->activeProcess) {
                    $this->stop();
                }
                $this->start($seekTime, $resolution, $directPlay);
            } finally {
                $lock->release();
            }
        });
    }


    /**
     * Stops the currently active transcode session, if any.
     */
    public function stop()
    {
        if (!$this->activeProcess) {
            return;
        }

        $this->cancellation->cancel(); // Cancel process execution
        $this->activeProcess = null;

        // Reinitialize the cancellation object for future use
        $this->cancellation = new DeferredCancellation();
    }

    /**
     * Checks if a process is currently running.
     */
    public function isRunning(): bool
    {
        return $this->activeProcess !== null;
    }

    /**
     * Handles cleanup of the session.
     */
    public function cleanup(): void
    {
        if ($this->activeProcess) {
            $this->cancellation->cancel();
            $this->activeProcess = null;
        }
    }
}