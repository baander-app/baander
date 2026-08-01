<?php

declare(strict_types=1);

namespace App\Auth\Domain\Model;

use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

final class LoginBlock
{
    private function __construct(
        private LoginBlockState $state,
    ) {}

    public static function create(
        string $ipAddress,
        string $email,
        string $fieldValue,
        string $userAgent,
    ): self {
        return new self(new LoginBlockState(
            id: new Uuid(),
            ipAddress: $ipAddress,
            email: $email,
            fieldValue: $fieldValue,
            userAgent: $userAgent,
            createdAt: new DateTimeImmutable(),
        ));
    }

    public static function reconstitute(LoginBlockState $state): self
    {
        return new self($state);
    }

    public function getId(): Uuid
    {
        return $this->state->id;
    }

    public function getIpAddress(): string
    {
        return $this->state->ipAddress;
    }

    public function getEmail(): string
    {
        return $this->state->email;
    }

    public function getFieldValue(): string
    {
        return $this->state->fieldValue;
    }

    public function getUserAgent(): string
    {
        return $this->state->userAgent;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->state->createdAt;
    }
}
