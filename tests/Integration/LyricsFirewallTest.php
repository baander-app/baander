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
use App\Lyrics\Application\DTO\LrclibResult;
use App\Lyrics\Application\Port\LrclibClientInterface;
use App\Lyrics\Infrastructure\Doctrine\Entity\LyricsEntity;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Auth\LyricsOAuthKernel;
use App\Tests\Fixtures\Auth\OAuthAccessTokenJwt;
use App\Tests\Fixtures\Auth\SignedDpopProof;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Actual OAuth/DPoP and library policy, real lyrics persistence, no remote network. */
final class LyricsFirewallTest extends TestCase
{
    private static string $directory;
    private static string $privateKey;
    private LyricsOAuthKernel $kernel;
    private EntityManagerInterface $manager;
    private UserRepositoryInterface $users;
    private UserEntity $owner;
    private LibraryEntity $allowedLibrary;
    private SongEntity $allowedSong;
    private SongEntity $deniedSong;
    /** @var list<UserEntity|LibraryEntity|AlbumEntity|ArtistEntity|SongEntity|LyricsEntity|ClientEntity|AccessTokenEntity> */
    private array $entities = [];
    private SignedDpopProof $proof;
    private ?string $jwt = null;
    private LrclibClientInterface&MockObject $remote;

    public static function setUpBeforeClass(): void
    {
        self::$directory = sys_get_temp_dir() . '/baander-lyrics-firewall-' . bin2hex(random_bytes(8));
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
        $this->kernel = new LyricsOAuthKernel(self::$directory, $url);
        $this->kernel->boot();
        $container = $this->kernel->getContainer();
        $manager = $container->get('oauth.acceptance.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $this->manager = $manager;
        $users = $container->get('oauth.acceptance.users');
        self::assertInstanceOf(UserRepositoryInterface::class, $users);
        $this->users = $users;
        $this->remote = $this->createMock(LrclibClientInterface::class);
        $container->set(LrclibClientInterface::class, $this->remote);
        $this->owner = $this->createUser('owner');
        $suffix = bin2hex(random_bytes(8));
        foreach (['allowed', 'denied'] as $visibility) {
            $library = new LibraryEntity(
                'Lyrics ' . $visibility,
                'lyrics-' . $visibility . '-' . $suffix,
                '/tmp/lyrics-' . $visibility . '-' . $suffix,
                'music',
                'local',
            );
            $album = new AlbumEntity(new PublicId(), $library, $visibility . ' album', 'album');
            $artist = new ArtistEntity(new PublicId(), $visibility . ' artist');
            $song = new SongEntity(new PublicId(), $album, $visibility . ' song', '/private/' . $visibility . '.flac', 1, 'audio/flac');
            $song->setLength(233.0);
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

    /** @return iterable<string, array{string, bool}> */
    public static function actorsAndLibraries(): iterable
    {
        foreach (['owner', 'unrelated', 'admin', 'anonymous'] as $actor) {
            foreach ([true, false] as $allowed) {
                yield $actor . '/' . ($allowed ? 'allowed' : 'denied') => [$actor, $allowed];
            }
        }
    }

    #[DataProvider('actorsAndLibraries')]
    public function testCachedLyricsRespectLibraryAuthorization(string $actor, bool $allowed): void
    {
        $song = $allowed ? $this->allowedSong : $this->deniedSong;
        $this->cacheLyrics($song);
        $this->expectNoRemoteCalls();
        if ($actor !== 'anonymous') {
            $this->authenticate($actor);
        }
        $response = $this->request('/api/songs/' . $song->getPublicId() . '/lyrics');
        $expected = $actor === 'anonymous' ? 401 : (($actor === 'admin' || ($actor === 'owner' && $allowed)) ? 200 : 404);
        self::assertSame($expected, $response->getStatusCode());
        if ($expected === 200) {
            $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR)['data'];
            self::assertSame('Cached lyrics', $data['plainLyrics']);
            self::assertSame('[00:01.00] Cached lyrics', $data['syncedLyrics']);
        } else {
            self::assertStringNotContainsString('Cached lyrics', (string) $response->getContent());
        }
    }

    public function testCacheMissIsReadOnlyAndRevocationTakesEffect(): void
    {
        $this->authenticate('owner');
        $this->expectNoRemoteCalls();
        $path = '/api/songs/' . $this->allowedSong->getPublicId() . '/lyrics';
        $response = $this->request($path);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR)['data']);
        $this->assertLyricsCount(0);
        $access = $this->kernel->getContainer()->get('lyrics.acceptance.library_access');
        self::assertInstanceOf(LibraryAccessPortInterface::class, $access);
        $access->revoke($this->owner->getId(), $this->allowedLibrary->getId());
        self::assertSame(404, $this->request($path)->getStatusCode());
        $this->assertLyricsCount(0);
    }

