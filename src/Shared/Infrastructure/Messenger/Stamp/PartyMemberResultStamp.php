<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger\Stamp;

use App\Party\Domain\Model\PartyMember;

final readonly class PartyMemberResultStamp implements ResultStampInterface
{
    public function __construct(
        private PartyMember $member,
    ) {
    }

    public static function fromResult(mixed $result): ?static
    {
        return $result instanceof PartyMember ? new self($result) : null;
    }

    public function getMember(): PartyMember
    {
        return $this->member;
    }
}
