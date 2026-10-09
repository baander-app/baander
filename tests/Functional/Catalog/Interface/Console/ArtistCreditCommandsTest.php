<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalog\Interface\Console;

use App\Catalog\Infrastructure\Doctrine\Entity\AlbumEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\ArtistAlbumEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\ArtistEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\ArtistSongEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\SongEntity;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Functional\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Response;

/**
 * The artist credit routes and app:artist:song:* and app:artist:album:* reach the same use cases
 * and report the same outcomes.
 */
final class ArtistCreditCommandsTest extends TestCase
{
    private ArtistEntity $artist;
    private AlbumEntity $album;
    private SongEntity $song;

    protected function setUp(): void
    {
        parent::setUp();

        $suffix = bin2hex(random_bytes(8));
        $library = new LibraryEntity('Credit fixture', 'credit-' . $suffix, '/tmp/credit-' . $suffix, 'music', 'local');
        $this->album = new AlbumEntity(new PublicId(), $library, 'Credit fixture album', 'album');
        $this->song = new SongEntity(new PublicId(), $this->album, 'Credit fixture song', '/tmp/credit-' . $suffix . '/song.flac', 1, 'audio/flac');
        $this->artist = new ArtistEntity(new PublicId(), 'Credit fixture artist ' . $suffix);
        foreach ([$library, $this->album, $this->song, $this->artist] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();
    }

    public function testAddingACreditForAnUnknownArtistSongOrAlbumIsNotFoundOnBothPaths(): void
    {
        $admin = $this->createAdminUser();
        $unknown = (new Uuid())->toString();
        $base = '/api/artists/' . $this->artist->getPublicId();

        $song = $this->assertJsonResponse($this->authenticatedRequest('POST', $base . '/songs', $admin, ['songId' => $unknown, 'role' => 'primary']), 404);
        self::assertSame(sprintf('Song "%s" not found.', $unknown), $song['error']['message']);
        $album = $this->assertJsonResponse($this->authenticatedRequest('POST', $base . '/albums', $admin, ['albumId' => $unknown, 'role' => 'primary']), 404);
        self::assertSame(sprintf('Album "%s" not found.', $unknown), $album['error']['message']);
        $artist = (string) new PublicId();
        $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/artists/' . $artist . '/songs', $admin, ['songId' => $this->songId(), 'role' => 'primary']), 404);

        foreach ([
            'app:artist:song:add' => ['song-id', sprintf('Song "%s" not found.', $unknown)],
            'app:artist:album:add' => ['album-id', sprintf('Album "%s" not found.', $unknown)],
        ] as $name => [$argument, $message]) {
            $command = $this->command($name);
            self::assertSame(Command::FAILURE, $command->execute(['public-id' => $this->publicId(), $argument => $unknown, 'role' => 'primary']), $name);
            self::assertStringContainsString($message, $this->normalized($command->getDisplay()), $name);
        }
        $unknownArtist = $this->command('app:artist:song:add');
        self::assertSame(Command::FAILURE, $unknownArtist->execute(['public-id' => $artist, 'song-id' => $this->songId(), 'role' => 'primary']));

        self::assertSame([[], []], [$this->songRoles(), $this->albumRoles()]);
    }

    public function testAddingTheSameCreditTwiceLeavesOneLinkAndPrintsNothingWithJson(): void
    {
        $admin = $this->createAdminUser();
        $base = '/api/artists/' . $this->artist->getPublicId();

        $this->assertNoContent($this->authenticatedRequest('POST', $base . '/songs', $admin, ['songId' => $this->songId(), 'role' => 'primary']));
        $song = $this->command('app:artist:song:add');
        self::assertSame(Command::SUCCESS, $song->execute(['public-id' => $this->publicId(), 'song-id' => $this->songId(), 'role' => 'primary', '--json' => true]), $song->getDisplay());
        self::assertSame('', $song->getDisplay());
        self::assertSame(['primary'], $this->songRoles());

        $album = $this->command('app:artist:album:add');
        self::assertSame(Command::SUCCESS, $album->execute(['public-id' => $this->publicId(), 'album-id' => $this->albumId(), 'role' => 'primary']), $album->getDisplay());
        $this->assertNoContent($this->authenticatedRequest('POST', $base . '/albums', $admin, ['albumId' => $this->albumId(), 'role' => 'primary']));
        self::assertSame(['primary'], $this->albumRoles());
    }

    public function testRemovingACreditThatDoesNotExistIsNotFoundOnBothPaths(): void
    {
        $admin = $this->createAdminUser();
        $base = '/api/artists/' . $this->artist->getPublicId();
        $message = sprintf('Artist "%s" has no credit on song "%s".', $this->publicId(), $this->songId());

        $error = $this->assertJsonResponse($this->authenticatedRequest('DELETE', $base . '/songs/' . $this->songId(), $admin), 404);
        self::assertSame($message, $error['error']['message']);
        $remove = $this->command('app:artist:song:remove');
        self::assertSame(Command::FAILURE, $remove->execute(['public-id' => $this->publicId(), 'song-id' => $this->songId()]));
        self::assertStringContainsString($message, $this->normalized($remove->getDisplay()));
        $this->assertJsonResponse($this->authenticatedRequest('DELETE', $base . '/albums/' . $this->albumId(), $admin), 404);

        $this->link('song', 'primary');
        $this->link('album', 'primary');
        $removed = $this->command('app:artist:song:remove');
        self::assertSame(Command::SUCCESS, $removed->execute(['public-id' => $this->publicId(), 'song-id' => $this->songId()]), $removed->getDisplay());
        $this->assertNoContent($this->authenticatedRequest('DELETE', $base . '/albums/' . $this->albumId(), $admin));
        self::assertSame([[], []], [$this->songRoles(), $this->albumRoles()]);

        $again = $this->command('app:artist:album:remove');
        self::assertSame(Command::FAILURE, $again->execute(['public-id' => $this->publicId(), 'album-id' => $this->albumId()]));
    }

    public function testChangingARoleToOneThePairAlreadyHoldsRemovesTheNamedLink(): void
    {
        $admin = $this->createAdminUser();
        $this->link('song', 'featured');
        $this->link('song', 'producer');
        $this->link('album', 'featured');
        $this->link('album', 'producer');

        $this->assertNoContent($this->authenticatedRequest(
            'PATCH',
            '/api/artists/' . $this->artist->getPublicId() . '/songs/' . $this->songId(),
            $admin,
            ['role' => 'producer', 'currentRole' => 'featured'],
        ));
        self::assertSame(['producer'], $this->songRoles());

        $album = $this->command('app:artist:album:role');
        self::assertSame(Command::SUCCESS, $album->execute([
            'public-id' => $this->publicId(),
            'album-id' => $this->albumId(),
            'role' => 'producer',
            '--from' => 'featured',
        ]), $album->getDisplay());
        self::assertSame(['producer'], $this->albumRoles());
    }

    public function testChangingARoleOnAPairWithSeveralRolesNeedsTheCurrentRole(): void
    {
        $admin = $this->createAdminUser();
        $this->link('song', 'featured');
        $this->link('song', 'producer');

        $error = $this->assertJsonResponse($this->authenticatedRequest(
            'PATCH',
            '/api/artists/' . $this->artist->getPublicId() . '/songs/' . $this->songId(),
            $admin,
            ['role' => 'primary'],
        ), 422);
        self::assertStringContainsString('featured, producer', $error['error']['message']);

        $role = $this->command('app:artist:song:role');
        self::assertSame(Command::INVALID, $role->execute(['public-id' => $this->publicId(), 'song-id' => $this->songId(), 'role' => 'primary']));
        self::assertStringContainsString('featured, producer', $this->normalized($role->getDisplay()));
        self::assertSame(['featured', 'producer'], $this->songRoles());

        $single = $this->command('app:artist:album:role');
        $this->link('album', 'primary');
        self::assertSame(Command::SUCCESS, $single->execute(['public-id' => $this->publicId(), 'album-id' => $this->albumId(), 'role' => 'featured']), $single->getDisplay());
        self::assertSame(['featured'], $this->albumRoles());
    }

    public function testAnInvalidRoleOrIdentifierIsInvalidInputOnBothPaths(): void
    {
        $admin = $this->createAdminUser();
        $base = '/api/artists/' . $this->artist->getPublicId();

        $role = $this->assertJsonResponse($this->authenticatedRequest('POST', $base . '/songs', $admin, ['songId' => $this->songId(), 'role' => 'singer']), 422);
        self::assertStringStartsWith('Invalid role "singer".', $role['error']['message']);
        $this->assertJsonResponse($this->authenticatedRequest('POST', $base . '/albums', $admin, ['albumId' => 'not-a-uuid', 'role' => 'primary']), 422);
        $this->assertJsonResponse($this->authenticatedRequest('DELETE', $base . '/songs/not-a-uuid', $admin), 422);
        $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/artists/not-a-public-id/songs', $admin, ['songId' => $this->songId(), 'role' => 'primary']), 422);

        $add = $this->command('app:artist:song:add');
        self::assertSame(Command::INVALID, $add->execute(['public-id' => $this->publicId(), 'song-id' => $this->songId(), 'role' => 'singer']));
        self::assertStringContainsString('Invalid role "singer".', $add->getDisplay());
        $malformed = $this->command('app:artist:album:remove');
        self::assertSame(Command::INVALID, $malformed->execute(['public-id' => $this->publicId(), 'album-id' => 'not-a-uuid']));

        self::assertSame([[], []], [$this->songRoles(), $this->albumRoles()]);
    }

    private function link(string $kind, string $role): void
    {
        $artist = $this->entityManager->find(ArtistEntity::class, $this->artist->getId());
        self::assertInstanceOf(ArtistEntity::class, $artist);
        if ($kind === 'song') {
            $song = $this->entityManager->find(SongEntity::class, $this->song->getId());
            self::assertInstanceOf(SongEntity::class, $song);
            $this->entityManager->persist(new ArtistSongEntity($artist, $song, $role));
        } else {
            $album = $this->entityManager->find(AlbumEntity::class, $this->album->getId());
            self::assertInstanceOf(AlbumEntity::class, $album);
            $this->entityManager->persist(new ArtistAlbumEntity($artist, $album, $role));
        }
        $this->entityManager->flush();
    }

    /** @return list<?string> */
    private function songRoles(): array
    {
        $this->entityManager->clear();
        $roles = array_map(
            static fn (ArtistSongEntity $link): ?string => $link->getRole(),
            $this->entityManager->getRepository(ArtistSongEntity::class)->findBy(['artist' => $this->artist->getId()]),
        );
        sort($roles);

        return $roles;
    }

    /** @return list<?string> */
    private function albumRoles(): array
    {
        $this->entityManager->clear();
        $roles = array_map(
            static fn (ArtistAlbumEntity $link): ?string => $link->getRole(),
            $this->entityManager->getRepository(ArtistAlbumEntity::class)->findBy(['artist' => $this->artist->getId()]),
        );
        sort($roles);

        return $roles;
    }

    private function publicId(): string
    {
        return (string) $this->artist->getPublicId();
    }

    private function songId(): string
    {
        return $this->song->getId()->toString();
    }

    private function albumId(): string
    {
        return $this->album->getId()->toString();
    }

    private function assertNoContent(Response $response): void
    {
        self::assertSame(204, $response->getStatusCode(), (string) $response->getContent());
    }

    private function normalized(string $display): string
    {
        return (string) preg_replace('/\s+/', ' ', $display);
    }

    private function command(string $name): CommandTester
    {
        return new CommandTester((new Application($this->client->getKernel()))->find($name));
    }
}
