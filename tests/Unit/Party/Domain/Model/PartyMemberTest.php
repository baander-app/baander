<?php

declare(strict_types=1);

namespace App\Tests\Unit\Party\Domain\Model;

use App\Party\Domain\Model\PartyMember;
use App\Party\Domain\Model\PartyMemberState;
use App\Party\Domain\ValueObject\MemberRole;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;

final class PartyMemberTest extends TestCase
{
    public function testCreateWithDefaultMemberRole(): void
    {
        $member = PartyMember::create(
            Uuid::v4(),
            Uuid::v4(),
        );

        $this->assertInstanceOf(Uuid::class, $member->getId());
        $this->assertInstanceOf(PublicId::class, $member->getPublicId());
        $this->assertSame(MemberRole::Member, $member->getRole());
        $this->assertSame(0.0, $member->getLastSyncPosition());
        $this->assertNull($member->getAudioProfileId());
        $this->assertNull($member->getSubtitleTrackId());
        $this->assertNull($member->getLastSyncAt());
        $this->assertSame(0.0, $member->getJitterCompensation());
        $this->assertTrue($member->isConnected());
    }

    public function testCreateWithHostRole(): void
    {
        $member = PartyMember::create(
            Uuid::v4(),
            Uuid::v4(),
            MemberRole::Host,
        );

        $this->assertSame(MemberRole::Host, $member->getRole());
    }

    public function testPromoteToHost(): void
    {
        $member = PartyMember::create(
            Uuid::v4(),
            Uuid::v4(),
        );

        $member->promoteToHost();

        $this->assertSame(MemberRole::Host, $member->getRole());
    }

    public function testPromoteToHostOnAlreadyHostIsIdempotent(): void
    {
        $member = PartyMember::create(
            Uuid::v4(),
            Uuid::v4(),
            MemberRole::Host,
        );

        $before = $member->getUpdatedAt();
        $member->promoteToHost();

        $this->assertSame(MemberRole::Host, $member->getRole());
        $this->assertSame($before, $member->getUpdatedAt(), 'updatedAt should not change on idempotent promote.');
    }

    public function testDemoteToMember(): void
    {
        $member = PartyMember::create(
            Uuid::v4(),
            Uuid::v4(),
            MemberRole::Host,
        );

        $member->demoteToMember();

        $this->assertSame(MemberRole::Member, $member->getRole());
    }

    public function testDemoteToMemberOnAlreadyMemberIsIdempotent(): void
    {
        $member = PartyMember::create(
            Uuid::v4(),
            Uuid::v4(),
        );

        $before = $member->getUpdatedAt();
        $member->demoteToMember();

        $this->assertSame(MemberRole::Member, $member->getRole());
        $this->assertSame($before, $member->getUpdatedAt(), 'updatedAt should not change on idempotent demote.');
    }

    public function testUpdateSyncPosition(): void
    {
        $member = PartyMember::create(
            Uuid::v4(),
            Uuid::v4(),
        );

        $member->updateSyncPosition(42.5, 0.3);

        $this->assertSame(42.5, $member->getLastSyncPosition());
        $this->assertSame(0.3, $member->getJitterCompensation());
        $this->assertNotNull($member->getLastSyncAt());
    }

    public function testSetAudioProfile(): void
    {
        $member = PartyMember::create(
            Uuid::v4(),
            Uuid::v4(),
        );

        $member->setAudioProfile('stereo-320k');

        $this->assertSame('stereo-320k', $member->getAudioProfileId());
    }

    public function testSetAudioProfileNullClears(): void
    {
        $member = PartyMember::create(
            Uuid::v4(),
            Uuid::v4(),
        );

        $member->setAudioProfile('stereo-320k');
        $member->setAudioProfile(null);

        $this->assertNull($member->getAudioProfileId());
    }

    public function testSetSubtitleTrack(): void
    {
        $member = PartyMember::create(
            Uuid::v4(),
            Uuid::v4(),
        );

        $member->setSubtitleTrack('en');

        $this->assertSame('en', $member->getSubtitleTrackId());
    }

    public function testSetSubtitleTrackNullClears(): void
    {
        $member = PartyMember::create(
            Uuid::v4(),
            Uuid::v4(),
        );

        $member->setSubtitleTrack('en');
        $member->setSubtitleTrack(null);

        $this->assertNull($member->getSubtitleTrackId());
    }

    public function testDisconnect(): void
    {
        $member = PartyMember::create(
            Uuid::v4(),
            Uuid::v4(),
        );

        $member->disconnect();

        $this->assertFalse($member->isConnected());
    }

    public function testReconnect(): void
    {
        $member = PartyMember::create(
            Uuid::v4(),
            Uuid::v4(),
        );

        $member->disconnect();
        $member->reconnect();

        $this->assertTrue($member->isConnected());
    }

    public function testReconstituteRestoresAllFields(): void
    {
        $now = new \DateTimeImmutable();
        $userId = Uuid::v4();
        $sessionId = Uuid::v4();
        $syncAt = new \DateTimeImmutable('-5 seconds');

        $state = new PartyMemberState(
            id: Uuid::v4(),
            publicId: new PublicId(),
            userId: $userId,
            sessionId: $sessionId,
            joinedAt: $now,
            role: MemberRole::Host,
            audioProfileId: 'surround-5.1',
            subtitleTrackId: 'fr',
            lastSyncPosition: 120.5,
            lastSyncAt: $syncAt,
            jitterCompensation: 0.15,
            isConnected: false,
            updatedAt: $now,
        );

        $member = PartyMember::reconstitute($state);

        $this->assertTrue($member->getUserId()->equals($userId));
        $this->assertTrue($member->getSessionId()->equals($sessionId));
        $this->assertSame(MemberRole::Host, $member->getRole());
        $this->assertSame('surround-5.1', $member->getAudioProfileId());
        $this->assertSame('fr', $member->getSubtitleTrackId());
        $this->assertSame(120.5, $member->getLastSyncPosition());
        $this->assertSame(0.15, $member->getJitterCompensation());
        $this->assertFalse($member->isConnected());
    }

    public function testGettersReturnExpectedTypes(): void
    {
        $member = PartyMember::create(
            Uuid::v4(),
            Uuid::v4(),
        );

        $this->assertInstanceOf(Uuid::class, $member->getId());
        $this->assertInstanceOf(PublicId::class, $member->getPublicId());
        $this->assertInstanceOf(Uuid::class, $member->getUserId());
        $this->assertInstanceOf(Uuid::class, $member->getSessionId());
        $this->assertInstanceOf(\DateTimeImmutable::class, $member->getJoinedAt());
        $this->assertInstanceOf(\DateTimeImmutable::class, $member->getUpdatedAt());
    }
}
