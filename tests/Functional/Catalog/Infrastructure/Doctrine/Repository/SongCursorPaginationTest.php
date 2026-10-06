<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalog\Infrastructure\Doctrine\Repository;

use App\Catalog\Domain\Model\Song;
use App\Catalog\Domain\Repository\SongRepositoryInterface;
use App\Catalog\Infrastructure\Doctrine\Entity\AlbumEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\ArtistEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\ArtistSongEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\SongEntity;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\SearchOptions;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use App\Shared\Infrastructure\Pagination\CursorCodec;
use App\Tests\Functional\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Walks every song sort field in both directions through all cursor pages.
 *
 * Ascending order is (key present, key, id): a missing key (no album year, no
 * primary artist) sorts before every value, and equal keys fall back to the
 * song id. Descending order is the exact reverse.
 */
final class SongCursorPaginationTest extends TestCase
{
    /** @var array<string, array{title: string, year: int|null}> */
    private const ALBUMS = [
        'alpha' => ['title' => 'Alpha Album', 'year' => 2001],
        'beta' => ['title' => 'Beta Album', 'year' => 1999],
        'gamma' => ['title' => 'Gamma Album', 'year' => null],
        'delta' => ['title' => 'Delta Album', 'year' => 2001],
        'epsilon' => ['title' => 'Epsilon Album', 'year' => null],
    ];

    /** @var list<array{title: string, album: string, artist: string|null, added: string}> */
    private const SONGS = [
        ['title' => 'Track Delta', 'album' => 'alpha', 'artist' => 'Mia', 'added' => '2026-01-01 10:00:00'],
        ['title' => 'Track Alpha', 'album' => 'beta', 'artist' => 'Bea', 'added' => '2026-01-02 10:00:00'],
        ['title' => 'Track Same', 'album' => 'gamma', 'artist' => null, 'added' => '2026-01-01 10:00:00'],
        ['title' => 'Track Same', 'album' => 'delta', 'artist' => 'Mia', 'added' => '2026-01-03 10:00:00'],
        ['title' => 'Track Charlie', 'album' => 'epsilon', 'artist' => 'Zed', 'added' => '2026-01-02 10:00:00'],
        ['title' => 'Track Bravo', 'album' => 'alpha', 'artist' => null, 'added' => '2026-01-03 10:00:00'],
        ['title' => 'Track Echo', 'album' => 'gamma', 'artist' => 'Bea', 'added' => '2026-01-01 10:00:00'],
    ];

    private LibraryReadScope $scope;

    /** @var array<string, array<string, int|string|null>> song id => sort field => key */
    private array $keys = [];

    protected function setUp(): void
    {
        parent::setUp();
        $library = new LibraryEntity('Cursor Library', 'cursor-library', '/media/cursor', 'music', 'local');
        $this->entityManager->persist($library);

        $albums = [];
        foreach (self::ALBUMS as $name => $album) {
            $albums[$name] = new AlbumEntity(new PublicId(), $library, $album['title'], 'album');
            $albums[$name]->setYear($album['year']);
            $this->entityManager->persist($albums[$name]);
        }

        $artists = [];
        $added = [];
        foreach (self::SONGS as $index => $definition) {
            $song = new SongEntity(
                new PublicId(),
                $albums[$definition['album']],
                $definition['title'],
                '/media/cursor/' . $index . '.flac',
                $index + 1,
                'audio/flac',
            );
            $this->entityManager->persist($song);
            if ($definition['artist'] !== null) {
                $artists[$definition['artist']] ??= new ArtistEntity(new PublicId(), $definition['artist']);
                $this->entityManager->persist($artists[$definition['artist']]);
                $this->entityManager->persist(new ArtistSongEntity($artists[$definition['artist']], $song, 'primary'));
            }

            $id = $song->getId()->toString();
            $added[$id] = $definition['added'];
            $this->keys[$id] = [
                'title' => $definition['title'],
                'artist' => $definition['artist'] ?? '',
                'album' => self::ALBUMS[$definition['album']]['title'],
                'year' => self::ALBUMS[$definition['album']]['year'],
                'added' => $definition['added'],
            ];
        }
        $this->entityManager->flush();

        $connection = $this->entityManager->getConnection();
        foreach ($added as $id => $timestamp) {
            $connection->executeStatement('UPDATE songs SET created_at = :added WHERE id = :id', ['added' => $timestamp, 'id' => $id]);
        }
        $this->entityManager->clear();

        $this->scope = LibraryReadScope::restricted([$library->getId()]);
    }

