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
use App\Tests\Functional\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Leaving, ending and the host's playback reach the live room listener, which does
 * nothing outside the web server's HTTP workers, so the routes and commands still
 * complete here.
 */
final class PartyLiveRoomWiringTest extends TestCase
{
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

        $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/party/sessions/' . $party . '/join', $guest), 200);
        $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/party/sessions/' . $party . '/leave', $guest), 200);
        $this->assertJsonResponse($this->authenticatedRequest('DELETE', '/api/party/sessions/' . $party, $host), 200);
    }

    public function testAHostWhoLeavesHandsThePartyAndItsPlaybackToTheEarliestMember(): void
    {
        $host = $this->createTestUser();
        $guest = $this->createTestUser();
        $party = $this->party($host);
        $sessionId = Uuid::fromString((string) $this->entityManager->getConnection()->fetchOne('SELECT id FROM party_sessions WHERE public_id = :publicId', ['publicId' => $party]));
        $bus = self::getContainer()->get(MessageBusInterface::class);

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

    /** @return array<string, mixed> */
    private function session(string $party, User $member): array
    {
        return $this->assertJsonResponse($this->authenticatedRequest('GET', '/api/party/sessions/' . $party, $member), 200, 'data')['data'];
    }

    private function party(User $host): string
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
            $this->authenticatedRequest('POST', '/api/party/sessions/', $host, ['videoId' => $video->getPublicId()->toString()]),
            201,
            'data',
        )['data']['publicId'];
    }
}
