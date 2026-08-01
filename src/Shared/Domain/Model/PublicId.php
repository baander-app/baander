<?php

declare(strict_types=1);

namespace App\Shared\Domain\Model;

use Hidehalo\Nanoid\Client;
use InvalidArgumentException;
use JsonSerializable;
use Stringable;

final readonly class PublicId implements Stringable, JsonSerializable
{
    private const int LENGTH = 21;
    private const string ALPHABET = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ_-';

    private string $id;

    public function __construct(?string $id = null)
    {
        if ($id === null) {
            $client = new Client();
            $this->id = $client->generateId(self::LENGTH, self::ALPHABET);

            return;
        }

        $this->validate($id);
        $this->id = $id;
    }

    public static function fromString(string $id): self
    {
        return new self($id);
    }

    public function toString(): string
    {
        return $this->id;
    }

    public function equals(self $other): bool
    {
        return $this->id === $other->id;
    }

    public function __toString(): string
    {
        return $this->id;
    }

    public function jsonSerialize(): string
    {
        return $this->id;
    }

    private function validate(string $id): void
    {
        if (strlen($id) !== self::LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'PublicId must be %d characters long, got %d.',
                self::LENGTH,
                strlen($id),
            ));
        }

        if (!preg_match('/^[0-9a-zA-Z_-]+$/', $id)) {
            throw new InvalidArgumentException(sprintf(
                'PublicId contains invalid characters: %s',
                $id,
            ));
        }
    }
}
