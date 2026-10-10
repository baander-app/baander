<?php

declare(strict_types=1);

namespace App\Tests\Functional\Party;

use App\Auth\Domain\Model\User;
use App\Catalog\Infrastructure\Doctrine\Entity\MovieEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\MovieVideoEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\VideoEntity;
use App\Library\Application\Port\LibraryAccessPortInterface;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Party\Domain\Event\MemberLeft;
use App\Party\Domain\Event\PartySessionEnded;
use App\Party\Infrastructure\EventListener\LivePartyRoomListener;
use App\Shared\Domain\Model\PublicId;
use App\Tests\Functional\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Leaving and ending a party reach the live room listener, which does nothing
 * outside the web server's HTTP workers, so the HTTP routes still answer here.
 */
final class PartyLiveRoomWiringTest extends TestCase
{
    public function testTheListenerHearsALeaveAndAnEnd(): void
    {
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        foreach ([MemberLeft::class => 'onMemberLeft', PartySessionEnded::class => 'onSessionEnded'] as $event => $method) {
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
            $this->authenticatedRequest('POST', '/api/party/sessions/', $host, ['videoId' => $video->getId()->toString()]),
            201,
            'data',
        )['data']['uuid'];
    }
}
