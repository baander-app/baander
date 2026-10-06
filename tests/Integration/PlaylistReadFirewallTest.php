<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\AccessTokenEntity;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\ClientEntity;
use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\AlbumEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\ArtistEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\ArtistSongEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\SongEntity;
use App\Library\Application\Port\LibraryAccessPortInterface;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Library\Infrastructure\Doctrine\Entity\UserLibraryAccessEntity;
use App\Playlist\Infrastructure\Doctrine\Entity\PlaylistEntity;
use App\Playlist\Infrastructure\Doctrine\Entity\PlaylistSongEntity;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Auth\OAuthAccessTokenJwt;
use App\Tests\Fixtures\Auth\PlaylistOAuthKernel;
use App\Tests\Fixtures\Auth\SignedDpopProof;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Actual production OAuth/DPoP, playlist voter, library policy and PostgreSQL adapters. */
final class PlaylistReadFirewallTest extends TestCase
{
    private static string $directory;
    private static string $privateKey;
    private PlaylistOAuthKernel $kernel;
    private EntityManagerInterface $manager;
    private UserRepositoryInterface $users;
    private UserEntity $owner;
    private LibraryEntity $allowedLibrary;
    private SongEntity $allowedSong;
    private SongEntity $deniedSong;
    /** @var array<string, PlaylistEntity> */
    private array $playlists = [];
    /** @var list<UserEntity|LibraryEntity|AlbumEntity|ArtistEntity|SongEntity|PlaylistEntity|ClientEntity|AccessTokenEntity> */
    private array $entities = [];
    private SignedDpopProof $proof;
    private ?string $jwt = null;
    private UserEntity $actor;

    public static function setUpBeforeClass(): void
    {
        self::$directory = sys_get_temp_dir() . '/baander-playlist-firewall-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir(self::$directory, 0700));
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        self::assertNotFalse($key);
        self::assertTrue(openssl_pkey_export($key, $privateKey));
        self::$privateKey = $privateKey;
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        self::assertSame(strlen($privateKey), file_put_contents(self::$directory . '/private.pem', $privateKey));
        self::assertSame(strlen($details['key']), file_put_contents(self::$directory . '/public.pem', $details['key']));
        self::assertTrue(chmod(self::$directory . '/private.pem', 0600));
        self::assertTrue(chmod(self::$directory . '/public.pem', 0600));
    }

    public static function tearDownAfterClass(): void
    {
        (new Filesystem())->remove(self::$directory);
    }

