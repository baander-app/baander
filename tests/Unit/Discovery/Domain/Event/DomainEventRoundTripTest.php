<?php

declare(strict_types=1);

namespace App\Tests\Unit\Discovery\Domain\Event;

use App\Discovery\Domain\Event\PairingCompleted;
use App\Discovery\Domain\Event\ServerRegistered;
use App\Discovery\Domain\ValueObject\AuthenticationMethod;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class DomainEventRoundTripTest extends TestCase
{
    public function testServerRegisteredRoundTrip(): void
    {
        $occurredAt = new DateTimeImmutable('2026-06-13T09:00:00+00:00');
        $serverId = Uuid::v4();
        $serverPublicId = new PublicId();

        $original = new ServerRegistered(
            serverId: $serverId,
            serverPublicId: $serverPublicId,
            serverUrl: 'https://music.example.com',
            name: 'Home Server',
            occurredAt: $occurredAt,
        );

        $restored = ServerRegistered::fromPayload($original->toPayload());

        $this->assertSame('discovery.server_registered', $original->eventName());
        $this->assertSame($original->eventName(), $restored->eventName());
        $this->assertTrue($original->getServerId()->equals($restored->getServerId()));
        $this->assertTrue($original->getServerPublicId()->equals($restored->getServerPublicId()));
        $this->assertSame('https://music.example.com', $restored->getServerUrl());
        $this->assertSame('Home Server', $restored->getName());
        $this->assertSame(
            $occurredAt->format(DateTimeImmutable::ATOM),
            $restored->occurredAt()->format(DateTimeImmutable::ATOM),
        );
    }

    public function testPairingCompletedRoundTrip(): void
    {
        $occurredAt = new DateTimeImmutable('2026-06-13T09:30:00+00:00');
        $pairingId = Uuid::v4();
        $pairingPublicId = new PublicId();
        $serverId = Uuid::v4();

        $original = new PairingCompleted(
            pairingId: $pairingId,
            pairingPublicId: $pairingPublicId,
            serverId: $serverId,
            method: AuthenticationMethod::QrCode,
            occurredAt: $occurredAt,
        );

        $restored = PairingCompleted::fromPayload($original->toPayload());

        $this->assertSame('discovery.pairing_completed', $original->eventName());
        $this->assertSame($original->eventName(), $restored->eventName());
        $this->assertTrue($original->getPairingId()->equals($restored->getPairingId()));
        $this->assertTrue($original->getPairingPublicId()->equals($restored->getPairingPublicId()));
        $this->assertTrue($original->getServerId()->equals($restored->getServerId()));
        $this->assertSame(AuthenticationMethod::QrCode, $restored->getMethod());
        $this->assertSame(
            $occurredAt->format(DateTimeImmutable::ATOM),
            $restored->occurredAt()->format(DateTimeImmutable::ATOM),
        );
    }
}
