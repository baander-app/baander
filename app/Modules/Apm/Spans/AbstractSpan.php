<?php

namespace App\Modules\Apm\Spans;

use Closure;
use Elastic\Apm\CustomErrorData;
use Elastic\Apm\SpanContextInterface;
use Elastic\Apm\SpanInterface;
use Throwable;

/**
 * Abstract base class for spans in the APM system
 *
 * This class provides a foundation for adding data to SpanInterface instances.
 * It is designed to be used as a helper for classes that implement SpanInterface,
 * focusing only on data-adding operations.
 */
abstract class AbstractSpan
{
    /**
     * The wrapped Elastic APM span instance
     */
    protected ?SpanInterface $span;

    public function __construct(?SpanInterface $span = null)
    {
        $this->span = $span;
    }

    /**
     * Get the wrapped span instance
     */
    public function getSpan(): ?SpanInterface
    {
        return $this->span;
    }

    /**
     * Set the wrapped span instance
     */
    public function setSpan(?SpanInterface $span): void
    {
        $this->span = $span;
    }

    public function getId(): string
    {
        return $this->span ? $this->span->getId() : '';
    }

    public function getTraceId(): string
    {
        return $this->span ? $this->span->getTraceId() : '';
    }

    public function getTimestamp(): float
    {
        return $this->span ? $this->span->getTimestamp() : 0.0;
    }

    public function beginChildSpan(
        string  $name,
        string  $type,
        ?string $subtype = null,
        ?string $action = null,
        ?float  $timestamp = null,
    ): SpanInterface
    {
        if (!$this->span) {
            throw new \RuntimeException('Cannot begin child span: no span instance set');
        }

        return $this->span->beginChildSpan($name, $type, $subtype, $action, $timestamp);
    }

    public function setName(string $name): void
    {
        if ($this->span) {
            $this->span->setName($name);
        }
    }

    public function setType(string $type): void
    {
        if ($this->span) {
            $this->span->setType($type);
        }
    }

    public function injectDistributedTracingHeaders(Closure $headerInjector): void
    {
        $this->span?->injectDistributedTracingHeaders($headerInjector);
    }


    public function end(?float $duration = null): void
    {
        $this->span?->end($duration);
    }

    public function hasEnded(): bool
    {
        return $this->span ? $this->span->hasEnded() : true;
    }

    public function createErrorFromThrowable(Throwable $throwable): ?string
    {
        return $this->span?->createErrorFromThrowable($throwable);
    }

    public function createCustomError(CustomErrorData $customErrorData): ?string
    {
        return $this->span?->createCustomError($customErrorData);
    }

    public function setOutcome(?string $outcome): void
    {
        $this->span?->setOutcome($outcome);
    }

    public function getOutcome(): ?string
    {
        return $this->span?->getOutcome();
    }

    public function isNoop(): bool
    {
        return !$this->span || $this->span->isNoop();
    }


    public function discard(): void
    {
        $this->span?->discard();
    }


    public function getTransactionId(): string
    {
        return $this->span ? $this->span->getTransactionId() : '';
    }

    public function getParentId(): string
    {
        return $this->span ? $this->span->getParentId() : '';
    }

    public function setAction(?string $action): void
    {
        $this->span?->setAction($action);
    }

    public function setSubtype(?string $subtype): void
    {
        $this->span?->setSubtype($subtype);
    }

    public function context(): SpanContextInterface
    {
        if (!$this->span) {
            throw new \RuntimeException('Cannot access context: no span instance set');
        }
        return $this->span->context();
    }

    public function endSpanEx(int $numberOfStackFramesToSkip, ?float $duration = null): void
    {
        $this->span?->endSpanEx($numberOfStackFramesToSkip, $duration);
    }
}
