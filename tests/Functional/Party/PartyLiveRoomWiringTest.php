<?php

declare(strict_types=1);

namespace App\Tests\Functional\Party;

use App\Auth\Domain\Model\User;
use App\Catalog\Infrastructure\Doctrine\Entity\MovieEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\MovieVideoEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\VideoEntity;
use App\Library\Application\Port\LibraryAccessPortInterface;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Party\Application\Command\StartPlaybackCommand;
use App\Party\Domain\Event\MemberLeft;
use App\Party\Domain\Event\PartySessionEnded;
use App\Party\Domain\Event\PlaybackStateChanged;
use App\Party\Infrastructure\EventListener\LivePartyRoomListener;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Swoole\Control\ServerWorkers;
use App\Shared\Infrastructure\Swoole\WebSocketConnectionRegistry;
use App\Shared\Infrastructure\Swoole\WebSocketPusher;
use App\Shared\Infrastructure\Swoole\WebSocketRooms;
use App\Shared\Interface\Controller\WebSocketController;
use App\Tests\Functional\TestCase;
use Swoole\WebSocket\Server;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Leaving, ending and the host's playback reach the live room listener, which does
 * nothing outside the web server's HTTP workers, so the routes and commands still
 * complete here. The revoke test and the socket tests put this process in the place of an
 * HTTP worker with open sockets, so the listener changes the room they fill and `party.join`
 * runs through the application's own controller, bus and handler.
 */
final class PartyLiveRoomWiringTest extends TestCase
{
    private const array ANSWER_FOR_AN_UNKNOWN_PARTY = ['type' => 'error', 'message' => 'Invalid public identity'];

    /** @var list<array{fd: int, payload: mixed}> what the server pushed to its sockets */
    private array $pushed = [];

    public function testTheListenerHearsALeaveAnEndAndAPlaybackChange(): void
    {
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        $listeners = [
            MemberLeft::class => 'onMemberLeft',
            PartySessionEnded::class => 'onSessionEnded',
            PlaybackStateChanged::class => 'onPlaybackStateChanged',
        ];
        foreach ($listeners as $event => $method) {
            $heard = array_filter(
                $dispatcher->getListeners($event),
                static fn (mixed $listener): bool => is_array($listener)
                    && $listener[0] instanceof LivePartyRoomListener
                    && $listener[1] === $method,
            );
            self::assertCount(1, $heard, $event . ' does not reach LivePartyRoomListener::' . $method);
        }
    }

    public function testAMemberLeavesAndTheHostEndsOverHttp(): void
    {
        $host = $this->createTestUser();
        $guest = $this->createTestUser();
        $party = $this->party($host);
        $this->invite($host, $guest, $party);

        $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/party/sessions/' . $party . '/join', $guest), 200);
        $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/party/sessions/' . $party . '/leave', $guest), 200);
        $this->assertJsonResponse($this->authenticatedRequest('DELETE', '/api/party/sessions/' . $party, $host), 200);
    }

    public function testARevokeTakesTheUsersSocketOutOfTheRoomAndTellsTheSocketsThatRemain(): void
    {
        $registry = $this->liveSockets();

        $host = $this->createTestUser();
        $guest = $this->createTestUser();
        $uninvited = $this->createTestUser();
        $party = $this->party($host);
        $invitations = '/api/party/sessions/' . $party . '/invitations/';
        $this->invite($host, $guest, $party);
        $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/party/sessions/' . $party . '/join', $guest), 200);

        $room = $this->room($party);
        $registry->addConnection(1, $host->getId()->toString(), 0, 'token-host');
        $registry->addConnection(2, $guest->getId()->toString(), 0, 'token-guest');
        $registry->joinRoom($room, 1);
        $registry->joinRoom($room, 2);
        self::assertEqualsCanonicalizing([1, 2], $registry->getRoomMembers($room));

        // A user who holds no member row leaves nothing, so the room hears nothing.
        self::assertSame(204, $this->authenticatedRequest('DELETE', $invitations . $uninvited->getPublicId()->toString(), $host)->getStatusCode());
        self::assertEqualsCanonicalizing([1, 2], $registry->getRoomMembers($room));
        self::assertSame([], $this->pushed);

        self::assertSame(204, $this->authenticatedRequest('DELETE', $invitations . $guest->getPublicId()->toString(), $host)->getStatusCode());

        self::assertSame([1], $registry->getRoomMembers($room));
        self::assertSame([[
            'fd' => 1,
            'payload' => [
                'type' => 'party.member_event',
                'sessionId' => $party,
                'action' => 'leave',
                'userId' => $guest->getPublicId()->toString(),
            ],
        ]], $this->pushed);
    }

