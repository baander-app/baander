<?php

declare(strict_types=1);

namespace Baander\Tooling\Process;

use Closure;
use Swoole\Event;
use Swoole\Process;
use Swoole\Timer;
use Throwable;
use WeakReference;

/** Shares SIGINT/SIGTERM ownership among standalone tooling workflows and waits. */
final class SignalScope
{
    /** @var array<int, WeakReference<self>> */
    private static array $scopes = [];
    private static bool $shutdownRegistered = false;
    private readonly ?Closure $observer;
    private ?int $received = null;
    private ?Throwable $failure = null;
    private bool $closed = false;

    /** @param (callable(int): void)|null $observer */
    public function __construct(?callable $observer = null)
    {
        if (!self::$shutdownRegistered) {
            self::$shutdownRegistered = true;
            register_shutdown_function(static function (): void {
                // Release the reactor while PHP shutdown callbacks can still service it.
                // Exception traces can otherwise retain scopes until object destruction.
                foreach (array_reverse(self::$scopes) as $reference) {
                    $reference->get()?->close();
                }
            });
        }
        $this->observer = $observer === null ? null : $observer(...);
        if (self::$scopes === []) {
            // Swoole cannot inspect foreign handlers. This scope composes only
            // handlers owned by this tooling library, in standalone CLI programs.
            $receive = static function (int $signal): void {
                foreach (array_reverse(self::$scopes) as $reference) {
                    $scope = $reference->get();
                    if ($scope !== null && !$scope->closed) {
                        $scope->received ??= $signal;
                        try {
                            if ($scope->observer !== null) {
                                ($scope->observer)($signal);
                            }
                        } catch (Throwable $error) {
                            $scope->failure = $error;
                        }
                    }
                }
            };
            Process::signal(2, $receive);
            Process::signal(15, $receive);
        }
        self::$scopes[spl_object_id($this)] = WeakReference::create($this);
    }

    public function receivedSignal(): ?int
    {
        return $this->received;
    }

    /** Pump a bounded checkpoint even when the reactor has only signal listeners. */
    public function dispatch(): void
    {
        $wake = Timer::after(1, static function (): void {});
        try {
            Event::dispatch();
        } finally {
            if (Timer::exists($wake)) {
                Timer::clear($wake);
            }
        }
        foreach (self::$scopes as $reference) {
            $failure = $reference->get()?->failure;
            if ($failure !== null) {
                throw $failure;
            }
        }
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        unset(self::$scopes[spl_object_id($this)]);
        if (self::$scopes === []) {
            Process::signal(2, null);
            Process::signal(15, null);
            // Finalize the idle reactor explicitly, preserving caller timers.
            // Waiting while another scope owns signal listeners would block.
            if (Timer::stats()['num'] === 0) {
                Event::wait();
            }
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
