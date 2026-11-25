<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Worker;

/**
 * The newest bytes a process wrote to one stream, at most a fixed number of them. Once older
 * bytes are dropped the tail starts with a marker, and a cut never leaves a partial UTF-8
 * sequence at its start.
 */
final class OutputTail
{
    public const string TRUNCATION_MARKER = "[earlier output truncated]\n";

    private string $bytes = '';
    private bool $truncated = false;

    /** @param positive-int $limit */
    public function __construct(private readonly int $limit)
    {
    }

    public function append(string $chunk): void
    {
        $this->bytes .= $chunk;
        if (strlen($this->bytes) > $this->limit) {
            $this->bytes = substr($this->bytes, -$this->limit)
                |> (static fn(string $tail): string => preg_replace('/^[\x80-\xBF]{1,3}/', '', $tail) ?? $tail);
            $this->truncated = true;
        }
    }

    public function contents(): string
    {
        return $this->truncated ? self::TRUNCATION_MARKER . $this->bytes : $this->bytes;
    }
}
