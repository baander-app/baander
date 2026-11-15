<?php

declare(strict_types=1);

namespace App\Catalog\Application\CommandHandler\MusicBrainzMatch;

use App\Catalog\Application\Command\MusicBrainzMatch\ApplyMatchCommand;
use App\Catalog\Application\Command\MusicBrainzMatch\MatchApplyOutcome;
use App\Catalog\Application\Command\MusicBrainzMatch\MatchApplyResult;
use App\Catalog\Application\Command\MusicBrainzMatch\MatchSelection;
use App\Catalog\Application\Port\AlbumPortInterface;
use App\Catalog\Application\Port\ArtistIdentity;
use App\Catalog\Application\Port\ArtistIdentityResolverInterface;
use App\Catalog\Application\Port\ArtistMergePortInterface;
use App\Catalog\Application\Port\ArtistPortInterface;
use App\Catalog\Application\Port\ArtistRemoval;
use App\Catalog\Application\Port\GenrePortInterface;
use App\Catalog\Application\Port\SongPortInterface;
use App\Catalog\Application\Query\MusicBrainzMatch\AlbumSnapshot;
use App\Catalog\Application\Query\MusicBrainzMatch\ArtistActionKind;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchComparison;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchCreditsRow;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchField;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchFieldRow;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchItemType;
use App\Catalog\Application\Query\MusicBrainzMatch\PredictedArtist;
use App\Catalog\Application\Query\MusicBrainzMatch\SongSnapshot;
use App\Catalog\Application\QueryHandler\MusicBrainzMatch\MatchComparisonBuilder;
use App\Catalog\Application\QueryHandler\MusicBrainzMatch\MatchSnapshots;
use App\Catalog\Application\QueryHandler\MusicBrainzMatch\MusicBrainzAnswer;
use App\Catalog\Application\Service\CoverImageDiscarder;
use App\Catalog\Application\Service\MusicBrainzMatch\CandidateScorer;
use App\Catalog\Application\Service\MusicBrainzMatch\LocalTrack;
use App\Catalog\Application\Service\MusicBrainzMatch\MatchCandidate;
use App\Catalog\Application\Service\MusicBrainzMatch\TrackPair;
use App\Catalog\Domain\Model\Album;
use App\Catalog\Domain\Model\Artist;
use App\Catalog\Domain\Model\Song;
use App\Catalog\Domain\Repository\AlbumMatchStateRepositoryInterface;
use App\Catalog\Domain\ValueObject\ArtistRole;
use App\Catalog\Domain\ValueObject\MatchedSongField;
use App\Catalog\Domain\ValueObject\MusicbrainzId;
use App\Media\Application\Port\ImagePortInterface;
use App\Media\Domain\Model\Image;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzArtist;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzArtistCredit;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzSlotWait;
use App\Metadata\Application\Port\MusicBrainzLookupInterface;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Exception\ServiceUnavailableException;
use App\Shared\Application\Exception\TransactionDeadlockException;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

/**
 * Handles ApplyMatchCommand: writes a MusicBrainz match into the catalog in one transaction, or
 * nothing.
 *
 * Every MusicBrainz call and the comparison run before the transaction: a manual apply looks the
 * candidate up again by its ID and compares it with the item as stored, never with values the
 * client sent, and a missing cover is downloaded and stored as a file. The transaction then takes
 * its locks in the order every catalog writer takes them: the album's row, every artist and genre
 * import key in one sorted pass, the rows of the artists the match may credit, merge, merge into or
 * remove, and the rows of the songs it writes. Under those locks it reads the item again, compares
 * once more and, for a manual apply, checks that every ticked row still shows what the admin saw;
 * only then does it resolve the credited artists as an import does, write the fields, IDs and the
 * credits that change, merge or remove the artists those credits leave without any, and record
 * what the match set on each song. A manual album apply also removes the album's match state,
 * which takes the album off the review list; the album match queue removes the request it claimed
 * itself, after an automatic apply returns. Covers the merges and removals free are discarded
 * after the commit.
 *
 * A manual apply that cannot apply answers with an exception, also when the Cover Art Archive
 * refuses the cover it ticked. An automatic apply reports every outcome in its result instead, a
 * Cover Art Archive outage included, and fails only on a deadlock, which the queue's message
 * retries. A cover the Cover Art Archive refuses does not stop it: the match is applied without
 * the cover, and its result and log entry say so.
 */
