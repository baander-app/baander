<?php

declare(strict_types=1);

namespace App\Catalog\Application\Service;

use App\Catalog\Application\CommandHandler\FilesDiscoveredHandler;
use App\Catalog\Domain\Repository\VideoRepositoryInterface;
use App\Library\Application\Port\LibraryProvisioningInterface;
use App\Library\Application\Port\ProvisionedLibraryScan;

/**
 * Worker-free movie ingest for developer tools.
 *
 * Library provisions and scans the library; each discovered directory is
 * handled synchronously here instead of through the async transport.
 */
final class MovieLibraryIngest
{
    public function __construct(
        private readonly LibraryProvisioningInterface $libraryProvisioning,
        private readonly FilesDiscoveredHandler $filesDiscoveredHandler,
        private readonly VideoRepositoryInterface $videoRepository,
    ) {
    }

    /**
     * Finds or creates the local movie library, then ingests its videos.
     */
    public function ingestMovieLibrary(string $name, string $slug, string $path): MovieLibraryIngestResult
    {
        return $this->ingest($this->libraryProvisioning->provisionMovieLibrary($name, $slug, $path));
    }

    /**
     * Ingests an existing library, or returns null when the slug is unknown.
     */
    public function ingestExistingLibrary(string $slug): ?MovieLibraryIngestResult
    {
        $scan = $this->libraryProvisioning->scanExistingLibrary($slug);

        return $scan === null ? null : $this->ingest($scan);
    }

    private function ingest(ProvisionedLibraryScan $scan): MovieLibraryIngestResult
    {
        $videoIds = [];
        foreach ($scan->discoveries as $discovery) {
            ($this->filesDiscoveredHandler)($discovery);

            foreach ($discovery->files as $file) {
                $video = $this->videoRepository->findByHash($file->hash);
                if ($video !== null) {
                    $videoIds[] = $video->getId()->toString();
                }
            }
        }

        return new MovieLibraryIngestResult(
            libraryId: $scan->libraryId->toString(),
            libraryName: $scan->libraryName,
            libraryCreated: $scan->created,
            videoIds: $videoIds,
        );
    }
}
