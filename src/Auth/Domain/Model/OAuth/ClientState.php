<?php

declare(strict_types=1);

namespace App\Auth\Domain\Model\OAuth;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

/**
 * Internal state for Client aggregate root.
 *
 * This class is mutable and should only be used by the aggregate root
 * and its repository implementation.
 */
final class ClientState
{
    /** @var string[] */
    public array $redirectUris;

    public function __construct(
        public readonly Uuid $id,
        public readonly PublicId $publicId,
        public string $name,
        public ?string $secret,
        array $redirectUris,
        public bool $personalAccessClient,
        public bool $passwordClient,
        public bool $deviceClient,
        public bool $confidential,
        public bool $firstParty,
        public readonly ?Uuid $userId,
        public readonly DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public bool $revoked = false,
    ) {
        $this->redirectUris = $redirectUris;
    }
}
