<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Auth\Domain\Model\User;
use App\Catalog\Infrastructure\Doctrine\Entity\MovieEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\MovieVideoEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\VideoEntity;
use App\Library\Application\Port\LibraryAccessPortInterface;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Functional\TestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Creating a party checks its video and transcode job against the playback authority.
 */
final class PartySessionCreationTest extends TestCase
{
    private const URI = '/api/party/sessions/';

    private User $host;
    private LibraryEntity $hostLibrary;
    private Uuid $video;
    private Uuid $job;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hostLibrary = $this->library();
        $this->host = $this->createTestUser();
        self::getContainer()->get(LibraryAccessPortInterface::class)->grant($this->host->getId(), $this->hostLibrary->getId());
        $this->video = $this->video($this->hostLibrary);
        $this->job = $this->job($this->video);
    }

    public function testCreatesAPartyForAPlayableVideoAndItsJob(): void
    {
        $data = $this->assertJsonResponse($this->create(['videoId' => $this->video->toString(), 'transcodeJobId' => $this->job->toString()]), 201, 'data')['data'];

        self::assertSame($this->video->toString(), $data['videoId']);
        self::assertSame($this->job->toString(), $data['transcodeJobId']);
        self::assertSame($this->host->getId()->toString(), $data['hostUserId']);
        self::assertSame(1, $this->countParties($this->video));
    }

    public function testCreatesAPartyWithoutAJob(): void
    {
        $data = $this->assertJsonResponse($this->create(['videoId' => $this->video->toString()]), 201, 'data')['data'];

        self::assertNull($data['transcodeJobId']);
    }

    public function testANonexistentVideoIsNotFound(): void
    {
        $this->assertJsonResponse($this->create(['videoId' => Uuid::generate()->toString()]), 404);
    }

    public function testAVideoOutsideTheHostsLibrariesIsNotFound(): void
    {
        $hidden = $this->video($this->library());

        $response = $this->assertJsonResponse($this->create(['videoId' => $hidden->toString()]), 404);
        $missing = $this->assertJsonResponse($this->create(['videoId' => Uuid::generate()->toString()]), 404);

        // The response does not reveal that the inaccessible video exists.
        self::assertSame($missing, $response);
        self::assertSame(0, $this->countParties($hidden));
    }

    public function testANonexistentJobIsNotFound(): void
    {
        $this->assertJsonResponse($this->create(['videoId' => $this->video->toString(), 'transcodeJobId' => Uuid::generate()->toString()]), 404);
        self::assertSame(0, $this->countParties($this->video));
    }

    public function testAJobOfAVideoOutsideTheHostsLibrariesIsNotFound(): void
    {
        $hiddenJob = $this->job($this->video($this->library()));

        $this->assertJsonResponse($this->create(['videoId' => $this->video->toString(), 'transcodeJobId' => $hiddenJob->toString()]), 404);
        self::assertSame(0, $this->countParties($this->video));
    }

    public function testAJobOfAnotherVideoIsRejected(): void
    {
        $otherJob = $this->job($this->video($this->hostLibrary));

        $error = $this->assertJsonResponse($this->create(['videoId' => $this->video->toString(), 'transcodeJobId' => $otherJob->toString()]), 422);

        self::assertSame(['transcodeJobId' => ['The transcode job does not belong to the video.']], $error['error']['details']);
        self::assertSame(0, $this->countParties($this->video));
    }

    public function testAMalformedIdentifierIsAValidationError(): void
    {
        $this->assertJsonResponse($this->create(['videoId' => 'not-a-uuid']), 422);
        $this->assertJsonResponse($this->create(['videoId' => $this->video->toString(), 'transcodeJobId' => 'not-a-uuid']), 422);
    }

    /** @param array<string, mixed> $body */
    private function create(array $body): Response
    {
        return $this->authenticatedRequest('POST', self::URI, $this->host, $body);
    }

    private function library(): LibraryEntity
    {
        $library = new LibraryEntity(
            name: 'Party test',
            slug: 'party-' . bin2hex(random_bytes(8)),
            path: '/media/party-' . bin2hex(random_bytes(8)),
            type: 'movie',
            filesystemType: 'local',
        );
        $this->entityManager->persist($library);
        $this->entityManager->flush();

        return $library;
    }

    private function video(LibraryEntity $library): Uuid
    {
        $video = new VideoEntity(new PublicId(), '/media/party-test.mkv', bin2hex(random_bytes(16)));
        $movie = new MovieEntity(new PublicId(), $library, 'Party test movie');
        $this->entityManager->persist($video);
        $this->entityManager->persist($movie);
        $this->entityManager->persist(new MovieVideoEntity($movie, $video));
        $this->entityManager->flush();

        return $video->getId();
    }

    private function job(Uuid $video): Uuid
    {
        $id = Uuid::generate();
        $this->entityManager->getConnection()->insert('transcode_jobs', [
            'id' => $id->toString(),
            'video_id' => $video->toString(),
            'public_id' => (new PublicId())->toString(),
            'quality_tier_name' => '1080p',
            'created_at' => '2026-10-07 12:00:00+00',
            'updated_at' => '2026-10-07 12:00:00+00',
        ]);

        return $id;
    }

    private function countParties(Uuid $video): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT count(*) FROM party_sessions WHERE video_id = :video',
            ['video' => $video->toString()],
        );
    }
}
