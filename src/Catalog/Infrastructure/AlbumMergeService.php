<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure;

use App\Catalog\Application\Port\AlbumMergePortInterface;
use App\Catalog\Application\Port\AlbumPortInterface;
use App\Catalog\Application\Port\SongPortInterface;
use App\Catalog\Domain\Model\Album;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;

final class AlbumMergeService implements AlbumMergePortInterface
{
    public function __construct(
        private readonly AlbumPortInterface $albumPort,
        private readonly SongPortInterface $songPort,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function mergeAlbums(Uuid $targetAlbumId, Uuid $sourceAlbumId): Album
    {
        if ($targetAlbumId->toString() === $sourceAlbumId->toString()) {
            throw new InvalidArgumentException('Cannot merge an album with itself.');
        }

        $target = $this->albumPort->findByUuid($targetAlbumId);
        $source = $this->albumPort->findByUuid($sourceAlbumId);

        if ($target === null) {
            throw new InvalidArgumentException('Target album not found.');
        }

        if ($source === null) {
            throw new InvalidArgumentException('Source album not found.');
        }

        if ($target->getLibraryId()->toString() !== $source->getLibraryId()->toString()) {
            throw new InvalidArgumentException('Albums must be in the same library to merge.');
        }

        // Get songs from both albums
        $targetSongs = $this->songPort->findByAlbum($targetAlbumId, limit: 10000);
        $sourceSongs = $this->songPort->findByAlbum($sourceAlbumId, limit: 10000);

        // Build hash map of target songs for deduplication
        $targetHashes = [];
        foreach ($targetSongs as $song) {
            $targetHashes[$song->getHash()] = true;
        }

        // Move non-duplicate source songs onto the target and delete duplicates,
        // all through the ORM. The previous implementation moved songs with a
        // raw SQL UPDATE and then cleared the entity manager; when the source
        // album still held (duplicate) songs at deletion time, a stale
        // SongEntity survived clear() and corrupted the unit of work, failing
        // the final save with a Doctrine cascade error. Moving through the
        // aggregate + repository keeps the unit of work consistent.
        foreach ($sourceSongs as $song) {
            if (!isset($targetHashes[$song->getHash()])) {
                $song->moveToAlbum($targetAlbumId);
                $this->songPort->save($song);
            } else {
                $this->songPort->delete($song);
            }
        }

        // Merge metadata: prefer non-null values from target, then source
        $metadataChanges = [];
        $this->mergeMetadata($target, $source, $metadataChanges);

        // Handle locked fields
        $this->handleLockedFields($target, $source);

        // Record the merge in the target's audit trail
        $target->addMergeRecord($sourceAlbumId, $source->getTitle());

        // Delete the source album (now empty — every song was moved or removed)
        $this->albumPort->delete($source);

        // Save the updated target album
        $this->albumPort->save($target);

        return $target;
    }

    /**
     * @param array<string, mixed> $metadataChanges
     */
    private function mergeMetadata(Album $target, Album $source, array &$metadataChanges): void
    {
        $fields = [
            'mbid' => 'getMbid',
            'discogsId' => 'getDiscogsId',
            'spotifyId' => 'getSpotifyId',
            'year' => 'getYear',
            'label' => 'getLabel',
            'catalogNumber' => 'getCatalogNumber',
            'barcode' => 'getBarcode',
            'country' => 'getCountry',
            'language' => 'getLanguage',
            'disambiguation' => 'getDisambiguation',
        ];

        $updateData = [];

        foreach ($fields as $field => $getter) {
            $targetValue = $target->{$getter}();
            $sourceValue = $source->{$getter}();

            // Prefer target value if non-null, otherwise use source value
            if ($targetValue === null && $sourceValue !== null) {
                $updateData[$field] = $sourceValue;
                $metadataChanges[$field] = ['from' => $targetValue, 'to' => $sourceValue];
            }
        }

        if ($updateData !== []) {
            $target->updateMetadata(
                title: null, // Don't override title
                type: null,  // Don't override type
                year: $updateData['year'] ?? null,
                label: $updateData['label'] ?? null,
                catalogNumber: $updateData['catalogNumber'] ?? null,
                barcode: $updateData['barcode'] ?? null,
                country: $updateData['country'] ?? null,
                language: $updateData['language'] ?? null,
                disambiguation: $updateData['disambiguation'] ?? null,
                annotation: null,
            );
        }

        // Merge external IDs separately
        $target->updateExternalIds(
            mbid: $updateData['mbid'] ?? null,
            discogsId: $updateData['discogsId'] ?? null,
            spotifyId: $updateData['spotifyId'] ?? null,
        );
    }

    private function handleLockedFields(Album $target, Album $source): void
    {
        // Unlock any fields on the target that are locked on the source
        // This allows the merged metadata to take effect
        $sourceLockedFields = $source->getLockedFields();
        $targetLockedFields = $target->getLockedFields();

        foreach ($sourceLockedFields as $field) {
            // If source had a locked field that target didn't, lock it on target
            if (!in_array($field, $targetLockedFields, true)) {
                $target->lockField($field);
            }
        }
    }
}
