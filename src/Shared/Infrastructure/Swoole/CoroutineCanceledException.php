<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Swoole;

/**
 * Thrown by Async::sleep() in a coroutine that was cancelled while it slept, such as the
 * coroutine writing a streamed response the stopping server cut.
 */
final class CoroutineCanceledException extends \RuntimeException
{
}
