<?php

declare(strict_types=1);

namespace App\Tests\Unit\Radio\Domain\Event;

use App\Radio\Domain\Event\CountrySubscribed;
use App\Radio\Domain\Event\CountryUnsubscribed;
use App\Radio\Domain\Event\RadioSessionStarted;
use App\Radio\Domain\Event\RadioSessionStopped;
use App\Radio\Domain\Event\StationStarred;
use App\Radio\Domain\Event\StationUnstarred;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;

final class RadioEventsTest extends TestCase
{
    public function testRadioSessionStartedConstructsWithCorrectPayload(): void
    {
        $userId = Uuid::v7();
        $stationId = Uuid::v7();
        $streamUrl = 'https://stream.example.com/high';

        $event = new RadioSessionStarted($userId, $stationId, $streamUrl);

        $this->assertSame('radio.session_started', $event->eventName());
        $this->assertTrue($event->getUserId()->equals($userId));
        $this->assertTrue($event->getStationId()->equals($stationId));
        $this->assertSame($streamUrl, $event->getStreamUrl());

        $payload = $event->toPayload();
        $this->assertSame($userId->toString(), $payload['user_id']);
        $this->assertSame($stationId->toString(), $payload['station_id']);
        $this->assertSame($streamUrl, $payload['stream_url']);
    }

    public function testRadioSessionStartedRoundtrip(): void
    {
        $userId = Uuid::v7();
        $stationId = Uuid::v7();

        $original = new RadioSessionStarted($userId, $stationId, 'https://stream.example.com');
        $payload = $original->toPayload();

        $restored = RadioSessionStarted::fromPayload($payload);

        $this->assertTrue($restored->getUserId()->equals($userId));
        $this->assertTrue($restored->getStationId()->equals($stationId));
        $this->assertSame('https://stream.example.com', $restored->getStreamUrl());
    }

    public function testRadioSessionStoppedConstructsWithCorrectPayload(): void
    {
        $userId = Uuid::v7();

        $event = new RadioSessionStopped($userId);

        $this->assertSame('radio.session_stopped', $event->eventName());
        $this->assertTrue($event->getUserId()->equals($userId));
    }

    public function testRadioSessionStoppedRoundtrip(): void
    {
        $userId = Uuid::v7();

        $original = new RadioSessionStopped($userId);
        $payload = $original->toPayload();

        $restored = RadioSessionStopped::fromPayload($payload);

        $this->assertTrue($restored->getUserId()->equals($userId));
    }

    public function testCountrySubscribedConstructsWithCorrectPayload(): void
    {
        $userId = Uuid::v7();
        $sourceId = Uuid::v7();
        $countryCode = 'DE';

        $event = new CountrySubscribed($userId, $sourceId, $countryCode);

        $this->assertSame('radio.country_subscribed', $event->eventName());
        $this->assertTrue($event->getUserId()->equals($userId));
        $this->assertTrue($event->getSourceId()->equals($sourceId));
        $this->assertSame('DE', $event->getCountryCode());
    }

    public function testCountrySubscribedRoundtrip(): void
    {
        $userId = Uuid::v7();
        $sourceId = Uuid::v7();

        $original = new CountrySubscribed($userId, $sourceId, 'US');
        $payload = $original->toPayload();
        $restored = CountrySubscribed::fromPayload($payload);

        $this->assertTrue($restored->getUserId()->equals($userId));
        $this->assertTrue($restored->getSourceId()->equals($sourceId));
        $this->assertSame('US', $restored->getCountryCode());
    }

    public function testCountryUnsubscribedConstructsWithCorrectPayload(): void
    {
        $userId = Uuid::v7();
        $sourceId = Uuid::v7();

        $event = new CountryUnsubscribed($userId, $sourceId, 'FR');

        $this->assertSame('radio.country_unsubscribed', $event->eventName());
        $this->assertSame('FR', $event->getCountryCode());
    }

    public function testStationStarredConstructsWithCorrectPayload(): void
    {
        $userId = Uuid::v7();
        $stationId = Uuid::v7();

        $event = new StationStarred($userId, $stationId);

        $this->assertSame('radio.station_starred', $event->eventName());
        $this->assertTrue($event->getUserId()->equals($userId));
        $this->assertTrue($event->getStationId()->equals($stationId));
    }

    public function testStationStarredRoundtrip(): void
    {
        $userId = Uuid::v7();
        $stationId = Uuid::v7();

        $original = new StationStarred($userId, $stationId);
        $payload = $original->toPayload();
        $restored = StationStarred::fromPayload($payload);

        $this->assertTrue($restored->getUserId()->equals($userId));
        $this->assertTrue($restored->getStationId()->equals($stationId));
    }

    public function testStationUnstarredConstructsWithCorrectPayload(): void
    {
        $userId = Uuid::v7();
        $stationId = Uuid::v7();

        $event = new StationUnstarred($userId, $stationId);

        $this->assertSame('radio.station_unstarred', $event->eventName());
        $this->assertTrue($event->getUserId()->equals($userId));
        $this->assertTrue($event->getStationId()->equals($stationId));
    }
}
