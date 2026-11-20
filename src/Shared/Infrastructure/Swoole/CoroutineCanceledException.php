<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Swoole;

/**
 * Thrown by Async when a cancelled coroutine reaches a cancellation checkpoint, such as
 * when it resumes after a sleep or after yielding a chunk to a stopped response writer.
 */
final class CoroutineCanceledException extends \RuntimeException
{
}
