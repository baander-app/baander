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
use App\Shared\Application\Http\BaanderHeader;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Auth\OAuthAccessTokenJwt;
use App\Tests\Fixtures\Auth\ProductionOAuthKernel;
use App\Tests\Fixtures\Auth\SignedDpopProof;
use App\Tests\Fixtures\Shared\SignedDeliveryRoutes;
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
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * The video delivery authorization matrix through the production firewall: real RS256 access
 * tokens, ES256 proofs, the Redis replay store, the HMAC signature and the SQL library check,
 * with only the media and encoding boundary as a fixture.
 *
 * A signed delivery URL is a bearer capability: it serves without a credential, and a
 * credential it does carry is validated by the authenticator but its proof is never read. The
 * signing endpoint and the quality ladder are DPoP-bound and library-checked. Every refusal row
 * asserts the component that refused: the authenticator's code in a 401 body, or the class and
 * message of the exception a 403 began as, recorded by a kernel-exception probe before the
 * firewall and the API's generic forbidden envelope rewrite it.
 */
final class SignedDeliveryFirewallTest extends TestCase
{
    private const string INVALID_TOKEN = 'AUTH_INVALID_TOKEN';
    private const string SIGNATURE_REFUSED = 'Invalid or expired signature.';
    private const string VIDEO_NOT_ACCESSIBLE = 'Video is not accessible.';
    private const string PROOF_REQUIRED = 'DPoP proof header is required.';
    private const string PROOF_KEY_MISMATCH = 'DPoP proof jwk thumbprint does not match token binding.';
    private const string TOKEN_UNBOUND = 'Access token is not DPoP-bound.';
    private const string PROOF_REUSED = 'DPoP proof "jti" has been reused.';
    private const string FINGERPRINT = 'disposable-client-fingerprint';
    private const string SIGN_URL = 'https://baander.app/api/stream/sign';
    private const array STREAMING_METHODS = [
        'getMasterManifest', 'getMediaManifest', 'getDashManifest', 'getSubtitleManifest',
        'getInitSegmentPath', 'getSubtitleSegmentPath', 'resolveVideoSegmentAvailability',
    ];

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
    private ?UserLibraryAccessEntity $membership = null;
    /** @var list<object> */
    private array $entities = [];
    /** @var array{class-string, string}|null the exception the last request was refused with */
    private ?array $refusal = null;
    /** @var list<string> */
    private array $tokens = [];
    /** @var list<string> */
    private array $proofs = [];
    /** @var list<string> */
    private array $signatures = [];
    /** @var list<array{string, string}> request path and response body */
    private array $bodies = [];

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
        $dispatcher = $container->get('signed_delivery.event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        // Above the firewall's exception listener (1), which turns a security refusal into an
        // HTTP exception, and the API's exception subscriber (0), which answers the generic envelope.
        $dispatcher->addListener(KernelEvents::EXCEPTION, function (ExceptionEvent $event): void {
            $throwable = $event->getThrowable();
            $this->refusal = [$throwable::class, $throwable->getMessage()];
        }, 64);
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
        $this->membership = null;
        $this->entities = [];
        $this->refusal = null;
        $this->tokens = [];
        $this->proofs = [];
        $this->signatures = [];
        $this->bodies = [];
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
        foreach (self::STREAMING_METHODS as $method) {
            $this->streaming->expects($this->once())->method($method);
        }
        foreach ($this->deliveryPaths() as $route => $path) {
            $response = $this->request('GET', $this->signedUrl($path), $jwt);
            self::assertSame(200, $response->getStatusCode(), $route . ': ' . $this->responseDetails($response));
            $this->assertSignatureRefused($this->request('GET', 'https://baander.app' . $path, $jwt), $route);
        }
        $this->assertNoCredentialInResponsesOrLogs();
    }

    public function testPublicAccessPatternOverMatchStillValidatesTheSignature(): void
    {
        $this->bus->expects($this->never())->method('dispatch');
        foreach (self::STREAMING_METHODS as $method) {
            $this->streaming->expects($this->never())->method($method);
        }

        $this->assertSignatureRefused($this->request('GET', 'https://baander.app/api/transcode/sessions/init'), 'stream_segment_init_segment');
    }

    /** @return iterable<string, array{string}> */
    public static function invalidSignatures(): iterable
    {
        foreach (['missing', 'expired', 'wrong-key', 'tampered-path', 'tampered-query', 'substituted-index'] as $kind) {
            yield $kind => [$kind];
        }
    }

