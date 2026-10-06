<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\AccessTokenEntity;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\ClientEntity;
use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\AlbumEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\SongEntity;
use App\Filesystem\Application\Port\LocalFilesystemPortInterface;
use App\Library\Application\Port\LibraryAccessPortInterface;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Library\Infrastructure\Doctrine\Entity\UserLibraryAccessEntity;
use App\Media\Infrastructure\Service\StreamService;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Auth\OAuthAccessTokenJwt;
use App\Tests\Fixtures\Auth\SignedDpopProof;
use App\Tests\Fixtures\Auth\TrackStreamOAuthKernel;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use SwooleBundle\SwooleBundle\Bridge\Symfony\HttpFoundation\SwooleBinaryFileResponse;

/** Production OAuth/DPoP, actual song/library repositories and disposable media bytes. */
final class TrackStreamFirewallTest extends TestCase
{
    private const string BYTES = '0123456789abcdef';
    private const string ENDPOINT = 'https://baander.app/api/stream/track';
    private static string $directory;
    private static string $privateKey;
    private TrackStreamOAuthKernel $kernel;
    private EntityManagerInterface $manager;
    private UserRepositoryInterface $users;
    private UserEntity $member;
    private LibraryEntity $allowedLibrary;
    private SongEntity $allowedSong;
    private SongEntity $deniedSong;
    private AccessTokenEntity $token;
    private SignedDpopProof $proof;
    private ?string $jwt = null;
    /** @var list<UserEntity|LibraryEntity|AlbumEntity|SongEntity|ClientEntity|AccessTokenEntity> */
    private array $entities = [];
    /** @var list<string> */
    private array $resolvedPaths = [];
    private ?\Throwable $lastException = null;

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
            self::$directory = sys_get_temp_dir() . '/baander-track-firewall-' . bin2hex(random_bytes(8));
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
            self::assertSame(strlen(self::BYTES), file_put_contents(self::$directory . '/track.mp3', self::BYTES));
        }
        $this->kernel = new TrackStreamOAuthKernel(self::$directory, $url);
        $this->kernel->boot();
        $container = $this->kernel->getContainer();
        $dispatcher = $container->get('track.acceptance.dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(
            KernelEvents::EXCEPTION,
            function (ExceptionEvent $event): void {
                $this->lastException = $event->getThrowable();
            },
            2048,
        );
        self::assertSame('prod', $container->getParameter('kernel.environment'));
        $manager = $container->get('oauth.acceptance.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $this->manager = $manager;
        $users = $container->get('oauth.acceptance.users');
        self::assertInstanceOf(UserRepositoryInterface::class, $users);
        $this->users = $users;
        $filesystem = $this->createMock(LocalFilesystemPortInterface::class);
        $filesystem->method('resolve')->willReturnCallback(function (string $path): string {
            $this->resolvedPaths[] = $path;

            return self::$directory . '/track.mp3';
        });
        foreach (['exists', 'size', 'read', 'write', 'open'] as $method) {
            $filesystem->expects(self::never())->method($method);
        }
        $container->set(LocalFilesystemPortInterface::class, $filesystem);
        self::assertInstanceOf(StreamService::class, $container->get('track.acceptance.stream'));
        $this->member = $this->createUser('member');
        $suffix = bin2hex(random_bytes(8));
        foreach (['allowed', 'denied'] as $visibility) {
            $library = new LibraryEntity(
                'Track ' . $visibility,
                'track-' . $visibility . '-' . $suffix,
                '/tmp/track-' . $visibility . '-' . $suffix,
                'music',
                'local',
            );
            $album = new AlbumEntity(new PublicId(), $library, 'Track acceptance album', 'album');
            $song = new SongEntity(
                new PublicId(),
                $album,
                'Track acceptance song',
                $visibility . '/track.mp3',
                strlen(self::BYTES),
                'audio/mpeg',
            );
            foreach ([$library, $album, $song] as $entity) {
                $this->manager->persist($entity);
                $this->entities[] = $entity;
            }
            if ($visibility === 'allowed') {
                $this->allowedLibrary = $library;
                $this->allowedSong = $song;
            } else {
                $this->deniedSong = $song;
            }
        }
        $this->manager->persist(new UserLibraryAccessEntity(
            $this->member,
            $this->allowedLibrary,
            new \DateTimeImmutable(),
        ));
        $this->manager->flush();
    }

    protected function tearDown(): void
    {
        if (isset($this->manager) && $this->manager->isOpen()) {
            $this->manager->clear();
            // Membership association foreign keys cascade when their library/user is removed.
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

    /** @return iterable<string, array{string, bool, int}> */
    public static function actorsAndLibraries(): iterable
    {
        foreach (['anonymous', 'unrelated', 'member', 'admin'] as $actor) {
            foreach ([true, false] as $allowedLibrary) {
                $authorized = $actor === 'admin' || ($actor === 'member' && $allowedLibrary);
                $status = $authorized ? 200 : 403;
                if ($actor === 'anonymous') {
                    $status = 401;
                }
                yield $actor . '/' . ($allowedLibrary ? 'allowed' : 'denied') => [
                    $actor,
                    $allowedLibrary,
                    $status,
                ];
            }
        }
    }

    #[DataProvider('actorsAndLibraries')]
    public function testTrackAuthorizationBeforeFilesystemResolution(string $actor, bool $allowedLibrary, int $status): void
    {
        $this->authenticate($actor);
        $song = $allowedLibrary ? $this->allowedSong : $this->deniedSong;
        $response = $this->request($song);
        self::assertSame($status, $response->getStatusCode(), (string) $response->getContent());
        if ($status !== 200) {
            self::assertSame([], $this->resolvedPaths, 'Denied requests must not resolve media.');

            return;
        }
        self::assertSame([$song->getPath()], $this->resolvedPaths);
        self::assertSame('audio/mpeg', $response->headers->get('Content-Type'));
        $this->assertPrivateResponse($response);
        self::assertSame(self::BYTES, $this->body($response));
    }

    public function testMemberRangeReturnsExactBytesAndPreparedTransportBoundaries(): void
    {
        $this->authenticate('member');
        $response = $this->request($this->allowedSong, range: 'bytes=3-7');
        self::assertSame(206, $response->getStatusCode());
        self::assertInstanceOf(SwooleBinaryFileResponse::class, $response);
        self::assertSame('bytes 3-7/16', $response->headers->get('Content-Range'));
        self::assertSame('5', $response->headers->get('Content-Length'));
        self::assertSame(3, $response->getOffset());
        self::assertSame(5, $response->getLength());
        self::assertSame('34567', $this->body($response));
        $this->assertPrivateResponse($response);
        self::assertSame([$this->allowedSong->getPath()], $this->resolvedPaths);
    }

    public function testMembershipRevocationRejectsNextRangeRequestWithSameToken(): void
    {
        $this->authenticate('member');
        self::assertSame(200, $this->request($this->allowedSong)->getStatusCode());
        $access = $this->kernel->getContainer()->get('track.acceptance.library_access');
        self::assertInstanceOf(LibraryAccessPortInterface::class, $access);
        $access->revoke($this->member->getId(), $this->allowedLibrary->getId());
        self::assertSame(403, $this->request($this->allowedSong, range: 'bytes=3-7')->getStatusCode());
        self::assertSame([$this->allowedSong->getPath()], $this->resolvedPaths);
    }

    public function testTokenRevocationRejectsNextRangeRequestWithFreshProof(): void
    {
        $this->authenticate('member');
        self::assertSame(200, $this->request($this->allowedSong)->getStatusCode());
        $token = $this->manager->find(AccessTokenEntity::class, $this->token->getId());
        self::assertInstanceOf(AccessTokenEntity::class, $token);
        $token->revoke();
        $this->manager->flush();

        self::assertSame(401, $this->request($this->allowedSong, range: 'bytes=3-7')->getStatusCode());
        self::assertSame([$this->allowedSong->getPath()], $this->resolvedPaths);
    }

    /** @return iterable<string, array{string, int}> */
    public static function invalidPresentations(): iterable
    {
        yield 'missing DPoP' => ['missing', 403];
        yield 'wrong proof key' => ['wrong-key', 403];
        yield 'wrong token audience' => ['audience', 401];
        yield 'revoked token' => ['revoked', 401];
    }

    #[DataProvider('invalidPresentations')]
    public function testInvalidAuthenticationNeverResolvesMedia(string $kind, int $status): void
    {
        $this->authenticate('member');
        if ($kind === 'revoked') {
            $this->token->revoke();
            $this->manager->flush();
        }
        if ($kind === 'audience') {
            $this->jwt = OAuthAccessTokenJwt::sign(self::$privateKey, $this->token, 'https://other.baander.app');
        }
        self::assertNotNull($this->jwt);
        $proof = $kind === 'wrong-key' ? new SignedDpopProof() : $this->proof;
        $header = $kind === 'missing' ? null : $proof->create('GET', self::ENDPOINT, $this->jwt);
        $response = $this->request($this->allowedSong, proof: $header, generateProof: false, range: 'bytes=3-7');
        self::assertSame($status, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame([], $this->resolvedPaths);
    }

    public function testReplayedDpopCannotResolveMediaAgain(): void
    {
        $this->authenticate('member');
        self::assertNotNull($this->jwt);
        $header = $this->proof->create('GET', self::ENDPOINT, $this->jwt);
        $first = $this->request($this->allowedSong, proof: $header, generateProof: false, range: 'bytes=3-7');
        self::assertSame(206, $first->getStatusCode());
        $replayed = $this->request($this->allowedSong, proof: $header, generateProof: false, range: 'bytes=8-12');
        self::assertSame(403, $replayed->getStatusCode());
        self::assertSame([$this->allowedSong->getPath()], $this->resolvedPaths);
    }

    private function createUser(string $actor): UserEntity
    {
        $user = User::createByOperator(
            new Email('track-' . $actor . '-' . bin2hex(random_bytes(8)) . '@baander.app'),
            'unused-password',
            'Track acceptance',
            $actor === 'admin' ? ['ROLE_USER', 'ROLE_ADMIN'] : ['ROLE_USER'],
        );
        $this->users->save($user);
        $entity = $this->manager->find(UserEntity::class, $user->getId());
        self::assertInstanceOf(UserEntity::class, $entity);
        $this->entities[] = $entity;

        return $entity;
    }

    private function authenticate(string $actor): void
    {
        if ($actor === 'anonymous') {
            return;
        }
        $entity = $actor === 'member' ? $this->member : $this->createUser($actor);
        $client = new ClientEntity(
            new PublicId(),
            'Track acceptance client',
            json_encode(['https://baander.app/callback'], JSON_THROW_ON_ERROR),
        );
        $this->proof = new SignedDpopProof();
        $this->token = new AccessTokenEntity(
            (new Uuid())->toString(),
            $client,
            $entity,
            scopes: ['library'],
            expiresAt: new \DateTimeImmutable('+1 hour'),
        );
        $this->token->setDpopJkt($this->proof->thumbprint());
        $this->manager->persist($client);
        $this->manager->persist($this->token);
        $this->entities[] = $client;
        $this->entities[] = $this->token;
        $this->manager->flush();
        $this->jwt = OAuthAccessTokenJwt::sign(self::$privateKey, $this->token);
    }

    private function request(
        SongEntity $song,
        ?string $proof = null,
        bool $generateProof = true,
        ?string $range = null,
    ): Response {
        $this->manager->clear();
        $request = Request::create(self::ENDPOINT . '?id=' . $song->getPublicId());
        if ($range !== null) {
            $request->headers->set('Range', $range);
        }
        if ($this->jwt !== null) {
            $request->headers->set('Authorization', 'DPoP ' . $this->jwt);
            $proof = $generateProof ? $this->proof->create('GET', self::ENDPOINT, $this->jwt) : $proof;
            if ($proof !== null) {
                $request->headers->set('DPoP', $proof);
            }
        }
        $response = $this->kernel->handle($request);
        $this->kernel->terminate($request, $response);
        if ($response->getStatusCode() >= 500 && $this->lastException !== null) {
            self::fail((string) $this->lastException);
        }

        return $response;
    }

    private function assertPrivateResponse(Response $response): void
    {
        self::assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    private function body(Response $response): string
    {
        ob_start();
        try {
            $response->sendContent();

            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }
}
