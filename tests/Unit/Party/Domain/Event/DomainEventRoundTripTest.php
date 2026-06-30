<?php

declare(strict_types=1);

namespace App\Tests\Unit\Party\Domain\Event;

use App\Party\Domain\Event\MemberJoined;
use App\Party\Domain\Event\PartySessionCreated;
use App\Party\Domain\Event\PartySessionEnded;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class DomainEventRoundTripTest extends TestCase
{
    public function testPartySessionCreatedRoundTrip(): void
    {
        $occurredAt = new DateTimeImmutable('2026-04-21T12:00:00+00:00');
        $sessionId = Uuid::v4();
        $hostUserId = Uuid::v4();
        $videoId = Uuid::v4();

        $original = new PartySessionCreated(
            sessionId: $sessionId,
            hostUserId: $hostUserId,
            videoId: $videoId,
            maxMembers: 15,
            occurredAt: $occurredAt,
        );

        $payload = $original->toPayload();
        $restored = PartySessionCreated::fromPayload($payload);

        $this->assertSame('party.session_created', $original->eventName());
        $this->assertSame($original->eventName(), $restored->eventName());
        $this->assertTrue($original->getSessionId()->equals($restored->getSessionId()));
        $this->assertTrue($original->getHostUserId()->equals($restored->getHostUserId()));
        $this->assertTrue($original->getVideoId()->equals($restored->getVideoId()));
        $this->assertSame(15, $restored->getMaxMembers());
        $this->assertSame($occurredAt->format(DateTimeImmutable::ATOM), $restored->occurredAt()->format(DateTimeImmutable::ATOM));
    }

    public function testPartySessionEndedRoundTrip(): void
    {
        $occurredAt = new DateTimeImmutable('2026-04-21T14:30:00+00:00');
        $sessionId = Uuid::v4();
        $hostUserId = Uuid::v4();

        $original = new PartySessionEnded(
            sessionId: $sessionId,
            hostUserId: $hostUserId,
            occurredAt: $occurredAt,
        );

        $payload = $original->toPayload();
        $restored = PartySessionEnded::fromPayload($payload);

        $this->assertSame('party.session_ended', $original->eventName());
        $this->assertSame($original->eventName(), $restored->eventName());
        $this->assertTrue($original->getSessionId()->equals($restored->getSessionId()));
        $this->assertTrue($original->getHostUserId()->equals($restored->getHostUserId()));
        $this->assertSame($occurredAt->format(DateTimeImmutable::ATOM), $restored->occurredAt()->format(DateTimeImmutable::ATOM));
    }

    public function testMemberJoinedRoundTrip(): void
    {
        $occurredAt = new DateTimeImmutable('2026-04-21T15:00:00+00:00');
        $sessionId = Uuid::v4();
        $userId = Uuid::v4();

        $original = new MemberJoined(
            sessionId: $sessionId,
            userId: $userId,
            role: 'host',
            occurredAt: $occurredAt,
        );

        $payload = $original->toPayload();
        $restored = MemberJoined::fromPayload($payload);

        $this->assertSame('party.member_joined', $original->eventName());
        $this->assertSame($original->eventName(), $restored->eventName());
        $this->assertTrue($original->getSessionId()->equals($restored->getSessionId()));
        $this->assertTrue($original->getUserId()->equals($restored->getUserId()));
        $this->assertSame('host', $restored->getRole());
        $this->assertSame($occurredAt->format(DateTimeImmutable::ATOM), $restored->occurredAt()->format(DateTimeImmutable::ATOM));
    }

    public function testPartySessionCreatedPayloadHasExpectedKeys(): void
    {
        $event = new PartySessionCreated(
            sessionId: Uuid::v4(),
            hostUserId: Uuid::v4(),
            videoId: Uuid::v4(),
            maxMembers: 10,
        );

        $payload = $event->toPayload();

        $this->assertArrayHasKey('session_id', $payload);
        $this->assertArrayHasKey('host_user_id', $payload);
        $this->assertArrayHasKey('video_id', $payload);
        $this->assertArrayHasKey('max_members', $payload);
        $this->assertArrayHasKey('occurred_at', $payload);
    }

    public function testMemberJoinedPayloadHasExpectedKeys(): void
    {
        $event = new MemberJoined(
            sessionId: Uuid::v4(),
            userId: Uuid::v4(),
            role: 'member',
        );

        $payload = $event->toPayload();

        $this->assertArrayHasKey('session_id', $payload);
        $this->assertArrayHasKey('user_id', $payload);
        $this->assertArrayHasKey('role', $payload);
        $this->assertArrayHasKey('occurred_at', $payload);
    }
}