    protected function setUp(): void
    {
        $url = getenv('OUTBOX_TEST_DATABASE_URL');
        if ($url === false || $url === '') {
            self::markTestSkipped('Disposable PostgreSQL and Redis services are required.');
        }
        $this->kernel = new PlaylistOAuthKernel(self::$directory, $url);
        $this->kernel->boot();
        $container = $this->kernel->getContainer();
        $manager = $container->get('oauth.acceptance.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $this->manager = $manager;
        $users = $container->get('oauth.acceptance.users');
        self::assertInstanceOf(UserRepositoryInterface::class, $users);
        $this->users = $users;
        $this->owner = $this->createUser('owner');
        $suffix = bin2hex(random_bytes(8));
        foreach (['allowed', 'denied'] as $visibility) {
            $library = new LibraryEntity(
                'Playlist ' . $visibility,
                'playlist-' . $visibility . '-' . $suffix,
                '/tmp/playlist-' . $visibility . '-' . $suffix,
                'music',
                'local',
            );
            $album = new AlbumEntity(new PublicId(), $library, $visibility . ' album', 'album');
            $artist = new ArtistEntity(new PublicId(), $visibility . ' artist');
            $song = new SongEntity(new PublicId(), $album, $visibility . ' song', '/private/' . $visibility . '.flac', 1, 'audio/flac');
            foreach ([$library, $album, $artist, $song] as $entity) {
                $this->persist($entity);
            }
            $this->manager->persist(new ArtistSongEntity($artist, $song, 'primary'));
            if ($visibility === 'allowed') {
                $this->allowedLibrary = $library;
                $this->allowedSong = $song;
                $this->manager->persist(new UserLibraryAccessEntity($this->owner->getId(), $library, new \DateTimeImmutable()));
            } else {
                $this->deniedSong = $song;
            }
        }
        foreach (['private', 'public', 'collaborative'] as $kind) {
            $playlist = new PlaylistEntity(new PublicId(), $this->owner, $kind . ' acceptance playlist');
            $playlist->setPublic($kind === 'public');
            $playlist->setCollaborative($kind === 'collaborative');
            $this->playlists[$kind] = $playlist;
            $this->persist($playlist);
            $this->manager->persist(new PlaylistSongEntity($playlist, $this->allowedSong, 0));
            $this->manager->persist(new PlaylistSongEntity($playlist, $this->deniedSong, 1));
        }
        $this->manager->flush();
    }

    protected function tearDown(): void
    {
        if (isset($this->manager) && $this->manager->isOpen()) {
            $this->manager->clear();
            foreach (array_reverse($this->entities) as $entity) {
                $managed = $this->manager->find($entity::class, $entity->getId());
                if ($managed !== null) {
                    $this->manager->remove($managed);
                }
            }
            $this->manager->flush();
            $this->manager->close();
        }
        if (isset($this->kernel)) {
            $this->kernel->shutdown();
        }
        parent::tearDown();
    }

    /** @return iterable<string, array{string, string}> */
    public static function actorsAndPlaylists(): iterable
    {
        foreach (['owner', 'unrelated', 'admin'] as $actor) {
            foreach (['private', 'public', 'collaborative'] as $kind) {
                yield $actor . '/' . $kind => [$actor, $kind];
            }
        }
    }

    #[DataProvider('actorsAndPlaylists')]
    public function testPlaylistDetailAuthorizationAndSongVisibility(string $actor, string $kind): void
    {
        $this->authenticate($actor);
        $response = $this->request('/api/playlists/' . $this->playlists[$kind]->getPublicId());
        if ($actor === 'unrelated') {
            self::assertSame(403, $response->getStatusCode());
            $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
            self::assertArrayNotHasKey('data', $data);

            return;
        }
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR)['data'];
        self::assertSame($this->owner->getId()->toString(), $data['userId']);
        $expectedCount = $actor === 'admin' ? 2 : 1;
        self::assertSame($expectedCount, $data['songCount']);
        self::assertCount($expectedCount, $data['songs']);
        self::assertSame($this->allowedSong->getId()->toString(), $data['songs'][0]['uuid']);
        self::assertSame('allowed song', $data['songs'][0]['title']);
        self::assertSame('allowed artist', $data['songs'][0]['artistName']);
        self::assertSame('allowed album', $data['songs'][0]['albumName']);
        foreach ($data['songs'] as $song) {
            self::assertArrayNotHasKey('path', $song);
        }
        if ($actor === 'owner') {
            self::assertNotContains($this->deniedSong->getId()->toString(), array_column($data['songs'], 'uuid'));
        }
    }

    /** @return iterable<string, array{string}> */
    public static function authenticatedActors(): iterable
    {
        foreach (['owner', 'unrelated', 'admin'] as $actor) {
            yield $actor => [$actor];
        }
    }

