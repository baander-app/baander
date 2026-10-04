<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger\Stamp;

use App\Party\Domain\Model\SyncedPartySession;

final readonly class SyncedPartySessionResultStamp implements ResultStampInterface
{
    public function __construct(
        private SyncedPartySession $session,
    ) {
    }

    public static function fromResult(mixed $result): ?static
    {
        return $result instanceof SyncedPartySession ? new self($result) : null;
    }

    public function getSession(): SyncedPartySession
    {
        return $this->session;
    }
}
