<?php

declare(strict_types=1);

namespace App\Transcode\Domain\ValueObject;

enum SessionState: string
{
    case Pending = 'pending';
    case Preparing = 'preparing';
    case Active = 'active';
    case Paused = 'paused';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Preparing, self::Cancelled],
            self::Preparing => [self::Active, self::Failed, self::Cancelled],
            self::Active => [self::Paused, self::Completed, self::Failed, self::Cancelled],
            self::Paused => [self::Active, self::Cancelled],
            self::Completed, self::Failed, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}
