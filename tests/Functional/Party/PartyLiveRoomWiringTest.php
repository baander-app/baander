<?php

declare(strict_types=1);

namespace App\Tests\Functional\Party;

use App\Auth\Domain\Model\User;
use App\Library\Application\Port\LibraryAccessPortInterface;
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
use PHPUnit\Framework\Attributes\DataProvider;
use Swoole\WebSocket\Server;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Leaving, ending and the host's playback reach the live room listener, which does
 * nothing outside the web server's HTTP workers, so the routes and commands still
 * complete here. The revoke test and the socket tests put this process in the place of an
 * HTTP worker with open sockets, so the listener changes the room they fill and the party
 * messages run through the application's own controller, bus and handlers.
 */
final class PartyLiveRoomWiringTest extends TestCase
{
    use PartyFixtures;

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

    /** @return iterable<string, array{array<string, mixed>, string}> the message without the party it names, and what an unknown party answers */
    public static function messagesThatNameAParty(): iterable
    {
        yield 'leave' => [['type' => 'party.leave'], 'Invalid public identity'];
        yield 'play' => [['type' => 'party.playback', 'action' => 'play', 'position' => 30.5], 'Invalid sessionId or action'];
        yield 'pause' => [['type' => 'party.playback', 'action' => 'pause'], 'Invalid sessionId or action'];
        yield 'seek' => [['type' => 'party.playback', 'action' => 'seek', 'position' => 12.5], 'Invalid sessionId or action'];
        yield 'sync' => [['type' => 'party.sync', 'position' => 12.5, 'latency' => 0.25], 'Invalid sessionId format'];
    }

    /** @param array<string, mixed> $message */
    #[DataProvider('messagesThatNameAParty')]
    public function testAPartyMessageFromAUserWithoutAMemberRowGetsTheFrameOfAnUnknownPartyAndTheRoomHearsNothing(array $message, string $answer): void
    {
        [$controller, $registry, $party, $host, , $outsiders] = $this->partyWithItsRoomAndTwoUsersWithoutAMemberRow();
        $room = $this->room($party);
        $stored = $this->session($party, $host);

        foreach (array_keys($outsiders) as $fd) {
            $missing = $this->send($controller, $fd, [...$message, 'sessionId' => (new PublicId())->toString()]);
            $hidden = $this->send($controller, $fd, [...$message, 'sessionId' => $party]);

            // One frame, to the sender alone: the two sockets in the party's room hear nothing.
            self::assertSame([['fd' => $fd, 'payload' => ['type' => 'error', 'message' => $answer]]], $missing);
            self::assertSame($missing, $hidden);
            self::assertEqualsCanonicalizing([1, 2], $registry->getRoomMembers($room));
            self::assertSame($stored, $this->session($party, $host));
        }
    }

    /** @return iterable<string, array{string}> what a user asks of a party that would change it or tell its room */
    public static function requestsThatWouldChangeAParty(): iterable
    {
        $requests = ['leaving', 'syncing', 'ending the party', 'handing the host role to a member', 'setting playback preferences', 'revoking a member'];

        foreach ($requests as $request) {
            yield $request => [$request];
        }
    }

