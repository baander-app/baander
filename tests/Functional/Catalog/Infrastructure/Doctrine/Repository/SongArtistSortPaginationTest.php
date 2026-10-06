<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalog\Infrastructure\Doctrine\Repository;

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

/**
 * Artist-sorted song pages use the displayed artist: the alphabetically first
 * primary artist, or an empty key for a song without one. Featured artists
 * must not move a song, and keyset cursors must use the same key as the ORDER BY.
 */
final class SongArtistSortPaginationTest extends TestCase
{
    /** @var array<string, string> song title => expected sort key */
    private const SONGS = [
        // Featured artist "Aaron" sorts before every primary artist.
        'Song Zed' => 'Zed',
        'Song Mia' => 'Mia',
        'Song Bea' => 'Bea',
        'Song Kai' => 'Kai',
        'Song Kai Two' => 'Kai',
        'Song No Primary' => '',
    ];

    private LibraryReadScope $scope;

    protected function setUp(): void
    {
        parent::setUp();
        $library = new LibraryEntity('Sort Library', 'sort-library', '/media/sort', 'music', 'local');
        $album = new AlbumEntity(new PublicId(), $library, 'Sort Album', 'album');
        $featured = new ArtistEntity(new PublicId(), 'Aaron Featured');
        $this->entityManager->persist($library);
        $this->entityManager->persist($album);
        $this->entityManager->persist($featured);

        $artists = [];
        $track = 1;
        foreach (self::SONGS as $title => $primaryName) {
            $song = new SongEntity(new PublicId(), $album, $title, '/media/sort/' . $track . '.flac', $track, 'audio/flac');
            ++$track;
            $this->entityManager->persist($song);
            $this->entityManager->persist(new ArtistSongEntity($featured, $song, 'featured'));
            if ($primaryName === '') {
                continue;
            }
            $artists[$primaryName] ??= new ArtistEntity(new PublicId(), $primaryName);
            $this->entityManager->persist($artists[$primaryName]);
            $this->entityManager->persist(new ArtistSongEntity($artists[$primaryName], $song, 'primary'));
        }
        // A second, later-sorting primary artist does not change the key.
        $this->entityManager->flush();
        $bea = $this->entityManager->getRepository(SongEntity::class)->findOneBy(['title' => 'Song Bea']);
        self::assertInstanceOf(SongEntity::class, $bea);
        $this->entityManager->persist(new ArtistSongEntity($artists['Zed'], $bea, 'primary'));
        $this->entityManager->flush();
        $this->entityManager->clear();

        $this->scope = LibraryReadScope::restricted([$library->getId()]);
    }

    public function testArtistSortedPagesReturnEverySongExactlyOnceInDisplayedArtistOrder(): void
    {
        $songs = static::getContainer()->get(SongRepositoryInterface::class);
        $codec = static::getContainer()->get(CursorCodec::class);

        $seen = [];
        $cursor = null;
        for ($page = 0; $page < 10; ++$page) {
            $options = SearchOptions::create('', limit: 2)->withSort('artist', 'asc')->withCursor($cursor);
            $result = $songs->searchVisibleWithCursor($options, $this->scope);
            self::assertSame(count(self::SONGS), $result->getTotal());
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

        self::assertSame(count(self::SONGS), count($seen), 'Songs seen: ' . implode(', ', $seen));
        self::assertSame(count(self::SONGS), count(array_unique($seen)), 'Songs seen: ' . implode(', ', $seen));

        $keys = array_map(static fn (string $title): string => self::SONGS[$title], $seen);
        $expected = $keys;
        sort($expected, SORT_STRING);
        self::assertSame($expected, $keys);
    }
}