    public function testPartyJoinOverTheSocketAdmitsOnlyAMemberOrAnInvitedUserAndHidesThePartyFromEveryoneElse(): void
    {
        $registry = $this->liveSockets();
        $controller = self::getContainer()->get(WebSocketController::class);
        self::assertInstanceOf(WebSocketController::class, $controller);

        $host = $this->createTestUser();
        $guest = $this->createTestUser();
        $uninvited = $this->createTestUser();
        $party = $this->party($host);
        $this->invite($host, $guest, $party);
        $room = $this->room($party);
        foreach ([1 => $host, 2 => $guest, 3 => $uninvited] as $fd => $user) {
            $controller->onOpen($fd, $user->getId()->toString(), 'token-' . $fd);
        }

        // A user who is neither a member nor invited gets the frame an unknown party gets.
        self::assertSame([['fd' => 3, 'payload' => self::ANSWER_FOR_AN_UNKNOWN_PARTY]], $this->partyJoin($controller, 3, (new PublicId())->toString()));
        self::assertSame([['fd' => 3, 'payload' => self::ANSWER_FOR_AN_UNKNOWN_PARTY]], $this->partyJoin($controller, 3, $party));
        self::assertSame([], $registry->getRoomMembers($room));

        self::assertSame(
            ['fd' => 1, 'payload' => ['type' => 'party.joined', 'sessionId' => $party, 'role' => 'host']],
            $this->partyJoin($controller, 1, $party)[0],
        );
        self::assertSame([1], $registry->getRoomMembers($room));

        $told = ['type' => 'party.member_event', 'sessionId' => $party, 'action' => 'join', 'userId' => $guest->getPublicId()->toString(), 'role' => 'member'];
        self::assertEqualsCanonicalizing([
            ['fd' => 2, 'payload' => ['type' => 'party.joined', 'sessionId' => $party, 'role' => 'member']],
            ['fd' => 1, 'payload' => $told],
            ['fd' => 2, 'payload' => $told],
        ], $this->partyJoin($controller, 2, $party));
        self::assertEqualsCanonicalizing([1, 2], $registry->getRoomMembers($room));
        self::assertEqualsCanonicalizing(
            [$host->getPublicId()->toString(), $guest->getPublicId()->toString()],
            array_column($this->session($party, $host)['members'], 'userId'),
        );

        // A revoked member is out of the room and is answered as the uninvited user was.
        self::assertSame(204, $this->authenticatedRequest('DELETE', '/api/party/sessions/' . $party . '/invitations/' . $guest->getPublicId()->toString(), $host)->getStatusCode());
        self::assertSame([1], $registry->getRoomMembers($room));
        self::assertSame([['fd' => 2, 'payload' => self::ANSWER_FOR_AN_UNKNOWN_PARTY]], $this->partyJoin($controller, 2, $party));
        self::assertSame([1], $registry->getRoomMembers($room));
        self::assertSame([$host->getPublicId()->toString()], array_column($this->session($party, $host)['members'], 'userId'));
    }

