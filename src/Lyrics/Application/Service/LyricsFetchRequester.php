<?php

declare(strict_types=1);

namespace App\Lyrics\Application\Service;

use App\Lyrics\Application\Command\FetchLyricsCommand;
use App\Lyrics\Application\Port\LyricsFetchRequestInterface;
use App\Lyrics\Application\Settings\LyricsSettingDefinitions;
use App\Shared\Application\Port\SystemSettingsPortInterface;
use App\Shared\Domain\Model\Uuid;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

/**
 * Queues automatic lyrics fetches for new songs while `lyrics.auto_fetch` is on.
 *
 * FetchLyricsCommand has no transport route, so the bulk fetch keeps handling it
 * synchronously; only these automatic requests go to the durable async transport.
 */
final readonly class LyricsFetchRequester implements LyricsFetchRequestInterface
{
    public function __construct(
        private SystemSettingsPortInterface $settings,
        private MessageBusInterface $bus,
    ) {
    }

    public function requestFetch(Uuid ...$songIds): void
    {
        if ($songIds === [] || $this->settings->get(LyricsSettingDefinitions::AUTO_FETCH) !== true) {
            return;
        }

        foreach ($songIds as $songId) {
            $this->bus->dispatch(new FetchLyricsCommand($songId), [new TransportNamesStamp(['async'])]);
        }
    }
}
