<?php

declare(strict_types=1);

namespace SwooleBundle\SwooleBundle\Bridge\Symfony\ErrorHandler;

use Swoole\Coroutine;
use SwooleBundle\SwooleBundle\Bridge\Swoole\Swoole;
use SwooleBundle\SwooleBundle\Reflection\ClassModifier;
use Throwable;
use ZEngine\Reflection\ReflectionFunction;

/**
 * Coroutine aware replacement of the PHP error and exception handler registry.
 *
 * PHP keeps a single, process wide stack of error/exception handlers. With coroutines enabled that
 * stack is shared by all requests handled concurrently in the same worker, so any code temporarily
 * overriding a handler (and restoring it afterwards) silently corrupts the handler of every other
 * coroutine running in the meantime.
 *
 * Once registered, set_error_handler(), set_exception_handler(), restore_error_handler() and
 * restore_exception_handler() are redefined to keep a separate handler stack per coroutine.
 * Handlers registered outside of a coroutine (for example during kernel boot) form the global
 * stack, which is used as a fallback by every coroutine that did not override them.
 */
final class ContextualErrorHandler
{
    private const int GLOBAL_CONTEXT_ID = -1;

    private static ?self $instance = null;

    /**
     * @var array<int, list<array{0: callable|null, 1: int}>>
     */
    private array $errorHandlers = [];

    /**
     * @var array<int, list<callable|null>>
     */
    private array $exceptionHandlers = [];

    /**
     * @var array<int, bool>
     */
    private array $deferredCleanups = [];

    private function __construct(
        private readonly Swoole $swoole,
    ) {}

    public static function register(Swoole $swoole, int $errorLevels = E_ALL): void
    {
        if (self::$instance !== null) {
            return;
        }

        ClassModifier::initialize();
        $instance = new self($swoole);
        self::$instance = $instance;

        $previousErrorHandler = set_error_handler([$instance, 'handleError'], $errorLevels);
        $previousExceptionHandler = set_exception_handler([$instance, 'handleException']);

        if ($previousErrorHandler !== null) {
            $instance->setErrorHandler($previousErrorHandler, $errorLevels);
        }

        if ($previousExceptionHandler !== null) {
            $instance->setExceptionHandler($previousExceptionHandler);
        }

        self::redefineHandlerFunctions();
    }

    public function handleError(int $type, string $message, string $file, int $line): bool
    {
        [$handler, $errorLevels] = $this->currentErrorHandler();

        if ($handler === null || ($errorLevels & $type) === 0) {
            // let the internal PHP error handler take over
            return false;
        }

        return $handler($type, $message, $file, $line) !== false;
    }

    public function handleException(Throwable $exception): void
    {
        $handler = $this->currentExceptionHandler();

        if ($handler === null) {
            throw $exception;
        }

        $handler($exception);
    }

    /**
     * @return callable|null the handler which was effective for the current coroutine before
     */
    public function setErrorHandler(?callable $callback, int $errorLevels = E_ALL): ?callable
    {
        $contextId = $this->contextId();
        [$previous] = $this->currentErrorHandler();
        $this->errorHandlers[$contextId][] = [$callback, $errorLevels];
        $this->deferContextCleanup($contextId);

        return $previous;
    }

    public function restoreErrorHandler(): void
    {
        $contextId = $this->contextId();

        if (($this->errorHandlers[$contextId] ?? []) === []) {
            return;
        }

        array_pop($this->errorHandlers[$contextId]);
    }

    public function getErrorHandler(): ?callable
    {
        return $this->currentErrorHandler()[0];
    }

    /**
     * @return callable|null the handler which was effective for the current coroutine before
     */
    public function setExceptionHandler(?callable $callback): ?callable
    {
        $contextId = $this->contextId();
        $previous = $this->currentExceptionHandler();
        $this->exceptionHandlers[$contextId][] = $callback;
        $this->deferContextCleanup($contextId);

        return $previous;
    }

    public function restoreExceptionHandler(): void
    {
        $contextId = $this->contextId();

        if (($this->exceptionHandlers[$contextId] ?? []) === []) {
            return;
        }

        array_pop($this->exceptionHandlers[$contextId]);
    }