    /** @return iterable<string, array{string, bool, string}> */
    public static function nonAdminMutations(): iterable
    {
        foreach (['owner', 'unrelated', 'anonymous'] as $actor) {
            foreach ([true, false] as $allowed) {
                foreach (['fetch', 'apply'] as $operation) {
                    yield $actor . '/' . ($allowed ? 'allowed' : 'denied') . '/' . $operation => [$actor, $allowed, $operation];
                }
            }
        }
    }

    #[DataProvider('nonAdminMutations')]
    public function testMutationsAreDeniedBeforeProviderOrPersistence(string $actor, bool $allowed, string $operation): void
    {
        $this->expectNoRemoteCalls();
        if ($actor !== 'anonymous') {
            $this->authenticate($actor);
        }
        $song = $allowed ? $this->allowedSong : $this->deniedSong;
        $response = $this->mutation($song, $operation);
        self::assertSame($actor === 'anonymous' ? 401 : 403, $response->getStatusCode());
        $this->assertLyricsCount(0);
    }

    public function testNonAdminCannotMutateCachedLyrics(): void
    {
        $this->authenticate('owner');
        $this->cacheLyrics($this->allowedSong);
        $this->expectNoRemoteCalls();
        foreach (['fetch', 'apply'] as $operation) {
            self::assertSame(403, $this->mutation($this->allowedSong, $operation)->getStatusCode());
        }
        $this->assertLyricsCount(1);
    }

    /** @return iterable<string, array{bool, string}> */
    public static function adminMutations(): iterable
    {
        foreach ([true, false] as $allowed) {
            foreach (['fetch', 'apply'] as $operation) {
                yield ($allowed ? 'allowed' : 'denied') . '/' . $operation => [$allowed, $operation];
            }
        }
    }

