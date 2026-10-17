<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalog\Interface\Controller;

use App\Auth\Domain\Model\User;
use App\Catalog\Infrastructure\Doctrine\Entity\AlbumEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\SongEntity;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Shared\Domain\Model\Cursor;
use App\Shared\Domain\Model\CursorDirection;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Infrastructure\Pagination\CursorCodec;
use App\Tests\Functional\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The song list accepts only the sort fields and orders it implements, and a
 * cursor continues only the ordering that issued it.
 */
final class SongListSortTest extends TestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $library = new LibraryEntity('Sort Library', 'sort-library', '/media/sort', 'music', 'local');
        $this->entityManager->persist($library);
        $album = new AlbumEntity(new PublicId(), $library, 'Sort Album', 'album');
        $this->entityManager->persist($album);
        foreach (['Track A', 'Track B', 'Track C'] as $index => $title) {
            $this->entityManager->persist(new SongEntity(
                new PublicId(),
                $album,
                $title,
                '/media/sort/' . $index . '.flac',
                $index + 1,
                'audio/flac',
            ));
        }
        $this->entityManager->flush();
        $this->entityManager->clear();

        $this->admin = $this->createAdminUser();
    }

    /** @return iterable<string, array{string}> */
    public static function supportedSorts(): iterable
    {
        foreach (['title', 'artist', 'album', 'year', 'added'] as $field) {
            foreach (['asc', 'desc'] as $order) {
                yield $field . ' ' . $order => [sprintf('sort=%s&order=%s', $field, $order)];
            }
        }
        yield 'default' => [''];
        yield 'order without sort' => ['order=desc'];
    }

    #[DataProvider('supportedSorts')]
    public function testSupportedSortsListEverySong(string $query): void
    {
        $body = $this->assertJsonResponse($this->list($query), 200, 'data');

        self::assertCount(3, $body['data']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function unsupportedSorts(): iterable
    {
        yield 'column id' => ['sort=artistName', 'sort'];
        yield 'unsupported column' => ['sort=duration', 'sort'];
        yield 'wrong case' => ['sort=Title', 'sort'];
        yield 'array' => ['sort[]=title', 'sort'];
        yield 'unknown order' => ['sort=title&order=sideways', 'order'];
        yield 'uppercase order' => ['sort=title&order=DESC', 'order'];
        yield 'order alone' => ['order=up', 'order'];
        yield 'empty sort' => ['sort=', 'sort'];
    }

    #[DataProvider('unsupportedSorts')]
    public function testUnsupportedSortsAreRejected(string $query, string $parameter): void
    {
        $body = $this->assertJsonResponse($this->list($query), 400, 'error');

        self::assertSame(400, $body['error']['code']);
        self::assertSame([$parameter], array_keys($body['error']['details']));
        self::assertStringStartsWith($parameter . ' must be one of: ', $body['error']['details'][$parameter][0]);
    }

    public function testCursorContinuesTheOrderingThatIssuedIt(): void
    {
        $first = $this->assertJsonResponse($this->list('sort=title&order=desc&limit=1'), 200, 'meta');
        self::assertSame(['Track C'], array_column($first['data'], 'title'));
        $cursor = $first['meta']['next_cursor'];
        self::assertIsString($cursor);

        $second = $this->assertJsonResponse($this->list('sort=title&order=desc&limit=1&cursor=' . $cursor), 200, 'meta');

        self::assertSame(['Track B'], array_column($second['data'], 'title'));
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function addedOrders(): iterable
    {
        yield 'asc' => ['asc', ['Track B', 'Track C', 'Track A']];
        yield 'desc' => ['desc', ['Track A', 'Track C', 'Track B']];
    }

    /**
     * Songs added within one second differ only in their microseconds, so the
     * "added" cursor must carry them to page without repeats or gaps.
     *
     * @param list<string> $expected
     */
    #[DataProvider('addedOrders')]
    public function testAddedCursorPagesSongsAddedWithinOneSecond(string $order, array $expected): void
    {
        $connection = $this->entityManager->getConnection();
        foreach (['Track A' => '.900000', 'Track B' => '.100000', 'Track C' => '.500000'] as $title => $fraction) {
            $connection->executeStatement(
                'UPDATE songs SET created_at = :added WHERE title = :title',
                ['added' => '2026-10-10 12:00:00' . $fraction . '+00:00', 'title' => $title],
            );
        }

        $titles = [];
        $cursor = null;
        for ($guard = 0; $guard <= count($expected); ++$guard) {
            $query = sprintf('sort=added&order=%s&limit=1', $order) . ($cursor !== null ? '&cursor=' . $cursor : '');
            $body = $this->assertJsonResponse($this->list($query), 200, 'meta');
            $titles = [...$titles, ...array_column($body['data'], 'title')];
            $cursor = $body['meta']['next_cursor'];
            if ($cursor === null) {
                break;
            }
            self::assertIsString($cursor);
        }

        self::assertSame($expected, $titles);
    }

    public function testDefaultOrderingCursorContinuesExplicitTitleAscending(): void
    {
        $first = $this->assertJsonResponse($this->list('limit=1'), 200, 'meta');
        $cursor = $first['meta']['next_cursor'];
        self::assertIsString($cursor);

        $second = $this->assertJsonResponse($this->list('sort=title&order=asc&limit=1&cursor=' . $cursor), 200, 'meta');

        self::assertSame(['Track B'], array_column($second['data'], 'title'));
    }

    /** @return iterable<string, array{string}> */
    public static function otherOrderings(): iterable
    {
        yield 'other order' => ['sort=title&order=asc'];
        yield 'other field' => ['sort=album&order=desc'];
        yield 'default ordering' => [''];
    }

    #[DataProvider('otherOrderings')]
    public function testCursorFromAnotherOrderingIsRejected(string $query): void
    {
        $first = $this->assertJsonResponse($this->list('sort=title&order=desc&limit=1'), 200, 'meta');
        $cursor = $first['meta']['next_cursor'];
        self::assertIsString($cursor);

        $body = $this->assertJsonResponse($this->list($query . '&limit=1&cursor=' . $cursor), 400, 'error');

        self::assertSame(400, $body['error']['code']);
        self::assertSame(['cursor'], array_keys($body['error']['details']));
    }

    public function testCursorWithInternalUuidIdentityIsRejected(): void
    {
        $codec = static::getContainer()->get(CursorCodec::class);
        $cursor = $codec->encode(Cursor::create(CursorDirection::Next, [
            'sort' => 'Track A', 'id' => (new \App\Shared\Domain\Model\Uuid())->toString(), 'binding' => 'title:asc',
        ]));
        $this->assertJsonResponse($this->list('limit=1&cursor=' . $cursor), 400, 'error');
    }

    public function testCursorWithoutOrderingIsRejected(): void
    {
        $codec = static::getContainer()->get(CursorCodec::class);
        $cursor = $codec->encode(Cursor::create(CursorDirection::Next, ['sort' => 'Track A', 'id' => '00000000-0000-7000-8000-000000000000']));

        $this->assertJsonResponse($this->list('limit=1&cursor=' . $cursor), 400, 'error');
    }

    private function list(string $query): \Symfony\Component\HttpFoundation\Response
    {
        return $this->authenticatedRequest('GET', '/api/songs/?' . $query, $this->admin);
    }
}
