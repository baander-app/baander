<?php

declare(strict_types=1);

namespace App\Shared\Domain\ValueObject;

use App\Shared\Domain\Model\Uuid;

final readonly class MediaReadScope
{
    private function __construct(private LibraryReadScope $libraries, private ?Uuid $actorId)
    {
    }

    public static function authenticated(Uuid $actorId, LibraryReadScope $libraries): self
    {
        return new self($libraries, $actorId);
    }

    public static function none(): self
    {
        return new self(LibraryReadScope::none(), null);
    }

    public function getLibraries(): LibraryReadScope
    {
        return $this->libraries;
    }

    public function getActorId(): ?Uuid
    {
        return $this->actorId;
    }

    public function isUnrestricted(): bool
    {
        return $this->actorId !== null && $this->libraries->isUnrestricted();
    }
}
