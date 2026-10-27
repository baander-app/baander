<?php

declare(strict_types=1);

namespace Baander\Tooling\Process;

final readonly class ProcessResult
{
    public function __construct(
        public int $exitCode,
        public ?int $signal,
        public bool $timedOut,
        public bool $cancelled,
        public string $stdout,
        public string $stderr,
        public float $duration,
    ) {}
}
