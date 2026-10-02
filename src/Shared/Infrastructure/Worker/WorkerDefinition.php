<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Worker;

use InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Explicit child configuration. Memory is an admission reservation, not an enforced limit. */
#[Exclude]
final readonly class WorkerDefinition
{
    public RestartPolicy $restartPolicy;

    /**
     * @param list<string> $argv Fresh-exec argument vector; never interpreted by a shell.
     * @param array<string, string>|null $environment Complete child environment, or null to inherit.
     */
    public function __construct(
        public string $id,
        public array $argv,
        public string $directory,
        public int $memoryReservationBytes,
        public float $stopGraceSeconds = 30.0,
        public ?array $environment = null,
        ?RestartPolicy $restartPolicy = null,
    ) {
        if (preg_match('/^[A-Za-z][A-Za-z0-9_.-]{0,63}$/D', $id) !== 1) {
            throw new InvalidArgumentException('Worker ID must start with a letter and contain at most 64 letters, digits, dots, underscores or hyphens.');
        }
        if ($argv === [] || $argv[0] === '') {
            throw new InvalidArgumentException('Worker requires a non-empty argument vector.');
        }
        foreach ($argv as $argument) {
            if (str_contains($argument, "\0")) {
                throw new InvalidArgumentException('Worker arguments must not contain NUL bytes.');
            }
        }
        if (!str_starts_with($directory, '/') || !is_dir($directory)) {
            throw new InvalidArgumentException('Worker directory must be an existing absolute directory.');
        }
        if ($memoryReservationBytes <= 0 || !is_finite($stopGraceSeconds) || $stopGraceSeconds < 0) {
            throw new InvalidArgumentException('Worker memory reservation must be positive and shutdown grace finite and non-negative.');
        }
        foreach ($environment ?? [] as $name => $value) {
            if ($name === '' || str_contains($name, '=') || str_contains($name, "\0") || str_contains($value, "\0")) {
                throw new InvalidArgumentException('Worker environment names and values must be valid environment entries.');
            }
        }
        $this->restartPolicy = $restartPolicy ?? new RestartPolicy();
    }
}