    #[DataProvider('invalidSignatures')]
    public function testEveryDeliveryEndpointRejectsInvalidSignatureBeforeReadingMedia(string $kind): void
    {
        $this->bus->expects($this->never())->method('dispatch');
        foreach (self::STREAMING_METHODS as $method) {
            $this->streaming->expects($this->never())->method($method);
        }
        foreach ($this->deliveryPaths() as $route => $path) {
            if ($kind === 'substituted-index' && $route !== 'stream_segment_segment') {
                continue;
            }
            $url = match ($kind) {
                'missing' => 'https://baander.app' . $path,
                'expired' => $this->signedUrl($path, -10),
                'wrong-key' => $this->signedUrl($path, signer: new HmacStreamAuthAdapter('other-secret', 'https://baander.app')),
                'tampered-path' => str_replace($this->jobId->toString(), (new PublicId())->toString(), str_replace($this->videoId->toString(), (new Uuid())->toString(), $this->signedUrl($path))),
                'substituted-index' => str_replace('index=0', 'index=1', $this->signedUrl($path)),
                default => $this->signedUrl($path) . '&extra=changed',
            };
            $this->assertSignatureRefused($this->request('GET', $url), $route);
        }
        $this->assertNoCredentialInResponsesOrLogs();
    }

    /** @return iterable<string, array{string, string, int}> delivery route, credential condition, status */
    public static function deliveryCredentials(): iterable
    {
        $conditions = [
            'anonymous' => 200,
            'valid token without proof' => 200,
            'valid token with proof' => 200,
            'proof from another key' => 200,
            'reused proof' => 200,
            'token without key binding' => 200,
            'audience list containing the server' => 200,
            'fingerprint-bound token with the header' => 200,
            'expired token' => 401,
            'revoked token' => 401,
            'foreign audience' => 401,
            'foreign audience list' => 401,
            'missing audience' => 401,
            'object audience' => 401,
            'audience list with a non-string element' => 401,
            'token with two parts' => 401,
            'token with a non-JSON payload' => 401,
            'empty token' => 401,
            'fingerprint-bound token without the header' => 401,
            'fingerprint-bound token with a wrong header' => 401,
            'bound token under the Bearer scheme' => 401,
        ];
        foreach (['stream_master_manifest', 'stream_segment_segment'] as $route) {
            foreach ($conditions as $condition => $status) {
                yield $condition . ' on ' . $route => [$route, $condition, $status];
            }
        }
    }

    /**
     * A credential presented on a signed delivery URL must be valid although none is needed,
     * and its proof is never read: the authenticator answers 401 before the signature or the
     * streaming port is reached, while a missing, foreign-key or reused proof changes nothing.
     */
    #[DataProvider('deliveryCredentials')]
    public function testSignedDeliveryUrlValidatesAPresentedCredentialAndNeverReadsTheProof(string $route, string $condition, int $status): void
    {
        [$jwt, $proof, $scheme, $headers] = $this->credential($condition, proof: false);
        $sends = $condition === 'reused proof' ? 2 : 1;
        $this->bus->expects($this->never())->method('dispatch');
        $this->streaming->expects($status === 200 ? $this->exactly($sends) : $this->never())
            ->method($route === 'stream_master_manifest' ? 'getMasterManifest' : 'resolveVideoSegmentAvailability');
        $url = $this->signedUrl($this->deliveryPaths()[$route]);
        if ($condition === 'reused proof') {
            self::assertIsString($jwt);
            $proof = $this->mintProof($this->proof, 'GET', $url, $jwt);
        }
        for ($send = 0; $send < $sends; $send++) {
            $this->assertDecidedBy($this->request('GET', $url, $jwt, $proof, scheme: $scheme, headers: $headers), $status, self::INVALID_TOKEN, $condition);
        }
        $this->assertNoCredentialInResponsesOrLogs();
    }

    public function testMemberOfAnotherLibraryIsServedBySignedUrlWithoutCredential(): void
    {
        $this->actorToken('member');
        $this->createVideoLibrary(true);
        $foreign = $this->createVideoLibrary(false);
        $this->bus->expects($this->never())->method('dispatch');
        foreach (self::STREAMING_METHODS as $method) {
            $this->streaming->expects($this->once())->method($method);
        }
        foreach (SignedDeliveryRoutes::paths($foreign->getId()->toString(), $this->jobId->toString()) as $route => $path) {
            $response = $this->request('GET', $this->signedUrl($path));
            self::assertSame(200, $response->getStatusCode(), $route . ': ' . $this->responseDetails($response));
        }
        $this->assertNoCredentialInResponsesOrLogs();
    }

