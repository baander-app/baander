<?php

declare(strict_types=1);

namespace App\Catalog\Application\Service;

use App\Catalog\Domain\Model\Movie;
use App\Catalog\Domain\Model\Video;
use App\Catalog\Domain\Model\VideoPlacement;
use App\Catalog\Domain\Repository\MovieRepositoryInterface;
use App\Catalog\Domain\Repository\TvCatalogRepositoryInterface;
use App\Catalog\Domain\Repository\VideoRepositoryInterface;
use App\Catalog\Domain\Service\VideoName\MovieNameParser;
use App\Catalog\Domain\ValueObject\VideoName\MovieName;
use App\Library\Application\Message\DiscoveredFile;
use App\Library\Application\Port\LibraryMediaFilesInterface;
use App\Shared\Domain\Model\Uuid;

final readonly class MovieFileIngest
{
    public function __construct(
        private MovieRepositoryInterface $movies,
        private TvCatalogRepositoryInterface $placements,
        private VideoRepositoryInterface $videos,
        private MovieNameParser $names,
        private LibraryMediaFilesInterface $files,
    ) {
    }

    public function accepts(DiscoveredFile $file): bool
    {
        return !$this->names->parse($file->relativePath)->sample;
    }

    /**
     * @param list<DiscoveredFile> $files Current observations locked by the caller.
     * @return array<string, Uuid> Existing movie IDs by the folder identity now observed.
     */
    public function prepareBatch(Uuid $libraryId, array $files): array
    {
        $keys = [];
        foreach ($files as $file) {
            $name = $this->names->parse($file->relativePath);
            $keys[] = 'video-hash:' . $file->hash;
            $keys[] = 'video-movie-folder:' . $libraryId->toString() . ':' . $this->folderIdentity($file, $name);
        }
        $this->placements->lockImportIdentities($keys);

        $owners = [];
        foreach ($files as $file) {
            $video = $this->videos->findByHash($file->hash);
            if ($video === null) {
                continue;
            }
            $placement = $this->placements->findPlacement($libraryId, $video->getId());
            $movieId = $placement?->getMovieId();
            if ($placement !== null && $movieId !== null
                && !$this->isDuplicate($libraryId, $placement, $file, $video, $this->libraryRoot($file))
            ) {
                $identity = $this->folderIdentity($file, $this->names->parse($file->relativePath));
                $owners[$identity] = $movieId;
            }
        }

        return $owners;
    }

    /** @param array<string, Uuid> $context Existing movie IDs by observed folder identity. */
    public function ingestFile(Uuid $libraryId, DiscoveredFile $file, Video $video, array $context): void
    {
        $name = $this->names->parse($file->relativePath);
        if ($name->sample) {
            return;
        }
        $identity = $this->folderIdentity($file, $name);
        $placement = $this->placements->findPlacement($libraryId, $video->getId());
        $movieId = $placement?->getMovieId();
        $movie = $movieId === null ? null : $this->movies->findByUuid($movieId);
        if ($movie === null && isset($context[$identity])) {
            $movie = $this->movies->findByUuid($context[$identity]);
        }
        $movie ??= $this->movies->findByPlacementFolder($libraryId, $identity);
        if ($movie === null) {
            $movie = Movie::create($libraryId, $name->title, $name->year);
            $this->movies->persist($movie);
            $this->movies->flush();
        }

        if ($placement !== null && $placement->getMovieId() === null) {
            $placement->review('duplicate_in_library');
            $this->placements->savePlacement($placement);
            $this->placements->flush();

            return;
        }

        $root = $this->libraryRoot($file);
        $rawParse = $name->jsonSerialize();
        if ($placement === null) {
            $placement = VideoPlacement::create(
                $libraryId,
                $video->getId(),
                $file->relativePath,
                $rawParse,
                movieId: $movie->getId(),
                extraKind: $name->extraKind,
            );
            $this->preserveReplacementAssignment($movie, $placement, $file);
        } elseif ($this->isDuplicate($libraryId, $placement, $file, $video, $root)) {
            $this->recordDuplicate($placement, $file, $video, $root);
            $this->placements->savePlacement($placement);
            $this->placements->flush();

            return;
        } else {
            $duplicates = $this->currentDuplicatePaths(
                $libraryId,
                $placement->getRawParse(),
                $video,
                $root,
                $file->relativePath,
            );
            if ($duplicates !== []) {
                $rawParse['duplicatePaths'] = $duplicates;
            }
            $placement->observe($file->relativePath, $rawParse);
            $placement->applyParsedExtraKind($name->extraKind);
            if ($duplicates !== []) {
                $placement->review('duplicate_file');
            } elseif ($placement->getReviewReason() === 'duplicate_file') {
                $placement->review(null);
            }
        }

        $this->placements->savePlacement($placement);
        if ($placement->getExtraKind() === null) {
            $this->movies->addVideoForImport($movie->getId(), $video->getId());
        } else {
            $this->movies->removeVideoForImport($movie->getId(), $video->getId());
        }
        $this->placements->flush();
    }

    public function flush(): void
    {
        $this->placements->flush();
    }

    private function folderIdentity(DiscoveredFile $file, MovieName $name): string
    {
        return $name->folderPath !== '' ? $name->folderPath : $file->relativePath;
    }

    private function libraryRoot(DiscoveredFile $file): string
    {
        if (!str_ends_with($file->absolutePath, '/' . $file->relativePath)) {
            throw new \InvalidArgumentException('A discovered movie path must end in its library-relative path.');
        }

        return substr($file->absolutePath, 0, strlen($file->absolutePath) - strlen($file->relativePath));
    }

    private function isDuplicate(Uuid $libraryId, VideoPlacement $placement, DiscoveredFile $file, Video $video, string $root): bool
    {
        if ($placement->getRelativePath() === $file->relativePath) {
            return false;
        }
        $path = $root . $placement->getRelativePath();
        $hashes = $this->files->findAvailableHashesByPaths($libraryId, [$path]);

        return ($hashes[$path] ?? null) === $video->getHash()
            && $this->files->markMissingUnavailable($libraryId, [$path]) === [];
    }

    private function recordDuplicate(VideoPlacement $placement, DiscoveredFile $file, Video $video, string $root): void
    {
        $rawParse = $placement->getRawParse();
        $duplicates = $this->currentDuplicatePaths(
            $placement->getLibraryId(),
            $rawParse,
            $video,
            $root,
            $placement->getRelativePath(),
        );
        $duplicates[] = $file->relativePath;
        $rawParse['duplicatePaths'] = array_values(array_unique($duplicates));
        $placement->observe($placement->getRelativePath(), $rawParse);
        $placement->review('duplicate_file');
    }

    /**
     * @param array<string, mixed> $rawParse
     * @return list<string>
     */
    private function currentDuplicatePaths(Uuid $libraryId, array $rawParse, Video $video, string $root, string $mainPath): array
    {
        $paths = array_filter(
            is_array($rawParse['duplicatePaths'] ?? null) ? $rawParse['duplicatePaths'] : [],
            static fn(mixed $path): bool => is_string($path) && $path !== $mainPath,
        );
        $absolutePaths = array_map(static fn(string $path): string => $root . $path, $paths);
        $hashes = $this->files->findAvailableHashesByPaths($libraryId, array_values($absolutePaths));
        $missing = $this->files->markMissingUnavailable($libraryId, array_values($absolutePaths))
            |> array_flip(...);

        return array_filter(
            $paths,
            static fn(string $path): bool => !isset($missing[$root . $path]) && ($hashes[$root . $path] ?? null) === $video->getHash(),
        ) |> array_unique(...) |> array_values(...);
    }

    private function preserveReplacementAssignment(Movie $movie, VideoPlacement $placement, DiscoveredFile $file): void
    {
        $latest = $this->placements->latestAssignedPlacementByMovieAtPath($movie->getId(), $file->relativePath);
        if ($latest === null) {
            return;
        }
        $video = $this->videos->findByUuid($latest->getVideoId());
        if ($video === null) {
            return;
        }
        $paths = $this->currentDuplicatePaths(
            $latest->getLibraryId(),
            $latest->getRawParse(),
            $video,
            $this->libraryRoot($file),
            $file->relativePath,
        );
        if ($paths !== []) {
            return;
        }
        $placement->copyAssignmentFrom($latest);
    }
}
