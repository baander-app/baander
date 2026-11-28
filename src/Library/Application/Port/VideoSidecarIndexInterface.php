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

    /**
     * Available root-level NFOs matching the supplied case-sensitive filename stems.
     *
     * @param list<string> $stems
     * @return list<array{path: string, relativePath: string}>
     */
    public function forRootNfoStems(Uuid $libraryId, array $stems): array;
}