    public function getExceptionHandler(): ?callable
    {
        return $this->currentExceptionHandler();
    }

    private static function instance(): self
    {
        if (self::$instance === null) {
            throw UnregisteredContextualErrorHandler::notRegisteredYet();
        }

        return self::$instance;
    }

    /**
     * The redefined closures have to be signature compatible with the functions they replace,
     * which is why the original parameter names are used here.
     *
     * Known limitation: whether a value satisfies the `callable` parameter type is decided against
     * the scope the check runs in. For the real, internal set_error_handler() that is the scope of
     * its caller, for these userland replacements it is the scope of this class. A handler such as
     * `[$object, 'privateMethod']` is therefore rejected here even though PHP itself would accept
     * it, and since the closures are invoked through an FFI trampoline the resulting TypeError is
     * fatal rather than catchable. Nothing can be done about it from this side - z-engine requires
     * the signature to match the replaced function exactly, so the type cannot be widened. This is
     * why the bundle requires monolog >= 3.10, which passes a first class callable instead of the
     * `[$this, 'customErrorHandler']` array that earlier versions used.
     */
    private static function redefineHandlerFunctions(): void
    {
        // phpcs:disable SlevomatCodingStandard.Functions.UnusedParameter
        (new ReflectionFunction('set_error_handler'))->redefine(
            static fn(?callable $callback, int $error_levels = E_ALL) // phpcs:ignore
                => self::instance()->setErrorHandler($callback, $error_levels),
        );

        (new ReflectionFunction('set_exception_handler'))->redefine(
            static fn(?callable $callback) => self::instance()->setExceptionHandler($callback),
        );
        // phpcs:enable SlevomatCodingStandard.Functions.UnusedParameter

        (new ReflectionFunction('restore_error_handler'))->redefine(static function (): true {
            self::instance()->restoreErrorHandler();

            return true;
        });

        (new ReflectionFunction('restore_exception_handler'))->redefine(static function (): true {
            self::instance()->restoreExceptionHandler();

            return true;
        });

        (new ReflectionFunction('get_error_handler'))->redefine(
            static fn(): ?callable => self::instance()->getErrorHandler(),
        );

        (new ReflectionFunction('get_exception_handler'))->redefine(
            static fn(): ?callable => self::instance()->getExceptionHandler(),
        );
    }

    /**
     * @return array{0: callable|null, 1: int}
     */
    private function currentErrorHandler(): array
    {
        $stack = $this->errorHandlerStack();

        return $stack === [] ? [null, 0] : $stack[array_key_last($stack)];
    }

    private function currentExceptionHandler(): ?callable
    {
        $contextId = $this->contextId();
        $stack = $this->exceptionHandlers[$contextId] ?? [];

        if ($stack === [] && $contextId !== self::GLOBAL_CONTEXT_ID) {
            $stack = $this->exceptionHandlers[self::GLOBAL_CONTEXT_ID] ?? [];
        }

        return $stack === [] ? null : $stack[array_key_last($stack)];
    }

    /**
     * @return list<array{0: callable|null, 1: int}>
     */
    private function errorHandlerStack(): array
    {
        $contextId = $this->contextId();
        $stack = $this->errorHandlers[$contextId] ?? [];

        if ($stack === [] && $contextId !== self::GLOBAL_CONTEXT_ID) {
            return $this->errorHandlers[self::GLOBAL_CONTEXT_ID] ?? [];
        }

        return $stack;
    }

    private function deferContextCleanup(int $contextId): void
    {
        if ($contextId === self::GLOBAL_CONTEXT_ID || isset($this->deferredCleanups[$contextId])) {
            return;
        }

        $this->deferredCleanups[$contextId] = true;

        Coroutine::defer(function (): void {
            $this->clearCurrentContext();
        });
    }

    private function clearCurrentContext(): void
    {
        $contextId = $this->contextId();

        if ($contextId === self::GLOBAL_CONTEXT_ID) {
            return;
        }

        unset(
            $this->errorHandlers[$contextId],
            $this->exceptionHandlers[$contextId],
            $this->deferredCleanups[$contextId],
        );
    }

    private function contextId(): int
    {
        return $this->swoole->getCoroutineId();
    }
}