    public function testPartyJoinOverTheSocketIsRefusedByAFullPartyExceptForItsMembersAndByAnEndedPartyForAll(): void
    {
        $registry = $this->liveSockets();
        $controller = self::getContainer()->get(WebSocketController::class);
        self::assertInstanceOf(WebSocketController::class, $controller);

        $host = $this->createTestUser();
        $guest = $this->createTestUser();
        $late = $this->createTestUser();
        $party = $this->party($host, maxMembers: 2);
        $this->invite($host, $guest, $party);
        $this->invite($host, $late, $party);
        $room = $this->room($party);
        foreach ([1 => $host, 2 => $guest, 3 => $late] as $fd => $user) {
            $controller->onOpen($fd, $user->getId()->toString(), 'token-' . $fd);
        }
        $refused = ['type' => 'error', 'message' => 'Party operation refused'];

        // The guest takes the party's second and last place.
        self::assertSame('party.joined', $this->partyJoin($controller, 2, $party)[0]['payload']['type']);
        self::assertSame([['fd' => 3, 'payload' => $refused]], $this->partyJoin($controller, 3, $party));
        // The host and the member return to the full party and are let in.
        foreach ([1 => 'host', 2 => 'member'] as $fd => $role) {
            self::assertSame(
                ['fd' => $fd, 'payload' => ['type' => 'party.joined', 'sessionId' => $party, 'role' => $role]],
                $this->partyJoin($controller, $fd, $party)[0],
            );
        }
        self::assertEqualsCanonicalizing([1, 2], $registry->getRoomMembers($room));

        $this->assertJsonResponse($this->authenticatedRequest('DELETE', '/api/party/sessions/' . $party, $host), 200);
        self::assertSame([], $registry->getRoomMembers($room));

        foreach ([1, 2, 3] as $fd) {
            self::assertSame([['fd' => $fd, 'payload' => $refused]], $this->partyJoin($controller, $fd, $party));
        }
        self::assertSame([], $registry->getRoomMembers($room));
    }

    public function testAHostWhoLeavesHandsThePartyAndItsPlaybackToTheEarliestMember(): void
    {
        $host = $this->createTestUser();
        $guest = $this->createTestUser();
        $party = $this->party($host);
        $sessionId = Uuid::fromString((string) $this->entityManager->getConnection()->fetchOne('SELECT id FROM party_sessions WHERE public_id = :publicId', ['publicId' => $party]));
        $bus = self::getContainer()->get(MessageBusInterface::class);
        $this->invite($host, $guest, $party);

        $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/party/sessions/' . $party . '/join', $guest), 200);
        $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/party/sessions/' . $party . '/leave', $host), 200);
        self::assertSame($guest->getPublicId()->toString(), $this->session($party, $guest)['hostUserId']);

        $bus->dispatch(new StartPlaybackCommand($sessionId, $host->getId(), 30.5));
        self::assertSame('stopped', $this->session($party, $guest)['playbackState'], 'The departed host still steered the party.');

        $bus->dispatch(new StartPlaybackCommand($sessionId, $guest->getId(), 30.5));
        $session = $this->session($party, $guest);
        self::assertSame('playing', $session['playbackState']);
        self::assertSame(30.5, $session['wallClockPosition']);
    }

    public function testPublicPartyOutputProjectsNestedIdentitiesAndRejectsTheInternalAddress(): void
    {
        $host = $this->createTestUser();
        $publicId = $this->party($host);
        $internalId = $this->entityManager->getConnection()->fetchOne('SELECT id FROM party_sessions WHERE public_id = :publicId', ['publicId' => $publicId]);
        $response = $this->authenticatedRequest('GET', '/api/party/sessions/' . $publicId, $host);
        $output = $this->assertJsonResponse($response, 200, 'data')['data'];

        self::assertSame($publicId, $output['publicId']);
        self::assertSame($host->getPublicId()->toString(), $output['hostUserId']);
        self::assertSame($host->getPublicId()->toString(), $output['members'][0]['userId']);
        self::assertSame($publicId, $output['members'][0]['sessionId']);
        self::assertArrayNotHasKey('uuid', $output);
        self::assertArrayNotHasKey('uuid', $output['members'][0]);
        self::assertStringNotContainsString($host->getId()->toString(), (string) $response->getContent());
        self::assertStringNotContainsString($internalId, (string) $response->getContent());
        $this->assertJsonResponse($this->authenticatedRequest('GET', '/api/party/sessions/' . $internalId, $host), 404);
        $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/party/sessions/' . $internalId . '/join', $host), 404);
    }

