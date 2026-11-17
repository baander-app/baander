<?php

declare(strict_types=1);

namespace App\Party\Application\Command;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;

/**
 * The new host travels as the public ID the caller named: the handler resolves it only after
 * it knows the caller is the host.
 */
final readonly class TransferHostCommand
{
    public function __construct(
        private Uuid $sessionId,
        private Uuid $currentHostUserId,
        private PublicId $newHostPublicId,
    ) {
    }

    public function getSessionId(): Uuid { return $this->sessionId; }
    public function getCurrentHostUserId(): Uuid { return $this->currentHostUserId; }
    public function getNewHostPublicId(): PublicId { return $this->newHostPublicId; }
}
