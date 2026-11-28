<?php

declare(strict_types=1);

namespace App\Library\Application\Port;

use App\Shared\Domain\Model\Uuid;

interface VideoSidecarIndexInterface
{
    /**
     * Available sidecars under a library-relative folder. An empty folder selects direct root files.
     *
     * @return list<array{path: string, relativePath: string}>
     */
    public function forFolder(Uuid $libraryId, string $folderPath): array;
}