    #[DataProvider('requestsThatWouldChangeAParty')]
    public function testARequestFromAUserWithoutAMemberRowReachesNoSocketInThePartysRoom(string $request): void
    {
        [, $registry, $party, $host, $guest, $outsiders] = $this->partyWithItsRoomAndTwoUsersWithoutAMemberRow();
        $room = $this->room($party);
        $stored = $this->session($party, $host);
        [$method, $path, $body] = [
            'leaving' => ['POST', '/leave', []],
            'syncing' => ['POST', '/sync', ['clientPosition' => 12.5, 'clientLatency' => 0.25]],
            'ending the party' => ['DELETE', '', []],
            'handing the host role to a member' => ['POST', '/members/transfer-host', ['newHostUserId' => $guest->getPublicId()->toString()]],
            'setting playback preferences' => ['PATCH', '/members/me', ['audioProfileId' => 'stereo']],
            'revoking a member' => ['DELETE', '/invitations/' . $guest->getPublicId()->toString(), []],
        ][$request];

        foreach ($outsiders as $user) {
            $this->pushed = [];

            $this->assertJsonResponse($this->authenticatedRequest($method, '/api/party/sessions/' . $party . $path, $user, $body), 404);

            self::assertSame([], $this->pushed);
            self::assertEqualsCanonicalizing([1, 2], $registry->getRoomMembers($room));
            self::assertSame($stored, $this->session($party, $host));
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> the malformed playback message without the party it names */
    public static function malformedPlaybackMessages(): iterable
    {
        yield 'an unknown action' => [['type' => 'party.playback', 'action' => 'rewind']];
        // Joining has a message of its own; playback does not act on it.
        yield 'join as the action' => [['type' => 'party.playback', 'action' => 'join']];
        yield 'a text position' => [['type' => 'party.playback', 'action' => 'seek', 'position' => '12.5']];
    }

    /** @param array<string, mixed> $message */
    #[DataProvider('malformedPlaybackMessages')]
    public function testAMalformedPartyPlaybackGetsTheSameAnswerForTheHostsPartyAndAnUnknownOneAndChangesNothing(array $message): void
    {
        [$controller, $registry, $party, $host] = $this->partyWithItsRoomAndTwoUsersWithoutAMemberRow();
        $room = $this->room($party);
        $stored = $this->session($party, $host);

        $missing = $this->send($controller, 1, [...$message, 'sessionId' => (new PublicId())->toString()]);
        $real = $this->send($controller, 1, [...$message, 'sessionId' => $party]);

        self::assertSame([['fd' => 1, 'payload' => ['type' => 'error', 'message' => 'Invalid sessionId or action']]], $missing);
        self::assertSame($missing, $real);
        self::assertEqualsCanonicalizing([1, 2], $registry->getRoomMembers($room));
        self::assertSame($stored, $this->session($party, $host));
    }

    public function testPartyPlaybackOverTheSocketIsAppliedAndBroadcastForTheHostAndIgnoredForAMember(): void
    {
        [$controller, , $party, $host] = $this->partyWithItsRoomAndTwoUsersWithoutAMemberRow();

        // A member who is not the host steers nothing and is told nothing.
        self::assertSame([], $this->send($controller, 2, ['type' => 'party.playback', 'sessionId' => $party, 'action' => 'play', 'position' => 30.5]));
        self::assertSame('stopped', $this->session($party, $host)['playbackState']);

        $steps = [
            'play' => [['action' => 'play', 'position' => 30.5], 'playing', 30.5],
            'seek' => [['action' => 'seek', 'position' => 12], 'playing', 12.0],
            'pause' => [['action' => 'pause'], 'paused', 12.0],
        ];
        foreach ($steps as $step => [$action, $state, $position]) {
            $frames = $this->send($controller, 1, ['type' => 'party.playback', 'sessionId' => $party, ...$action]);

            // The host's own socket is in the room, so it hears the new state as the member does.
            self::assertEqualsCanonicalizing([1, 2], array_column($frames, 'fd'), $step);
            foreach ($frames as $frame) {
                self::assertSame('party.playback_state', $frame['payload']['type'], $step);
                self::assertSame($party, $frame['payload']['sessionId'], $step);
                self::assertSame($state, $frame['payload']['playbackState'], $step);
                self::assertEquals($position, $frame['payload']['wallClockPosition'], $step);
            }
            $session = $this->session($party, $host);
            self::assertSame($state, $session['playbackState'], $step);
            self::assertEquals($position, $session['wallClockPosition'], $step);
        }
    }

    public function testPartySyncOverTheSocketAnswersTheHostAndAMemberWithTheServerPosition(): void
    {
        [$controller, , $party] = $this->partyWithItsRoomAndTwoUsersWithoutAMemberRow();

        foreach ([1, 2] as $fd) {
            $frames = $this->send($controller, $fd, ['type' => 'party.sync', 'sessionId' => $party, 'position' => 12.5, 'latency' => 0.25]);

            self::assertCount(1, $frames);
            self::assertSame($fd, $frames[0]['fd']);
            self::assertSame(['type', 'sessionId', 'serverPosition'], array_keys($frames[0]['payload']));
            self::assertSame('party.sync_response', $frames[0]['payload']['type']);
            self::assertSame($party, $frames[0]['payload']['sessionId']);
            self::assertIsNumeric($frames[0]['payload']['serverPosition']);
        }
    }

    public function testPartyLeaveOverTheSocketTakesAMemberAndThenTheHostOutOfThePartyAndItsRoom(): void
    {
        [$controller, $registry, $party, $host, $guest] = $this->partyWithItsRoomAndTwoUsersWithoutAMemberRow();
        $room = $this->room($party);
        $second = $this->createTestUser();
        $this->invite($host, $second, $party);
        $controller->onOpen(5, $second->getId()->toString(), 'token-5');
        self::assertSame('party.joined', $this->send($controller, 5, ['type' => 'party.join', 'sessionId' => $party])[0]['payload']['type']);
        $left = static fn (User $user): array => ['type' => 'party.member_event', 'sessionId' => $party, 'action' => 'leave', 'userId' => $user->getPublicId()->toString()];

        // The leaver is told nothing; the sockets left in the room hear that they left.
        self::assertEqualsCanonicalizing(
            [['fd' => 1, 'payload' => $left($guest)], ['fd' => 5, 'payload' => $left($guest)]],
            $this->send($controller, 2, ['type' => 'party.leave', 'sessionId' => $party]),
        );
        self::assertEqualsCanonicalizing([1, 5], $registry->getRoomMembers($room));
        self::assertEqualsCanonicalizing(
            [$host->getPublicId()->toString(), $second->getPublicId()->toString()],
            array_column($this->session($party, $host)['members'], 'userId'),
        );

        self::assertSame([['fd' => 5, 'payload' => $left($host)]], $this->send($controller, 1, ['type' => 'party.leave', 'sessionId' => $party]));
        self::assertSame([5], $registry->getRoomMembers($room));
        $session = $this->session($party, $second);
        self::assertSame($second->getPublicId()->toString(), $session['hostUserId']);
        self::assertSame([$second->getPublicId()->toString()], array_column($session['members'], 'userId'));
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
     * A party with a socket of its host (1) and of a member (2) in its room and, outside the
     * room, a socket of each of two users without a member row: one who was never invited (3)
     * and one the host revoked after they had joined (4).
     *
     * @return array{WebSocketController, WebSocketConnectionRegistry, string, User, User, array<int, User>} the controller, the registry, the party, its host, the member, and the two other users by socket
     */
    private function partyWithItsRoomAndTwoUsersWithoutAMemberRow(): array
    {
        $registry = $this->liveSockets();
        $controller = self::getContainer()->get(WebSocketController::class);
        self::assertInstanceOf(WebSocketController::class, $controller);

        $host = $this->createTestUser();
        $guest = $this->createTestUser();
        $uninvited = $this->createTestUser();
        $revoked = $this->createTestUser();
        $party = $this->party($host);
        $this->invite($host, $guest, $party);
        $this->invite($host, $revoked, $party);
        foreach ([1 => $host, 2 => $guest, 3 => $uninvited, 4 => $revoked] as $fd => $user) {
            $controller->onOpen($fd, $user->getId()->toString(), 'token-' . $fd);
        }
        foreach ([1, 2, 4] as $fd) {
            self::assertSame('party.joined', $this->partyJoin($controller, $fd, $party)[0]['payload']['type']);
        }
        self::assertSame(204, $this->authenticatedRequest('DELETE', '/api/party/sessions/' . $party . '/invitations/' . $revoked->getPublicId()->toString(), $host)->getStatusCode());
        self::assertEqualsCanonicalizing([1, 2], $registry->getRoomMembers($this->room($party)));
        $this->pushed = [];

        return [$controller, $registry, $party, $host, $guest, [3 => $uninvited, 4 => $revoked]];
    }

    /**
     * The socket sends `party.join` for the party.
     *
     * @return list<array{fd: int, payload: mixed}> what the server pushed in answer, to this socket and to the room
     */
    private function partyJoin(WebSocketController $controller, int $fd, string $party): array
    {
        return $this->send($controller, $fd, ['type' => 'party.join', 'sessionId' => $party]);
    }

    /**
     * The socket sends the message.
     *
     * @param array<string, mixed> $message
     *
     * @return list<array{fd: int, payload: mixed}> what the server pushed in answer, to this socket and to others
     */
    private function send(WebSocketController $controller, int $fd, array $message): array
    {
        $this->pushed = [];
        $controller->onMessage($fd, json_encode($message, JSON_THROW_ON_ERROR));

        return $this->pushed;
    }

    /** The live room of the party. */
    private function room(string $party): string
    {
        return WebSocketRooms::party((string) $this->entityManager->getConnection()->fetchOne('SELECT id FROM party_sessions WHERE public_id = :publicId', ['publicId' => $party]));
    }

    /** @return array<string, mixed> */
    private function session(string $party, User $member): array
    {
        return $this->assertJsonResponse($this->authenticatedRequest('GET', '/api/party/sessions/' . $party, $member), 200, 'data')['data'];
    }

    /** The party the host creates for a video in a new library the host has access to. */
    private function party(User $host, int $maxMembers = 10): string
    {
        $library = $this->library();
        $video = $this->video($library);
        self::getContainer()->get(LibraryAccessPortInterface::class)->grant($host->getId(), $library->getId());

        return $this->createParty($host, $video, $maxMembers);
    }
}
