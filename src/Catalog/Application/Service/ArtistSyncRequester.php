<?php

declare(strict_types=1);

namespace App\Catalog\Application\Service;

use App\Catalog\Application\Port\ArtistSyncRequestInterface;
use App\Catalog\Application\Port\IdentifiedArtist;
use App\Metadata\Application\Settings\MetadataSettingDefinitions;
use App\Shared\Application\Port\SystemSettingsPortInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Queues an artist fill for each artist a match apply or an import batch identified, through the
 * queue every producer shares, so the fills keep the shared pace and an artist is not asked about
 * twice. It runs after the writer's commit and outside any transaction, and it reads no catalog
 * row, so it takes no lock.
 *
 * An import's fills follow the automatic matching switch, as its album match requests do; an
 * apply's do not, as an admin's sync does not. The switch is read when the fills are queued: a fill
 * queued while it was on still runs after it is turned off. A failure is logged with the artists
 * not queued and goes no further, so the apply or batch that identified them stays committed.
 */
final readonly class ArtistSyncRequester implements ArtistSyncRequestInterface
{
    public function __construct(
        private ArtistSyncQueue $queue,
        private SystemSettingsPortInterface $settings,
        private LoggerInterface $logger,
    ) {
    }

    #[\Override]
    public function requestAfterImport(IdentifiedArtist ...$artists): void
    {
        $this->request($this->automaticMatchingIsOn(...), ...$artists);
    }

    #[\Override]
    public function requestAfterMatch(IdentifiedArtist ...$artists): void
    {
        $this->request(static fn (): bool => true, ...$artists);
    }

    /** @param \Closure(): bool $wanted whether the fills are to be queued, read once there is an artist to queue */
    private function request(\Closure $wanted, IdentifiedArtist ...$artists): void
    {
        if ($artists === []) {
            return;
        }

        $queued = 0;
        try {
            if (!$wanted()) {
                return;
            }
            foreach ($artists as $artist) {
                $this->queue->queue($artist->artistId, $artist->musicbrainzId);
                ++$queued;
            }
        } catch (Throwable $failure) {
            $missed = array_slice($artists, $queued);
            $this->logger->error('Could not queue the MusicBrainz fill of {count} artist(s); a later metadata sync from the admin panel queues them.', [
                'count' => count($missed),
                'artist_ids' => array_map(static fn (IdentifiedArtist $artist): string => $artist->artistId->toString(), $missed),
                'exception' => $failure,
            ]);
        }
    }

    private function automaticMatchingIsOn(): bool
    {
        return $this->settings->get(MetadataSettingDefinitions::AUTO_SYNC) === true;
    }
}
