<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Activity\Domain\Model\MediaActivity;
use App\Activity\Domain\Repository\MediaActivityRepositoryInterface;
use App\Auth\Domain\Model\User;
use App\Catalog\Application\Port\MoviePortInterface;
use App\Catalog\Domain\Model\Movie;
use App\Catalog\Infrastructure\Doctrine\Entity\AlbumEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\ArtistEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\SongEntity;
use App\Library\Application\Port\LibraryAccessPortInterface;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Media\Infrastructure\Doctrine\Entity\ImageEntity;
use App\Shared\Domain\Model\PublicId;
use App\Tests\Functional\TestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Activity history follows the viewer's current library access: once a grant is revoked,
 * the activity stays in the history but names nothing from the revoked library.
 */
final class ActivityLibraryScopeTest extends TestCase
{
    public function testARevokedLibrarysSongLeavesItsActivityWithoutTitlesIdsOrCover(): void
    {
        $member = $this->createTestUser();
        $kept = $this->songIn($this->library('kept', 'music'), 'Kept track', 'Kept album');
        $revoked = $this->songIn($this->library('revoked', 'music'), 'Revoked track', 'Revoked album');
        $artist = new ArtistEntity(new PublicId(), 'Revoked artist');
        $this->entityManager->persist($artist);
        $this->entityManager->flush();
        $access = $this->service(LibraryAccessPortInterface::class);
        $access->grant($member->getId(), $kept['library']->getId());
        $access->grant($member->getId(), $revoked['library']->getId());
        $keptPlay = $this->record(MediaActivity::create(
            $member->getId(),
            'play',
            $kept['song']->getId(),
            $kept['album']->getId(),
        ));
        $revokedPlay = $this->record(MediaActivity::create(
            $member->getId(),
            'play',
            $revoked['song']->getId(),
            $revoked['album']->getId(),
            $artist->getId(),
        ));
        $before = $this->history($member, 'limit=2');
        self::assertSame('Revoked track', $before[$revokedPlay]['songTitle']);

        $access->revoke($member->getId(), $revoked['library']->getId());
        $this->entityManager->clear();
        $response = $this->authenticatedRequest('GET', '/api/activity/history?limit=2', $member);
        $after = $this->rows($response);

        self::assertEqualsCanonicalizing(
            [$keptPlay, $revokedPlay],
            array_keys($after),
            'A row outside the viewer\'s access keeps its place on the page.',
        );
        self::assertSame($kept['song']->getPublicId()->toString(), $after[$keptPlay]['songId']);
        self::assertSame('Kept track', $after[$keptPlay]['songTitle']);
        self::assertSame('Kept album', $after[$keptPlay]['albumTitle']);

        $row = $after[$revokedPlay];
        self::assertSame('play', $row['activityType']);
        foreach (['songId', 'songPublicId', 'songTitle', 'albumId', 'albumPublicId', 'albumTitle', 'artistId', 'coverImage'] as $field) {
            self::assertNull($row[$field], $field);
        }
        self::assertFalse($row['songAvailable']);
        foreach ([
            'Revoked track',
            'Revoked album',
            $revoked['song']->getPublicId()->toString(),
            $revoked['album']->getPublicId()->toString(),
            $revoked['cover']->getPublicId()->toString(),
            $artist->getPublicId()->toString(),
        ] as $hidden) {
            self::assertStringNotContainsString($hidden, (string) $response->getContent());
        }
    }

