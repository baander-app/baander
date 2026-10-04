<?php

declare(strict_types=1);

namespace Tests\Unit\Party\Infrastructure;

use App\Party\Application\Port\PartyMemberPortInterface;
use App\Party\Application\Port\PartySessionPortInterface;
use App\Party\Domain\Model\PartyMember;
use App\Party\Domain\Model\PartyMemberState;
use App\Party\Domain\ValueObject\MemberRole;
use App\Party\Infrastructure\PlaybackSynchronizer;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class PlaybackSynchronizerTest extends TestCase
{
    private PartySessionPortInterface&Stub $sessionPort;
    private PartyMemberPortInterface&Stub $memberPort;
    private PlaybackSynchronizer $synchronizer;

    protected function setUp(): void
    {
        $this->sessionPort = $this->createStub(PartySessionPortInterface::class);
        $this->memberPort = $this->createStub(PartyMemberPortInterface::class);
        $this->synchronizer = $this->createPlaybackSynchronizerFixture();
    }

    private function createPlaybackSynchronizerFixture(): PlaybackSynchronizer
    {
        $fixture = new PlaybackSynchronizer($this->sessionPort, $this->memberPort);
        return $fixture;
    }

    public function testSynchronizeReturnsServerPositionWithSmallDrift(): void
    {
        $this->memberPort = $this->createMock(PartyMemberPortInterface::class);
        $this->sessionPort = $this->createMock(PartySessionPortInterface::class);
        $this->synchronizer = $this->createPlaybackSynchronizerFixture();

        $sessionId = Uuid::v4();
        $userId = Uuid::v4();
        $serverPosition = 100.5;
        $clientPosition = 101.0; // 0.5s drift -- within tolerance
        $clientLatency = 0.2;

        $this->sessionPort
            ->expects($this->once())
            ->method('syncPlayback')
            ->with($sessionId, $clientPosition, $clientLatency)
            ->willReturn($serverPosition);

        $member = $this->createMemberWithJitter($userId, $sessionId, 0.0);
        $this->memberPort
            ->expects($this->once())
            ->method('findByUserAndSession')
            ->with($userId, $sessionId)
            ->willReturn($member);

        $this->memberPort
            ->expects($this->once())
            ->method('save')
            ->with($member);

        $result = $this->synchronizer->synchronize($sessionId, $userId, $clientPosition, $clientLatency);

        $this->assertSame($serverPosition, $result);
    }

    public function testJitterCompensationUsesExponentialMovingAverage(): void
    {
        $this->memberPort = $this->createMock(PartyMemberPortInterface::class);
        $this->synchronizer = $this->createPlaybackSynchronizerFixture();

        $sessionId = Uuid::v4();
        $userId = Uuid::v4();
        $serverPosition = 100.0;
        $clientPosition = 98.0; // 2.0s drift
        $clientLatency = 0.1;

        $this->sessionPort
            ->method('syncPlayback')
            ->willReturn($serverPosition);

        // Member has existing jitter compensation of 1.0
        $existingJitter = 1.0;
        $member = $this->createMemberWithJitter($userId, $sessionId, $existingJitter);
        $this->memberPort
            ->method('findByUserAndSession')
            ->willReturn($member);

        // Capture the arguments passed to updateSyncPosition via save
        $this->memberPort
            ->expects($this->once())
            ->method('save')
            ->with($this->callback(function (PartyMember $savedMember) use ($serverPosition): bool {
                // EMA: alpha=0.3, jitter=min(2.0, 2.0)=2.0, current=1.0
                // smoothed = 0.3 * 2.0 + 0.7 * 1.0 = 0.6 + 0.7 = 1.3
                $expectedJitter = 0.3 * 2.0 + 0.7 * 1.0;
                $this->assertSame($serverPosition, $savedMember->getLastSyncPosition());
                $this->assertEqualsWithDelta($expectedJitter, $savedMember->getJitterCompensation(), 0.0001);

                return true;
            }));

        $this->synchronizer->synchronize($sessionId, $userId, $clientPosition, $clientLatency);
    }

    public function testJitterCompensationInitializesFromDriftWhenZero(): void
    {
        $this->memberPort = $this->createMock(PartyMemberPortInterface::class);
        $this->synchronizer = $this->createPlaybackSynchronizerFixture();

        $sessionId = Uuid::v4();
        $userId = Uuid::v4();
        $serverPosition = 50.0;
        $clientPosition = 49.0; // 1.0s drift
        $clientLatency = 0.1;

        $this->sessionPort
            ->method('syncPlayback')
            ->willReturn($serverPosition);

        // Member has zero jitter -- should use raw drift, not EMA
        $member = $this->createMemberWithJitter($userId, $sessionId, 0.0);
        $this->memberPort
            ->method('findByUserAndSession')
            ->willReturn($member);

        $this->memberPort
            ->expects($this->once())
            ->method('save')
            ->with($this->callback(function (PartyMember $savedMember) use ($serverPosition): bool {
                // With currentJitter=0, the code takes the else branch: smoothed = jitter (raw)
                $this->assertSame($serverPosition, $savedMember->getLastSyncPosition());
                $this->assertEqualsWithDelta(1.0, $savedMember->getJitterCompensation(), 0.0001);

                return true;
            }));

        $this->synchronizer->synchronize($sessionId, $userId, $clientPosition, $clientLatency);
    }

    public function testMissingMembershipIsRejectedBeforeSessionAccess(): void
    {
        $this->memberPort = $this->createMock(PartyMemberPortInterface::class);
        $this->sessionPort = $this->createMock(PartySessionPortInterface::class);
        $this->synchronizer = $this->createPlaybackSynchronizerFixture();
        $sessionId = Uuid::v4();
        $userId = Uuid::v4();
        $this->sessionPort->expects($this->never())->method('syncPlayback');
        $this->memberPort->expects($this->once())
            ->method('findByUserAndSession')->with($userId, $sessionId)->willReturn(null);
        $this->memberPort->expects($this->never())->method('save');
        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('Party membership required.');
        $this->synchronizer->synchronize($sessionId, $userId, 75.0, 0.3);
    }

    public function testLargeDriftIsClampedToMaxJitter(): void
    {
        $this->memberPort = $this->createMock(PartyMemberPortInterface::class);
        $this->synchronizer = $this->createPlaybackSynchronizerFixture();

        $sessionId = Uuid::v4();
        $userId = Uuid::v4();
        $serverPosition = 100.0;
        $clientPosition = 50.0; // 50s drift -- way beyond MAX_JITTER (2.0)
        $clientLatency = 0.1;

        $this->sessionPort
            ->method('syncPlayback')
            ->willReturn($serverPosition);

        $member = $this->createMemberWithJitter($userId, $sessionId, 0.0);
        $this->memberPort
            ->method('findByUserAndSession')
            ->willReturn($member);

        $this->memberPort
            ->expects($this->once())
            ->method('save')
            ->with($this->callback(function (PartyMember $savedMember) use ($serverPosition): bool {
                // jitter = min(50.0, 2.0) = 2.0, currentJitter=0 so smoothed = 2.0
                $this->assertSame($serverPosition, $savedMember->getLastSyncPosition());
                $this->assertEqualsWithDelta(2.0, $savedMember->getJitterCompensation(), 0.0001);

                return true;
            }));

        $result = $this->synchronizer->synchronize($sessionId, $userId, $clientPosition, $clientLatency);

        $this->assertSame($serverPosition, $result);
    }

    public function testLargeDriftWithExistingJitterUsesEmaClamped(): void
    {
        $this->memberPort = $this->createMock(PartyMemberPortInterface::class);
        $this->synchronizer = $this->createPlaybackSynchronizerFixture();

        $sessionId = Uuid::v4();
        $userId = Uuid::v4();
        $serverPosition = 200.0;
        $clientPosition = 100.0; // 100s drift -- clamped to MAX_JITTER=2.0
        $clientLatency = 0.1;

        $this->sessionPort
            ->method('syncPlayback')
            ->willReturn($serverPosition);

        $existingJitter = 1.5;
        $member = $this->createMemberWithJitter($userId, $sessionId, $existingJitter);
        $this->memberPort
            ->method('findByUserAndSession')
            ->willReturn($member);

        $this->memberPort
            ->expects($this->once())
            ->method('save')
            ->with($this->callback(function (PartyMember $savedMember) use ($serverPosition, $existingJitter): bool {
                // jitter = min(100.0, 2.0) = 2.0
                // smoothed = 0.3 * 2.0 + 0.7 * 1.5 = 0.6 + 1.05 = 1.65
                $expectedJitter = 0.3 * 2.0 + 0.7 * $existingJitter;
                $this->assertSame($serverPosition, $savedMember->getLastSyncPosition());
                $this->assertEqualsWithDelta($expectedJitter, $savedMember->getJitterCompensation(), 0.0001);

                return true;
            }));

        $this->synchronizer->synchronize($sessionId, $userId, $clientPosition, $clientLatency);
    }

    private function createMemberWithJitter(Uuid $userId, Uuid $sessionId, float $jitterCompensation): PartyMember
    {
        $state = new PartyMemberState(
            id: Uuid::v4(),
            publicId: new PublicId(),
            userId: $userId,
            sessionId: $sessionId,
            joinedAt: new \DateTimeImmutable(),
            role: MemberRole::Member,
            audioProfileId: null,
            subtitleTrackId: null,
            lastSyncPosition: 0.0,
            lastSyncAt: null,
            jitterCompensation: $jitterCompensation,
            isConnected: true,
            updatedAt: new \DateTimeImmutable(),
        );

        return PartyMember::reconstitute($state);
    }
}
