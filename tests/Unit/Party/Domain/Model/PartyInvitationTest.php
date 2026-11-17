<?php

declare(strict_types=1);

namespace App\Tests\Unit\Party\Domain\Model;

use App\Party\Domain\Model\PartyInvitation;
use App\Party\Domain\Model\PartyInvitationState;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class PartyInvitationTest extends TestCase
{
    public function testANewInvitationCarriesItsSessionUserAndCreationTime(): void
    {
        $user = Uuid::generate();
        $session = Uuid::generate();
        $before = new DateTimeImmutable();

        $invitation = PartyInvitation::issue($user, $session);

        $after = new DateTimeImmutable();
        self::assertTrue($invitation->getUserId()->equals($user));
        self::assertTrue($invitation->getSessionId()->equals($session));
        self::assertGreaterThanOrEqual($before, $invitation->getCreatedAt());
        self::assertLessThanOrEqual($after, $invitation->getCreatedAt());
    }

    public function testEachNewInvitationHasItsOwnIdentity(): void
    {
        $user = Uuid::generate();
        $session = Uuid::generate();

        self::assertFalse(
            PartyInvitation::issue($user, $session)->getId()->equals(PartyInvitation::issue($user, $session)->getId()),
        );
    }

    public function testAnInvitationReconstitutesToTheSameState(): void
    {
        $issued = PartyInvitation::issue(Uuid::generate(), Uuid::generate());

        $restored = PartyInvitation::reconstitute($issued->getState());

        self::assertTrue($restored->getId()->equals($issued->getId()));
        self::assertTrue($restored->getUserId()->equals($issued->getUserId()));
        self::assertTrue($restored->getSessionId()->equals($issued->getSessionId()));
        self::assertSame($issued->getCreatedAt(), $restored->getCreatedAt());
    }

    public function testAReconstitutedInvitationKeepsTheStoredValues(): void
    {
        $state = new PartyInvitationState(
            id: Uuid::generate(),
            userId: Uuid::generate(),
            sessionId: Uuid::generate(),
            createdAt: new DateTimeImmutable('2026-11-17 12:29:00.123456+00:00'),
        );

        $invitation = PartyInvitation::reconstitute($state);

        self::assertSame($state->id, $invitation->getId());
        self::assertSame($state->userId, $invitation->getUserId());
        self::assertSame($state->sessionId, $invitation->getSessionId());
        self::assertSame('2026-11-17T12:29:00.123456+00:00', $invitation->getCreatedAt()->format('Y-m-d\TH:i:s.uP'));
    }
}
