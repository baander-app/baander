<?php

declare(strict_types=1);

namespace App\Tests\Unit\Radio\Domain\Model\RadioStation;

use App\Radio\Domain\Model\RadioStation\RadioStation;
use App\Radio\Domain\Model\RadioStation\RadioStationState;
use App\Radio\Domain\Model\RadioStation\Stream;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RadioStationTest extends TestCase
{
    private Uuid $sourceId;

    protected function setUp(): void
    {
        $this->sourceId = Uuid::v7();
    }

    public function testCreateWithMultipleStreams(): void
    {
        $streams = [
            new Stream(url: 'https://stream.example.com/high', format: 'aac', bitrate: 320, reliability: 0.99),
            new Stream(url: 'https://stream.example.com/low', format: 'mp3', bitrate: 128, reliability: 0.95),
        ];

        $station = RadioStation::create(
            sourceId: $this->sourceId,
            externalId: 'station-42',
            name: 'Radio Example FM',
            country: 'DE',
            streams: $streams,
        );

        $this->assertInstanceOf(Uuid::class, $station->getId());
        $this->assertTrue($station->getSourceId()->equals($this->sourceId));
        $this->assertSame('station-42', $station->getExternalId());
        $this->assertSame('Radio Example FM', $station->getName());
        $this->assertSame('DE', $station->getCountry());
        $this->assertCount(2, $station->getStreams());
        $this->assertSame('https://stream.example.com/high', $station->getStreams()[0]->url);
        $this->assertSame(320, $station->getStreams()[0]->bitrate);
        $this->assertNull($station->getLanguage());
        $this->assertSame([], $station->getGenres());
        $this->assertSame([], $station->getTags());
        $this->assertNull($station->getLogo());
        $this->assertNull($station->getWebsite());
        $this->assertNull($station->getLastCheckedAt());
    }

    public function testCreateThrowsOnEmptyName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Radio station name cannot be empty.');

        RadioStation::create(
            sourceId: $this->sourceId,
            externalId: 'x',
            name: '',
            country: 'DE',
            streams: [],
        );
    }

    public function testCreateThrowsOnEmptyCountry(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Radio station country cannot be empty.');

        RadioStation::create(
            sourceId: $this->sourceId,
            externalId: 'x',
            name: 'Station',
            country: '',
            streams: [],
        );
    }

    public function testReconstituteRoundtrip(): void
    {
        $id = Uuid::v7();
        $createdAt = new DateTimeImmutable('2025-01-01 00:00:00');
        $updatedAt = new DateTimeImmutable('2025-06-01 12:00:00');
        $lastCheckedAt = new DateTimeImmutable('2025-06-01 11:00:00');

        $streams = [
            new Stream('https://stream.example.com', 'mp3', 128, 0.9),
        ];

        $state = new RadioStationState(
            id: $id,
            sourceId: $this->sourceId,
            externalId: 'ext-1',
            name: 'Test FM',
            country: 'US',
            language: 'English',
            genres: ['rock', 'pop'],
            tags: ['news'],
            streams: $streams,
            logo: 'https://example.com/logo.png',
            website: 'https://example.com',
            lastCheckedAt: $lastCheckedAt,
            createdAt: $createdAt,
            updatedAt: $updatedAt,
        );

        $station = RadioStation::reconstitute($state);

        $this->assertTrue($station->getId()->equals($id));
        $this->assertTrue($station->getSourceId()->equals($this->sourceId));
        $this->assertSame('ext-1', $station->getExternalId());
        $this->assertSame('Test FM', $station->getName());
        $this->assertSame('US', $station->getCountry());
        $this->assertSame('English', $station->getLanguage());
        $this->assertSame(['rock', 'pop'], $station->getGenres());
        $this->assertSame(['news'], $station->getTags());
        $this->assertCount(1, $station->getStreams());
        $this->assertSame('https://example.com/logo.png', $station->getLogo());
        $this->assertSame('https://example.com', $station->getWebsite());
        $this->assertEquals($lastCheckedAt, $station->getLastCheckedAt());
    }

    public function testStreamValueObjectStoresUrlFormatBitrateReliability(): void
    {
        $stream = new Stream(
            url: 'https://stream.example.com/high',
            format: 'aac',
            bitrate: 320,
            reliability: 0.99,
        );

        $this->assertSame('https://stream.example.com/high', $stream->url);
        $this->assertSame('aac', $stream->format);
        $this->assertSame(320, $stream->bitrate);
        $this->assertSame(0.99, $stream->reliability);
    }

    public function testUpdateStationDetails(): void
    {
        $station = RadioStation::create(
            sourceId: $this->sourceId,
            externalId: 'ext-1',
            name: 'Test FM',
            country: 'US',
            streams: [],
        );

        $newStreams = [new Stream('https://new.url', 'ogg', 192, 0.8)];
        $station->updateDetails(
            name: 'Updated FM',
            streams: $newStreams,
            genres: ['jazz'],
            lastCheckedAt: new DateTimeImmutable(),
        );

        $this->assertSame('Updated FM', $station->getName());
        $this->assertSame(['jazz'], $station->getGenres());
        $this->assertCount(1, $station->getStreams());
        $this->assertNotNull($station->getLastCheckedAt());
    }
}
