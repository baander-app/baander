<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalog\Interface\Controller;

use App\Auth\Domain\Model\User;
use App\Catalog\Infrastructure\Doctrine\Entity\AlbumEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\SongEntity;
use App\Library\Application\Port\LibraryAccessPortInterface;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Shared\Domain\Model\PublicId;
use App\Tests\Functional\TestCase;

/** Only administrators read the file path of a song; a member with access reads the rest. */
final class SongServerPathTest extends TestCase
{
    private string $path;

    private PublicId $song;

    private PublicId $album;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $suffix = random_bytes(4) |> bin2hex(...);
        $library = new LibraryEntity('Path Library', 'path-library-' . $suffix, '/media/path-library-' . $suffix, 'music', 'local');
        $album = new AlbumEntity(new PublicId(), $library, 'Path Album', 'album');
        $this->path = $library->getPath() . '/01 Track.flac';
        $song = new SongEntity(new PublicId(), $album, 'Path Track', $this->path, 1_000, 'audio/flac');
        foreach ([$library, $album, $song] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();
        $this->song = $song->getPublicId();
        $this->album = $album->getPublicId();
        $this->member = $this->createTestUser();
        static::getContainer()->get(LibraryAccessPortInterface::class)->grant($this->member->getId(), $library->getId());
        $this->entityManager->clear();
    }

    public function testAMemberReadsSongsWithoutTheirFilePath(): void
    {
        foreach ($this->songReads($this->member) as $route => $song) {
            self::assertSame('Path Track', $song['title'], $route);
            self::assertArrayNotHasKey('path', $song, $route);
            self::assertStringNotContainsString('path-library-', json_encode($song, JSON_THROW_ON_ERROR), $route);
        }
    }

    public function testAnAdministratorReadsTheFilePathOfEachSong(): void
    {
        foreach ($this->songReads($this->createAdminUser()) as $route => $song) {
            self::assertSame($this->path, $song['path'], $route);
        }
    }

    /** @return array<string, array<string, mixed>> the song as each member route returns it, keyed by route */
    private function songReads(User $viewer): array
    {
        $shown = $this->data('/api/songs/' . $this->song->toString(), $viewer);
        $listed = array_column($this->data('/api/songs/?limit=100', $viewer), null, 'publicId');
        $album = $this->data('/api/albums/' . $this->album->toString(), $viewer);

        return [
            'song detail' => $shown,
            'song list' => $listed[$this->song->toString()],
            'album tracks' => array_column($album['songs'], null, 'publicId')[$this->song->toString()],
        ];
    }

    /** @return array<array-key, mixed> */
    private function data(string $uri, User $viewer): array
    {
        return $this->assertJsonResponse($this->authenticatedRequest('GET', $uri, $viewer), 200, 'data')['data'];
    }
}
