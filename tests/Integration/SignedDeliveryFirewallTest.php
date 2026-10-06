<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\AccessTokenEntity;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\ClientEntity;
use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\MovieEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\MovieVideoEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\VideoEntity;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Library\Infrastructure\Doctrine\Entity\UserLibraryAccessEntity;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Auth\ProductionOAuthKernel;
use App\Tests\Fixtures\Auth\SignedDpopProof;
use App\Transcode\Application\Port\SegmentAvailabilityInterface;
use App\Transcode\Application\Port\TranscodeStreamingPortInterface;
use App\Transcode\Infrastructure\Auth\HmacStreamAuthAdapter;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/** Real production firewall/HMAC/SQL library checks; fixture media and encoding boundary only. */
final class SignedDeliveryFirewallTest extends TestCase
{
    private static string $directory;
    private static string $privateKey;
    private SignedDeliveryKernel $kernel;
    private EntityManagerInterface $manager;
    private TranscodeStreamingPortInterface&MockObject $streaming;
    private MessageBusInterface&MockObject $bus;
    private HmacStreamAuthAdapter $signer;
    private SignedDpopProof $proof;
    private Uuid $videoId;
    private PublicId $jobId;
    private UserEntity $actor;
    private AccessTokenEntity $accessToken;
    /** @var list<object> */
    private array $entities = [];

