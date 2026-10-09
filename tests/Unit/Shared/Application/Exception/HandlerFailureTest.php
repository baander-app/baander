<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Application\Exception;

use App\Shared\Application\Exception\HandlerFailure;
use App\Shared\Application\Exception\NotFoundException;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;

final class HandlerFailureTest extends TestCase
{
    public function testAnExceptionThatIsNotAWrapperIsItsOwnCause(): void
    {
        $exception = new NotFoundException('Job not found.');

        self::assertSame($exception, HandlerFailure::cause($exception));
    }

    public function testUnwrapsNestedWrappersAroundASingleException(): void
    {
        $exception = new NotFoundException('Job not found.');
        $inner = new HandlerFailedException(new Envelope(new stdClass()), ['inner' => $exception]);
        $outer = new HandlerFailedException(new Envelope(new stdClass()), ['outer' => $inner]);

        self::assertSame($exception, HandlerFailure::cause($outer));
    }

    public function testKeepsAWrapperAroundSeveralExceptions(): void
    {
        $wrapper = new HandlerFailedException(new Envelope(new stdClass()), [
            'first' => new RuntimeException('first'),
            'second' => new LogicException('second'),
        ]);
        $outer = new HandlerFailedException(new Envelope(new stdClass()), ['outer' => $wrapper]);

        self::assertSame($wrapper, HandlerFailure::cause($outer));
    }
}
