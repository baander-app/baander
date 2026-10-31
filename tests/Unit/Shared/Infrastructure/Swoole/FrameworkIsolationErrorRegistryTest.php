<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Swoole;

use App\Tests\Fixtures\Swoole\CoroutineProbe;
use PHPUnit\Framework\TestCase;

final class FrameworkIsolationErrorRegistryTest extends TestCase
{
    public function testOverlappingCoroutineHandlerStacksKeepTheirOwnOverridesAndGlobalFallback(): void
    {
        $script = <<<'PHP'
require $argv[1];
use SwooleBundle\SwooleBundle\Bridge\Swoole\Swoole as SwooleAdapter;
use SwooleBundle\SwooleBundle\Bridge\Symfony\ErrorHandler\ContextualErrorHandler;
use SwooleBundle\SwooleBundle\Reflection\ClassModifier;
$seen = [];
$globalError = static function () use (&$seen): bool { $seen[] = 'global'; return true; };
$globalException = static function (Throwable $error): void {};
set_error_handler($globalError);
set_exception_handler($globalException);
ClassModifier::initialize();
if (class_exists(ContextualErrorHandler::class)) {
    ContextualErrorHandler::register(new SwooleAdapter());
    ContextualErrorHandler::register(new SwooleAdapter());
}
$checks = [];
Swoole\Coroutine\run(static function () use (&$checks, &$seen, $globalError, $globalException): void {
    $ready = new Swoole\Coroutine\Channel(1);
    $runA = new Swoole\Coroutine\Channel(1);
    $finishedA = new Swoole\Coroutine\Channel(1);
    Swoole\Coroutine::create(static function () use ($ready, $runA, $finishedA, &$checks, &$seen, $globalError, $globalException): void {
        $error = static function () use (&$seen): bool { $seen[] = 'A'; return true; };
        $exception = static function (Throwable $error): void {};
        set_error_handler($error, E_USER_NOTICE);
        set_exception_handler($exception);
        $ready->push(true);
        $runA->pop();
        $checks[] = get_error_handler() === $error && get_exception_handler() === $exception;
        trigger_error('coroutine A', E_USER_NOTICE);
        restore_error_handler();
        restore_exception_handler();
        $checks[] = get_error_handler() === $globalError && get_exception_handler() === $globalException;
        $finishedA->push(true);
    });
    Swoole\Coroutine::create(static function () use ($ready, $runA, $finishedA, &$checks, &$seen, $globalError, $globalException): void {
        $ready->pop();
        $error = static function () use (&$seen): bool { $seen[] = 'B'; return true; };
        $exception = static function (Throwable $error): void {};
        set_error_handler($error);
        set_exception_handler($exception);
        $runA->push(true);
        $finishedA->pop();
        $checks[] = get_error_handler() === $error && get_exception_handler() === $exception;
        trigger_error('coroutine B', E_USER_NOTICE);
        restore_error_handler();
        restore_exception_handler();
        $checks[] = get_error_handler() === $globalError && get_exception_handler() === $globalException;
    });
});
$checks[] = get_error_handler() === $globalError && get_exception_handler() === $globalException;
restore_error_handler();
restore_exception_handler();
echo json_encode(['checks' => $checks, 'seen' => $seen], JSON_THROW_ON_ERROR);
PHP;
        $process = CoroutineProbe::create(['-d', 'ffi.enable=true', '-r', $script, dirname(__DIR__, 5) . '/vendor/autoload.php']);
        $process->setTimeout(15);
        self::assertSame(0, $process->run(), $process->getErrorOutput());
        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame([true, true, true, true, true], $result['checks']);
        self::assertSame(['A', 'B'], $result['seen']);
    }
}
