<?php

declare(strict_types=1);

namespace Baander\Tooling\Configuration;

final readonly class WorkflowBudgets
{
    /** @param array<string,float> $values */
    public function __construct(private array $values)
    {
        foreach ($values as $value) {
            if (!is_finite($value) || $value <= 0) {
                throw new \InvalidArgumentException('Workflow budgets must be positive finite seconds.');
            }
        }
    }

    public function seconds(string $workflow): float
    {
        return $this->values[$workflow] ?? throw new \InvalidArgumentException('Unknown workflow budget: ' . $workflow);
    }

    /** @return array<string,float> */
    public function toArray(): array
    {
        return $this->values;
    }
}
