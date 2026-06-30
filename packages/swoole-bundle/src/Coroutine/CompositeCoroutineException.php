<?php

declare(strict_types=1);

namespace SwooleBundle\SwooleBundle\Coroutine;

use Throwable;

/**
 * Aggregates multiple coroutine exceptions into a single throwable.
 * Thrown by CoroutinePool::run() when one or more coroutines fail.
 */
final class CompositeCoroutineException extends \RuntimeException
{
    /** @var array<Throwable> */
    private readonly array $exceptions;

    /**
     * @param array<Throwable> $exceptions
     */
    private function __construct(
        string $message,
        array $exceptions,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
        $this->exceptions = $exceptions;
    }

    /**
     * @param array<Throwable> $exceptions
     */
    public static function fromExceptions(array $exceptions): self
    {
        if (count($exceptions) === 0) {
            throw new \InvalidArgumentException(
                'Cannot create CompositeCoroutineException from empty exceptions array.'
            );
        }

        $first = $exceptions[0];
        $message = sprintf(
            '%d coroutine(s) failed. First failure: [%s] %s',
            count($exceptions),
            (new \ReflectionClass($first))->getShortName(),
            $first->getMessage(),
        );

        return new self($message, $exceptions, $first);
    }

    /**
     * @return array<Throwable>
     */
    public function getExceptions(): array
    {
        return $this->exceptions;
    }

    public function getFirstException(): Throwable
    {
        return $this->exceptions[0];
    }

    public function getExceptionCount(): int
    {
        return count($this->exceptions);
    }

    public function __toString(): string
    {
        $lines = [$this->getMessage()];
        foreach ($this->exceptions as $i => $exception) {
            $lines[] = sprintf(
                '  [%d] %s: %s in %s:%d',
                $i,
                (new \ReflectionClass($exception))->getShortName(),
                $exception->getMessage(),
                $exception->getFile(),
                $exception->getLine(),
            );
        }

        return implode("\n", $lines);
    }
}
