<?php

declare(strict_types=1);

namespace App\Library\Application\Command;

use App\Shared\Domain\Model\Uuid;

/**
 * Creates a library: the admin panel's POST /api/libraries and `app:library:create`.
 * The handler validates every field.
 */
final readonly class CreateLibraryCommand
{
    public function __construct(
        public string $name,
        /** An absolute path inside the container. */
        public string $path,
        /** A LibraryType value, such as `music`. */
        public string $type,
        /** A FilesystemType value. */
        public string $filesystemType = 'local',
        /** Generated from the name when null. */
        public ?string $slug = null,
        public int $sortOrder = 0,
        /**
         * The user granted access to the new library, which also makes them a recipient of its
         * scan notifications: the creating admin on the web, nobody from the shell.
         */
        public ?Uuid $grantTo = null,
    ) {
    }
}