    public function testARevokedLibrarysMovieLeavesItsActivityWithoutTitleIdOrPoster(): void
    {
        $member = $this->createTestUser();
        $keptLibrary = $this->library('kept-movies', 'movie');
        $revokedLibrary = $this->library('revoked-movies', 'movie');
        $kept = $this->movieIn($keptLibrary, 'Kept movie', 'https://images.baander.app/kept-poster.jpg');
        $revoked = $this->movieIn($revokedLibrary, 'Revoked movie', 'https://images.baander.app/revoked-poster.jpg');
        $access = $this->service(LibraryAccessPortInterface::class);
        $access->grant($member->getId(), $keptLibrary->getId());
        $access->grant($member->getId(), $revokedLibrary->getId());
        $keptPlay = $this->record(MediaActivity::create($member->getId(), 'play', movieId: $kept->getId()));
        $revokedPlay = $this->record(MediaActivity::create($member->getId(), 'play', movieId: $revoked->getId()));
        self::assertSame('Revoked movie', $this->history($member, '')[$revokedPlay]['movieTitle']);

        $access->revoke($member->getId(), $revokedLibrary->getId());
        $this->entityManager->clear();
        $response = $this->authenticatedRequest('GET', '/api/activity/history', $member);
        $after = $this->rows($response);

        self::assertSame($kept->getPublicId()->toString(), $after[$keptPlay]['movieId']);
        self::assertSame('Kept movie', $after[$keptPlay]['movieTitle']);
        self::assertSame(['url' => 'https://images.baander.app/kept-poster.jpg'], $after[$keptPlay]['posterImage']);
        foreach (['movieId', 'moviePublicId', 'movieTitle', 'posterImage'] as $field) {
            self::assertNull($after[$revokedPlay][$field] ?? null, $field);
        }
        foreach (['Revoked movie', 'revoked-poster.jpg', $revoked->getPublicId()->toString()] as $hidden) {
            self::assertStringNotContainsString($hidden, (string) $response->getContent());
        }
    }

    private function library(string $name, string $type): LibraryEntity
    {
        $suffix = random_bytes(4) |> bin2hex(...);
        $library = new LibraryEntity(
            'Library ' . $name,
            $name . '-' . $suffix,
            '/media/' . $name . '-' . $suffix,
            $type,
            'local',
        );
        $this->entityManager->persist($library);
        $this->entityManager->flush();

        return $library;
    }

    /** @return array{library: LibraryEntity, album: AlbumEntity, cover: ImageEntity, song: SongEntity} */
    private function songIn(LibraryEntity $library, string $title, string $albumTitle): array
    {
        $album = new AlbumEntity(new PublicId(), $library, $albumTitle, 'album');
        $cover = new ImageEntity(
            '/images/' . $library->getSlug() . '.webp',
            'webp',
            'image/webp',
            new PublicId(),
            32,
            2,
            2,
            'album',
        );
        $album->setCoverImage($cover);
        $song = new SongEntity(new PublicId(), $album, $title, $library->getPath() . '/1.flac', 1_000, 'audio/flac');
        foreach ([$cover, $album, $song] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();

        return ['library' => $library, 'album' => $album, 'cover' => $cover, 'song' => $song];
    }

    private function movieIn(LibraryEntity $library, string $title, string $posterUrl): Movie
    {
        $movie = Movie::create($library->getId(), $title, 2026);
        $movie->updateMetadata(posterUrl: $posterUrl);
        $this->service(MoviePortInterface::class)->save($movie);

        return $movie;
    }

    /** Records the activity and returns its public ID. */
    private function record(MediaActivity $activity): string
    {
        $this->service(MediaActivityRepositoryInterface::class)->save($activity);

        return $activity->getPublicId()->toString();
    }

    /** @return array<string, array<string, mixed>> history rows keyed by activity public ID */
    private function history(User $user, string $query): array
    {
        return $this->rows($this->authenticatedRequest(
            'GET',
            '/api/activity/history' . ($query === '' ? '' : '?' . $query),
            $user,
        ));
    }

    /** @return array<string, array<string, mixed>> history rows keyed by activity public ID */
    private function rows(Response $response): array
    {
        return array_column($this->assertJsonResponse($response, 200, 'data')['data'], null, 'publicId');
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    private function service(string $id): object
    {
        $service = static::getContainer()->get($id);
        self::assertInstanceOf($id, $service);

        return $service;
    }
}
