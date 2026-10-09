<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command\Artist;

/**
 * Changes an artist's metadata and which of its fields are locked. A null field stays as it is.
 *
 * Lock changes apply before the edit, so one command may unlock a field and change it.
 */
final readonly class UpdateArtistCommand
{
    /**
     * @param string            $publicId     the artist's public ID, as given
     * @param list<string>|null $lockedFields the complete list of locked fields, replacing the current one
     * @param list<string>      $lock         fields to lock in addition
     * @param list<string>      $unlock       fields to unlock
     */
    public function __construct(
        public string $publicId,
        public ?string $name = null,
        public ?string $country = null,
        public ?string $gender = null,
        public ?string $type = null,
        public ?string $disambiguation = null,
        public ?string $sortName = null,
        public ?string $biography = null,
        public ?array $lockedFields = null,
        public array $lock = [],
        public array $unlock = [],
    ) {
    }
}
