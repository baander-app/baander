<?php

declare(strict_types=1);

namespace App\Auth\Domain\Event\OAuth;

use App\Shared\Domain\Event\AbstractDomainEvent;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

final readonly class TokenRevoked extends AbstractDomainEvent
{
    public function __construct(
        private readonly string $tokenId,
        private readonly string $tokenType,
        private readonly ?Uuid $userId = null,
        ?DateTimeImmutable $occurredAt = null,
    ) {
        parent::__construct($occurredAt);
    }

    public function eventName(): string
    {
        return 'token.revoked';
    }

    public function getTokenId(): string
    {
        return $this->tokenId;
    }

    public function getTokenType(): string
    {
        return $this->tokenType;
    }

    public function getUserId(): ?Uuid
    {
        return $this->userId;
    }

    public static function fromPayload(array $payload): static
    {
        return new self(
            $payload['token_id'],
            $payload['token_type'],
            isset($payload['user_id']) ? Uuid::fromString($payload['user_id']) : null,
            new DateTimeImmutable($payload['occurred_at']),
        );
    }

    public function toPayload(): array
    {
        return [
            'token_id' => $this->tokenId,
            'token_type' => $this->tokenType,
            'user_id' => $this->userId?->toString(),
            'occurred_at' => $this->occurredAt->format(DateTimeImmutable::ATOM),
        ];
    }
}
