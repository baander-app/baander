<?php

declare(strict_types=1);

namespace App\QoL\Domain\ValueObject;

enum AlgorithmProfile: string
{
    case Conservative = 'conservative';
    case Balanced = 'balanced';
    case Aggressive = 'aggressive';

    public function budgetCap(): float
    {
        return match ($this) {
            self::Conservative => 0.70,
            self::Balanced     => 0.80,
            self::Aggressive   => 0.90,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Conservative => 'Conservative (70%)',
            self::Balanced     => 'Balanced (80%)',
            self::Aggressive   => 'Aggressive (90%)',
        };
    }
}
