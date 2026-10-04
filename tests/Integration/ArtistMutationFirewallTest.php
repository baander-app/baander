<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\AccessTokenEntity;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\ClientEntity;
use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\AlbumEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\ArtistAlbumEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\ArtistEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\ArtistSongEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\SongEntity;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Library\Infrastructure\Doctrine\Entity\UserLibraryAccessEntity;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Auth\OAuthAccessTokenJwt;
use App\Tests\Fixtures\Auth\ProductionOAuthKernel;
use App\Tests\Fixtures\Auth\SignedDpopProof;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Real production OAuth/DPoP firewall, ORM catalog adapters, and disposable PostgreSQL/Redis. */
final class ArtistMutationFirewallTest extends TestCase
{
    private static string $directory;
    private static string $privateKey;
    private ProductionOAuthKernel $kernel;
    private EntityManagerInterface $manager;
    private UserRepositoryInterface $users;
    private ArtistEntity $artist;
    private AlbumEntity $album;
    private SongEntity $song;
    private LibraryEntity $library;
    private string $createdName;
    /** @var list<array{class: class-string, id: Uuid}> */
    private array $cleanup = [];

    public static function tearDownAfterClass(): void
    {
        if (isset(self::$directory)) {
            (new Filesystem())->remove(self::$directory);
        }
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        $url = getenv('OUTBOX_TEST_DATABASE_URL');
        if ($url === false || $url === '') {
            self::markTestSkipped('Disposable PostgreSQL and Redis services are required.');
        }
        if (!isset(self::$directory)) {
            self::$directory = sys_get_temp_dir() . '/baander-artist-firewall-' . bin2hex(random_bytes(8));
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

        $this->kernel = new ProductionOAuthKernel(self::$directory, $url);
        $this->kernel->boot();
        $container = $this->kernel->getContainer();
        self::assertSame('prod', $container->getParameter('kernel.environment'));
        $manager = $container->get('oauth.acceptance.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $this->manager = $manager;
        $users = $container->get('oauth.acceptance.users');
        self::assertInstanceOf(UserRepositoryInterface::class, $users);
        $this->users = $users;

        $suffix = bin2hex(random_bytes(8));
        $this->createdName = 'Created artist ' . $suffix;
        $this->library = new LibraryEntity('Artist acceptance', 'artist-' . $suffix, '/tmp/artist-' . $suffix, 'music', 'local');
        $this->album = new AlbumEntity(new PublicId(), $this->library, 'Acceptance album', 'album');
        $this->song = new SongEntity(new PublicId(), $this->album, 'Acceptance song', '/tmp/acceptance.flac', 1, 'audio/flac');
        $this->artist = new ArtistEntity(new PublicId(), 'Original artist ' . $suffix);
        foreach ([$this->library, $this->album, $this->song, $this->artist] as $entity) {
            $this->manager->persist($entity);
            $this->cleanup[] = ['class' => $entity::class, 'id' => $entity->getId()];
        }
        $this->manager->flush();
    }

    protected function tearDown(): void
    {
        if (isset($this->manager) && $this->manager->isOpen()) {
            $this->manager->clear();
            $created = $this->manager->getRepository(ArtistEntity::class)->findOneBy(['name' => $this->createdName]);
            if ($created !== null) {
                $this->manager->remove($created);
            }
            foreach (array_reverse($this->cleanup) as $identity) {
                $entity = $this->manager->find($identity['class'], $identity['id']);
                if ($entity !== null) {
                    $this->manager->remove($entity);
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
    public static function mutations(): iterable
    {
        foreach (['store', 'update', 'destroy', 'add-song', 'remove-song', 'song-role', 'add-album', 'remove-album', 'album-role'] as $action) {
            yield $action . ' member' => [$action, false];
            yield $action . ' admin' => [$action, true];
        }
    }

    #[DataProvider('mutations')]
    public function testEveryArtistMutationRequiresAdministrator(string $action, bool $admin): void
    {
        $this->prepareLinks($action);
        $before = $this->catalogState();
        [$method, $path, $payload, $status] = $this->mutation($action);
        $response = $this->request($method, $path, $payload, $admin);

        self::assertSame($admin ? $status : 403, $response->getStatusCode(), (string) $response->getContent());
        if (!$admin) {
            self::assertSame($before, $this->catalogState(), 'Denied mutations must leave artist metadata and relationships unchanged.');

            return;
        }
        $after = $this->catalogState();
        match ($action) {
            'store' => self::assertSame($before['artists'] + 1, $after['artists']),
            'update' => self::assertSame('Modified artist', $after['name']),
            'destroy' => self::assertNull($after['name']),
            'add-song' => self::assertSame(['primary'], $after['songs']),
            'remove-song' => self::assertSame([], $after['songs']),
            'song-role' => self::assertSame(['featured'], $after['songs']),
            'add-album' => self::assertSame(['primary'], $after['albums']),
            'remove-album' => self::assertSame([], $after['albums']),
            'album-role' => self::assertSame(['featured'], $after['albums']),
            default => throw new \LogicException('Unknown mutation.'),
        };
    }

    public function testAnonymousCannotMutateAndLibraryMemberCanStillReadArtist(): void
    {
        $this->manager->persist(new ArtistSongEntity($this->artist, $this->song, 'primary'));
        $this->manager->flush();
        $before = $this->catalogState();
        foreach (self::mutations() as [$action, $admin]) {
            if ($admin) {
                continue;
            }
            [$method, $path, $payload] = $this->mutation($action);
            $response = $this->request($method, $path, $payload, null);
            self::assertSame(401, $response->getStatusCode(), (string) $response->getContent());
        }
        self::assertSame($before, $this->catalogState());
        $response = $this->request('GET', '/api/artists/' . $this->artist->getPublicId(), [], false);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $response = $this->request('GET', '/api/artists/', [], false);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    }

    private function prepareLinks(string $action): void
    {
        if (in_array($action, ['remove-song', 'song-role'], true)) {
            $this->manager->persist(new ArtistSongEntity($this->artist, $this->song, 'primary'));
        }
        if (in_array($action, ['remove-album', 'album-role'], true)) {
            $this->manager->persist(new ArtistAlbumEntity($this->artist, $this->album, 'primary'));
        }
        $this->manager->flush();
    }

    /** @return array{string, string, array<string, string>, int} */
    private function mutation(string $action): array
    {
        $base = '/api/artists/' . $this->artist->getPublicId();
        $songId = $this->song->getId()->toString();
        $albumId = $this->album->getId()->toString();

        return match ($action) {
            'store' => ['POST', '/api/artists/', ['name' => $this->createdName], 201],
            'update' => ['PATCH', $base, ['name' => 'Modified artist'], 200],
            'destroy' => ['DELETE', $base, [], 204],
            'add-song' => ['POST', $base . '/songs', ['songId' => $songId, 'role' => 'primary'], 204],
            'remove-song' => ['DELETE', $base . '/songs/' . $songId, [], 204],
            'song-role' => ['PATCH', $base . '/songs/' . $songId, ['role' => 'featured'], 204],
            'add-album' => ['POST', $base . '/albums', ['albumId' => $albumId, 'role' => 'primary'], 204],
            'remove-album' => ['DELETE', $base . '/albums/' . $albumId, [], 204],
            'album-role' => ['PATCH', $base . '/albums/' . $albumId, ['role' => 'featured'], 204],
            default => throw new \LogicException('Unknown mutation.'),
        };
    }

    /** @return array{artists: int, name: ?string, songs: list<?string>, albums: list<?string>} */
    private function catalogState(): array
    {
        $this->manager->clear();
        $artist = $this->manager->find(ArtistEntity::class, $this->artist->getId());
        $songs = $this->manager->getRepository(ArtistSongEntity::class)->findBy(['artist' => $this->artist->getId()]);
        $albums = $this->manager->getRepository(ArtistAlbumEntity::class)->findBy(['artist' => $this->artist->getId()]);

        return [
            'artists' => $this->manager->getRepository(ArtistEntity::class)->count([]),
            'name' => $artist?->getName(),
            'songs' => array_map(static fn (ArtistSongEntity $link): ?string => $link->getRole(), $songs),
            'albums' => array_map(static fn (ArtistAlbumEntity $link): ?string => $link->getRole(), $albums),
        ];
    }

    /** @param array<string, string> $payload */
    private function request(string $method, string $path, array $payload, ?bool $admin): Response
    {
        $uri = 'https://baander.app' . $path;
        $request = Request::create($uri, $method, server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($payload, JSON_THROW_ON_ERROR));
        if ($admin !== null) {
            $user = User::createByOperator(new Email('artist-' . bin2hex(random_bytes(8)) . '@baander.app'), 'unused-password', 'Artist acceptance', $admin ? ['ROLE_USER', 'ROLE_ADMIN'] : ['ROLE_USER']);
            $this->users->save($user);
            $entity = $this->manager->find(UserEntity::class, $user->getId());
            self::assertInstanceOf(UserEntity::class, $entity);
            $client = new ClientEntity(new PublicId(), 'Artist acceptance client', json_encode(['https://baander.app/callback'], JSON_THROW_ON_ERROR));
            $proof = new SignedDpopProof();
            $token = new AccessTokenEntity((new Uuid())->toString(), $client, $entity, scopes: ['library'], expiresAt: new \DateTimeImmutable('+1 hour'));
            $token->setDpopJkt($proof->thumbprint());
            $library = $this->manager->find(LibraryEntity::class, $this->library->getId());
            self::assertInstanceOf(LibraryEntity::class, $library);
            $this->manager->persist(new UserLibraryAccessEntity($entity, $library, new \DateTimeImmutable()));
            $this->manager->persist($client);
            $this->manager->persist($token);
            $this->manager->flush();
            $this->cleanup[] = ['class' => UserEntity::class, 'id' => $user->getId()];
            $this->cleanup[] = ['class' => ClientEntity::class, 'id' => $client->getId()];
            $this->cleanup[] = ['class' => AccessTokenEntity::class, 'id' => $token->getId()];
            $jwt = OAuthAccessTokenJwt::sign(self::$privateKey, $token);
            $request->headers->set('Authorization', 'DPoP ' . $jwt);
            $request->headers->set('DPoP', $proof->create($method, $uri, $jwt));
        }
        $response = $this->kernel->handle($request);
        $this->kernel->terminate($request, $response);

        return $response;
    }
}
