<?php

declare(strict_types=1);

namespace App\Auth\Domain\Event\OAuth;

use App\Shared\Domain\Event\AbstractDomainEvent;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

final readonly class TokenIssued extends AbstractDomainEvent
{
    public function __construct(
        private readonly string $tokenId,
        private readonly array $scopes,
        ?DateTimeImmutable $occurredAt = null,
    ) {
        parent::__construct($occurredAt);
    }

    public function eventName(): string
    {
        return 'token.issued';
    }

    public function getTokenId(): string
    {
        return $this->tokenId;
    }

    public function getScopes(): array
    {
        return $this->scopes;
    }
}