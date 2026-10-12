<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Interface\Controller;

use App\Auth\Application\DTO\TokenResponseDTO;
use App\Auth\Application\Port\DpopJtiCacheInterface;
use App\Auth\Application\Port\UserPortInterface;
use App\Auth\Infrastructure\Security\OAuth\DpopNonceManager;
use App\Auth\Infrastructure\Security\OAuth\DpopProofValidator;
use App\Auth\Infrastructure\Security\Passkey\PasskeyService;
use App\Auth\Interface\Controller\User\AuthController;
use App\Auth\Interface\Request\OAuth\RefreshTokenRequest;
use App\Shared\Application\Http\AcceptLanguageMatcher;
use App\Shared\Infrastructure\Redis\RedisClientFactory;
use App\Tests\Fixtures\Auth\SignedDpopProof;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Redis;
use RedisException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Contracts\Translation\TranslatorInterface;
use Webauthn\Counter\CounterChecker;

/**
 * The refresh endpoint while the DPoP nonce store is unavailable (R7, R8).
 *
 * An outage answers 503 with Retry-After and no nonce, so the client keeps its session
 * and waits; a genuine nonce miss keeps its use_dpop_nonce challenge. A refresh that
 * succeeded before the post-commit nonce store failed still answers 200, without the
 * DPoP-Nonce header.
 */
final class AuthControllerRefreshOutageTest extends TestCase
{
    private const string URI = 'https://baander.app/api/auth/refresh';
    private const string REFRESH_TOKEN = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    private MessageBusInterface&MockObject $commandBus;
    private LoggerInterface&MockObject $logger;
    private Redis&Stub $redis;
    private AuthController $controller;

    protected function setUp(): void
    {
        $this->commandBus = $this->createMock(MessageBusInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->redis = $this->createStub(Redis::class);
        $jsonEncoder = new JsonEncoder();

        $factory = $this->createStub(RedisClientFactory::class);
        $redis = $this->redis;
        $factory->method('borrow')->willReturnCallback(static fn (callable $callback): mixed => $callback($redis));

        $jtiCache = $this->createStub(DpopJtiCacheInterface::class);
        $jtiCache->method('isReplay')->willReturn(false);

        $passkeyService = new PasskeyService(
            appDomain: 'baander.app',
            appName: 'Baander',
            timeout: 60000,
            authenticatorAttachment: 'platform',
            userVerification: 'preferred',
            residentKey: 'preferred',
            attestation: 'none',
            counterChecker: $this->createStub(CounterChecker::class),
            eventDispatcher: $this->createStub(EventDispatcherInterface::class),
            logger: $this->createStub(LoggerInterface::class),
            cache: $this->createStub(CacheItemPoolInterface::class),
            supportedAlgorithmIds: [-7],
            jsonEncoder: $jsonEncoder,
        );

        $this->controller = new AuthController(
            $this->createStub(Security::class),
            $this->commandBus,
            $this->createStub(UserPortInterface::class),
            $passkeyService,
            new DpopProofValidator($jtiCache),
            new DpopNonceManager($factory),
            $jsonEncoder,
            $this->logger,
            new AcceptLanguageMatcher(),
        );
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);
        $this->controller->setTranslator($translator);
    }

    public function testARefreshDuringAStoreOutageAnswers503WithoutANonceAndKeepsTheToken(): void
    {
        $this->redis->method('getDel')->willThrowException(new RedisException('Connection refused'));
        $this->commandBus->expects($this->never())->method('dispatch');
        // The nonce manager already logged the store failure; the edge adds nothing.
        $this->logger->expects($this->never())->method('error');

        $response = $this->refresh($this->proof('issued-nonce'));

        self::assertStoreUnavailable($response);
    }

    public function testAGenuineNonceMissKeepsItsChallenge(): void
    {
        $this->logger->expects($this->never())->method('error');
        $this->redis->method('getDel')->willReturn(false);
        $this->redis->method('setex')->willReturn(true);
        $this->commandBus->expects($this->never())->method('dispatch');

        $response = $this->refresh($this->proof('stale-nonce'));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('use_dpop_nonce', self::body($response)['error']);
        self::assertIsString($response->headers->get('DPoP-Nonce'));
    }

    public function testNoChallengeIsIssuedWhenItsNonceCannotBeStored(): void
    {
        $this->logger->expects($this->never())->method('error');
        $this->redis->method('setex')->willThrowException(new RedisException('read error on connection'));
        $this->commandBus->expects($this->never())->method('dispatch');

        $response = $this->refresh((new SignedDpopProof())->create('POST', self::URI, 'no-access-token'));

        self::assertStoreUnavailable($response);
    }

    public function testASuccessfulRefreshWhosePostCommitNonceStoreFailsAnswers200WithoutTheHeader(): void
    {
        $this->logger->expects($this->never())->method('error');
        $this->redis->method('getDel')->willReturn('1');
        $this->redis->method('setex')->willThrowException(new RedisException('Connection refused'));
        $this->commandBus->expects($this->once())->method('dispatch')->willReturnCallback(
            static fn (object $command): Envelope => new Envelope($command, [new HandledStamp(new TokenResponseDTO('access', refreshToken: 'rotated'), 'handler')]),
        );

        $response = $this->refresh($this->proof('issued-nonce'));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('rotated', self::body($response)['data']['refreshToken']);
        self::assertFalse($response->headers->has('DPoP-Nonce'));
    }

    public function testASuccessfulRefreshCarriesTheNonceItStored(): void
    {
        $stored = [];
        $this->logger->expects($this->never())->method('error');
        $this->redis->method('getDel')->willReturn('1');
        $this->redis->method('setex')->willReturnCallback(static function (string $key) use (&$stored): bool {
            $stored[] = $key;

            return true;
        });
        $this->commandBus->expects($this->once())->method('dispatch')->willReturnCallback(
            static fn (object $command): Envelope => new Envelope($command, [new HandledStamp(new TokenResponseDTO('access', refreshToken: 'rotated'), 'handler')]),
        );

        $response = $this->refresh($this->proof('issued-nonce'));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $nonce = $response->headers->get('DPoP-Nonce');
        self::assertIsString($nonce);
        self::assertSame(['dpop:nonce:' . $nonce], $stored);
    }

    private static function assertStoreUnavailable(JsonResponse $response): void
    {
        self::assertSame(503, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('5', $response->headers->get('Retry-After'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertFalse($response->headers->has('DPoP-Nonce'));
        self::assertFalse($response->headers->has('WWW-Authenticate'));
        self::assertSame(['error' => ['message' => 'errors.internal', 'code' => 503]], self::body($response));
    }

    private function proof(string $nonce): string
    {
        return (new SignedDpopProof())->createWithNonce('POST', self::URI, $nonce);
    }

    private function refresh(string $proof): JsonResponse
    {
        $request = Request::create(self::URI, 'POST', server: ['REMOTE_ADDR' => '10.0.0.7']);
        $request->headers->set('DPoP', $proof);

        return $this->controller->refresh(new RefreshTokenRequest(refreshToken: self::REFRESH_TOKEN), $request);
    }

    /** @return array<string, mixed> */
    private static function body(JsonResponse $response): array
    {
        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        return $body;
    }
}
