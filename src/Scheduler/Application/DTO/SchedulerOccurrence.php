<?php

declare(strict_types=1);

namespace App\Scheduler\Application\DTO;

use App\Scheduler\Domain\ValueObject\JobType;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Immutable minute execution snapshot; manual IDs identify requests, parameter order is invocation order. */
#[Exclude]
final readonly class SchedulerOccurrence
{
    public DateTimeImmutable $scheduledFor;
    /** @var array<array-key, mixed> JSON-safe scalar/array values only, recursively detached from caller references. */
    public array $parameters;
    private string $encodedParameters;

    /** @param array<array-key, mixed> $parameters */
    public function __construct(
        public Uuid $id,
        public Uuid $jobId,
        DateTimeImmutable $scheduledFor,
        public JobType $jobType,
        public string $command,
        array $parameters,
        public SchedulerOccurrenceOrigin $origin = SchedulerOccurrenceOrigin::Scheduled,
    ) {
        $utc = $scheduledFor->setTimezone(new DateTimeZone('UTC'));
        if ($utc->format('s.u') !== '00.000000') {
            throw new InvalidArgumentException('Scheduler occurrence requires an exact UTC minute.');
        }
        if ($command === '' || strlen($command) > 512 || str_contains($command, "\0")) {
            throw new InvalidArgumentException('Scheduler occurrence command requires 1..512 bytes without NUL.');
        }
        $nodes = 0;
        $rawBytes = 0;
        $copy = self::copyParameters($parameters, 1, $nodes, $rawBytes);
        try {
            $encoded = json_encode($copy, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION, 8);
        } catch (\JsonException $error) {
            throw new InvalidArgumentException('Scheduler occurrence parameters must be valid JSON-safe values.', 0, $error);
        }
        if (strlen($encoded) > 16 * 1024) {
            throw new InvalidArgumentException('Scheduler occurrence parameters exceed 16 KiB of JSON.');
        }
        $this->scheduledFor = $utc;
        $this->parameters = $copy;
        $this->encodedParameters = $encoded;
    }

    /** Authoritative JSON preserves float tokens, array shape and argument order; do not normalize through JSONB. */
    public function parametersJson(): string
    {
        return $this->encodedParameters;
    }

    /**
     * @param array<array-key, mixed> $values
     * @return array<array-key, mixed>
     */
    private static function copyParameters(array $values, int $depth, int &$nodes, int &$rawBytes): array
    {
        if ($depth > 8) {
            throw new InvalidArgumentException('Scheduler occurrence parameter nesting exceeds eight arrays.');
        }
        $copy = [];
        foreach ($values as $key => $value) {
            $rawBytes += is_string($key) ? strlen($key) : 0;
            $rawBytes += is_string($value) ? strlen($value) : 0;
            if (++$nodes > 16 * 1024 || $rawBytes > 16 * 1024) {
                throw new InvalidArgumentException('Scheduler occurrence parameters exceed their bounded JSON budget.');
            }
            if (is_array($value)) {
                $copy[$key] = self::copyParameters($value, $depth + 1, $nodes, $rawBytes);
            } elseif ($value === null || is_bool($value) || is_int($value)) {
                $copy[$key] = $value;
            } elseif (is_float($value) && is_finite($value)) {
                $copy[$key] = $value;
            } elseif (is_string($value) && strlen($value) <= 16 * 1024) {
                $copy[$key] = $value;
            } else {
                throw new InvalidArgumentException('Scheduler occurrence parameters contain unsupported or unbounded values.');
            }
        }

        return $copy;
    }
}
