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
 * Records album match requests and keeps one album match queue run in flight while requests wait.
 *
 * The queue's run queues the next run itself while any request waits, so a request queues a run
 * only when it is the first to wait: it writes its request first and then looks for others, and
 * the run removes or marks its album first and then looks for what is left. Of a request and a
 * finishing run that overlap, at least one therefore sees the other's write and the queue keeps
 * its run.
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
        $queued = array_filter($albumIds, fn (Uuid $albumId): bool => $this->states->request($albumId, $source, $dueAt));
        if ($queued === [] || $this->states->hasRequestsOtherThan(...$queued)) {
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
