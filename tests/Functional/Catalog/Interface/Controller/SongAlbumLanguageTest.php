<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalog\Interface\Controller;

use App\Auth\Domain\Model\User;
use App\Catalog\Infrastructure\Doctrine\Entity\AlbumEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\SongEntity;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Shared\Domain\Model\PublicId;
use App\Tests\Functional\TestCase;

/**
 * Song payloads carry the stored language of their album, unchanged, so the
 * client can pick glyph forms for the song's text.
 */
final class SongAlbumLanguageTest extends TestCase
{
    private User $admin;

    private PublicId $japaneseSong;

    private PublicId $untaggedSong;

    protected function setUp(): void
    {
        parent::setUp();
        $library = new LibraryEntity('Language Library', 'language-library', '/media/language', 'music', 'local');
        $this->entityManager->persist($library);

        $japanese = new AlbumEntity(new PublicId(), $library, '直', 'album');
        $japanese->setLanguage('jpn');
        $this->entityManager->persist($japanese);
        $untagged = new AlbumEntity(new PublicId(), $library, 'Untagged', 'album');
        $this->entityManager->persist($untagged);

        $this->japaneseSong = new PublicId();
        $this->untaggedSong = new PublicId();
        $this->entityManager->persist(new SongEntity($this->japaneseSong, $japanese, '続きを見る', '/media/language/1.flac', 1, 'audio/flac'));
        $this->entityManager->persist(new SongEntity($this->untaggedSong, $untagged, 'Plain', '/media/language/2.flac', 2, 'audio/flac'));
        $this->entityManager->flush();
        $this->entityManager->clear();

        $this->admin = $this->createAdminUser();
    }

    public function testSongListCarriesEachSongsAlbumLanguage(): void
    {
        $body = $this->assertJsonResponse($this->authenticatedRequest('GET', '/api/songs/?sort=title&order=asc', $this->admin), 200, 'data');

        self::assertSame(
            [$this->untaggedSong->toString() => null, $this->japaneseSong->toString() => 'jpn'],
            array_column($body['data'], 'albumLanguage', 'publicId'),
        );
        self::assertSame(
            [$this->untaggedSong->toString() => 'Untagged', $this->japaneseSong->toString() => '直'],
            array_column($body['data'], 'albumName', 'publicId'),
        );
    }

    public function testSongDetailCarriesItsAlbumLanguage(): void
    {
        $body = $this->assertJsonResponse(
            $this->authenticatedRequest('GET', '/api/songs/' . $this->japaneseSong->toString(), $this->admin),
            200,
            'data',
        );

        self::assertSame('jpn', $body['data']['albumLanguage']);
    }

    public function testPlaylistSongsCarryTheirAlbumLanguage(): void
    {
        $created = $this->assertJsonResponse(
            $this->authenticatedRequest('POST', '/api/playlists/', $this->admin, ['name' => 'Languages']),
            201,
        );
        $playlist = $created['publicId'];
        foreach ([$this->japaneseSong, $this->untaggedSong] as $song) {
            $this->assertJsonResponse(
                $this->authenticatedRequest('POST', '/api/playlists/' . $playlist . '/songs', $this->admin, ['songId' => $song->toString()]),
                201,
            );
        }

        $body = $this->assertJsonResponse($this->authenticatedRequest('GET', '/api/playlists/' . $playlist, $this->admin), 200, 'data');

        self::assertSame(
            [$this->japaneseSong->toString() => 'jpn', $this->untaggedSong->toString() => null],
            array_column($body['data']['songs'], 'albumLanguage', 'publicId'),
        );
    }
}
