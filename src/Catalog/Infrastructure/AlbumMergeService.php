<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure;

use App\Activity\Application\Port\AlbumActivityMergePortInterface;
use App\Catalog\Application\Port\AlbumMatchRequestInterface;
use App\Catalog\Application\Port\AlbumMergePortInterface;
use App\Catalog\Application\Port\AlbumPortInterface;
use App\Catalog\Application\Port\SongPortInterface;
use App\Catalog\Application\Service\CoverImageDiscarder;
use App\Catalog\Domain\Model\Album;
use App\Catalog\Domain\Model\Song;
use App\Favorites\Application\Port\AlbumFavoriteMergePortInterface;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Exception\TransactionDeadlockException;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\Uuid;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;

/**
 * Merges a source album into a target in one transaction, under the catalog's lock order: both
 * album rows in ID order, the source FOR UPDATE and the target FOR NO KEY UPDATE, then the rows of
 * every song the two albums hold, in ID order. The albums and songs are read only once those locks
 * are held, so the merge acts on what a match apply, a retag or a hand change that held one first
 * committed, and it moves or removes every song the source holds: a song stored for the source
 * meanwhile waits for the source's row and then fails. The source's favorites and plays move to the
 * target in the same transaction, through the ports of Favorites and Activity. The source's cover is
 * discarded and a match of the target requested once the merge committed.
 */
