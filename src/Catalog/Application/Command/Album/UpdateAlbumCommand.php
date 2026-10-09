<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command\Album;

/**
 * Changes an album's metadata and which of its fields are locked. A null field stays as it is.
 *
 * Lock changes apply before the edit, so one command may unlock a field and change it.
 */
final readonly class UpdateAlbumCommand
{
    /**
     * @param string            $publicId     the album's public ID, as given
     * @param list<string>|null $lockedFields the complete list of locked fields, replacing the current one
     * @param list<string>      $lock         fields to lock in addition
     * @param list<string>      $unlock       fields to unlock
     */
    public function __construct(
        public string $publicId,
        public ?string $title = null,
        public ?string $type = null,
        public ?int $year = null,
        public ?string $label = null,
        public ?string $catalogNumber = null,
        public ?string $barcode = null,
        public ?string $country = null,
        public ?string $language = null,
        public ?string $disambiguation = null,
        public ?string $annotation = null,
        public ?array $lockedFields = null,
        public array $lock = [],
        public array $unlock = [],
    ) {
    }
}
