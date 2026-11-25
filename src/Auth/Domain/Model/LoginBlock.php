<?php

declare(strict_types=1);

namespace App\Auth\Domain\Model;

use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\Model\PublicId;
use DateInterval;
use DateTimeImmutable;

/**
 * A sign-in attempt that filled the honeypot field. Every field is sender input, so each is
 * stored as valid UTF-8 of at most FIELD_LENGTH characters, and a block is kept for RETENTION.
 */
final class LoginBlock
{
    public const int FIELD_LENGTH = 255;

    public const string RETENTION = 'P30D';

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
            publicId: new PublicId(),
            ipAddress: self::bounded($ipAddress),
            email: self::bounded($email),
            fieldValue: self::bounded($fieldValue),
            userAgent: self::bounded($userAgent),
            createdAt: new DateTimeImmutable(),
        ));
    }

    public static function reconstitute(LoginBlockState $state): self
    {
        return new self($state);
    }

    public function getPublicId(): PublicId
    {
        return $this->state->publicId;
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

    /** Blocks recorded before this instant are past retention once this block is stored. */
    public function retainedSince(): DateTimeImmutable
    {
        return $this->state->createdAt->sub(new DateInterval(self::RETENTION));
    }

    /**
     * PostgreSQL text holds neither invalid UTF-8 nor a NUL byte; each becomes `?`, and the
     * cut counts characters, so it never splits one.
     */
    private static function bounded(string $value): string
    {
        $storable = str_replace("\0", '?', mb_scrub($value, 'UTF-8'));

        return mb_substr($storable, 0, self::FIELD_LENGTH, 'UTF-8');
    }
}
