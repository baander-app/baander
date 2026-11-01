<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Infrastructure\Doctrine\Repository;

use App\Catalog\Infrastructure\Doctrine\Repository\VideoRepository;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class VideoVisibilityTest extends TestCase
{
    private Connection $connection;
    private VideoRepository $videos;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement('CREATE TABLE videos (id TEXT PRIMARY KEY, public_id TEXT UNIQUE NOT NULL)');
        $this->connection->executeStatement('CREATE TABLE movies (id TEXT PRIMARY KEY, library_id TEXT)');
        $this->connection->executeStatement('CREATE TABLE movie_video (movie_id TEXT NOT NULL, video_id TEXT NOT NULL)');
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($this->connection);
        $this->videos = new VideoRepository($em);
    }

    protected function tearDown(): void
    {
        $this->connection->close();
    }

    public function testAdministratorStillNeedsAnExistingVideoAndMaySeeUnlinkedVideo(): void
    {
        $video = $this->addVideo();
        self::assertTrue($this->videos->isVisibleByPublicId($video, LibraryReadScope::unrestricted()));
        self::assertFalse($this->videos->isVisibleByPublicId(new PublicId(), LibraryReadScope::unrestricted()));
        self::assertFalse($this->videos->isVisibleByPublicId($video, LibraryReadScope::none()));
        self::assertFalse($this->videos->isVisibleByPublicId($video, LibraryReadScope::restricted([new Uuid()])));
    }

    public function testRestrictedVideoNeedsAtLeastOneMovieInAnAllowedLibrary(): void
    {
        $video = $this->addVideo();
        $visible = new Uuid();
        $hidden = new Uuid();
        $this->linkMovie($video, $hidden);
        self::assertFalse($this->videos->isVisibleByPublicId($video, LibraryReadScope::restricted([$visible])));
        $this->linkMovie($video, $visible);
        self::assertTrue($this->videos->isVisibleByPublicId($video, LibraryReadScope::restricted([$visible])));
        self::assertFalse($this->videos->isVisibleByPublicId($video, LibraryReadScope::none()));
        self::assertFalse($this->videos->isVisibleByPublicId(new PublicId(), LibraryReadScope::restricted([$visible])));
    }

    public function testNullLibraryCannotGrantVisibility(): void
    {
        $video = $this->addVideo();
        $this->linkMovie($video, null);
        self::assertFalse($this->videos->isVisibleByPublicId($video, LibraryReadScope::restricted([new Uuid()])));
        self::assertTrue($this->videos->isVisibleByPublicId($video, LibraryReadScope::unrestricted()));
    }

    private function addVideo(): PublicId
    {
        $video = new PublicId();
        $this->connection->insert('videos', ['id' => new Uuid()->toString(), 'public_id' => $video->toString()]);

        return $video;
    }

    private function linkMovie(PublicId $video, ?Uuid $library): void
    {
        $movie = new Uuid()->toString();
        $this->connection->insert('movies', ['id' => $movie, 'library_id' => $library?->toString()]);
        $videoId = $this->connection->fetchOne('SELECT id FROM videos WHERE public_id = ?', [$video->toString()]);
        $this->connection->insert('movie_video', ['movie_id' => $movie, 'video_id' => $videoId]);
    }
}
