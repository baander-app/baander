<?php

declare(strict_types=1);

namespace App\Tests\Unit\Radio\Infrastructure;

use App\Radio\Application\Port\StationSyncPortInterface;
use App\Radio\Domain\Model\RadioSource\RadioSource;
use App\Radio\Domain\Model\RadioStation\RadioStation;
use App\Radio\Domain\Repository\RadioSource\RadioSourceRepositoryInterface;
use App\Radio\Domain\Repository\RadioStation\RadioStationRepositoryInterface;
use App\Radio\Domain\ValueObject\SyncConfig;
use App\Radio\Infrastructure\RadioStationService;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;

final class RadioPublicIdentityTest extends TestCase
{
    public function testStationAndSourceUsePublicIdsWhileProviderUuidRemainsExternal(): void
    {
        $source = RadioSource::create('Provider', 'iprd', new SyncConfig('https://radio.baander.app', null, []));
        $externalId = (new Uuid())->toString();
        $station = RadioStation::create($source->getId(), $externalId, 'Station', 'DK', []);
        $stations = $this->createMock(RadioStationRepositoryInterface::class);
        $stations->expects(self::once())->method('search')->with('')->willReturn([$station]);
        $stations->expects(self::once())->method('findByPublicId')->with($station->getPublicId())->willReturn($station);
        $sources = $this->createMock(RadioSourceRepositoryInterface::class);
        $sources->expects(self::once())->method('find')->with($source->getId())->willReturn($source);
        $service = new RadioStationService($stations, $sources, $this->createStub(StationSyncPortInterface::class));

        $view = $service->listStations()[0];

        self::assertSame($station, $service->findByPublicId($station->getPublicId()));
        self::assertSame($station->getPublicId()->toString(), $view['publicId']);
        self::assertSame($source->getPublicId()->toString(), $view['sourceId']);
        self::assertSame($externalId, $view['externalId']);
        self::assertArrayNotHasKey('id', $view);
        self::assertStringNotContainsString($station->getId()->toString(), json_encode($view, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString($source->getId()->toString(), json_encode($view, JSON_THROW_ON_ERROR));
    }
}
