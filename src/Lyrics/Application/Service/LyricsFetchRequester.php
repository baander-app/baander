<?php

declare(strict_types=1);

namespace App\Lyrics\Application\Service;

use App\Lyrics\Application\Command\BulkFetchLyricsCommand;
use App\Lyrics\Application\Command\FetchLyricsCommand;
use App\Lyrics\Application\Port\LyricsFetchRequestInterface;
use App\Lyrics\Application\Settings\LyricsSettingDefinitions;
use App\Shared\Application\Port\SystemSettingsPortInterface;
use App\Shared\Domain\Model\Uuid;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

/**
 * Queues automatic lyrics fetches for new songs while `lyrics.auto_fetch` is on.
 *
 * FetchLyricsCommand has no transport route, so the bulk fetch keeps handling it
 * synchronously; only these automatic requests go to the durable async transport.
 *
 * Each fetch is delayed so that fetches run at the bulk fetch's pace, one per
 * BulkFetchLyricsCommand::DEFAULT_DELAY_MS. The schedule carries over between
 * calls in the same process, so the albums of one scan queue behind each other
 * instead of all starting at once and getting throttled by LRCLIB.
 */
final class LyricsFetchRequester implements LyricsFetchRequestInterface
{
    /** Milliseconds since the epoch at which the next fetch may run. */
    private float $nextFetchAtMs = 0.0;

    public function __construct(
        private readonly SystemSettingsPortInterface $settings,
        private readonly MessageBusInterface $bus,
        private readonly ClockInterface $clock,
    ) {
    }

    public function requestFetch(Uuid ...$songIds): void
    {
        if ($songIds === [] || $this->settings->get(LyricsSettingDefinitions::AUTO_FETCH) !== true) {
            return;
        }

        $nowMs = (float) $this->clock->now()->format('U.u') * 1000;
        foreach ($songIds as $songId) {
            $fetchAtMs = max($nowMs, $this->nextFetchAtMs);
            $this->bus->dispatch(new FetchLyricsCommand($songId), [
                new TransportNamesStamp(['async']),
                new DelayStamp((int) round($fetchAtMs - $nowMs)),
            ]);
            $this->nextFetchAtMs = $fetchAtMs + BulkFetchLyricsCommand::DEFAULT_DELAY_MS;
        }
    }
}
