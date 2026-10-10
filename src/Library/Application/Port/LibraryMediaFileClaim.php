<?php

declare(strict_types=1);

namespace App\Library\Application\Port;

use App\Shared\Domain\Model\Uuid;

/** The claim a delete with files holds on its library, from LibraryMediaFilesInterface::claim(). */
final readonly class LibraryMediaFileClaim
{
    public function __construct(
        public Uuid $libraryId,
        public Uuid $claimId,
    ) {
    }
}