    public static function setUpBeforeClass(): void
    {
        self::$directory = sys_get_temp_dir() . '/baander-signed-firewall-' . bin2hex(random_bytes(8));
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
        self::assertSame(13, file_put_contents(self::$directory . '/segment.mp4', 'fixture-media'));
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
        $this->kernel = new SignedDeliveryKernel(self::$directory, $url);
        $this->kernel->boot();
        $container = $this->kernel->getContainer();
        $manager = $container->get('oauth.acceptance.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $this->manager = $manager;
        $this->streaming = $this->createMock(TranscodeStreamingPortInterface::class);
        $this->streaming->method('getMasterManifest')->willReturn('#EXTM3U');
        $this->streaming->method('getMediaManifest')->willReturn('#EXTINF:10');
        $this->streaming->method('getDashManifest')->willReturn('<MPD/>');
        $this->streaming->method('getSubtitleManifest')->willReturn('#EXTM3U');
        $this->streaming->method('getInitSegmentPath')->willReturn(self::$directory . '/segment.mp4');
        $this->streaming->method('getSubtitleSegmentPath')->willReturn(self::$directory . '/segment.mp4');
        $this->streaming->method('resolveVideoSegmentAvailability')->willReturn([
            'jobId' => new Uuid(), 'tierKey' => '720p', 'path' => self::$directory . '/segment.mp4',
        ]);
        $this->streaming->method('getQualityLadderForVideo')->willReturn([]);
        $availability = $this->createStub(SegmentAvailabilityInterface::class);
        $availability->method('isReady')->willReturn(self::$directory . '/segment.mp4');
        $this->bus = $this->createMock(MessageBusInterface::class);
        $container->set(TranscodeStreamingPortInterface::class, $this->streaming);
        $container->set(SegmentAvailabilityInterface::class, $availability);
        $container->set('messenger.bus.default', $this->bus);
        $this->signer = new HmacStreamAuthAdapter('disposable-stream-secret', 'https://baander.app');
        $this->proof = new SignedDpopProof();
        $this->videoId = new Uuid();
        $this->jobId = new PublicId();
        $this->entities = [];
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->entities) as $entity) {
            $this->manager->remove($entity);
        }
        if (isset($this->manager)) {
            $this->manager->flush();
            $this->manager->close();
        }
        if (isset($this->kernel)) {
            $this->kernel->shutdown();
        }
        parent::tearDown();
    }

    /** @return iterable<string, array{string}> */
    public static function actors(): iterable
    {
        foreach (['anonymous', 'owner', 'member', 'unrelated', 'admin'] as $actor) {
            yield $actor => [$actor];
        }
    }

    #[DataProvider('actors')]
    public function testSignedCapabilityAllowsDeliveryWithoutDpopProofForEveryActor(string $actor): void
    {
        $jwt = $actor === 'anonymous' ? null : $this->actorToken($actor);
        $this->bus->expects($this->never())->method('dispatch');
        foreach (['getMasterManifest', 'getMediaManifest', 'getDashManifest', 'getSubtitleManifest', 'getInitSegmentPath', 'getSubtitleSegmentPath', 'resolveVideoSegmentAvailability'] as $method) {
            $this->streaming->expects($this->once())->method($method);
        }
        foreach ($this->deliveryPaths() as $path) {
            $response = $this->request('GET', $this->signer->signUrl($path)['url'], $jwt);
            self::assertSame(200, $response->getStatusCode(), $path);
            self::assertSame(403, $this->request('GET', 'https://baander.app' . $path, $jwt)->getStatusCode(), $path);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function invalidSignatures(): iterable
    {
        foreach (['missing', 'expired', 'wrong-key', 'tampered-path', 'tampered-query'] as $kind) {
            yield $kind => [$kind];
        }
    }

    #[DataProvider('invalidSignatures')]
    public function testEveryDeliveryEndpointRejectsInvalidSignatureBeforeReadingMedia(string $kind): void
    {
        $this->bus->expects($this->never())->method('dispatch');
        foreach (['getMasterManifest', 'getMediaManifest', 'getDashManifest', 'getSubtitleManifest', 'getInitSegmentPath', 'getSubtitleSegmentPath', 'resolveVideoSegmentAvailability'] as $method) {
            $this->streaming->expects($this->never())->method($method);
        }
        foreach ($this->deliveryPaths() as $path) {
            $url = match ($kind) {
                'missing' => 'https://baander.app' . $path,
                'expired' => $this->signer->signUrl($path, -10)['url'],
                'wrong-key' => (new HmacStreamAuthAdapter('other-secret', 'https://baander.app'))->signUrl($path)['url'],
                'tampered-path' => str_replace($this->jobId->toString(), (new PublicId())->toString(), str_replace($this->videoId->toString(), (new Uuid())->toString(), $this->signer->signUrl($path)['url'])),
                default => $this->signer->signUrl($path)['url'] . '&extra=changed',
            };
            self::assertSame(403, $this->request('GET', $url)->getStatusCode(), $path);
        }
    }

    #[DataProvider('actors')]
    public function testLibraryAuthorizationControlsQualityLadderAndSigning(string $actor): void
    {
        $jwt = $actor === 'anonymous' ? null : $this->actorToken($actor);
        $allowed = in_array($actor, ['owner', 'member', 'admin'], true);
        $this->bus->expects($allowed ? $this->exactly(3) : $this->never())
            ->method('dispatch')
            ->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));
        $this->streaming->expects($allowed ? $this->once() : $this->never())->method('getQualityLadderForVideo');
        $video = $this->createVideoLibrary($actor === 'owner' || $actor === 'member');
        $path = '/api/transcode/' . $video->getId()->toString() . '/quality-ladder';
        self::assertSame($allowed ? 200 : ($actor === 'anonymous' ? 401 : 403), $this->request('GET', 'https://baander.app' . $path, $jwt, true)->getStatusCode());
        $response = $this->request('POST', 'https://baander.app/api/stream/sign', $jwt, true, [
            'path' => '/api/transcode/' . $video->getId()->toString() . '/master.m3u8',
        ]);
        self::assertSame($allowed ? 200 : ($actor === 'anonymous' ? 401 : 403), $response->getStatusCode(), $this->responseDetails($response));
    }

    public function testMembershipInAnotherLibraryCannotInitializeOrSignVideo(): void
    {
        $jwt = $this->actorToken('member');
        $this->createVideoLibrary(true);
        $foreign = $this->createVideoLibrary(false);
        $this->bus->expects($this->never())->method('dispatch');
        $this->streaming->expects($this->never())->method('getQualityLadderForVideo');
        $path = '/api/transcode/' . $foreign->getId()->toString();
        self::assertSame(403, $this->request('GET', 'https://baander.app' . $path . '/quality-ladder', $jwt, true)->getStatusCode());
        self::assertSame(403, $this->request('POST', 'https://baander.app/api/stream/sign', $jwt, true, ['path' => $path . '/master.m3u8'])->getStatusCode());
    }

    public function testRevokedCredentialsCannotAuthenticateEvenWithValidDeliverySignature(): void
    {
        $jwt = $this->actorToken('member');
        $this->accessToken->revoke();
        $this->manager->flush();
        $this->bus->expects($this->never())->method('dispatch');
        foreach (['getMasterManifest', 'getMediaManifest', 'getDashManifest', 'getSubtitleManifest', 'getInitSegmentPath', 'getSubtitleSegmentPath', 'resolveVideoSegmentAvailability'] as $method) {
            $this->streaming->expects($this->never())->method($method);
        }
        foreach ($this->deliveryPaths() as $path) {
            self::assertSame(401, $this->request('GET', $this->signer->signUrl($path)['url'], $jwt)->getStatusCode());
        }
    }

    public function testDeliveryProofExemptionDoesNotAuthorizeSigningOrQualityQueries(): void
    {
        $jwt = $this->actorToken('member');
        $video = $this->createVideoLibrary(true);
        $this->bus->expects($this->never())->method('dispatch');
        $this->streaming->expects($this->never())->method('getQualityLadderForVideo');
        $path = '/api/transcode/' . $video->getId()->toString();

        self::assertSame(403, $this->request('GET', 'https://baander.app' . $path . '/quality-ladder', $jwt)->getStatusCode());
        self::assertSame(403, $this->request('POST', 'https://baander.app/api/stream/sign', $jwt, data: ['path' => $path . '/master.m3u8'])->getStatusCode());
    }

    /** @return list<string> */
    private function deliveryPaths(): array
    {
        $video = $this->videoId->toString();
        $job = $this->jobId->toString();
        return [
            "/api/transcode/$video/master.m3u8",
            "/api/transcode/$video/manifest.mpd",
            "/api/transcode/$job/media.m3u8",
            "/api/transcode/$job/subtitles/en/media.m3u8",
            "/api/transcode/$job/init",
            "/api/transcode/$job/segment?index=0",
            "/api/transcode/$job/subtitles/en/0.vtt",
        ];
    }

    private function actorToken(string $actor): string
    {
        $users = $this->kernel->getContainer()->get('oauth.acceptance.users');
        self::assertInstanceOf(UserRepositoryInterface::class, $users);
        $user = User::createByOperator(
            new Email('stream-' . bin2hex(random_bytes(8)) . '@baander.app'),
            'test-only-password',
            'Stream actor',
            $actor === 'admin' ? ['ROLE_USER', 'ROLE_ADMIN'] : ['ROLE_USER'],
        );
        $users->save($user);
        $entity = $this->manager->find(UserEntity::class, $user->getId());
        self::assertInstanceOf(UserEntity::class, $entity);
        $this->actor = $entity;
        $this->entities[] = $entity;
        $client = new ClientEntity(new PublicId(), 'Disposable client', json_encode(['https://baander.app/callback'], JSON_THROW_ON_ERROR));
        $token = new AccessTokenEntity((new Uuid())->toString(), $client, $entity, scopes: ['library'], expiresAt: new \DateTimeImmutable('+1 hour'));
        $token->setDpopJkt($this->proof->thumbprint());
        $this->accessToken = $token;
        $this->persist($client);
        $this->persist($token);
        $this->manager->flush();
        $claims = [
            'jti' => $token->getTokenId(),
            'sub' => $user->getId()->toString(),
            'aud' => 'https://baander.app',
            'client_id' => $client->getIdentifier(),
            'scopes' => ['library'],
            'iat' => time(),
            'nbf' => time() - 1,
            'exp' => time() + 3600,
            'cnf' => ['jkt' => $this->proof->thumbprint()],
        ];
        $body = SignedDpopProof::encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR)) . '.' . SignedDpopProof::encode(json_encode($claims, JSON_THROW_ON_ERROR));
        self::assertTrue(openssl_sign($body, $signature, self::$privateKey, OPENSSL_ALGO_SHA256));

        return $body . '.' . SignedDpopProof::encode($signature);
    }

    private function createVideoLibrary(bool $grant): VideoEntity
    {
        $library = new LibraryEntity('Disposable library', bin2hex(random_bytes(16)), self::$directory, 'video', 'local');
        $movie = new MovieEntity(new PublicId(), $library, 'Disposable movie');
        $video = new VideoEntity(new PublicId(), self::$directory . '/segment.mp4', bin2hex(random_bytes(16)));
        $this->persist($library);
        $this->persist($movie);
        $this->persist($video);
        $this->persist(new MovieVideoEntity($movie, $video));
        if ($grant) {
            $this->persist(new UserLibraryAccessEntity($this->actor->getId(), $library, new \DateTimeImmutable()));
        }
        $this->manager->flush();

        return $video;
    }

    private function persist(object $entity): void
    {
        $this->manager->persist($entity);
        $this->entities[] = $entity;
    }

    private function responseDetails(Response $response): string
    {
        if ($response->getStatusCode() < 500) {
            return (string) $response->getContent();
        }
        $details = '';
        $lines = file(self::$directory . '/logs/error.log');
        if ($lines !== false) {
            foreach ($lines as $line) {
                $record = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                if (isset($record['message']) && is_string($record['message'])) {
                    $details = $record['message'];
                }
            }
        }
        return $details;
    }

    /** @param array<string, mixed> $data */
    private function request(string $method, string $uri, ?string $jwt = null, bool $withProof = false, array $data = []): Response
    {
        $request = Request::create($uri, $method, content: $data === [] ? null : json_encode($data, JSON_THROW_ON_ERROR));
        $request->headers->set('Content-Type', 'application/json');
        if ($jwt !== null) {
            $request->headers->set('Authorization', 'DPoP ' . $jwt);
            if ($withProof) {
                $parts = parse_url($uri);
                $proofUri = $parts['scheme'] . '://' . $parts['host'] . ($parts['path'] ?? '/');
                $request->headers->set('DPoP', $this->proof->create($method, $proofUri, $jwt));
            }
        }
        $response = $this->kernel->handle($request);
        $this->kernel->terminate($request, $response);

        return $response;
    }
}

final class SignedDeliveryKernel extends ProductionOAuthKernel
{
    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                foreach ([TranscodeStreamingPortInterface::class, SegmentAvailabilityInterface::class, 'messenger.bus.default'] as $id) {
                    $container->removeAlias($id);
                    $container->setDefinition($id, (new Definition())->setSynthetic(true)->setPublic(true));
                }
                $container->getDefinition(HmacStreamAuthAdapter::class)->setArgument('$hmacSecret', 'disposable-stream-secret')->setArgument('$appDomain', 'https://baander.app');
            }
        });
    }
}
