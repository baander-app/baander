<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\AccessTokenEntity;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\ClientEntity;
use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\AlbumEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\MovieEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\MovieVideoEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\VideoEntity;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Library\Infrastructure\Doctrine\Entity\UserLibraryAccessEntity;
use App\Playlist\Infrastructure\Doctrine\Entity\PlaylistEntity;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Auth\FirstPartyOAuthClient;
use App\Tests\Fixtures\Auth\OAuthAccessTokenJwt;
use App\Tests\Fixtures\Auth\ProductionOAuthKernel;
use App\Tests\Fixtures\Auth\SignedDpopProof;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Delegated tokens through the production firewall, PostgreSQL and Redis: a token issued to
 * any client other than Baander's own reaches only the routes its scopes cover, and never
 * carries its user's admin authority. Baander's own token keeps both.
 */
final class OAuthScopeEnforcementTest extends TestCase
{
    private static string $directory;
    private static string $privateKey;
    private ProductionOAuthKernel $kernel;
    private EntityManagerInterface $manager;
    private UserRepositoryInterface $users;
    private SignedDpopProof $proof;
    private UserEntity $admin;
    /** @var list<object> rows removed again in reverse order */
    private array $rows = [];

    public static function setUpBeforeClass(): void
    {
        self::$directory = sys_get_temp_dir() . '/baander-oauth-scopes-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir(self::$directory, 0700));
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        self::assertNotFalse($key);
        self::assertTrue(openssl_pkey_export($key, $privateKey));
        self::$privateKey = $privateKey;
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        file_put_contents(self::$directory . '/private.pem', $privateKey);
        file_put_contents(self::$directory . '/public.pem', $details['key']);
        chmod(self::$directory . '/private.pem', 0600);
        chmod(self::$directory . '/public.pem', 0600);
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
        $this->kernel = new ProductionOAuthKernel(self::$directory, $url);
        $this->kernel->boot();
        $container = $this->kernel->getContainer();
        $manager = $container->get('oauth.acceptance.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $this->manager = $manager;
        $users = $container->get('oauth.acceptance.users');
        self::assertInstanceOf(UserRepositoryInterface::class, $users);
        $this->users = $users;
        $this->proof = new SignedDpopProof();
        $this->admin = $this->user(['ROLE_USER', 'ROLE_ADMIN']);
    }

    protected function tearDown(): void
    {
        if (isset($this->manager) && $this->manager->isOpen()) {
            $this->manager->clear();
            foreach (array_reverse($this->rows) as $row) {
                $managed = $this->manager->find($row::class, $row->getId());
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

    /** @return iterable<string, array{string, string, array<string, mixed>}> */
    public static function firstPartyRoutes(): iterable
    {
        yield 'user administration' => ['GET', '/api/admin/users', []];
        yield 'email change' => ['PUT', '/api/auth/me/email', ['email' => 'changed@baander.app']];
        yield 'consent decision' => ['POST', '/api/oauth/authorize', ['decision' => 'approve']];
        yield 'device approval' => ['POST', '/api/oauth/device/approve', ['userCode' => 'BCDF-GHJK', 'decision' => 'approve']];
        yield 'client registration' => ['POST', '/api/oauth/clients/', ['name' => 'Another client']];
        yield 'WebSocket ticket' => ['POST', '/api/ws/tickets', []];
    }

    /**
     * An administrator who approved a client for their profile handed it nothing else.
     *
     * @param array<string, mixed> $body
     */
    #[DataProvider('firstPartyRoutes')]
    public function testAProfileTokenOfAnAdministratorIsRefusedEverywhereElse(string $method, string $path, array $body): void
    {
        $jwt = $this->token($this->thirdPartyClient(), $this->admin, ['profile']);

        $response = $this->send($jwt, $method, $path, $body);

        $this->assertInsufficientScope($response);
    }

    public function testALibraryTokenOfADevicePlaysButCannotAdministerTheCatalog(): void
    {
        $member = $this->user(['ROLE_USER']);
        $library = $this->library();
        // The grant goes with the library.
        $this->manager->persist(new UserLibraryAccessEntity($member->getId(), $library, new \DateTimeImmutable()));
        $album = $this->persist(new AlbumEntity(new PublicId(), $library, 'Delegated album', 'album'));
        $this->manager->flush();
        $jwt = $this->token($this->deviceClient(), $member, ['library']);

        self::assertSame(200, $this->send($jwt, 'GET', '/api/albums/' . $album->getPublicId()->toString())->getStatusCode());
        // The scope check passes and the stream controller looks the track up: an unknown
        // track is its 404, not a refusal.
        $stream = $this->send($jwt, 'GET', '/api/stream/track', query: ['id' => (new PublicId())->toString()]);
        self::assertSame(404, $stream->getStatusCode(), (string) $stream->getContent());
        self::assertNull($stream->headers->get('WWW-Authenticate'), 'No insufficient_scope challenge.');
        self::assertSame('Track not found.', json_decode((string) $stream->getContent(), true, flags: JSON_THROW_ON_ERROR)['error']['message']);
        self::assertSame(200, $this->send($jwt, 'POST', '/api/devices', ['deviceId' => Uuid::v7()->toString(), 'name' => 'Living room TV'])->getStatusCode());
        $snapshot = $this->send($jwt, 'GET', '/api/session/snapshot');
        self::assertSame(200, $snapshot->getStatusCode(), (string) $snapshot->getContent());

        $this->assertInsufficientScope($this->send($jwt, 'GET', '/api/admin/users'));
        $this->assertInsufficientScope($this->send($jwt, 'POST', '/api/genres/', ['name' => 'Delegated genre']));
    }

    public function testAPlaylistTokenManagesPlaylistsButCannotBrowseTheCatalog(): void
    {
        $jwt = $this->token($this->thirdPartyClient(), $this->user(['ROLE_USER']), ['playlist']);

        $created = $this->send($jwt, 'POST', '/api/playlists/', ['name' => 'Delegated playlist']);
        self::assertSame(201, $created->getStatusCode(), (string) $created->getContent());
        $this->persist($this->manager->getRepository(PlaylistEntity::class)->findOneBy(['name' => 'Delegated playlist']) ?? self::fail('The playlist was not stored.'));

        $this->assertInsufficientScope($this->send($jwt, 'GET', '/api/albums/'));
    }

    public function testVideoPlaybackReadsRequireBothLibraryScopeAndAGrant(): void
    {
        $granted = '/api/videos/' . $this->videoInLibrary($this->admin);
        $ungranted = '/api/videos/' . $this->videoInLibrary();
        $client = $this->deviceClient();
        $library = $this->token($client, $this->admin, ['library']);
        $profile = $this->token($client, $this->admin, ['profile']);

        $information = $this->data($this->send($library, 'GET', $granted . '/playback-information'));
        self::assertSame('Opening', $information['chapters'][0]['title']);
        self::assertSame('unavailable', $information['preview']['state']);
        $preview = $this->send($library, 'GET', $granted . '/timeline-preview');
        self::assertSame(404, $preview->getStatusCode(), (string) $preview->getContent());
        self::assertNull($preview->headers->get('WWW-Authenticate'));

        foreach (['playback-information', 'timeline-preview'] as $route) {
            $this->assertInsufficientScope($this->send($profile, 'GET', $granted . '/' . $route));
            self::assertSame(404, $this->send($library, 'GET', $ungranted . '/' . $route)->getStatusCode());
        }
    }

    public function testAdminLogsRemainFirstPartyEvenForADelegatedSuperAdministratorWithEveryScope(): void
    {
        $superAdmin = $this->user(['ROLE_USER', 'ROLE_SUPER_ADMIN']);
        $jwt = $this->token($this->thirdPartyClient(), $superAdmin, ['profile', 'email', 'library', 'playlist']);

        $this->assertInsufficientScope($this->send($jwt, 'GET', '/api/admin/logs/sources'));
        $this->assertInsufficientScope($this->send($jwt, 'GET', '/api/admin/logs/entries'));
        $this->assertInsufficientScope($this->send($jwt, 'GET', '/api/admin/logs/entries/unknown'));
        $this->assertInsufficientScope($this->send($jwt, 'POST', '/api/admin/logs/query', ['sql' => 'SELECT 1']));
    }

    public function testAProfileTokenReadsTheProfileWithoutEmailOrRoles(): void
    {
        $profile = $this->send($this->token($this->personalAccessClient(), $this->admin, ['profile']), 'GET', '/api/auth/me');
        $email = $this->send($this->token($this->personalAccessClient(), $this->admin, ['email']), 'GET', '/api/auth/me');

        self::assertSame(['publicId', 'name', 'createdAt'], array_keys($this->data($profile)));
        self::assertSame(['publicId', 'name', 'email', 'emailVerifiedAt', 'createdAt'], array_keys($this->data($email)));
    }

    public function testAnAdministratorsDelegatedTokensReachOnlyWhatTheAdministratorIsGrantedAsAUser(): void
    {
        $library = $this->library();
        $other = $this->user(['ROLE_USER']);
        $playlist = $this->persist(new PlaylistEntity(new PublicId(), $other, 'Private playlist of another user'));
        $this->manager->flush();
        $libraryPath = '/api/libraries/' . $library->getPublicId()->toString();
        $playlistPath = '/api/playlists/' . $playlist->getPublicId()->toString();
        $library = $this->token($this->personalAccessClient(), $this->admin, ['library']);
        $playlists = $this->token($this->personalAccessClient(), $this->admin, ['playlist']);
        $firstParty = $this->token(FirstPartyOAuthClient::entity($this->kernel->getContainer(), $this->manager), $this->admin, []);

        self::assertSame(404, $this->send($library, 'GET', $libraryPath)->getStatusCode());
        $this->assertInsufficientScope($this->send($library, 'GET', '/api/admin/activity/summary'));
        self::assertContains($this->send($playlists, 'GET', $playlistPath)->getStatusCode(), [403, 404]);

        self::assertSame(200, $this->send($firstParty, 'GET', $libraryPath)->getStatusCode());
        self::assertSame(200, $this->send($firstParty, 'GET', $playlistPath)->getStatusCode());
        self::assertSame(200, $this->send($firstParty, 'GET', '/api/admin/users')->getStatusCode());
    }

    private function assertInsufficientScope(Response $response): void
    {
        self::assertSame(403, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('DPoP error="insufficient_scope"', $response->headers->get('WWW-Authenticate'));
        self::assertSame('insufficient_scope', json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR)['error']['code']);
    }

    /** @param list<string> $roles */
    private function user(array $roles): UserEntity
    {
        $user = User::createByOperator(new Email('scopes-' . bin2hex(random_bytes(8)) . '@baander.app'), 'test-only-password', 'Scope acceptance', $roles);
        $this->users->save($user);
        $entity = $this->manager->find(UserEntity::class, $user->getId());
        self::assertInstanceOf(UserEntity::class, $entity);
        $this->rows[] = $entity;

        return $entity;
    }

    private function library(): LibraryEntity
    {
        $suffix = bin2hex(random_bytes(8));

        return $this->persist(new LibraryEntity('Scope library', 'scopes-' . $suffix, '/tmp/scopes-' . $suffix, 'music', 'local'));
    }

    private function videoInLibrary(?UserEntity $grantee = null): PublicId
    {
        $slug = 'scope-video-' . bin2hex(random_bytes(8));
        $directory = self::$directory . '/' . $slug;
        self::assertTrue(mkdir($directory, 0700));
        $path = $directory . '/source.mkv';
        $contents = 'Video metadata fixture for ' . $slug;
        self::assertSame(strlen($contents), file_put_contents($path, $contents));

        $library = $this->persist(new LibraryEntity('Scope video library', $slug, $directory, 'movie', 'local'));
        $movie = $this->persist(new MovieEntity(new PublicId(), $library, 'Scoped movie'));
        $video = new VideoEntity(new PublicId(), $path, hash('xxh3', $contents));
        $video->setProbe(['chapters' => [['start' => 0.0, 'end' => 10.0, 'title' => 'Opening']]]);
        $this->persist($video);
        $this->persist(new MovieVideoEntity($movie, $video));
        if ($grantee !== null) {
            $this->manager->persist(new UserLibraryAccessEntity($grantee->getId(), $library, new \DateTimeImmutable()));
            $this->manager->flush();
        }

        return $video->getPublicId();
    }

    private function thirdPartyClient(): ClientEntity
    {
        return $this->persist(new ClientEntity(new PublicId(), 'Third-party player', json_encode(['https://player.baander.app/callback'], JSON_THROW_ON_ERROR)));
    }

    private function deviceClient(): ClientEntity
    {
        return $this->persist(new ClientEntity(new PublicId(), 'Living room TV', '[]', deviceClient: true));
    }

    private function personalAccessClient(): ClientEntity
    {
        return $this->persist(new ClientEntity(new PublicId(), 'Administrator scripts', '[]', personalAccessClient: true));
    }

    /** @param list<string> $scopes */
    private function token(ClientEntity $client, UserEntity $user, array $scopes): string
    {
        $token = new AccessTokenEntity((new Uuid())->toString(), $client, $user, scopes: $scopes, expiresAt: new \DateTimeImmutable('+1 hour'));
        $token->setDpopJkt($this->proof->thumbprint());
        $this->manager->persist($token);
        $this->manager->flush();

        return OAuthAccessTokenJwt::sign(self::$privateKey, $token);
    }

    /**
     * @template T of object
     * @param T $row
     * @return T
     */
    private function persist(object $row): object
    {
        $this->manager->persist($row);
        $this->manager->flush();
        $this->rows[] = $row;

        return $row;
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $query
     */
    private function send(string $jwt, string $method, string $path, array $body = [], array $query = []): Response
    {
        $uri = 'https://baander.app' . $path;
        $request = Request::create($uri, $method, $query, server: ['CONTENT_TYPE' => 'application/json'], content: $body === [] ? null : json_encode($body, JSON_THROW_ON_ERROR));
        $request->headers->set('Authorization', 'DPoP ' . $jwt);
        $request->headers->set('DPoP', $this->proof->create($method, $uri, $jwt));
        $request->headers->set('Origin', 'https://baander.app');
        $response = $this->kernel->handle($request);
        $this->kernel->terminate($request, $response);

        return $response;
    }

    /** @return array<string, mixed> */
    private function data(Response $response): array
    {
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($body['data']);

        return $body['data'];
    }
}