final readonly class AlbumMergeService implements AlbumMergePortInterface
{
    /** The database ended a merge to break a deadlock with another catalog writer; merging again may succeed. */
    public const string BUSY_REASON = 'merge_busy';

    public function __construct(
        private AlbumPortInterface $albumPort,
        private SongPortInterface $songPort,
        private AlbumMatchRequestInterface $albumMatches,
        private TransactionPortInterface $transaction,
        private CoverImageDiscarder $covers,
        private AlbumFavoriteMergePortInterface $favorites,
        private AlbumActivityMergePortInterface $activities,
        private LoggerInterface $logger,
    ) {
    }

    #[\Override]
    public function mergeAlbums(Uuid $targetAlbumId, Uuid $sourceAlbumId): Album
    {
        if ($targetAlbumId->toString() === $sourceAlbumId->toString()) {
            throw new InvalidArgumentException('Cannot merge an album with itself.');
        }
        // Read for their titles alone, which name the albums should the merge deadlock; the merge
        // reads both again under its locks and decides on that.
        $target = $this->albumPort->findByUuid($targetAlbumId) ?? throw self::notFound('Target');
        $source = $this->albumPort->findByUuid($sourceAlbumId) ?? throw self::notFound('Source');

        try {
            [$merged, $sourceCoverId] = $this->transaction->run(fn (): array => $this->mergeUnderLocks($targetAlbumId, $sourceAlbumId));
        } catch (TransactionDeadlockException $deadlock) {
            throw $this->busy($target, $source, $deadlock);
        }

        // A rollback would have left the source with its cover, so the image goes only now.
        if ($sourceCoverId !== null) {
            $this->covers->discard($sourceCoverId);
        }
        $this->requestMatch($targetAlbumId);

        return $merged;
    }

    /**
     * @return array{Album, ?Uuid} the merged target, and the source's cover to discard once the merge committed
     */
    private function mergeUnderLocks(Uuid $targetAlbumId, Uuid $sourceAlbumId): array
    {
        $this->albumPort->lockForMerge($sourceAlbumId, $targetAlbumId);
        $target = $this->albumPort->findFreshByUuid($targetAlbumId) ?? throw self::notFound('Target');
        $source = $this->albumPort->findFreshByUuid($sourceAlbumId) ?? throw self::notFound('Source');
        if (!$target->getLibraryId()->equals($source->getLibraryId())) {
            throw new InvalidArgumentException('Albums must be in the same library to merge.');
        }

        // With the source's row held FOR UPDATE no song is added to it, and only a merge moves a
        // song between albums, so these are all the songs the source holds until it is deleted.
        $songIds = $this->songPort->findIdsByAlbums([$targetAlbumId, $sourceAlbumId]);
        $this->songPort->lockInIdOrder($songIds);
        $songs = $this->songPort->findFreshByUuids($songIds) |> array_values(...);
        $keptSongs = self::keptSongs(array_filter($songs, static fn (Song $song): bool => $song->getAlbumId()->equals($targetAlbumId)));
        $moved = [];
        $duplicates = [];
        foreach (array_filter($songs, static fn (Song $song): bool => $song->getAlbumId()->equals($sourceAlbumId)) as $song) {
            $kept = $keptSongs[self::hashOf($song) ?? ''] ?? null;
            if ($kept === null) {
                $moved[] = $song;
            } else {
                $duplicates[] = [$song, $kept];
            }
        }

        $this->moveSongs($moved, $targetAlbumId);
        $this->removeDuplicates($duplicates, $sourceAlbumId);
        $this->moveFavoritesAndPlays($source, $target);

        $metadataChanges = [];
        $this->mergeMetadata($target, $source, $metadataChanges);
        $this->handleLockedFields($target, $source);
        $target->addMergeRecord($sourceAlbumId, $source->getTitle());
        $this->albumPort->delete($source, deleteCover: false);
        $this->albumPort->save($target);

        return [$target, $source->getCoverImageId()];
    }

    /**
     * The target's oldest song of each file hash, which a source song with that hash duplicates.
     *
     * @param array<array-key, Song> $targetSongs
     *
     * @return array<string, Song> by hash
     */
    private static function keptSongs(array $targetSongs): array
    {
        $kept = [];
        foreach ($targetSongs as $song) {
            $hash = self::hashOf($song);
            if ($hash !== null && (!isset($kept[$hash]) || self::isOlder($song, $kept[$hash]))) {
                $kept[$hash] = $song;
            }
        }

        return $kept;
    }

    /** The song's file hash; null when it has none, so that two songs without one are no duplicates. */
    private static function hashOf(Song $song): ?string
    {
        $hash = $song->getHash();

        return $hash === null || $hash === '' ? null : $hash;
    }

    private static function isOlder(Song $song, Song $than): bool
    {
        return ($song->getCreatedAt() <=> $than->getCreatedAt() ?: strcmp($song->getId()->toBinary(), $than->getId()->toBinary())) < 0;
    }

    /**
     * Moves the songs onto the target and writes them in one flush, not one per song: each flush
     * walks every entity the merge holds.
     *
     * @param list<Song> $songs
     */
    private function moveSongs(array $songs, Uuid $targetAlbumId): void
    {
        foreach ($songs as $song) {
            $song->moveToAlbum($targetAlbumId);
            $this->songPort->persist($song);
        }
        $this->songPort->flush();
    }

    /**
     * Removes each source song whose file the target holds already, and logs which target song
     * holds it: nothing else records where the removed song went.
     *
     * @param list<array{Song, Song}> $duplicates each duplicate with the target's song it duplicates
     */
    private function removeDuplicates(array $duplicates, Uuid $sourceAlbumId): void
    {
        foreach ($duplicates as [$duplicate, $kept]) {
            $this->songPort->delete($duplicate);
            $this->logger->info('Removed song {song_id} of the merged album {source_album_id}: song {kept_song_id} of album {target_album_id} holds the same file.', [
                'song_id' => $duplicate->getId()->toString(),
                'source_album_id' => $sourceAlbumId->toString(),
                'kept_song_id' => $kept->getId()->toString(),
                'target_album_id' => $kept->getAlbumId()->toString(),
            ]);
        }
    }

    /**
     * Makes the users' favorites of the source favorites of the target, and lets the plays recorded
     * for the source count for the target. It runs before the source is deleted, whose foreign key
     * would otherwise clear the album of those plays.
     */
    private function moveFavoritesAndPlays(Album $source, Album $target): void
    {
        $this->favorites->merge($source->getPublicId(), $target->getPublicId());
        $this->activities->merge($source->getId(), $target->getId());
    }

    /** Logged with both albums, since frequent deadlocks with imports would call for another design. */
    private function busy(Album $target, Album $source, TransactionDeadlockException $deadlock): ConflictException
    {
        $this->logger->warning('An album merge deadlocked with another catalog writer and was rolled back.', [
            'source_album_id' => $source->getId()->toString(),
            'target_album_id' => $target->getId()->toString(),
            'exception' => $deadlock,
        ]);

        return new ConflictException(
            sprintf('Another change to "%s" or "%s" ran at the same time and nothing was merged. Merge the albums again.', $source->getTitle(), $target->getTitle()),
            ['reason' => self::BUSY_REASON],
            $deadlock,
        );
    }

    /** @param 'Target'|'Source' $role */
    private static function notFound(string $role): NotFoundException
    {
        // The message the entry points answer when their lookup finds no such album.
        return new NotFoundException($role . ' album not found.');
    }

    /**
     * Requests an automatic MusicBrainz match of the target once the moved songs are committed: no
     * match has compared them with the target's release. The request decides whether the album
     * needs a match and does nothing while automatic matching is off.
     *
     * The request is best-effort: a failure must not fail the finished merge. A metadata sync from
     * the admin panel requests the album again.
     */
    private function requestMatch(Uuid $targetAlbumId): void
    {
        try {
            $this->albumMatches->requestAfterImport($targetAlbumId);
        } catch (\Throwable $e) {
            $this->logger->error('Could not request a MusicBrainz match of album {album_id} after a merge; a metadata sync from the admin panel requests it again.', [
                'album_id' => $targetAlbumId->toString(),
                'exception' => $e,
            ]);
        }
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
            // A field the target has locked keeps its value, even when that value is empty.
            if ($target->isFieldLocked($field)) {
                continue;
            }

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
