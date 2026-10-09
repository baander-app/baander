<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Interface\WebSocket;

use App\Shared\Interface\WebSocket\WebSocketRoomPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WebSocketRoomPolicyTest extends TestCase
{
    public function testAPartyRoomIsNamedAfterItsSession(): void
    {
        self::assertSame('party:01900000-0000-7000-8000-000000000002', WebSocketRoomPolicy::partyRoom('01900000-0000-7000-8000-000000000002'));
    }

    public function testAPartyRoomIsJoinedOnlyThroughPartyJoin(): void
    {
        self::assertSame(
            'Party rooms are joined with party.join',
            WebSocketRoomPolicy::clientJoinRefusal(WebSocketRoomPolicy::partyRoom('01900000-0000-7000-8000-000000000002')),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function roomsNoClientMayJoin(): iterable
    {
        yield 'a user room' => ['user:01900000-0000-7000-8000-000000000001'];
        yield 'an admin room' => ['admin:jobs'];
        yield 'a listening session room' => ['session:01900000-0000-7000-8000-000000000001'];
        yield 'an arbitrary name' => ['notifications:user-1'];
        yield 'a prefix of the party room' => ['party'];
    }

    #[DataProvider('roomsNoClientMayJoin')]
    public function testEveryOtherRoomIsUnknown(string $room): void
    {
        self::assertSame('Unknown room', WebSocketRoomPolicy::clientJoinRefusal($room));
    }
}
