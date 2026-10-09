<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command\Song;

/**
 * Changes a song's metadata and which of its fields are locked. A null field stays as it is.
 *
 * Unlocks apply before the edit and locks after it, so one command may unlock a field and change
 * it, or change a field and lock it.
 */
final readonly class UpdateSongCommand
{
    /**
     * @param string            $publicId     the song's public ID, as given
     * @param list<string>|null $lockedFields the complete list of locked fields, replacing the current one
     * @param list<string>      $lock         fields to lock in addition
     * @param list<string>      $unlock       fields to unlock
     */
    public function __construct(
        public string $publicId,
        public ?string $title = null,
        public ?int $track = null,
        public ?int $disc = null,
        public ?int $year = null,
        public ?string $comment = null,
        public ?string $lyrics = null,
        public ?bool $explicit = null,
        public ?array $lockedFields = null,
        public array $lock = [],
        public array $unlock = [],
    ) {
    }
}
