<?php

declare(strict_types=1);

namespace App\Library\Application\Port;

use App\Library\Application\Message\FilesDiscovered;
use App\Shared\Domain\Model\Uuid;

final readonly class ProvisionedLibraryScan
{
    /**
     * @param list<FilesDiscovered> $discoveries one message per discovered directory
     */
    public function __construct(
        public Uuid $libraryId,
        public string $libraryName,
        public bool $created,
        public array $discoveries,
    ) {
    }
}
