<?php

declare(strict_types=1);

namespace App\Tests\Unit\Discovery\Domain\Model;

use App\Discovery\Domain\Model\PairingSession;
use App\Discovery\Domain\Model\PairingSessionState;
use App\Discovery\Domain\ValueObject\AuthenticationMethod;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use DateInterval;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PairingSessionTest extends TestCase
{
    private Uuid $serverId;
    private PublicId $serverPublicId;

    protected function setUp(): void
    {
        $this->serverId = Uuid::v4();
        $this->serverPublicId = new PublicId();
    }

    public function testCreateSetsIdentityAndDefaultTtl(): void
    {
        $before = new DateTimeImmutable('-1 second');
        $session = PairingSession::create(
            serverId: $this->serverId,
            serverPublicId: $this->serverPublicId,
            serverUrl: 'https://music.example.com',
            serverName: 'Home Server',
            method: AuthenticationMethod::QrCode,
        );

        $this->assertFalse($session->getId()->equals($this->serverId));
        $this->assertFalse($session->getPublicId()->equals($this->serverPublicId));
        $this->assertTrue($session->getServerId()->equals($this->serverId));
        $this->assertTrue($session->getServerPublicId()->equals($this->serverPublicId));
        $this->assertSame('https://music.example.com', $session->getServerUrl());
        $this->assertSame('Home Server', $session->getServerName());
        $this->assertSame(AuthenticationMethod::QrCode, $session->getMethod());

        // Default 5-minute TTL.
        $this->assertNotNull($session->getExpiresAt());
        $this->assertGreaterThan($before->modify('+4 minutes'), $session->getExpiresAt());
        $this->assertLessThan($before->modify('+6 minutes'), $session->getExpiresAt());
    }

    public function testCreateWithCustomTtl(): void
    {
        $before = new DateTimeImmutable('-1 second');
        $session = PairingSession::create(
            serverId: $this->serverId,
            serverPublicId: $this->serverPublicId,
            serverUrl: 'https://music.example.com',
            serverName: 'Home Server',
            method: AuthenticationMethod::QrCode,
            ttl: new DateInterval('PT60S'),
        );

        $this->assertNotNull($session->getExpiresAt());
        $this->assertGreaterThan($before->modify('+50 seconds'), $session->getExpiresAt());
        $this->assertLessThan($before->modify('+70 seconds'), $session->getExpiresAt());
    }

    public function testNewlyCreatedSessionIsPending(): void
    {
        $session = $this->createSession();

        $this->assertTrue($session->isPending());
        $this->assertFalse($session->isCompleted());
        $this->assertFalse($session->isExpired());
        $this->assertNull($session->getCompletedAt());
        $this->assertNull($session->getExpiredAt());
    }

    public function testCompleteTransitionsToCompleted(): void
    {
        $session = $this->createSession();
        $previousUpdate = $session->getUpdatedAt();

        $session->complete();

        $this->assertTrue($session->isCompleted());
        $this->assertFalse($session->isPending());
        $this->assertNotNull($session->getCompletedAt());
        $this->assertGreaterThanOrEqual($previousUpdate, $session->getUpdatedAt());
    }

    public function testCompleteIsIdempotent(): void
    {
        $session = $this->createSession();
        $session->complete();
        $firstCompletedAt = $session->getCompletedAt();

        $session->complete();

        $this->assertSame($firstCompletedAt, $session->getCompletedAt());
    }

    public function testCompleteExpiredSessionThrows(): void
    {
        $session = $this->createExpiredSession();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot complete an expired pairing session.');

        $session->complete();
    }

    public function testExpireTransitionsPendingSession(): void
    {
        $session = $this->createSession();

        $session->expire();

        $this->assertNotNull($session->getExpiredAt());
        $this->assertFalse($session->isPending());
    }

    public function testExpireIsNoOpOnCompletedSession(): void
    {
        $session = $this->createSession();
        $session->complete();

        $session->expire();

        $this->assertNull($session->getExpiredAt());
        $this->assertTrue($session->isCompleted());
    }

    public function testExpireIsIdempotent(): void
    {
        $session = $this->createSession();
        $session->expire();
        $firstExpiredAt = $session->getExpiredAt();

        $session->expire();

        $this->assertSame($firstExpiredAt, $session->getExpiredAt());
    }

    public function testGetQrPayloadContainsPublicIdAndCode(): void
    {
        $session = $this->createSession();

        $payload = $session->getQrPayload();

        $this->assertStringStartsWith('baander://pair?', $payload);
        $this->assertStringContainsString('server=' . $this->serverPublicId->toString(), $payload);
        $this->assertStringContainsString('code=' . $session->getPairingCode()->toString(), $payload);
    }

    public function testReconstitutePreservesState(): void
    {
        $state = new PairingSessionState(
            id: Uuid::v4(),
            publicId: new PublicId(),
            serverId: $this->serverId,
            serverPublicId: $this->serverPublicId,
            serverUrl: 'https://music.example.com',
            serverName: 'Home Server',
            pairingCode: \App\Discovery\Domain\ValueObject\PairingCode::fromString('BCDF-GHJK'),
            method: AuthenticationMethod::EmailUrl,
            expiresAt: new DateTimeImmutable('+2 minutes'),
            createdAt: new DateTimeImmutable('-5 minutes'),
        );

        $session = PairingSession::reconstitute($state);

        $this->assertTrue($session->getId()->equals($state->id));
        $this->assertSame($state, $session->getState());
        $this->assertSame(AuthenticationMethod::EmailUrl, $session->getMethod());
    }

    private function createSession(): PairingSession
    {
        return PairingSession::create(
            serverId: $this->serverId,
            serverPublicId: $this->serverPublicId,
            serverUrl: 'https://music.example.com',
            serverName: 'Home Server',
            method: AuthenticationMethod::QrCode,
        );
    }

    private function createExpiredSession(): PairingSession
    {
        return PairingSession::reconstitute(new PairingSessionState(
            id: Uuid::v4(),
            publicId: new PublicId(),
            serverId: $this->serverId,
            serverPublicId: $this->serverPublicId,
            serverUrl: 'https://music.example.com',
            serverName: 'Home Server',
            pairingCode: \App\Discovery\Domain\ValueObject\PairingCode::fromString('BCDF-GHJK'),
            method: AuthenticationMethod::QrCode,
            expiresAt: new DateTimeImmutable('-1 minute'),
            createdAt: new DateTimeImmutable('-10 minutes'),
        ));
    }
}
