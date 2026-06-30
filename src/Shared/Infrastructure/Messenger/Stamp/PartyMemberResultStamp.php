<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger\Stamp;

use App\Party\Domain\Model\PartyMember;
use Symfony\Component\Messenger\Stamp\StampInterface;

final readonly class PartyMemberResultStamp implements StampInterface
{
    public function __construct(
        private PartyMember $member,
    ) {
    }

    public static function fromResult(mixed $result): ?self
    {
        return $result instanceof PartyMember ? new self($result) : null;
    }

    public function getMember(): PartyMember
    {
        return $this->member;
    }
}