    #[DataProvider('adminMutations')]
    public function testAdministratorCanStoreLyricsThroughRealService(bool $allowed, string $operation): void
    {
        $this->authenticate('admin');
        $song = $allowed ? $this->allowedSong : $this->deniedSong;
        $result = new LrclibResult(912345, $song->getTitle(), 'Provider artist', 'Provider album', 233.0, false, 'Provider lyrics', '[00:02.00] Provider lyrics');
        $this->remote->expects(self::never())->method('search');
        $this->remote->expects(self::never())->method('getBySignature');
        if ($operation === 'fetch') {
            $this->remote->expects(self::once())->method('getBySignatureCached')
                ->with($song->getTitle(), $allowed ? 'allowed artist' : 'denied artist', $allowed ? 'allowed album' : 'denied album', 233.0)
                ->willReturn($result);
            $this->remote->expects(self::never())->method('getById');
        } else {
            $this->remote->expects(self::once())->method('getById')->with(912345)->willReturn($result);
            $this->remote->expects(self::never())->method('getBySignatureCached');
        }
        $response = $this->mutation($song, $operation);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('Provider lyrics', json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR)['data']['plainLyrics']);
        $this->manager->clear();
        $stored = $this->manager->getRepository(LyricsEntity::class)->findOneBy(['songId' => $song->getId()]);
        self::assertInstanceOf(LyricsEntity::class, $stored);
        self::assertSame('Provider lyrics', $stored->getPlainLyrics());
        self::assertSame(912345, $stored->getLrclibId());
        $this->assertLyricsCount(1);
    }

    public function testAdministratorCachedMutationsDoNotOverwriteOrCallProvider(): void
    {
        $this->authenticate('admin');
        $this->cacheLyrics($this->deniedSong);
        $this->expectNoRemoteCalls();
        // A fetch returns the lyrics the song has; an apply reports them as a conflict.
        $fetch = $this->mutation($this->deniedSong, 'fetch');
        self::assertSame(200, $fetch->getStatusCode());
        self::assertSame('Cached lyrics', json_decode((string) $fetch->getContent(), true, 512, JSON_THROW_ON_ERROR)['data']['plainLyrics']);
        $apply = $this->mutation($this->deniedSong, 'apply');
        self::assertSame(409, $apply->getStatusCode());
        self::assertStringNotContainsString('Provider lyrics', (string) $apply->getContent());
        $this->assertLyricsCount(1);
        $this->manager->clear();
        $stored = $this->manager->getRepository(LyricsEntity::class)->findOneBy(['songId' => $this->deniedSong->getId()]);
        self::assertInstanceOf(LyricsEntity::class, $stored);
        self::assertSame('Cached lyrics', $stored->getPlainLyrics());
    }

    private function expectNoRemoteCalls(): void
    {
        foreach (['getBySignatureCached', 'getBySignature', 'getById', 'search'] as $method) {
            $this->remote->expects(self::never())->method($method);
        }
    }

    private function cacheLyrics(SongEntity $song): void
    {
        $lyrics = new LyricsEntity(new Uuid(), $song->getId(), 'embedded');
        $lyrics->setPlainLyrics('Cached lyrics');
        $lyrics->setSyncedLyrics('[00:01.00] Cached lyrics');
        $this->persist($lyrics);
        $this->manager->flush();
    }

    private function assertLyricsCount(int $expected): void
    {
        $this->manager->clear();
        self::assertSame($expected, $this->manager->getRepository(LyricsEntity::class)->count(['songId' => [$this->allowedSong->getId(), $this->deniedSong->getId()]]));
    }

    private function mutation(SongEntity $song, string $operation): Response
    {
        return $operation === 'fetch'
            ? $this->request('/api/songs/' . $song->getPublicId() . '/lyrics/fetch', 'POST')
            : $this->request('/api/lyrics/search/912345/apply', 'POST', ['songPublicId' => $song->getPublicId()->toString()]);
    }

    private function createUser(string $actor): UserEntity
    {
        $user = User::createByOperator(
            new Email('lyrics-' . $actor . '-' . bin2hex(random_bytes(8)) . '@baander.app'),
            'unused-password',
            'Lyrics acceptance',
            $actor === 'admin' ? ['ROLE_USER', 'ROLE_ADMIN'] : ['ROLE_USER'],
        );
        $this->users->save($user);
        $entity = $this->manager->find(UserEntity::class, $user->getId());
        self::assertInstanceOf(UserEntity::class, $entity);
        $this->entities[] = $entity;

        return $entity;
    }

    /** @param LibraryEntity|AlbumEntity|ArtistEntity|SongEntity|LyricsEntity $entity */
    private function persist(object $entity): void
    {
        $this->manager->persist($entity);
        $this->entities[] = $entity;
    }

    private function authenticate(string $actor): void
    {
        $entity = $actor === 'owner' ? $this->owner : $this->createUser($actor);
        $client = new ClientEntity(new PublicId(), 'Lyrics acceptance client', json_encode(['https://baander.app/callback'], JSON_THROW_ON_ERROR));
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
