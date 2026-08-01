<?php

declare(strict_types=1);

namespace App\Radio\Application\CommandHandler;

use App\Radio\Application\Command\SyncCountryStationsCommand;
use App\Radio\Application\Port\RadioStationPortInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(fromTransport: 'swoole_task')]
final class SyncCountryStationsHandler
{
    public function __construct(
        private readonly RadioStationPortInterface $stationPort,
    ) {
    }

    public function __invoke(SyncCountryStationsCommand $command): int
    {
        return $this->stationPort->syncCountryStations(
            sourceId: $command->getSourceId(),
            countryCode: $command->getCountryCode(),
        );
    }
}
