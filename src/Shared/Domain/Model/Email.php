<?php

declare(strict_types=1);

namespace App\Shared\Domain\Model;

use InvalidArgumentException;
use JsonSerializable;
use Stringable;

final readonly class Email implements Stringable, JsonSerializable
{
    private string $email;

    public function __construct(string $email)
    {
        $this->validate($email);
        $this->email = strtolower($email);
    }

    public static function fromString(string $email): self
    {
        return new self($email);
    }

    public function toString(): string
    {
        return $this->email;
    }

    public function domain(): string
    {
        return substr(strrchr($this->email, '@'), 1);
    }

    public function equals(self $other): bool
    {
        return $this->email === $other->email;
    }

    public function __toString(): string
    {
        return $this->email;
    }

    public function jsonSerialize(): string
    {
        return $this->email;
    }

    private function validate(string $email): void
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException(sprintf('"%s" is not a valid email address.', $email));
        }
    }
}