    #[DataProvider('authenticatedActors')]
    public function testPlaylistListIsOwnerOnlyWithVisibleCounts(string $actor): void
    {
        $this->authenticate($actor);
        $response = $this->request('/api/playlists/');
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR)['data'];
        self::assertCount($actor === 'owner' ? 3 : 0, $data);
        foreach ($data as $playlist) {
            self::assertSame($this->owner->getId()->toString(), $playlist['userId']);
            self::assertSame(1, $playlist['songCount']);
        }
    }

    public function testAnonymousPlaylistReadsRequireAuthentication(): void
    {
        foreach (['/api/playlists/', '/api/playlists/' . $this->playlists['public']->getPublicId()] as $path) {
            self::assertSame(401, $this->request($path)->getStatusCode());
        }
    }

    public function testAdministratorListCountsAllSongsAndRetainsEmptyOwnedPlaylists(): void
    {
        $this->authenticate('admin');
        $withSongs = new PlaylistEntity(new PublicId(), $this->actor, 'Admin songs');
        $empty = new PlaylistEntity(new PublicId(), $this->actor, 'Admin empty');
        $this->persist($withSongs);
        $this->persist($empty);
        $this->manager->persist(new PlaylistSongEntity($withSongs, $this->allowedSong, 0));
        $this->manager->persist(new PlaylistSongEntity($withSongs, $this->deniedSong, 1));
        $this->manager->flush();

        $response = $this->request('/api/playlists/');
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR)['data'];
        self::assertCount(2, $data);
        $counts = array_column($data, 'songCount', 'publicId');
        self::assertSame(2, $counts[$withSongs->getPublicId()->toString()]);
        self::assertSame(0, $counts[$empty->getPublicId()->toString()]);
    }

    public function testRevokedLibraryHidesSongsAndCountsWithoutRemovingPlaylistEntries(): void
    {
        $this->authenticate('owner');
        $access = $this->kernel->getContainer()->get('playlist.acceptance.library_access');
        self::assertInstanceOf(LibraryAccessPortInterface::class, $access);
        $access->revoke($this->owner->getId(), $this->allowedLibrary->getId());
        $response = $this->request('/api/playlists/' . $this->playlists['private']->getPublicId());
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR)['data'];
        self::assertSame(0, $data['songCount']);
        self::assertSame([], $data['songs']);
        $list = json_decode((string) $this->request('/api/playlists/')->getContent(), true, 512, JSON_THROW_ON_ERROR)['data'];
        self::assertSame([0, 0, 0], array_column($list, 'songCount'));
        $this->assertStoredEntryCount(2);
        $access->grant($this->owner->getId(), $this->allowedLibrary->getId());
        $data = json_decode((string) $this->request('/api/playlists/' . $this->playlists['private']->getPublicId())->getContent(), true, 512, JSON_THROW_ON_ERROR)['data'];
        self::assertCount(1, $data['songs']);
        self::assertSame(1, $data['songCount']);
    }

    public function testMetadataUpdateReturnsVisibleCountAndPreservesHiddenEntries(): void
    {
        $this->authenticate('owner');
        $response = $this->request('/api/playlists/' . $this->playlists['private']->getPublicId(), 'PATCH', ['name' => 'Renamed']);
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR)['data'];
        self::assertSame('Renamed', $data['name']);
        self::assertSame(1, $data['songCount']);
        $this->assertStoredEntryCount(2);
    }

    public function testMissingPlaylistReturns404(): void
    {
        $this->authenticate('owner');
        self::assertSame(404, $this->request('/api/playlists/' . new PublicId())->getStatusCode());
    }

    private function assertStoredEntryCount(int $expected): void
    {
        $this->manager->clear();
        $entries = $this->manager->getRepository(PlaylistSongEntity::class)->findBy(['playlist' => $this->playlists['private']->getId()]);
        self::assertCount($expected, $entries);
    }

    private function createUser(string $actor): UserEntity
    {
        $user = User::createByOperator(
            new Email('playlist-' . $actor . '-' . bin2hex(random_bytes(8)) . '@baander.app'),
            'unused-password',
            'Playlist acceptance',
            $actor === 'admin' ? ['ROLE_USER', 'ROLE_ADMIN'] : ['ROLE_USER'],
        );
        $this->users->save($user);
        $entity = $this->manager->find(UserEntity::class, $user->getId());
        self::assertInstanceOf(UserEntity::class, $entity);
        $this->entities[] = $entity;

        return $entity;
    }

    /** @param LibraryEntity|AlbumEntity|ArtistEntity|SongEntity|PlaylistEntity $entity */
    private function persist(object $entity): void
    {
        $this->manager->persist($entity);
        $this->entities[] = $entity;
    }

    private function authenticate(string $actor): void
    {
        $entity = $actor === 'owner' ? $this->owner : $this->createUser($actor);
        $this->actor = $entity;
        $client = new ClientEntity(new PublicId(), 'Playlist acceptance client', json_encode(['https://baander.app/callback'], JSON_THROW_ON_ERROR));
        $this->proof = new SignedDpopProof();
        $token = new AccessTokenEntity((new Uuid())->toString(), $client, $entity, scopes: ['library'], expiresAt: new \DateTimeImmutable('+1 hour'));
        $token->setDpopJkt($this->proof->thumbprint());
        $this->manager->persist($client);
        $this->manager->persist($token);
        $this->entities[] = $client;
        $this->entities[] = $token;
        $this->manager->flush();
        $this->jwt = OAuthAccessTokenJwt::sign(self::$privateKey, $token);
    }

    /** @param array<string, mixed> $content */
    private function request(string $path, string $method = 'GET', array $content = []): Response
    {
        $this->manager->clear();
        $uri = 'https://baander.app' . $path;
        $request = Request::create($uri, $method, content: $content === [] ? null : json_encode($content, JSON_THROW_ON_ERROR));
        $request->headers->set('Content-Type', 'application/json');
        if ($this->jwt !== null) {
            $request->headers->set('Authorization', 'DPoP ' . $this->jwt);
            $request->headers->set('DPoP', $this->proof->create($method, $uri, $this->jwt));
        }
        $response = $this->kernel->handle($request);
        $this->kernel->terminate($request, $response);

        return $response;
    }
}
