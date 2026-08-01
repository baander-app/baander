<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure;

use App\Catalog\Application\Port\AlbumDuplicatePortInterface;
use App\Catalog\Application\Port\AlbumPortInterface;
use App\Media\Application\Port\ImagePortInterface;
use App\Catalog\Domain\Service\AlbumDuplicateDetector;
use App\Catalog\Domain\ValueObject\DuplicateGroup;
use App\Shared\Domain\Model\Uuid;
use App\Catalog\Interface\Resource\AlbumResource;

final class AlbumDuplicateService implements AlbumDuplicatePortInterface
{
    public function __construct(
        private readonly AlbumDuplicateDetector $detector,
        private readonly AlbumPortInterface $albumService,
        private readonly ImagePortInterface $imagePort,
    ) {
    }

    public function findDuplicates(Uuid $libraryId): array
    {
        $groups = $this->detector->findDuplicates($libraryId);

        if ($groups === []) {
            return [];
        }

        // Collect all album IDs from all groups
        $allAlbumIds = [];
        foreach ($groups as $group) {
            foreach ($group->getAlbumIds() as $id) {
                $allAlbumIds[] = $id;
            }
        }

        // Batch fetch artist names for all albums
        $artistNamesMap = $this->albumService->getArtistNamesForAlbums($allAlbumIds);

        // Enrich groups with album data
        return array_map(
            fn(DuplicateGroup $group) => $this->enrichGroup($group, $artistNamesMap),
            $groups,
        );
    }

    /**
     * @param array<string, array> $artistNamesMap
     */
    private function enrichGroup(DuplicateGroup $group, array $artistNamesMap): DuplicateGroup
    {
        $albumIds = $group->getAlbumIds();
        $albums = [];

        foreach ($albumIds as $id) {
            $album = $this->albumService->findByUuid($id);
            if ($album !== null) {
                $albumData = AlbumResource::from($album);

                // Add cover image
                $coverImageId = $album->getCoverImageId();
                if ($coverImageId !== null) {
                    $coverImage = $this->imagePort->findByUuid($coverImageId);
                    if ($coverImage !== null) {
                        $albumData['coverImage'] = [
                            'publicId' => $coverImage->getPublicId()->toString(),
                            'blurhash' => $coverImage->getBlurhash(),
                        ];
                    }
                } else {
                    $albumData['coverImage'] = null;
                }

                // Add artist names
                $albumIdString = $album->getId()->toString();
                $albumData['artists'] = $artistNamesMap[$albumIdString] ?? [];

                $albums[] = $albumData;
            }
        }

        return new DuplicateGroup(
            $albumIds,
            $group->getConfidence(),
            $albums,
        );
    }

    public function findDuplicatesForAlbum(Uuid $albumId): array
    {
        $album = $this->albumService->findByUuid($albumId);

        if ($album === null) {
            return [];
        }

        $allGroups = $this->detector->findDuplicates($album->getLibraryId());

        // Filter to only groups containing the specified album
        return array_filter(
            $allGroups,
            fn(DuplicateGroup $group) => in_array(
                $albumId->toString(),
                array_map(fn($id) => $id->toString(), $group->getAlbumIds()),
                true,
            ),
        );
    }
}
