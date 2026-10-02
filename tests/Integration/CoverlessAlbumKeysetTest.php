<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Catalog\Domain\Repository\AlbumRepositoryInterface;
use App\Catalog\Infrastructure\Doctrine\Entity\AlbumEntity;
use App\Kernel;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Media\Infrastructure\Doctrine\Entity\ImageEntity;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Actual PostgreSQL UUID ordering while independent writers change eligibility. */
#[SkipDatabaseRollback]
final class CoverlessAlbumKeysetTest extends TestCase
{
    private Kernel $kernel;
    private Connection $observer;
    private AlbumRepositoryInterface $repository;
    private LibraryEntity $library;
    private ImageEntity $cover;

    protected function setUp(): void
    {
        $url = getenv('OUTBOX_TEST_DATABASE_URL');
        if (!$url) {
            self::markTestSkipped('Set OUTBOX_TEST_DATABASE_URL and DATABASE_URL to the same fully migrated disposable PostgreSQL database.');
        }

        $params = (new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url);
        $params['serverVersion'] = '18';
        $this->observer = DriverManager::getConnection($params);
        $this->kernel = new Kernel('test', false);
        $this->kernel->boot();
        $container = $this->kernel->getContainer()->get('test.service_container');
        $manager = $container->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        self::assertSame(0, $manager->getConnection()->getTransactionNestingLevel());
        self::assertSame($this->observer->fetchOne('SELECT current_database()'), $manager->getConnection()->fetchOne('SELECT current_database()'));
        $this->repository = $container->get(AlbumRepositoryInterface::class);
        $this->library = new LibraryEntity('Keyset fixture', 'keyset-' . bin2hex(random_bytes(8)), '/tmp/keyset-fixture', 'music', 'local');
        $manager->persist($this->library);
        $albums = [];
        foreach ([10, 20, 30, 40, 50, 60] as $number) {
            $album = new AlbumEntity(new PublicId(), $this->library, 'Album ' . $number, 'album', $this->id($number));
            $manager->persist($album);
            $albums[] = $album;
        }
        $this->cover = new ImageEntity('images/keyset-fixture.jpg', 'jpg', 'image/jpeg', new PublicId(), 10, 1, 1, 'album');
        $this->cover->setAlbum($albums[5]);
        $manager->persist($this->cover);
        $albums[5]->setCoverImage($this->cover);
        $manager->flush();
        $manager->clear();
        self::assertSame(6, (int) $this->observer->fetchOne('SELECT COUNT(*) FROM albums WHERE library_id = ?', [$this->library->getId()->toString()]));
    }

    public function testAlbumsBecomingCoveredBetweenPagesDoNotSkipRemainingIds(): void
    {
        $seen = [];
        $after = null;
        foreach ([[10, 20], [30, 40], [50]] as $expected) {
            $page = $this->repository->findCoverlessAlbumIdsAfter($after, 2);
            self::assertSame($this->strings($expected), $this->stringsFromIds($page));
            foreach ($page as $id) {
                $seen[] = $id->toString();
                $this->observer->executeStatement('UPDATE albums SET cover_image_id = ? WHERE id = ?', [$this->cover->getId()->toString(), $id->toString()]);
            }
            $after = $page[array_key_last($page)];
        }
        self::assertSame($this->strings([10, 20, 30, 40, 50]), $seen);
        self::assertSame([], $this->repository->findCoverlessAlbumIdsAfter($after, 2));
    }

    public function testPermanentlyCoverlessAlbumsAreVisitedOnceThenExhausted(): void
    {
        $seen = [];
        $after = null;
        // Bound the test independently of a broken cursor implementation.
        for ($pageNumber = 0; $pageNumber < 4; ++$pageNumber) {
            $page = $this->repository->findCoverlessAlbumIdsAfter($after, 2);
            if ($page === []) {
                self::assertSame($this->strings([10, 20, 30, 40, 50]), $seen);
                return;
            }
            $seen = [...$seen, ...$this->stringsFromIds($page)];
            $after = $page[array_key_last($page)];
        }
        self::fail('Keyset pagination must exhaust coverless rows without requiring cover extraction success.');
    }

    public function testNullCursorMissingCursorAndStrictBoundaryExcludeCoveredAlbums(): void
    {
        self::assertSame($this->strings([10, 20, 30, 40, 50]), $this->stringsFromIds($this->repository->findCoverlessAlbumIdsAfter()));
        self::assertSame($this->strings([30, 40]), $this->stringsFromIds($this->repository->findCoverlessAlbumIdsAfter($this->id(20), 2)));
        self::assertSame($this->strings([30, 40, 50]), $this->stringsFromIds($this->repository->findCoverlessAlbumIdsAfter($this->id(25))));
        self::assertSame([], $this->repository->findCoverlessAlbumIdsAfter($this->id(50)));
        self::assertSame([], $this->repository->findCoverlessAlbumIdsAfter($this->id(100)));
        $this->observer->executeStatement('DELETE FROM albums WHERE id = ?', [$this->id(20)->toString()]);
        self::assertSame($this->strings([30, 40, 50]), $this->stringsFromIds($this->repository->findCoverlessAlbumIdsAfter($this->id(20))));
    }

    /** @return iterable<string, array{int}> */
    public static function invalidPageSizes(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
    }

    #[DataProvider('invalidPageSizes')]
    public function testNonPositivePageSizeIsRejected(int $limit): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->repository->findCoverlessAlbumIdsAfter(limit: $limit);
    }

    protected function tearDown(): void
    {
        if (isset($this->library)) {
            $this->observer->executeStatement('UPDATE albums SET cover_image_id = NULL WHERE library_id = ?', [$this->library->getId()->toString()]);
            if (isset($this->cover)) {
                $this->observer->executeStatement('DELETE FROM images WHERE id = ?', [$this->cover->getId()->toString()]);
            }
            $this->observer->executeStatement('DELETE FROM libraries WHERE id = ?', [$this->library->getId()->toString()]);
        }
        if (isset($this->kernel)) {
            $this->kernel->shutdown();
        }
        if (isset($this->observer)) {
            $this->observer->close();
        }
    }

    private function id(int $number): Uuid
    {
        return Uuid::fromString(sprintf('10000000-0000-4000-8000-%012x', $number));
    }

    /** @param list<int> $numbers
     * @return list<string>
     */
    private function strings(array $numbers): array
    {
        return array_map(fn (int $number): string => $this->id($number)->toString(), $numbers);
    }

    /** @param list<Uuid> $ids
     * @return list<string>
     */
    private function stringsFromIds(array $ids): array
    {
        return array_map(static fn (Uuid $id): string => $id->toString(), $ids);
    }
}
