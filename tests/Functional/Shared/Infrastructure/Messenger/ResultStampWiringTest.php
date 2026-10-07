<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared\Infrastructure\Messenger;

use App\Catalog\Infrastructure\Doctrine\Entity\MovieEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\MovieVideoEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\VideoEntity;
use App\Library\Application\Port\LibraryAccessPortInterface;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Party\Application\Command\JoinPartySessionCommand;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Messenger\Stamp\PartyMemberResultStamp;
use App\Tests\Functional\TestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The WebSocket party.join reads the joined member from PartyMemberResultStamp, which only
 * the default bus's ResultStampMiddleware adds.
 */
final class ResultStampWiringTest extends TestCase
{
    public function testTheDefaultBusStampsTheJoinedPartyMember(): void
    {
        $host = $this->createTestUser();
        $library = new LibraryEntity(
            name: 'Result stamp test',
            slug: 'result-stamp-' . bin2hex(random_bytes(8)),
            path: '/media/result-stamp-' . bin2hex(random_bytes(8)),
            type: 'movie',
            filesystemType: 'local',
        );
        $video = new VideoEntity(new PublicId(), '/media/result-stamp.mkv', bin2hex(random_bytes(16)));
        $movie = new MovieEntity(new PublicId(), $library, 'Result stamp movie');
        $this->entityManager->persist($library);
        $this->entityManager->persist($video);
        $this->entityManager->persist($movie);
        $this->entityManager->persist(new MovieVideoEntity($movie, $video));
        $this->entityManager->flush();
        self::getContainer()->get(LibraryAccessPortInterface::class)->grant($host->getId(), $library->getId());
        $party = $this->assertJsonResponse(
            $this->authenticatedRequest('POST', '/api/party/sessions/', $host, ['videoId' => $video->getId()->toString()]),
            201,
            'data',
        )['data'];
        $guest = $this->createTestUser();

        $envelope = self::getContainer()->get(MessageBusInterface::class)->dispatch(
            new JoinPartySessionCommand($guest->getId(), Uuid::fromString($party['uuid'])),
        );

        $member = $envelope->last(PartyMemberResultStamp::class)?->getMember();
        self::assertNotNull($member);
        self::assertSame($guest->getId()->toString(), $member->getUserId()->toString());
        self::assertSame('member', $member->getRole()->value);
    }
}
