<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger\Stamp;

use App\Party\Domain\Model\SyncedPartySession;
use Symfony\Component\Messenger\Stamp\StampInterface;

final readonly class SyncedPartySessionResultStamp implements StampInterface
{
    public function __construct(
        private SyncedPartySession $session,
    ) {
    }

    public static function fromResult(mixed $result): ?self
    {
        return $result instanceof SyncedPartySession ? new self($result) : null;
    }

    public function getSession(): SyncedPartySession
    {
        return $this->session;
    }
}
