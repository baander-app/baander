<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Swoole;

use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Swoole\Control\ServerWorkers;
use App\Shared\Infrastructure\Swoole\SwooleLivePartyRooms;
use App\Shared\Infrastructure\Swoole\WebSocketConnectionRegistry;
use App\Shared\Infrastructure\Swoole\WebSocketPusher;
use App\Shared\Infrastructure\Swoole\WebSocketRooms;
use PHPUnit\Framework\TestCase;
use Swoole\WebSocket\Server;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final class SwooleLivePartyRoomsTest extends TestCase
{
    private const string SESSION = '01900000-0000-7000-8000-00000000000a';
    private const string OTHER_SESSION = '01900000-0000-7000-8000-00000000000b';
    private const string LEAVER = '01900000-0000-7000-8000-000000000001';
    private const string MEMBER = '01900000-0000-7000-8000-000000000002';

    private WebSocketConnectionRegistry $registry;
    private WebSocketPusher $pusher;

    /** @var list<array{fd: int, payload: mixed}> */
    private array $pushed = [];

    protected function setUp(): void
    {
        if (!\extension_loaded('swoole')) {
            self::markTestSkipped('Swoole extension is not loaded.');
        }

        $this->registry = WebSocketConnectionRegistry::create(16, 64);
        $this->pushed = [];
        $server = $this->createStub(Server::class);
        $server->method('isEstablished')->willReturn(true);
        $server->method('push')->willReturnCallback(function (int $fd, string $data): bool {
            $this->pushed[] = ['fd' => $fd, 'payload' => json_decode($data, true)];

            return true;
        });
        $this->pusher = new WebSocketPusher($this->registry, new JsonEncoder());
        $this->pusher->setServer($server);

        // The leaver has two sockets in the party and one outside it; another member
        // has one; a socket in another party must not be touched.
        $this->registry->addConnection(1, self::LEAVER, 0);
        $this->registry->addConnection(2, self::LEAVER, 1);
        $this->registry->addConnection(3, self::MEMBER, 0);
        $this->registry->addConnection(4, self::LEAVER, 0);
        $this->registry->addConnection(5, self::MEMBER, 1);
        // party.join builds the room from the client's string, which may be upper case.
        $this->registry->joinRoom(WebSocketRooms::party(strtoupper(self::SESSION)), 1);
        $this->registry->joinRoom(WebSocketRooms::party(self::SESSION), 2);
        $this->registry->joinRoom(WebSocketRooms::party(self::SESSION), 3);
        $this->registry->joinRoom(WebSocketRooms::party(self::OTHER_SESSION), 5);
    }

    public function testRemovesEverySocketOfTheLeaverAndTellsTheRemainingMembersOnce(): void
    {
        $this->rooms(inHttpWorker: true)->removeMember(Uuid::fromString(self::SESSION), Uuid::fromString(self::LEAVER));

        self::assertSame([3], $this->registry->getRoomMembers(WebSocketRooms::party(self::SESSION)));
        self::assertSame([5], $this->registry->getRoomMembers(WebSocketRooms::party(self::OTHER_SESSION)));
        self::assertSame([[
            'fd' => 3,
            'payload' => [
                'type' => 'party.member_event',
                'sessionId' => self::SESSION,
                'action' => 'leave',
                'userId' => self::LEAVER,
            ],
        ]], $this->pushed);
    }

    public function testClosingEmptiesTheRoomOnly(): void
    {
        $this->rooms(inHttpWorker: true)->close(Uuid::fromString(self::SESSION));

        self::assertSame([], $this->registry->getRoomMembers(WebSocketRooms::party(self::SESSION)));
        self::assertSame([5], $this->registry->getRoomMembers(WebSocketRooms::party(self::OTHER_SESSION)));
        self::assertSame([], $this->pushed);
    }

    public function testDoesNothingOutsideAnHttpWorkerOfTheRunningServer(): void
    {
        $rooms = $this->rooms(inHttpWorker: false);

        $rooms->removeMember(Uuid::fromString(self::SESSION), Uuid::fromString(self::LEAVER));
        $rooms->close(Uuid::fromString(self::SESSION));

        $members = $this->registry->getRoomMembers(WebSocketRooms::party(self::SESSION));
        sort($members);
        self::assertSame([1, 2, 3], $members);
        self::assertSame([], $this->pushed);
    }

    private function rooms(bool $inHttpWorker): SwooleLivePartyRooms
    {
        $workers = $this->createStub(ServerWorkers::class);
        $workers->method('currentHttpWorkerId')->willReturn($inHttpWorker ? 0 : null);

        return new SwooleLivePartyRooms($workers, $this->registry, $this->pusher);
    }
}