    public function testProofSentOnDeliveryIsNotConsumedAndStillSignsAfterwards(): void
    {
        $jwt = $this->actorToken('member');
        $video = $this->createVideoLibrary(true);
        $this->bus->expects($this->exactly(3))->method('dispatch')
            ->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));
        $this->streaming->expects($this->once())->method('getMasterManifest');
        $path = '/api/transcode/' . $video->getId()->toString() . '/master.m3u8';
        $proof = $this->mintProof($this->proof, 'POST', self::SIGN_URL, $jwt);

        $delivery = $this->request('GET', $this->signedUrl($path), $jwt, $proof);
        self::assertSame(200, $delivery->getStatusCode(), $this->responseDetails($delivery));
        $signing = $this->request('POST', self::SIGN_URL, $jwt, $proof, ['path' => $path]);
        self::assertSame(200, $signing->getStatusCode(), $this->responseDetails($signing));
        $this->issuedUrl($signing);
        $this->assertNoCredentialInResponsesOrLogs();
    }

    /** @return iterable<string, array{string, string, int, ?string}> actor, credential condition, status, deciding code or message */
    public static function boundEndpointRows(): iterable
    {
        yield 'anonymous' => ['anonymous', 'anonymous', 401, null];
        yield 'owner' => ['owner', 'valid token with proof', 200, null];
        yield 'member' => ['member', 'valid token with proof', 200, null];
        yield 'admin' => ['admin', 'valid token with proof', 200, null];
        yield 'unrelated user' => ['unrelated', 'valid token with proof', 403, self::VIDEO_NOT_ACCESSIBLE];
        yield 'member of another library' => ['foreign member', 'valid token with proof', 403, self::VIDEO_NOT_ACCESSIBLE];
        yield 'missing proof' => ['member', 'missing proof', 403, self::PROOF_REQUIRED];
        yield 'proof from another key' => ['member', 'proof from another key', 403, self::PROOF_KEY_MISMATCH];
        yield 'token without key binding' => ['member', 'token without key binding', 403, self::TOKEN_UNBOUND];
        yield 'reused proof' => ['member', 'reused proof', 403, self::PROOF_REUSED];
        yield 'expired token' => ['member', 'expired token', 401, self::INVALID_TOKEN];
        yield 'revoked token' => ['member', 'revoked token', 401, self::INVALID_TOKEN];
        yield 'foreign audience' => ['member', 'foreign audience', 401, self::INVALID_TOKEN];
        yield 'token with two parts' => ['member', 'token with two parts', 401, self::INVALID_TOKEN];
        yield 'fingerprint-bound token without the header' => ['member', 'fingerprint-bound token without the header', 401, self::INVALID_TOKEN];
        yield 'fingerprint-bound token with the header' => ['member', 'fingerprint-bound token with the header', 200, null];
        yield 'bound token under the Bearer scheme' => ['member', 'bound token under the Bearer scheme', 401, self::INVALID_TOKEN];
        yield 'bound token under the Bearer scheme without a proof' => ['member', 'bound token under the Bearer scheme without a proof', 401, self::INVALID_TOKEN];
    }

    /**
     * The signing endpoint and the quality ladder answer with the component that decided: the
     * firewall for an anonymous request, the authenticator for an invalid token, the binding
     * listener for a proof fault and the library check for a user without access. A success
     * signs with three session dispatches and reads the ladder from the port once.
     */
    #[DataProvider('boundEndpointRows')]
    public function testBoundEndpointsDecideByActorAndCredential(string $actor, string $condition, int $status, ?string $discriminator): void
    {
        [$jwt, $proof, $scheme, $headers] = $this->credential($condition, $actor);
        if ($actor === 'foreign member') {
            $this->createVideoLibrary(true);
        }
        $video = $this->createVideoLibrary(in_array($actor, ['owner', 'member'], true));
        $accepted = $status === 200 || $condition === 'reused proof';
        $this->bus->expects($accepted ? $this->exactly(3) : $this->never())->method('dispatch')
            ->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));
        $this->streaming->expects($accepted ? $this->once() : $this->never())->method('getQualityLadderForVideo');
        $id = $video->getId()->toString();
        $requests = [
            ['GET', 'https://baander.app/api/transcode/' . $id . '/quality-ladder', []],
            ['POST', self::SIGN_URL, ['path' => '/api/transcode/' . $id . '/master.m3u8']],
        ];
        foreach ($requests as [$method, $url, $data]) {
            $sent = $proof;
            if ($condition === 'reused proof') {
                self::assertIsString($jwt);
                $sent = $this->mintProof($this->proof, $method, $url, $jwt);
                $first = $this->request($method, $url, $jwt, $sent, $data, $scheme, $headers);
                self::assertSame(200, $first->getStatusCode(), $method . ' ' . $url . ': ' . $this->responseDetails($first));
            }
            $response = $this->request($method, $url, $jwt, $sent, $data, $scheme, $headers);
            $this->assertDecidedBy($response, $status, $discriminator, $method . ' ' . $url);
            if ($method === 'POST' && $response->getStatusCode() === 200) {
                $this->issuedUrl($response);
            }
        }
        $this->assertNoCredentialInResponsesOrLogs();
    }

    public function testRevokedCredentialsCannotAuthenticateEvenWithValidDeliverySignature(): void
    {
        $jwt = $this->actorToken('member');
        $this->accessToken->revoke();
        $this->manager->flush();
        $this->bus->expects($this->never())->method('dispatch');
        foreach (self::STREAMING_METHODS as $method) {
            $this->streaming->expects($this->never())->method($method);
        }
        foreach ($this->deliveryPaths() as $route => $path) {
            $this->assertDecidedBy($this->request('GET', $this->signedUrl($path), $jwt), 401, self::INVALID_TOKEN, $route);
        }
        $this->assertNoCredentialInResponsesOrLogs();
    }

    public function testMembershipRemovedAfterSigningLeavesTheSignedUrlServingAndRefusesNewGrants(): void
    {
        $jwt = $this->actorToken('member');
        $video = $this->createVideoLibrary(true);
        $id = $video->getId()->toString();
        $this->bus->expects($this->exactly(3))->method('dispatch')
            ->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));
        $this->streaming->expects($this->once())->method('getMasterManifest');
        $this->streaming->expects($this->never())->method('getQualityLadderForVideo');
        $data = ['path' => '/api/transcode/' . $id . '/master.m3u8'];
        $url = $this->issuedUrl($this->request('POST', self::SIGN_URL, $jwt, true, $data));

        $this->removeMembership();

        $delivery = $this->request('GET', $url);
        self::assertSame(200, $delivery->getStatusCode(), $this->responseDetails($delivery));
        $this->assertDecidedBy($this->request('GET', 'https://baander.app/api/transcode/' . $id . '/quality-ladder', $jwt, true), 403, self::VIDEO_NOT_ACCESSIBLE, 'quality ladder');
        $this->assertDecidedBy($this->request('POST', self::SIGN_URL, $jwt, true, $data), 403, self::VIDEO_NOT_ACCESSIBLE, 'new signature');
        $this->assertNoCredentialInResponsesOrLogs();
    }

    public function testTokenRevokedAfterSigningLeavesTheSignedUrlServingWithoutIt(): void
    {
        $jwt = $this->actorToken('member');
        $video = $this->createVideoLibrary(true);
        $this->bus->expects($this->exactly(3))->method('dispatch')
            ->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));
        $this->streaming->expects($this->once())->method('getMasterManifest');
        $url = $this->issuedUrl($this->request('POST', self::SIGN_URL, $jwt, true, [
            'path' => '/api/transcode/' . $video->getId()->toString() . '/master.m3u8',
        ]));

        $this->accessToken->revoke();
        $this->manager->flush();

        $delivery = $this->request('GET', $url);
        self::assertSame(200, $delivery->getStatusCode(), $this->responseDetails($delivery));
        $this->assertDecidedBy($this->request('GET', $url, $jwt), 401, self::INVALID_TOKEN, 'revoked token attached');
        $this->assertNoCredentialInResponsesOrLogs();
    }

    /** @return array<string, string> route name => request path */
    private function deliveryPaths(): array
    {
        return SignedDeliveryRoutes::paths($this->videoId->toString(), $this->jobId->toString());
    }

    /**
     * The token, proof, Authorization scheme and extra headers a credential condition presents.
     * The token is minted for `$actor`; `$proof` is the default proof choice for conditions
     * that do not name one.
     *
     * @return array{?string, bool|SignedDpopProof, string, array<string, string>}
     */
    private function credential(string $condition, string $actor = 'member', bool $proof = true): array
    {
        $scheme = 'DPoP';
        $headers = [];
        $fingerprintBound = str_starts_with($condition, 'fingerprint-bound');
        $jwt = match ($condition) {
            'anonymous' => null,
            'token without key binding' => $this->actorToken($actor, ['cnf' => null]),
            'audience list containing the server' => $this->actorToken($actor, ['aud' => ['https://other.baander.app', 'https://baander.app']]),
            'expired token' => $this->actorToken($actor, expiresAt: new \DateTimeImmutable('-1 minute')),
            'foreign audience' => $this->actorToken($actor, ['aud' => 'https://other.baander.app']),
            'foreign audience list' => $this->actorToken($actor, ['aud' => ['https://other.baander.app', 'https://third.baander.app']]),
            'missing audience' => $this->actorToken($actor, ['aud' => null]),
            'object audience' => $this->actorToken($actor, ['aud' => ['uri' => 'https://baander.app']]),
            'audience list with a non-string element' => $this->actorToken($actor, ['aud' => ['https://baander.app', 42]]),
            default => $this->actorToken($actor, fingerprint: $fingerprintBound ? self::FINGERPRINT : null),
        };
        switch ($condition) {
            case 'revoked token':
                $this->accessToken->revoke();
                $this->manager->flush();
                break;
            case 'token with two parts':
                self::assertIsString($jwt);
                $jwt = substr($jwt, 0, (int) strrpos($jwt, '.'));
                break;
            case 'token with a non-JSON payload':
                self::assertIsString($jwt);
                [$header, , $signature] = explode('.', $jwt);
                $jwt = $header . '.' . SignedDpopProof::encode('not json') . '.' . $signature;
                break;
            case 'empty token':
                $jwt = '';
                break;
            case 'valid token with proof':
                $proof = true;
                break;
            case 'valid token without proof':
            case 'missing proof':
                $proof = false;
                break;
            case 'proof from another key':
                $proof = new SignedDpopProof();
                break;
            case 'fingerprint-bound token with a wrong header':
                $headers = [BaanderHeader::ClientFingerprint->value => 'another-client'];
                break;
            case 'fingerprint-bound token with the header':
                $headers = [BaanderHeader::ClientFingerprint->value => self::FINGERPRINT];
                break;
            case 'bound token under the Bearer scheme':
                $scheme = 'Bearer';
                break;
            case 'bound token under the Bearer scheme without a proof':
                $scheme = 'Bearer';
                $proof = false;
                break;
        }

        return [$jwt, $proof, $scheme, $headers];
    }

    /**
     * Persists a user with a client and a DPoP-bound access token row and signs the token,
     * with `$claims` merged over the row's claims. The row expires in an hour unless given.
     *
     * @param array<string, mixed> $claims
     */
    private function actorToken(string $actor, array $claims = [], ?\DateTimeImmutable $expiresAt = null, ?string $fingerprint = null): string
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
        $token = new AccessTokenEntity((new Uuid())->toString(), $client, $entity, scopes: ['library'], expiresAt: $expiresAt ?? new \DateTimeImmutable('+1 hour'));
        $token->setDpopJkt($this->proof->thumbprint());
        $this->accessToken = $token;
        $this->persist($client);
        $this->persist($token);
        $this->manager->flush();
        if ($fingerprint !== null) {
            $this->entities[] = OAuthAccessTokenJwt::bindFingerprint($this->manager, $token, $fingerprint);
        }
        $jwt = OAuthAccessTokenJwt::sign(self::$privateKey, $token, claims: $claims);
        $this->tokens[] = $jwt;

        return $jwt;
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
            $this->membership = new UserLibraryAccessEntity($this->actor->getId(), $library, new \DateTimeImmutable());
            $this->persist($this->membership);
        }
        $this->manager->flush();

        return $video;
    }

    private function removeMembership(): void
    {
        $membership = $this->membership;
        self::assertInstanceOf(UserLibraryAccessEntity::class, $membership);
        $this->manager->remove($membership);
        $this->manager->flush();
        $this->entities = array_values(array_filter($this->entities, static fn (object $entity): bool => $entity !== $membership));
        $this->membership = null;
    }

    private function persist(object $entity): void
    {
        $this->manager->persist($entity);
        $this->entities[] = $entity;
    }

    /** Signs a delivery path and records the signature for the credential scan. */
    private function signedUrl(string $path, int $expiresInSeconds = 86400, ?HmacStreamAuthAdapter $signer = null): string
    {
        $signed = ($signer ?? $this->signer)->signUrl($path, $expiresInSeconds);
        $this->signatures[] = $signed['sig'];

        return $signed['url'];
    }

    /** The URL a successful signing response issued, with its signature recorded for the credential scan. */
    private function issuedUrl(Response $response): string
    {
        self::assertSame(200, $response->getStatusCode(), $this->responseDetails($response));
        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsString($body['sig'] ?? null);
        self::assertIsString($body['url'] ?? null);
        $this->signatures[] = $body['sig'];

        return $body['url'];
    }

    /** A proof for the request, with `htu` as the scheme, host and path of `$uri`, recorded for the credential scan. */
    private function mintProof(SignedDpopProof $key, string $method, string $uri, string $jwt): string
    {
        $parts = parse_url($uri);
        self::assertIsArray($parts);
        $proof = $key->create($method, ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? 'baander.app') . ($parts['path'] ?? '/'), $jwt);
        $this->proofs[] = $proof;

        return $proof;
    }

    /**
     * Asserts the status and the deciding component: for a 401 the authenticator's code in the
     * body, for a 403 the security exception the probe recorded. A null discriminator asserts
     * the status alone.
     */
    private function assertDecidedBy(Response $response, int $status, ?string $discriminator, string $context): void
    {
        self::assertSame($status, $response->getStatusCode(), $context . ': ' . $this->responseDetails($response));
        if ($discriminator === null || $status === 200) {
            return;
        }
        if ($status === 401) {
            $body = json_decode((string) $response->getContent(), true);
            self::assertIsArray($body);
            self::assertSame($discriminator, $body['error']['code'] ?? null, $context);

            return;
        }
        self::assertSame([AccessDeniedException::class, $discriminator], $this->refusal, $context);
    }

    /**
     * A signature fault answers 403 from the controller: the manifest routes throw the
     * access-denied HTTP exception the probe records, the segment routes answer their own body.
     */
    private function assertSignatureRefused(Response $response, string $route): void
    {
        self::assertSame(403, $response->getStatusCode(), $route . ': ' . $this->responseDetails($response));
        if (str_starts_with($route, 'stream_segment_')) {
            self::assertSame(['error' => 'Invalid or expired signature'], json_decode((string) $response->getContent(), true), $route);
            self::assertNull($this->refusal, $route);

            return;
        }
        self::assertSame([AccessDeniedHttpException::class, self::SIGNATURE_REFUSED], $this->refusal, $route);
    }

    /**
     * No response body and no log file of the kernel carries an access token, a proof or a
     * signature the test minted; only the signing response carries the signature it issues.
     */
    private function assertNoCredentialInResponsesOrLogs(): void
    {
        foreach ($this->bodies as [$path, $body]) {
            $secrets = [...$this->tokens, ...$this->proofs, ...($path === '/api/stream/sign' ? [] : $this->signatures)];
            foreach ($secrets as $secret) {
                self::assertStringNotContainsString($secret, $body, 'The response body of ' . $path . ' carries a credential.');
            }
        }
        foreach (glob(self::$directory . '/logs/*') ?: [] as $file) {
            $content = (string) file_get_contents($file);
            foreach ([...$this->tokens, ...$this->proofs, ...$this->signatures] as $secret) {
                self::assertStringNotContainsString($secret, $content, basename($file) . ' carries a credential.');
            }
        }
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

    /**
     * @param bool|string|SignedDpopProof $proof         false for none, true for a proof from the token's key,
     *                                                   a key for a proof from it, a string for that proof verbatim
     * @param array<string, mixed>        $data
     * @param array<string, string>       $headers
     */
    private function request(string $method, string $uri, ?string $jwt = null, bool|string|SignedDpopProof $proof = false, array $data = [], string $scheme = 'DPoP', array $headers = []): Response
    {
        $request = Request::create($uri, $method, content: $data === [] ? null : json_encode($data, JSON_THROW_ON_ERROR));
        $request->headers->set('Content-Type', 'application/json');
        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }
        if ($jwt !== null) {
            $request->headers->set('Authorization', $scheme . ' ' . $jwt);
            if ($proof !== false) {
                $request->headers->set('DPoP', is_string($proof) ? $proof : $this->mintProof($proof === true ? $this->proof : $proof, $method, $uri, $jwt));
            }
        }
        $this->refusal = null;
        $response = $this->kernel->handle($request);
        $this->kernel->terminate($request, $response);
        $this->bodies[] = [$request->getPathInfo(), (string) $response->getContent()];

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
                // The suite's kernel-exception probe listens on the dispatcher.
                $container->setAlias('signed_delivery.event_dispatcher', 'event_dispatcher')->setPublic(true);
            }
        });
    }
}
