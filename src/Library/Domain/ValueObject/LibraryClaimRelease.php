<?php

declare(strict_types=1);

namespace App\Library\Domain\ValueObject;

/** The outcome of releasing a library's claim on an operator's request. */
final readonly class LibraryClaimRelease
{
    private function __construct(
        /** The kind of the claim that was ended. */
        public ?LibraryClaimKind $released,
        /** The kind of the live claim that stopped the release. */
        public ?LibraryClaimKind $live,
    ) {
    }

    public static function released(LibraryClaimKind $kind): self
    {
        return new self($kind, null);
    }

    /** No claim held the library; nothing changed. */
    public static function noClaim(): self
    {
        return new self(null, null);
    }

    /** A claim whose lease is still running holds the library; nothing changed. */
    public static function live(LibraryClaimKind $kind): self
    {
        return new self(null, $kind);
    }
}
