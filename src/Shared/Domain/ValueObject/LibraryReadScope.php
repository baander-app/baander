<?php

declare(strict_types=1);

namespace App\Shared\Domain\ValueObject;

use App\Shared\Domain\Model\Uuid;

final readonly class LibraryReadScope
{
    /** @param list<string> $libraryIds */
    private function __construct(private bool $unrestricted, private array $libraryIds)
    {
    }

    public static function unrestricted(): self
    {
        return new self(true, []);
    }

    /** @param list<Uuid> $libraryIds */
    public static function restricted(array $libraryIds): self
    {
        return new self(false, array_values(array_unique(array_map(static fn (Uuid $id): string => $id->toString(), $libraryIds))));
    }

    public static function none(): self
    {
        return new self(false, []);
    }

    public function isUnrestricted(): bool
    {
        return $this->unrestricted;
    }

    /** @return list<string> */
    public function getLibraryIds(): array
    {
        return $this->libraryIds;
    }

    public function allows(Uuid $libraryId): bool
    {
        return $this->unrestricted || in_array($libraryId->toString(), $this->libraryIds, true);
    }
}