#[AsMessageHandler]
final readonly class ApplyMatchHandler
{
    /** A deadlock with another writer ended a manual apply before it wrote anything; applying again may succeed. */
    public const string BUSY_REASON = 'match_busy';

    /** An automatic apply found the album matched to another release since the matcher read it. */
    public const string ALREADY_MATCHED_REASON = 'album_matched';

    public function __construct(
        private MusicBrainzLookupInterface $musicBrainz,
        private CandidateScorer $scorer,
        private MatchSnapshots $snapshots,
        private MatchComparisonBuilder $comparisons,
        private MatchCovers $covers,
        private AlbumPortInterface $albums,
        private SongPortInterface $songs,
        private ArtistPortInterface $artists,
        private GenrePortInterface $genres,
        private ArtistIdentityResolverInterface $artistIdentities,
        private ArtistMergePortInterface $merges,
        private AlbumMatchStateRepositoryInterface $matchStates,
        private ImagePortInterface $images,
        private CoverImageDiscarder $discarder,
        private TransactionPortInterface $transaction,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws InvalidInputException        when the public ID or the candidate's MusicBrainz ID is malformed
     * @throws NotFoundException            when no such item exists (`item_not_found`), or MusicBrainz has no such
     *                                      candidate (`candidate_not_found`)
     * @throws ServiceUnavailableException  when MusicBrainz or the Cover Art Archive did not answer (`musicbrainz_unavailable`)
     * @throws ConflictException            when MusicBrainz refused the request (`musicbrainz_rejected`), the Cover Art
     *                                      Archive refused the ticked cover (`cover_refused`), the item changed since the
     *                                      comparison (`match_stale`), or the apply deadlocked with another writer
     *                                      (`match_busy`); nothing is written then. An automatic apply throws none of
     *                                      these: its result reports the outcome.
     * @throws TransactionDeadlockException when an automatic apply deadlocked with another writer; nothing is written
     */
    public function __invoke(ApplyMatchCommand $command): MatchApplyResult
    {
        try {
            $applied = match ($command->type) {
                MatchItemType::Album => $command->isAutomatic() ? $this->applyAutomatically($command) : $this->applyAlbum($command),
                MatchItemType::Song => $this->applySong($command),
                MatchItemType::Artist => $this->applyArtist($command),
            };
        } catch (TransactionDeadlockException $deadlock) {
            // An import message retries on its own; an admin applies again.
            if ($command->isAutomatic()) {
                throw $deadlock;
            }

            throw new ConflictException(
                'Another change to the same songs or artists ran at the same time. Apply the match again.',
                ['reason' => self::BUSY_REASON],
                $deadlock,
            );
        }
        if (!$applied->isApplied()) {
            return $applied;
        }

        $this->logger->info('Applied a MusicBrainz match', [
            'type' => $applied->type->value,
            'public_id' => $applied->publicId,
            'candidate_id' => $applied->candidateId,
            'automatic' => $command->isAutomatic(),
            'cover_refused' => $applied->coverRefused,
            'fields' => $applied->fields,
            'songs_changed' => $applied->songsChanged,
            'merged_artists' => $applied->mergedArtists,
            'removed_artists' => $applied->removedArtists,
        ]);

        return $applied;
    }

    /**
     * Applies the matcher's candidate and turns each way it can end without applying into an
     * outcome: an automatic apply throws only on a deadlock.
     */
    private function applyAutomatically(ApplyMatchCommand $command): MatchApplyResult
    {
        $albumId = $command->albumId ?? throw new \LogicException('An automatic apply names its album.');
        $notApplied = static fn (MatchApplyOutcome $outcome): MatchApplyResult => MatchApplyResult::notApplied($outcome, null, $command->candidateId);
        try {
            return $this->applyAlbum($command);
        } catch (NotFoundException $notFound) {
            // An artist the apply merges or removes is locked and cannot be gone; only the album can.
            return $this->albums->findByUuid($albumId) === null ? $notApplied(MatchApplyOutcome::AlbumGone) : throw $notFound;
        } catch (ServiceUnavailableException) {
            return $notApplied(MatchApplyOutcome::Unavailable);
        } catch (ConflictException $conflict) {
            return match ($conflict->details['reason'] ?? null) {
                self::ALREADY_MATCHED_REASON => $notApplied(MatchApplyOutcome::AlbumMatchedMeanwhile),
                MatchTicks::STALE_REASON => $notApplied(MatchApplyOutcome::Stale),
                default => throw $conflict,
            };
        }
    }

    private function applyAlbum(ApplyMatchCommand $command): MatchApplyResult
    {
        if ($command->isAutomatic()) {
            $album = $this->albums->findByUuid($command->albumId)
                ?? throw new NotFoundException(sprintf('Album "%s" not found.', $command->albumId->toString()));
            self::assertReleaseUnchanged($command, $album);
            $snapshot = $this->snapshots->ofAlbum($album);
            $candidate = $command->candidate;
            $wait = MusicBrainzSlotWait::Unbounded;
        } else {
            $releaseId = self::musicbrainzId($command->candidateId);
            $snapshot = $this->snapshots->album($command->publicId);
            $release = ($this->musicBrainz->fetchRelease($releaseId) |> MusicBrainzAnswer::unwrap(...))
                ?? throw MusicBrainzAnswer::unknownCandidate(MatchItemType::Album);
            $scored = $this->scorer->scoreRelease($release, $snapshot->localAlbum());
            $candidate = new MatchCandidate($release, $scored->score, $scored->pairs, [$release]);
            $wait = MusicBrainzSlotWait::Bounded;
        }

        $albumId = $snapshot->album->getId();
        $plan = $this->albumPlan($command, $this->comparisons->album($snapshot, $candidate), $snapshot, $candidate);
        $compared = self::comparedSongIds($candidate);
        $locks = ($plan->artistIds() |> $this->storedIdentities(...) |> $plan->locks(...))->withSongs($compared);
        [$cover, $coverRefused] = $plan->ticks->field(MatchField::Cover) === null
            ? [null, false]
            : $this->cover($albumId, $candidate->release->id, $wait, $command->isAutomatic());

        $coverSaved = false;
        try {
            [$applied, $discard] = $this->transaction->run(function () use ($command, $albumId, $candidate, $compared, $locks, $cover, &$coverSaved): array {
                $this->albums->lockInIdOrder([$albumId]);
                $this->lock($locks);
                $album = $this->albums->findFreshByUuid($albumId) ?? throw self::gone(MatchItemType::Album);
                self::assertReleaseUnchanged($command, $album);
                // The songs read before the transaction are held by this process as they were then.
                foreach ($locks->songIds as $songId) {
                    $this->songs->findFreshByUuid($songId);
                }
                $snapshot = $this->snapshots->ofAlbum($album);
                $plan = $this->albumPlan($command, $this->comparisons->album($snapshot, $candidate), $snapshot, $candidate);
                if (!$locks->covers($plan->locks())) {
                    throw MatchTicks::stale('The album changed since the comparison.');
                }

                return $this->writeAlbum($album, $snapshot, $plan, $candidate, $compared, $command->isAutomatic(), $cover, $coverSaved);
            });
        } catch (Throwable $exception) {
            if ($cover !== null && !$this->coverCommitted($cover)) {
                $this->covers->discard($cover);
            }

            throw $exception;
        }

        if ($cover !== null && !$coverSaved) {
            $this->covers->discard($cover);
        }
        $this->discardImages($discard);

        return $coverRefused ? $applied->withCoverRefused() : $applied;
    }

    /**
     * Downloads the release's front cover and stores it as a file. An automatic apply goes on
     * without a cover the Cover Art Archive refuses; a manual apply ends there, before anything is
     * written.
     *
     * @return array{Image|null, bool} the cover, when the release has one a match can use, and whether it was refused
     *
     * @throws ServiceUnavailableException when the Cover Art Archive did not answer
     * @throws ConflictException           when the Cover Art Archive refused the cover of a manual apply
     */
    private function cover(Uuid $albumId, string $releaseId, MusicBrainzSlotWait $wait, bool $automatic): array
    {
        try {
            return [$this->covers->fetch($albumId, $releaseId, $wait), false];
        } catch (ConflictException $conflict) {
            return $automatic && ($conflict->details['reason'] ?? null) === MatchCovers::REFUSED_REASON ? [null, true] : throw $conflict;
        }
    }

    private function applySong(ApplyMatchCommand $command): MatchApplyResult
    {
        $selection = $command->selection ?? throw new \LogicException('A song is matched by an admin only.');
        $recordingId = self::musicbrainzId($command->candidateId);
        $snapshot = $this->snapshots->song($command->publicId ?? '');
        $recording = ($this->musicBrainz->fetchRecording($recordingId) |> MusicBrainzAnswer::unwrap(...))
            ?? throw MusicBrainzAnswer::unknownCandidate(MatchItemType::Song);

        $songId = $snapshot->song->getId();
        $plan = $this->songPlan($selection, $this->comparisons->song($snapshot, $recording), $snapshot, $recording->artistCredit);
        $locks = ($plan->artistIds() |> $this->storedIdentities(...) |> $plan->locks(...))->withSongs([$songId]);

        [$applied, $discard] = $this->transaction->run(function () use ($selection, $songId, $recording, $locks): array {
            $this->lock($locks);
            $song = $this->songs->findFreshByUuid($songId) ?? throw self::gone(MatchItemType::Song);
            $snapshot = $this->snapshots->ofSong($song);
            $plan = $this->songPlan($selection, $this->comparisons->song($snapshot, $recording), $snapshot, $recording->artistCredit);
            if (!$locks->covers($plan->locks())) {
                throw MatchTicks::stale('The song changed since the comparison.');
            }

            $resolved = $this->resolve($plan);
            $publicId = $song->getPublicId()->toString();
            $song->markMatchChecked($this->now());
            $creditsChanged = $this->writeSong($song, $plan->ticks->fields, $plan->ticks->creditsOf($publicId), $plan, $resolved);
            $this->songs->flush();
            [$merged, $removed, $discard] = $this->applyArtistActions($plan, $resolved);

            return [
                MatchApplyResult::applied(
                    type: MatchItemType::Song,
                    publicId: $publicId,
                    candidateId: $recording->id,
                    fields: self::fieldNames($plan->ticks->fields),
                    creditsChanged: $creditsChanged,
                    songsChanged: 0,
                    mergedArtists: $merged,
                    removedArtists: $removed,
                ),
                $discard,
            ];
        });
        $this->discardImages($discard);

        return $applied;
    }

    /**
     * An artist takes the candidate's fields and ID, or, when another artist holds that ID, merges
     * into that artist.
     */
    private function applyArtist(ApplyMatchCommand $command): MatchApplyResult
    {
        $selection = $command->selection ?? throw new \LogicException('An artist is matched by an admin only.');
        $artistMbid = self::musicbrainzId($command->candidateId);
        $artist = $this->snapshots->artist($command->publicId ?? '');
        $candidate = ($this->musicBrainz->fetchArtist($artistMbid) |> MusicBrainzAnswer::unwrap(...))
            ?? throw MusicBrainzAnswer::unknownCandidate(MatchItemType::Artist);

        $artistId = $artist->getId();
        $locks = self::artistLocks($artist, $candidate, MatchTicks::manual($this->comparisons->artist($artist, $candidate), $selection));

        [$applied, $discard] = $this->transaction->run(function () use ($selection, $artistId, $candidate, $locks): array {
            $this->songs->lockImportIdentities($locks->identities, []);
            $this->merges->lock($locks->artistIds);
            $artist = $this->artists->findFreshByUuid($artistId) ?? throw self::gone(MatchItemType::Artist);
            $ticks = MatchTicks::manual($this->comparisons->artist($artist, $candidate), $selection);
            if (!$locks->covers(self::artistLocks($artist, $candidate, $ticks))) {
                throw MatchTicks::stale('The artist changed since the comparison.');
            }

            $holder = array_first($ticks->actions)?->target?->id;
            if ($holder !== null) {
                return [
                    MatchApplyResult::applied(MatchItemType::Artist, $artist->getPublicId()->toString(), $candidate->id, [], false, 0, [$artist->getPublicId()->toString()], []),
                    $this->mergeArtist($artistId, $holder)->imagesToDiscard,
                ];
            }
            $this->writeArtist($artist, $ticks->fields);

            return [
                MatchApplyResult::applied(MatchItemType::Artist, $artist->getPublicId()->toString(), $candidate->id, self::fieldNames($ticks->fields), false, 0, [], []),
                [],
            ];
        });
        $this->discardImages($discard);

        return $applied;
    }

    /**
     * Writes an album match under the locks: resolves the credited artists, writes the album's
     * fields, genres, cover and credits and its songs' fields and credits, then merges or removes
     * the artists the new credits left without any. Every song the candidate compared is marked as
     * checked, paired or not, so a later match of the album leaves it alone. A manual apply removes
     * the album's match state; an automatic one leaves it to the queue, which removes the request it
     * claimed.
     *
     * @param list<Uuid> $compared the songs the candidate was compared with, all locked
     *
     * @param-out bool $coverSaved
     *
     * @return array{MatchApplyResult, list<Uuid>} what the apply wrote, and the images to discard after the commit
     */
    private function writeAlbum(Album $album, AlbumSnapshot $snapshot, MatchPlan $plan, MatchCandidate $candidate, array $compared, bool $automatic, ?Image $cover, bool &$coverSaved): array
    {
        $ticks = $plan->ticks;
        $resolved = $this->resolve($plan);
        $discard = [];
        $now = $this->now();

        $album->updateMetadata(
            title: self::text($ticks->field(MatchField::Title)),
            year: self::number($ticks->field(MatchField::Year)),
            label: self::text($ticks->field(MatchField::Label)),
            catalogNumber: self::text($ticks->field(MatchField::CatalogNumber)),
            barcode: self::text($ticks->field(MatchField::Barcode)),
            country: self::text($ticks->field(MatchField::Country)),
            disambiguation: self::text($ticks->field(MatchField::Disambiguation)),
        );
        $album->updateExternalIds(mbid: self::text($ticks->field(MatchField::Mbid)));
        // An album applied again to the release it holds is matched anew: its songs count as matched from now on.
        if ($ticks->field(MatchField::ReleaseMbid) !== null || $album->getReleaseMbid() === $candidate->release->id) {
            $album->recordReleaseMatch(MusicbrainzId::fromString($candidate->release->id) ?? throw new \LogicException('A release has an ID.'), $now);
        }
        $genres = $plan->genres();
        if ($genres !== []) {
            $this->albums->replaceGenres($album->getId(), $this->genres->findOrCreateForImport($genres) |> array_values(...));
        }
        $fields = array_filter($ticks->fields, static fn (MatchFieldRow $row): bool => $row->field !== MatchField::Cover) |> self::fieldNames(...);
        if ($cover !== null && $ticks->field(MatchField::Cover) !== null) {
            $this->images->save($cover);
            $coverSaved = true;
            $replaced = $album->getCoverImageId();
            $album->setCoverImage($cover->getId());
            $discard = $replaced === null ? [] : [$replaced];
            $fields[] = MatchField::Cover->value;
        }
        $this->albums->persist($album);
        $albumCredits = $ticks->creditsOf(null);
        $creditsChanged = $albumCredits !== null && $this->albums->replacePerformerCredits($album->getId(), $this->credits($albumCredits, $plan, $resolved));

        $written = array_map(static fn (Uuid $songId): string => $songId->toString(), $plan->songIds);
        foreach ($compared as $songId) {
            $song = $snapshot->song($songId->toString());
            $song?->markMatchChecked($now);
            if ($song !== null && !in_array($songId->toString(), $written, true)) {
                $this->songs->persist($song);
            }
        }
        $songsChanged = 0;
        foreach ($plan->songIds as $publicId => $songId) {
            $song = $snapshot->song($songId->toString()) ?? throw new \LogicException('A locked song is missing from the album.');
            $this->writeSong($song, $ticks->songFields[$publicId] ?? [], $ticks->creditsOf($publicId), $plan, $resolved);
            ++$songsChanged;
        }
        $this->albums->flush();

        [$merged, $removed, $freed] = $this->applyArtistActions($plan, $resolved);
        if (!$automatic) {
            $this->matchStates->removeByAlbum($album->getId());
        }

        return [
            MatchApplyResult::applied(
                type: MatchItemType::Album,
                publicId: $album->getPublicId()->toString(),
                candidateId: $candidate->release->id,
                fields: $fields,
                creditsChanged: $creditsChanged,
                songsChanged: $songsChanged,
                mergedArtists: $merged,
                removedArtists: $removed,
            ),
            [...$discard, ...$freed],
        ];
    }

    /**
     * Writes a song's fields and credits and records them as set by the match.
     *
     * @param list<MatchFieldRow>  $fields
     * @param array<string, Uuid>  $resolved the artist of each credited identity, by its key
     *
     * @return bool whether the song's credits changed
     */
    private function writeSong(Song $song, array $fields, ?MatchCreditsRow $credits, MatchPlan $plan, array $resolved): bool
    {
        $field = static fn (MatchField $name): ?MatchFieldRow => array_find($fields, static fn (MatchFieldRow $row): bool => $row->field === $name);
        $song->updateMetadata(
            title: self::text($field(MatchField::Title)),
            track: self::number($field(MatchField::Track)),
            disc: self::number($field(MatchField::Disc)),
            year: self::number($field(MatchField::Year)),
        );
        $song->updateExternalIds(mbid: self::text($field(MatchField::Mbid)));
        $matched = array_map(static fn (MatchFieldRow $row): ?MatchedSongField => MatchedSongField::tryFrom($row->field->value), $fields)
            |> array_filter(...);

        $changed = false;
        if ($credits !== null) {
            $changed = $this->songs->replacePerformerCredits($song->getId(), $this->credits($credits, $plan, $resolved));
            $matched[] = MatchedSongField::Credits;
        }
        if ($matched !== []) {
            $song->recordMatchedFields(...$matched);
        }
        $this->songs->persist($song);

        return $changed;
    }

    /** @param list<MatchFieldRow> $fields */
    private function writeArtist(Artist $artist, array $fields): void
    {
        $field = static fn (MatchField $name): ?MatchFieldRow => array_find($fields, static fn (MatchFieldRow $row): bool => $row->field === $name);
        $artist->updateMetadata(
            name: self::text($field(MatchField::Name)),
            country: self::text($field(MatchField::Country)),
            type: self::text($field(MatchField::Type)),
            lifeSpanBegin: self::date($field(MatchField::LifeSpanBegin)),
            lifeSpanEnd: self::date($field(MatchField::LifeSpanEnd)),
            disambiguation: self::text($field(MatchField::Disambiguation)),
            sortName: self::text($field(MatchField::SortName)),
        );
        $artist->updateExternalIds(mbid: self::text($field(MatchField::Mbid)));
        $this->artists->save($artist);
    }

    /**
     * The songs a candidate was compared with: every local track it paired, and every one it paired
     * with no track of the release.
     *
     * @return list<Uuid>
     */
    private static function comparedSongIds(MatchCandidate $candidate): array
    {
        $paired = array_map(static fn (TrackPair $pair): string => $pair->local->id, $candidate->pairs->pairs);
        $unpaired = array_map(static fn (LocalTrack $track): string => $track->id, $candidate->pairs->unpairedLocal);

        return [...$paired, ...$unpaired]
            |> array_unique(...)
            |> (static fn (array $ids): array => array_map(Uuid::fromString(...), $ids))
            |> array_values(...);
    }

    /**
     * Whether a cover the failed apply stored a file for was committed after all: a failed commit may
     * have succeeded on the server. When that cannot be told, the file stays rather than break a
     * committed cover.
     */
    private function coverCommitted(Image $cover): bool
    {
        try {
            return $this->images->findByUuid($cover->getId()) !== null;
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * Deletes the images merges, removals and a replaced cover freed, once the apply committed.
     *
     * @param list<Uuid> $imageIds
     */
    private function discardImages(array $imageIds): void
    {
        foreach ($imageIds as $imageId) {
            $this->discarder->discard($imageId);
        }
    }

    /**
     * Merges or removes each artist the written credits left without any credit, as the merge port
     * reports once those credits are written: an artist that still holds a credit anywhere, such
     * as one an import gave it before the locks, is kept.
     *
     * @param array<string, Uuid> $resolved the artist of each credited identity, by its key
     *
     * @return array{list<string>, list<string>, list<Uuid>} the public IDs merged and removed, and the images to discard
     */
    private function applyArtistActions(MatchPlan $plan, array $resolved): array
    {
        $actions = $plan->ticks->actions;
        if ($actions === []) {
            return [[], [], []];
        }

        $sources = array_map($plan->artistIdOf(...), $actions);
        $credited = $this->merges->lock($sources);
        $merged = [];
        $removed = [];
        $discard = [];
        foreach ($actions as $index => $action) {
            $sourceId = $sources[$index];
            if ($credited[$sourceId->toString()] ?? true) {
                continue;
            }
            $targetId = $action->kind === ArtistActionKind::Merge && $action->target !== null
                ? $this->targetId($action->target, $plan, $resolved)
                : null;
            if ($targetId?->equals($sourceId)) {
                continue;
            }
            $removal = $targetId === null ? $this->merges->remove($sourceId) : $this->mergeArtist($sourceId, $targetId);
            if ($targetId === null) {
                $removed[] = $action->artistPublicId;
            } else {
                $merged[] = $action->artistPublicId;
            }
            $discard = [...$discard, ...$removal->imagesToDiscard];
        }

        return [$merged, $removed, $discard];
    }

    /**
     * Merges the artist into the target and keeps the cover the target took naming its new owner:
     * the database clears the image's owner when it deletes the merged artist.
     */
    private function mergeArtist(Uuid $sourceId, Uuid $targetId): ArtistRemoval
    {
        $cover = $this->artists->findFreshByUuid($sourceId)?->getCoverImageId();
        $removal = $this->merges->merge($sourceId, $targetId);
        if ($cover !== null && !array_any($removal->imagesToDiscard, $cover->equals(...))) {
            $this->images->assignArtistOwner($cover, $targetId);
        }

        return $removal;
    }

    /**
     * An automatic apply matches only an album that holds the release ID the matcher read: an admin
     * may have matched it meanwhile.
     *
     * @throws ConflictException when the album holds another release ID now
     */
    private static function assertReleaseUnchanged(ApplyMatchCommand $command, Album $album): void
    {
        if ($command->isAutomatic() && $album->getReleaseMbid() !== $command->releaseIdSeen) {
            throw new ConflictException(
                sprintf('Album "%s" was matched to another release meanwhile.', $album->getPublicId()->toString()),
                ['reason' => self::ALREADY_MATCHED_REASON],
            );
        }
    }

    /** @param array<string, Uuid> $resolved */
    private function targetId(PredictedArtist $target, MatchPlan $plan, array $resolved): ?Uuid
    {
        $identity = $plan->identityOfArtist($target);

        return ($identity === null ? null : $resolved[$identity->key()] ?? null) ?? $target->id;
    }

    /**
     * The credits a row gives, each MusicBrainz artist resolved; an artist credited twice keeps its first credit.
     *
     * @param array<string, Uuid> $resolved
     *
     * @return list<array{artistId: Uuid, role: ArtistRole, creditedName: string}>
     */
    private function credits(MatchCreditsRow $row, MatchPlan $plan, array $resolved): array
    {
        $credits = [];
        foreach ($row->proposed as $index => $credit) {
            $artistId = $resolved[$plan->identityOf($row, $index)->key()] ?? throw new \LogicException('A credited artist was not resolved.');
            $credits[$artistId->toString() |> strtolower(...)] ??= ['artistId' => $artistId, 'role' => $credit->role, 'creditedName' => $credit->creditedName];
        }

        return array_values($credits);
    }

    /** @return array<string, Uuid> the artist of each credited identity, by its key */
    private function resolve(MatchPlan $plan): array
    {
        $identities = $plan->identities();

        return $identities === [] ? [] : $this->artistIdentities->resolve($identities);
    }

    /** Takes the import keys, then the artist rows, then the song rows, inside the transaction. */
    private function lock(MatchLocks $locks): void
    {
        $this->songs->lockImportIdentities($locks->identities, $locks->genres);
        $this->merges->lock($locks->artistIds);
        $this->songs->lockInIdOrder($locks->songIds);
    }

    private function albumPlan(ApplyMatchCommand $command, MatchComparison $comparison, AlbumSnapshot $snapshot, MatchCandidate $candidate): MatchPlan
    {
        $ticks = $command->isAutomatic() ? MatchTicks::automatic($comparison) : MatchTicks::manual($comparison, $command->selection);
        $sources = ['' => $candidate->release->artistCredit];
        $songs = [];
        foreach ($candidate->pairs->pairs as $pair) {
            $song = $snapshot->song($pair->local->id);
            if ($song !== null) {
                $sources[$song->getPublicId()->toString()] = $pair->release->track->artistCredit;
                $songs[$song->getPublicId()->toString()] = $song->getId();
            }
        }

        return MatchPlan::of($ticks, $sources, $songs);
    }

    private function songPlan(MatchSelection $selection, MatchComparison $comparison, SongSnapshot $snapshot, MusicBrainzArtistCredit $credit): MatchPlan
    {
        $publicId = $snapshot->song->getPublicId()->toString();

        return MatchPlan::of(MatchTicks::manual($comparison, $selection), [$publicId => $credit], [$publicId => $snapshot->song->getId()]);
    }

    /**
     * The import keys and rows an artist match locks: the artist's own name and ID, the candidate's
     * name and ID, which the artist takes on, and the artist holding that ID when the match merges
     * into it.
     */
    private static function artistLocks(Artist $artist, MusicBrainzArtist $candidate, MatchTicks $ticks): MatchLocks
    {
        $holder = array_first($ticks->actions)?->target;
        $identities = [self::storedIdentity($artist), new ArtistIdentity($candidate->name, [$candidate->id])];
        if ($holder !== null) {
            $identities[] = new ArtistIdentity($holder->name, [$candidate->id]);
        }

        return new MatchLocks($identities, [$artist->getId(), ...($holder?->id === null ? [] : [$holder->id])]);
    }

    /**
     * @param list<Uuid> $artistIds
     *
     * @return list<ArtistIdentity> the stored name and MusicBrainz ID of each artist
     */
    private function storedIdentities(array $artistIds): array
    {
        return $artistIds === [] ? [] : $this->artists->findByUuids($artistIds) |> array_values(...) |> (static fn (array $artists): array => array_map(self::storedIdentity(...), $artists));
    }

    private static function storedIdentity(Artist $artist): ArtistIdentity
    {
        return new ArtistIdentity($artist->getName(), $artist->getMbid() === null ? [] : [$artist->getMbid()]);
    }

    /**
     * @param array<MatchFieldRow> $rows
     *
     * @return list<string>
     */
    private static function fieldNames(array $rows): array
    {
        return array_map(static fn (MatchFieldRow $row): string => $row->field->value, $rows) |> array_values(...);
    }

    private static function text(?MatchFieldRow $row): ?string
    {
        return is_string($row?->proposed) ? $row->proposed : null;
    }

    private static function number(?MatchFieldRow $row): ?int
    {
        return is_int($row?->proposed) ? $row->proposed : null;
    }

    private static function date(?MatchFieldRow $row): ?DateTimeImmutable
    {
        $text = self::text($row);

        return $text === null ? null : (DateTimeImmutable::createFromFormat('!Y-m-d', $text) ?: null);
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock->now();
    }

    /** @throws InvalidInputException */
    private static function musicbrainzId(string $id): string
    {
        return MusicbrainzId::tryFromString($id)?->toString() ?? throw new InvalidInputException('Invalid MusicBrainz ID format.');
    }

    private static function gone(MatchItemType $type): NotFoundException
    {
        return new NotFoundException(sprintf('The %s no longer exists.', $type->label() |> strtolower(...)), ['reason' => MatchSnapshots::ITEM_NOT_FOUND_REASON]);
    }
}