    /** @return iterable<string, array{string, string}> */
    public static function sorts(): iterable
    {
        foreach (['title', 'artist', 'album', 'year', 'added'] as $field) {
            foreach (['asc', 'desc'] as $order) {
                yield $field . ' ' . $order => [$field, $order];
            }
        }
    }

    #[DataProvider('sorts')]
    public function testForwardPagesReturnEverySongOnceInSortOrder(string $field, string $order): void
    {
        $expected = $this->expectedOrder($field, $order);

        foreach ([1, 2, 3] as $limit) {
            $pages = $this->walkForward($field, $order, $limit);
            $seen = array_merge(...$pages);

            self::assertSame($expected, $seen, sprintf('%s %s, limit %d: %s', $field, $order, $limit, $this->describe($seen, $field)));
            self::assertCount((int) ceil(count($expected) / $limit), $pages, sprintf('%s %s, limit %d', $field, $order, $limit));
        }
    }

    #[DataProvider('sorts')]
    public function testPreviousCursorsWalkBackToTheFirstSong(string $field, string $order): void
    {
        $expected = $this->expectedOrder($field, $order);
        $songs = static::getContainer()->get(SongRepositoryInterface::class);
        $codec = static::getContainer()->get(CursorCodec::class);

        foreach ([1, 2, 3] as $limit) {
            $pages = $this->walkForward($field, $order, $limit, $lastPage);
            self::assertNotNull($lastPage);
            $seen = end($pages);
            self::assertIsArray($seen);

            $page = $lastPage;
            for ($guard = 0; $page->hasPreviousPage(); ++$guard) {
                self::assertLessThan(count($expected), $guard, 'Backward walk did not terminate.');
                $prev = $page->getPrevCursor();
                self::assertNotNull($prev);
                $options = SearchOptions::create('', limit: $limit)->withSort($field, $order)->withCursor($codec->decode($prev));
                $page = $songs->searchVisibleWithCursor($options, $this->scope);
                self::assertTrue($page->hasNextPage(), 'A page reached backwards has a next page.');
                $seen = array_merge($this->ids($page->getItems()), $seen);
            }

            self::assertSame($expected, $seen, sprintf('%s %s, limit %d backwards: %s', $field, $order, $limit, $this->describe($seen, $field)));
        }
    }

    /**
     * @param-out \App\Shared\Domain\Model\CursorPage|null $lastPage
     *
     * @return list<list<string>> song ids per page
     */
    private function walkForward(string $field, string $order, int $limit, mixed &$lastPage = null): array
    {
        $songs = static::getContainer()->get(SongRepositoryInterface::class);
        $codec = static::getContainer()->get(CursorCodec::class);

        $pages = [];
        $cursor = null;
        for ($guard = 0; $guard <= count(self::SONGS); ++$guard) {
            $options = SearchOptions::create('', limit: $limit)->withSort($field, $order)->withCursor($cursor);
            $page = $songs->searchVisibleWithCursor($options, $this->scope);
            self::assertSame(count(self::SONGS), $page->getTotal());
            self::assertSame($guard > 0, $page->hasPreviousPage(), 'Only pages after the first have a previous page.');
            $pages[] = $this->ids($page->getItems());
            $lastPage = $page;
            if (!$page->hasNextPage()) {
                return $pages;
            }
            $next = $page->getNextCursor();
            self::assertNotNull($next);
            $cursor = $codec->decode($next);
        }

        self::fail(sprintf('%s %s, limit %d: forward walk did not terminate.', $field, $order, $limit));
    }

    /** @return list<string> */
    private function expectedOrder(string $field, string $order): array
    {
        $ids = array_keys($this->keys);
        usort($ids, function (string $left, string $right) use ($field): int {
            $a = $this->keys[$left][$field];
            $b = $this->keys[$right][$field];

            return [$a !== null, $a, $left] <=> [$b !== null, $b, $right];
        });

        return $order === 'desc' ? array_reverse($ids) : $ids;
    }

    /**
     * @param list<Song> $songs
     *
     * @return list<string>
     */
    private function ids(array $songs): array
    {
        return array_map(static fn (Song $song): string => $song->getId()->toString(), $songs);
    }

    /** @param list<string> $ids */
    private function describe(array $ids, string $field): string
    {
        return implode(', ', array_map(
            fn (string $id): string => sprintf('%s', var_export($this->keys[$id][$field], true)),
            $ids,
        ));
    }
}
