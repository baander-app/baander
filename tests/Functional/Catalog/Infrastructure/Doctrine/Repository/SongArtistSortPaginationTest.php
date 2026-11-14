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
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use App\Shared\Infrastructure\Pagination\CursorCodec;
use App\Tests\Functional\TestCase;

/**
 * Artist-sorted song pages use the name the song list displays: the credited
 * name of the song's first primary credit by credited name, or an empty key
 * for a song without one. The credited artist's own name and featured credits
 * must not move a song, and keyset cursors must use the same key as the
 * ORDER BY.
 */
final class SongArtistSortPaginationTest extends TestCase
{
    /**
     * Song title => its primary credits as [the artist's own name, credited name].
     * One artist per own name, so "Zed" is credited under three names.
     *
     * @var array<string, list<array{string, string}>>
     */
    private const PRIMARY_CREDITS = [
        'Song Zed' => [['Zed', 'Zed']],
        'Song Mia' => [['Mia', 'Mia']],
        'Song Kai' => [['Kai', 'Kai']],
        'Song Kai Two' => [['Kai', 'Kai']],
        'Song Alias' => [['Zed', 'Cy']],
        'Song Duet' => [['Abe', 'Ola'], ['Zed', 'Lu']],
        'Song No Primary' => [],
    ];

    /** @var array<string, string> song title => expected sort key */
    private const KEYS = [
        'Song Zed' => 'Zed',
        'Song Mia' => 'Mia',
        'Song Kai' => 'Kai',
        'Song Kai Two' => 'Kai',
        // Sorts under its credited name, not under its artist "Zed".
        'Song Alias' => 'Cy',
        // "Lu" is the first credited name, though "Abe" is the first artist name.
        'Song Duet' => 'Lu',
        'Song No Primary' => '',
    ];

    private LibraryReadScope $scope;

    protected function setUp(): void
    {
        parent::setUp();
        $library = new LibraryEntity('Sort Library', 'sort-library', '/media/sort', 'music', 'local');
        $album = new AlbumEntity(new PublicId(), $library, 'Sort Album', 'album');
        // A featured credit sorts before every primary one and must not become the key.
        $featured = new ArtistEntity(new PublicId(), 'Aaron Featured');
        $this->entityManager->persist($library);
        $this->entityManager->persist($album);
        $this->entityManager->persist($featured);

        $artists = [];
        $track = 1;
        foreach (self::PRIMARY_CREDITS as $title => $credits) {
            $song = new SongEntity(new PublicId(), $album, $title, '/media/sort/' . $track . '.flac', $track, 'audio/flac');
            ++$track;
            $this->entityManager->persist($song);
            $this->entityManager->persist(new ArtistSongEntity($featured, $song, 'featured', 'Aaron Featured'));
            foreach ($credits as [$artistName, $creditedName]) {
                $artists[$artistName] ??= new ArtistEntity(new PublicId(), $artistName);
                $this->entityManager->persist($artists[$artistName]);
                $this->entityManager->persist(new ArtistSongEntity($artists[$artistName], $song, 'primary', $creditedName));
            }
        }
        $this->entityManager->flush();
        $this->entityManager->clear();

        $this->scope = LibraryReadScope::restricted([$library->getId()]);
    }

    public function testArtistSortedPagesReturnEverySongExactlyOnceInDisplayedNameOrder(): void
    {
        $songs = static::getContainer()->get(SongRepositoryInterface::class);
        $codec = static::getContainer()->get(CursorCodec::class);

        $seen = [];
        $cursor = null;
        for ($page = 0; $page < 10; ++$page) {
            $options = SearchOptions::create('', limit: 2)->withSort('artist', 'asc')->withCursor($cursor);
            $result = $songs->searchVisibleWithCursor($options, $this->scope);
            self::assertSame(count(self::KEYS), $result->getTotal());
            foreach ($result->getItems() as $song) {
                $seen[] = $song->getTitle();
            }
            if (!$result->hasNextPage()) {
                break;
            }
            $next = $result->getNextCursor();
            self::assertNotNull($next);
            $cursor = $codec->decode($next);
        }

        self::assertSame(count(self::KEYS), count($seen), 'Songs seen: ' . implode(', ', $seen));
        self::assertSame(count(self::KEYS), count(array_unique($seen)), 'Songs seen: ' . implode(', ', $seen));

        $keys = array_map(static fn (string $title): string => self::KEYS[$title], $seen);
        $expected = $keys;
        sort($expected, SORT_STRING);
        self::assertSame($expected, $keys, 'Songs seen: ' . implode(', ', $seen));
    }

    public function testTheSongListDisplaysTheNameItSortsBy(): void
    {
        $songs = static::getContainer()->get(SongRepositoryInterface::class);
        $items = $songs->searchVisibleWithCursor(SearchOptions::create('')->withSort('artist', 'asc'), $this->scope)->getItems();
        $displayed = $songs->getVisibleArtistNamesForSongs(array_map(static fn (Song $song): Uuid => $song->getId(), $items), $this->scope);

        $names = [];
        foreach ($items as $song) {
            $names[$song->getTitle()] = $displayed[$song->getId()->toString()] ?? '';
        }
        ksort($names);
        $expected = self::KEYS;
        ksort($expected);
        self::assertSame($expected, $names);
    }
}
