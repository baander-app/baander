<?php

declare(strict_types=1);

namespace App\Party\Domain\ValueObject;

enum MemberRole: string
{
    case Host = 'host';
    case Member = 'member';

    public function label(): string
    {
        return match ($this) {
            self::Host => 'Host',
            self::Member => 'Member',
        };
    }
}
