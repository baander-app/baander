<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Worker\Autoscaling;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class ResourceObservation implements \JsonSerializable
{
    public function __construct(
        public string $state,
        public float $sampledAt,
        public ?float $cpuUtilization = null,
        public ?float $cpuThrottledRatio = null,
        public ?float $memoryRatio = null,
        public ?float $pidsRatio = null,
        public ?int $cpuUsageUsec = null,
        public ?float $cpuQuotaCores = null,
        public ?int $memoryCurrent = null,
        public ?int $memoryMax = null,
        public ?int $pidsCurrent = null,
        public ?int $pidsMax = null,
        public ?string $fault = null,
    ) {
    }

    public function isKnown(): bool
    {
        return $this->state === 'known' && $this->cpuUtilization !== null
            && $this->memoryRatio !== null && $this->pidsRatio !== null;
    }

    /** @param array<string, mixed> $fields */
    public static function fromArray(array $fields): self
    {
        $expected = ['state', 'sampledAt', 'cpuUtilization', 'cpuThrottledRatio', 'memoryRatio', 'pidsRatio',
            'cpuUsageUsec', 'cpuQuotaCores', 'memoryCurrent', 'memoryMax', 'pidsCurrent', 'pidsMax', 'fault'];
        if (count($fields) !== count($expected) || array_diff($expected, array_keys($fields)) !== []
            || !in_array($fields['state'] ?? null, ['known', 'unknown', 'unavailable'], true)) {
            throw new \InvalidArgumentException('Invalid resource observation fields.');
        }
        foreach (['sampledAt', 'cpuUtilization', 'cpuThrottledRatio', 'memoryRatio', 'pidsRatio', 'cpuQuotaCores'] as $key) {
            $value = $fields[$key];
            if ($value === null && $key !== 'sampledAt') {
                continue;
            }
            if ((!is_float($value) && !is_int($value)) || !is_finite((float) $value) || $value < 0) {
                throw new \InvalidArgumentException('Invalid resource observation measurement.');
            }
        }
        foreach (['cpuUsageUsec', 'memoryCurrent', 'memoryMax', 'pidsCurrent', 'pidsMax'] as $key) {
            if ($fields[$key] !== null && (!is_int($fields[$key]) || $fields[$key] < 0)) {
                throw new \InvalidArgumentException('Invalid resource observation counter.');
            }
        }
        if (!in_array($fields['fault'], [null, 'cgroup_unavailable'], true)) {
            throw new \InvalidArgumentException('Invalid resource observation fault.');
        }
        $result = new self(...$fields);
        if ($result->state === 'known' && !$result->isKnown()) {
            throw new \InvalidArgumentException('Known resource observation requires pressure measurements.');
        }
        return $result;
    }

    /** @return array<string, int|float|string|null> */
    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }
}
