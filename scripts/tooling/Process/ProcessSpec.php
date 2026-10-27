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
    ) {}
}
