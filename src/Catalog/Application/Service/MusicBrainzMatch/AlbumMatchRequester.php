<?php

declare(strict_types=1);

namespace App\Catalog\Application\Service\MusicBrainzMatch;

use App\Catalog\Application\Message\DrainAlbumMatchesMessage;
use App\Catalog\Application\Port\AlbumMatchRequestInterface;
use App\Catalog\Application\Port\AlbumPortInterface;
use App\Catalog\Application\Port\SongPortInterface;
use App\Catalog\Domain\Model\Song;
use App\Catalog\Domain\Repository\AlbumMatchStateRepositoryInterface;
use App\Catalog\Domain\ValueObject\AlbumMatchSource;
use App\Catalog\Domain\ValueObject\AlbumMatchStatus;
use App\Metadata\Application\Settings\MetadataSettingDefinitions;
use App\Shared\Application\Port\SystemSettingsPortInterface;
use App\Shared\Domain\Model\Uuid;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Records album match requests and makes sure an album match queue run is on its way to each.
 *
 * While requests wait, each queue run queues the next one for when the earliest of them can be
 * taken. A request that brings an album's due time forward therefore queues a run only when no
 * other request falls due at or before its own; a request that falls due later, such as an album
 * to try again in hours, brings no run in time. A request a run holds counts from the expiry of
 * that run's claim. The request writes itself first and then looks for others, and the run ends
 * its claim first and then looks for what is left. Of a request and a finishing run that overlap,
 * at least one therefore sees the other's write and the queue keeps its run.
 *
 * A request for an album a run is matching queues no run. It waits beside that run's claim, and
 * the run, when it ends the claim, leaves the album waiting for a match from the request's due
 * time and queues the run that comes for it.
 */
final readonly class AlbumMatchRequester implements AlbumMatchRequestInterface
{
    /** How long an import's request waits, so that the album's other discs land first. */
    public const int SETTLING_DELAY_SECONDS = 300;

    public function __construct(
        private AlbumMatchStateRepositoryInterface $states,
        private AlbumPortInterface $albums,
        private SongPortInterface $songs,
        private SystemSettingsPortInterface $settings,
        private MessageBusInterface $bus,
        private ClockInterface $clock,
    ) {
    }

    #[\Override]
    public function requestAfterImport(Uuid ...$albumIds): void
    {
        if ($albumIds === [] || $this->settings->get(MetadataSettingDefinitions::AUTO_SYNC) !== true) {
            return;
        }

        $this->request(AlbumMatchSource::Ingest, self::SETTLING_DELAY_SECONDS, ...array_filter($albumIds, $this->wantsMatch(...)));
    }

    #[\Override]
    public function requestForAdmin(Uuid $albumId): void
    {
        if ($this->wantsMatch($albumId)) {
            $this->request(AlbumMatchSource::Admin, 0, $albumId);
        }
    }

    /** @param int<0, max> $delaySeconds how long from now the requests fall due */
    private function request(AlbumMatchSource $source, int $delaySeconds, Uuid ...$albumIds): void
    {
        $dueAt = $this->clock->now()->add(new \DateInterval('PT' . $delaySeconds . 'S'));
        $dueSooner = array_filter($albumIds, fn (Uuid $albumId): bool => $this->states->request($albumId, $source, $dueAt));
        // A request due at or before these leads a run to them: its own run, or the chain waiting
        // for the earliest due time. An album here that already waited for an earlier time counts
        // too. A request due later, such as one parked for a retry, would leave these waiting.
        if ($dueSooner === [] || $this->states->hasRequestsDueBy($dueAt, ...$dueSooner)) {
            return;
        }

        $this->bus->dispatch(new DrainAlbumMatchesMessage(), [new DelayStamp($delaySeconds * 1000)]);
    }

    /**
     * Whether a match has something to do for the album: it has no release yet and is not left for
     * review with the song count the matcher checked, or it is matched and has a song no match has
     * compared yet.
     */
    private function wantsMatch(Uuid $albumId): bool
    {
        $album = $this->albums->findByUuid($albumId);
        if ($album === null) {
            return false;
        }

        if ($album->getReleaseMbid() !== null) {
            return array_any(
                $this->songs->findByAlbumSortedByTrack($albumId),
                static fn (Song $song): bool => $song->getMatchCheckedAt() === null,
            );
        }

        $state = $this->states->findByAlbum($albumId);

        return $state?->status !== AlbumMatchStatus::NeedsReview
            || $state->checkedSongCount !== $this->songs->countByAlbum($albumId);
    }
}
