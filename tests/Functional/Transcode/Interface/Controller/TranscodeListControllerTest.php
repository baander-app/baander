<?php

declare(strict_types=1);

namespace App\Tests\Functional\Transcode\Interface\Controller;

use App\Auth\Domain\Model\User;
use App\Catalog\Infrastructure\Doctrine\Entity\VideoEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\MovieEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\MovieVideoEntity;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Library\Infrastructure\Doctrine\Entity\UserLibraryAccessEntity;
use App\Shared\Domain\Model\PublicId;
use App\Tests\Functional\TestCase;
use App\Transcode\Domain\ValueObject\AudioProfile;
use App\Transcode\Infrastructure\Doctrine\Entity\TranscodeJobEntity;
use App\Transcode\Infrastructure\Doctrine\Entity\TranscodeSessionEntity;

/**
 * The transcode list routes are reached ahead of the `show` routes whose path
 * parameter would also match "list", and they return each instant in the API
 * format, whole seconds at +00:00, whatever fraction the column holds.
 */
final class TranscodeListControllerTest extends TestCase
{
    private User $user;

    private TranscodeJobEntity $job;

    private TranscodeSessionEntity $session;

    private PublicId $videoPublicId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->createTestUser();

        $video = new VideoEntity(new PublicId(), '/media/transcode-list.mkv', bin2hex(random_bytes(16)));
        $this->entityManager->persist($video);
        $this->videoPublicId = $video->getPublicId();
        $library = new LibraryEntity('Transcode library', bin2hex(random_bytes(16)), '/media/transcode-list', 'video', 'local');
        $movie = new MovieEntity(new PublicId(), $library, 'Transcode movie');
        $this->entityManager->persist($library);
        $this->entityManager->persist($movie);
        $this->entityManager->persist(new MovieVideoEntity($movie, $video));
        $this->entityManager->persist(new UserLibraryAccessEntity($this->user->getId(), $library, new \DateTimeImmutable()));
        $this->job = new TranscodeJobEntity(new PublicId(), $video->getId(), '1080p');
        $this->entityManager->persist($this->job);
        $now = new \DateTimeImmutable();
        $this->session = new TranscodeSessionEntity(
            new PublicId(),
            $this->user->getId(),
            $this->job,
            $video->getId(),
            'pending',
            'normal',
            AudioProfile::streamingStereo()->jsonSerialize(),
            $now,
            $now,
        );
        $this->entityManager->persist($this->session);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $connection = $this->entityManager->getConnection();
        foreach (['transcode_jobs' => $this->job->getId(), 'transcode_sessions' => $this->session->getId()] as $table => $id) {
            $connection->executeStatement(
                sprintf('UPDATE %s SET created_at = :created, updated_at = :updated WHERE id = :id', $table),
                ['created' => '2026-10-10 12:00:00.123456+00:00', 'updated' => '2026-10-10 12:30:45.654321+00:00', 'id' => $id->toString()],
            );
        }
    }

    public function testJobListReturnsInstantsInTheApiFormat(): void
    {
        $body = $this->assertJsonResponse($this->authenticatedRequest('GET', '/api/transcode/jobs/list', $this->user), 200, 'data');

        self::assertCount(1, $body['data']);
        self::assertSame($this->job->getPublicId()->toString(), $body['data'][0]['publicId']);
        self::assertSame('1080p', $body['data'][0]['qualityTier']);
        self::assertSame('2026-10-10T12:00:00+00:00', $body['data'][0]['createdAt']);
        self::assertSame('2026-10-10T12:30:45+00:00', $body['data'][0]['updatedAt']);
    }

    public function testSessionListReturnsInstantsInTheApiFormat(): void
    {
        $body = $this->assertJsonResponse($this->authenticatedRequest('GET', '/api/transcode/sessions/list', $this->user), 200, 'data');

        self::assertCount(1, $body['data']);
        self::assertSame($this->session->getPublicId()->toString(), $body['data'][0]['publicId']);
        self::assertSame($this->videoPublicId->toString(), $body['data'][0]['videoId']);
        self::assertSame($this->job->getPublicId()->toString(), $body['data'][0]['jobId']);
        self::assertSame($this->user->getPublicId()->toString(), $body['data'][0]['userId']);
        self::assertArrayNotHasKey('uuid', $body['data'][0]);
        self::assertSame('2026-10-10T12:00:00+00:00', $body['data'][0]['createdAt']);
        self::assertSame('2026-10-10T12:30:45+00:00', $body['data'][0]['updatedAt']);
    }

    public function testJobShowStillServesAPublicId(): void
    {
        $publicId = $this->job->getPublicId()->toString();

        $body = $this->assertJsonResponse($this->authenticatedRequest('GET', '/api/transcode/jobs/' . $publicId, $this->user), 200, 'data');

        self::assertSame($publicId, $body['data']['publicId']);
    }

    public function testSessionShowServesOnlyItsPublicId(): void
    {
        $publicId = $this->session->getPublicId()->toString();

        $body = $this->assertJsonResponse($this->authenticatedRequest('GET', '/api/transcode/sessions/' . $publicId, $this->user), 200, 'data');

        self::assertSame($publicId, $body['data']['publicId']);
        self::assertArrayNotHasKey('uuid', $body['data']);
        $this->assertJsonResponse($this->authenticatedRequest('GET', '/api/transcode/sessions/' . $this->session->getId()->toString(), $this->user), 404);
    }
}