    /**
     * Puts this process in the place of an HTTP worker with open sockets. Called before the
     * first request, so the live rooms are built on these.
     */
    private function liveSockets(): WebSocketConnectionRegistry
    {
        $workers = $this->createStub(ServerWorkers::class);
        $workers->method('currentHttpWorkerId')->willReturn(0);
        self::getContainer()->set(ServerWorkers::class, $workers);
        $server = $this->createStub(Server::class);
        $server->method('isEstablished')->willReturn(true);
        $server->method('push')->willReturnCallback(function (int $fd, string $data): bool {
            $this->pushed[] = ['fd' => $fd, 'payload' => json_decode($data, true)];

            return true;
        });
        $pusher = self::getContainer()->get(WebSocketPusher::class);
        self::assertInstanceOf(WebSocketPusher::class, $pusher);
        $pusher->setServer($server);
        $registry = self::getContainer()->get(WebSocketConnectionRegistry::class);
        self::assertInstanceOf(WebSocketConnectionRegistry::class, $registry);

        return $registry;
    }

    /**
     * The socket sends `party.join` for the party.
     *
     * @return list<array{fd: int, payload: mixed}> what the server pushed in answer, to this socket and to the room
     */
    private function partyJoin(WebSocketController $controller, int $fd, string $party): array
    {
        $this->pushed = [];
        $controller->onMessage($fd, json_encode(['type' => 'party.join', 'sessionId' => $party], JSON_THROW_ON_ERROR));

        return $this->pushed;
    }

    /** The live room of the party. */
    private function room(string $party): string
    {
        return WebSocketRooms::party((string) $this->entityManager->getConnection()->fetchOne('SELECT id FROM party_sessions WHERE public_id = :publicId', ['publicId' => $party]));
    }

    /** The party's host invites the guest, who may then join. */
    private function invite(User $host, User $guest, string $party): void
    {
        $response = $this->authenticatedRequest('PUT', '/api/party/sessions/' . $party . '/invitations/' . $guest->getPublicId()->toString(), $host);

        self::assertSame(204, $response->getStatusCode(), (string) $response->getContent());
    }

    /** @return array<string, mixed> */
    private function session(string $party, User $member): array
    {
        return $this->assertJsonResponse($this->authenticatedRequest('GET', '/api/party/sessions/' . $party, $member), 200, 'data')['data'];
    }

    private function party(User $host, int $maxMembers = 10): string
    {
        $library = new LibraryEntity(
            name: 'Live room test',
            slug: 'live-room-' . bin2hex(random_bytes(8)),
            path: '/media/live-room-' . bin2hex(random_bytes(8)),
            type: 'movie',
            filesystemType: 'local',
        );
        $video = new VideoEntity(new PublicId(), '/media/live-room.mkv', bin2hex(random_bytes(16)));
        $movie = new MovieEntity(new PublicId(), $library, 'Live room movie');
        $this->entityManager->persist($library);
        $this->entityManager->persist($video);
        $this->entityManager->persist($movie);
        $this->entityManager->persist(new MovieVideoEntity($movie, $video));
        $this->entityManager->flush();
        self::getContainer()->get(LibraryAccessPortInterface::class)->grant($host->getId(), $library->getId());

        return $this->assertJsonResponse(
            $this->authenticatedRequest('POST', '/api/party/sessions/', $host, ['videoId' => $video->getPublicId()->toString(), 'maxMembers' => $maxMembers]),
            201,
            'data',
        )['data']['publicId'];
    }
}
