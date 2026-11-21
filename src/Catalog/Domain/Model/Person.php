<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Model;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use InvalidArgumentException;

final class Person
{
    private function __construct(private readonly PersonState $state) {}

    public static function create(string $name): self
    {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('A person needs a display name.');
        }

        return new self(new PersonState(Uuid::v7(), new PublicId(), $name));
    }

    public static function reconstitute(PersonState $state): self
    {
        return new self($state);
    }

    public function getId(): Uuid
    {
        return $this->state->id;
    }

    public function getPublicId(): PublicId
    {
        return $this->state->publicId;
    }

    public function getName(): string
    {
        return $this->state->name;
    }
}
