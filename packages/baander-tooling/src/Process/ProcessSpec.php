<?php

declare(strict_types=1);

namespace Baander\Tooling\Process;

final readonly class ProcessSpec
{
    /**
     * @param non-empty-list<string> $argv
     * @param array<string, string> $environment
     */
    public function __construct(
        public array $argv,
        public ?string $cwd = null,
        public array $environment = [],
        public ?float $timeout = null,
        public ?string $stdin = null,
        public ?string $logPath = null,
        public bool $passthrough = false,
        public bool $processGroup = false,
        public bool $ignoreCancellation = false,
        public bool $appendLog = false,
        public ?string $stderrLogPath = null,
        public float $terminationGrace = 0.25,
    ) {
        if (!is_finite($terminationGrace) || $terminationGrace < 0) {
            throw new \InvalidArgumentException('Process termination grace must be finite nonnegative seconds.');
        }
    }
}
