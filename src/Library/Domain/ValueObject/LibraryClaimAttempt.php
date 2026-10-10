<?php

declare(strict_types=1);

namespace App\Library\Domain\ValueObject;

/** The outcome of claiming a library: claimed, refused by the live claim that holds it, or no such library. */
final readonly class LibraryClaimAttempt
{
    private function __construct(
        public bool $claimed,
        /** The kind of the live claim that refused the attempt. */
        public ?LibraryClaimKind $holder,
    ) {
    }

    public static function claimed(): self
    {
        return new self(true, null);
    }

    public static function heldBy(LibraryClaimKind $holder): self
    {
        return new self(false, $holder);
    }

    public static function noLibrary(): self
    {
        return new self(false, null);
    }
}
