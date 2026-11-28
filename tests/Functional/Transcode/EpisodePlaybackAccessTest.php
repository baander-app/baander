<?php

declare(strict_types=1);

namespace App\Tests\Functional\Transcode;

use App\Catalog\Infrastructure\Doctrine\Entity\VideoEntity;
use App\Library\Application\Port\LibraryAccessPortInterface;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Library\Infrastructure\Doctrine\Entity\LibraryFileIndexEntity;
use App\Recommendation\Application\Port\RecommendationPublicIdentityInterface;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Catalog\TvPlaybackPlacement;
use App\Tests\Functional\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Filesystem\Filesystem;

final class EpisodePlaybackAccessTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . '/baander-episode-access-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory, 0700));
        self::assertSame(13, file_put_contents($this->directory . '/episode.mp4', 'fixture-media'));
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->directory);
        parent::tearDown();
    }

    /** @return iterable<string, array{string, bool}> */
    public static function placements(): iterable
    {
        yield 'episode member' => ['episode', true];
        yield 'episode denied' => ['episode', false];
        yield 'extra member' => ['extra', true];
        yield 'extra denied' => ['extra', false];
        yield 'unassigned member' => ['review', true];
    }

    #[DataProvider('placements')]
    public function testOriginalDeliveryAndPublicRecommendationRequireAnAccessibleTitleLink(string $kind, bool $grant): void
    {
        $library = new LibraryEntity('TV access', 'tv-access-' . bin2hex(random_bytes(8)), $this->directory, 'tv_show', 'local');
        $video = new VideoEntity(new PublicId(), $this->directory . '/episode.mp4', hash('sha256', $this->directory));
        $video->setProbe(['containerFormat' => 'mov,mp4,m4a,3gp,3g2,mj2', 'videoCodec' => 'h264']);
        $this->entityManager->persist($library);
        $this->entityManager->persist($video);
        foreach (TvPlaybackPlacement::entities($library, $video, $kind, 'episode.mp4') as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->persist(new LibraryFileIndexEntity(
            Uuid::v7(), $library->getId(), $video->getPath(), $video->getHash(), 13, 'mp4',
            (int) filemtime($video->getPath()), new \DateTimeImmutable(),
        ));
        $this->entityManager->flush();
        $member = $this->createTestUser();
        if ($grant) {
            self::getContainer()->get(LibraryAccessPortInterface::class)->grant($member->getId(), $library->getId());
        }

        $allowed = $grant && $kind !== 'review';
        $response = $this->authenticatedRequest('GET', '/api/stream/video?id=' . $video->getPublicId()->toString(), $member);
        self::assertSame($allowed ? 200 : 403, $response->getStatusCode());
        $identities = self::getContainer()->get(RecommendationPublicIdentityInterface::class);
        self::assertSame(
            $allowed ? $video->getPublicId()->toString() : null,
            $identities->publicEntityId('video', $video->getId()->toString(), $member->getId(), false),
        );
        if (!$allowed) {
            $this->expectException(NotFoundException::class);
        }
        $id = $identities->internalEntityId('video', $video->getPublicId()->toString(), $member->getId(), false);
        self::assertTrue($video->getId()->equals($id));
    }
}
