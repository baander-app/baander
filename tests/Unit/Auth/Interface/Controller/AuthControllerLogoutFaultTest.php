<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Interface\Controller;

use App\Auth\Application\Command\OAuth\RevokeTokenCommand;
use App\Auth\Application\Port\DpopJtiCacheInterface;
use App\Auth\Application\Port\UserPortInterface;
use App\Auth\Infrastructure\Security\OAuth\DpopNonceManager;
use App\Auth\Infrastructure\Security\OAuth\DpopProofValidator;
use App\Auth\Infrastructure\Security\Passkey\PasskeyService;
use App\Auth\Interface\Controller\User\AuthController;
use App\Shared\Application\Http\AcceptLanguageMatcher;
use App\Shared\Application\Port\ServerControlException;
use App\Shared\Infrastructure\Redis\RedisClientFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Contracts\Translation\TranslatorInterface;
use Webauthn\Counter\CounterChecker;

/**
 * Logout answers 200 only when the handler completed: an unknown or already revoked
 * token completes silently (RFC 7009 section 2.2), while a fault that may have left the
 * chain or its sockets live answers 503 with Retry-After, so the client sends the
 * logout again.
 */
final class AuthControllerLogoutFaultTest extends TestCase
{
    private const string ACCESS_TOKEN_ID = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    private MessageBusInterface&MockObject $commandBus;
    private LoggerInterface&MockObject $logger;
    private AuthController $controller;

    protected function setUp(): void
    {
        $this->commandBus = $this->createMock(MessageBusInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $jsonEncoder = new JsonEncoder();

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
            new DpopProofValidator($this->createStub(DpopJtiCacheInterface::class)),
            new DpopNonceManager($this->createStub(RedisClientFactory::class)),
            $jsonEncoder,
            $this->logger,
            new AcceptLanguageMatcher(),
        );
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);
        $this->controller->setTranslator($translator);
    }

    public function testACompletedRevocationAnswers200(): void
    {
        $this->commandBus->expects($this->once())->method('dispatch')->willReturnCallback(
            static function (object $command): Envelope {
                self::assertInstanceOf(RevokeTokenCommand::class, $command);
                self::assertSame(self::ACCESS_TOKEN_ID, $command->getTokenId());
                self::assertTrue($command->shouldRevokeChain());

                return new Envelope($command, [new HandledStamp(null, 'handler')]);
            },
        );
        $this->logger->expects($this->never())->method('error');

        $response = $this->logout();

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('success.token_revoked', self::body($response)['data']['message']);
    }

    public function testAChainWhoseSocketsWereNotClosedAnswers503WithRetryAfter(): void
    {
        $fault = new ServerControlException('a worker did not answer');
        $this->commandBus->expects($this->once())->method('dispatch')->willReturnCallback(
            static fn (object $command): never => throw new HandlerFailedException(new Envelope($command), [$fault]),
        );
        $this->logger->expects($this->once())->method('error')->with(
            'Logout could not revoke the token chain.',
            ['exception' => $fault],
        );

        $response = $this->logout();

        self::assertRetryLater($response);
    }

    public function testADatabaseFaultDuringTheRevocationAnswers503WithRetryAfter(): void
    {
        $fault = new \RuntimeException('SQLSTATE[08006] the database system is shutting down');
        $this->commandBus->expects($this->once())->method('dispatch')->willReturnCallback(
            static fn (object $command): never => throw new HandlerFailedException(new Envelope($command), [$fault]),
        );
        $this->logger->expects($this->once())->method('error');

        $response = $this->logout();

        self::assertRetryLater($response);
    }

    public function testARequestWithoutTheAuthenticatedTokenIsRefused(): void
    {
        $this->commandBus->expects($this->never())->method('dispatch');
        $this->logger->expects($this->never())->method('error');

        $response = $this->controller->logout(Request::create('https://baander.app/api/auth/logout', 'POST'));

        self::assertSame(401, $response->getStatusCode());
    }

    private static function assertRetryLater(JsonResponse $response): void
    {
        self::assertSame(503, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('5', $response->headers->get('Retry-After'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame(['error' => ['message' => 'errors.internal', 'code' => 503]], self::body($response));
    }

    private function logout(): JsonResponse
    {
        $request = Request::create('https://baander.app/api/auth/logout', 'POST');
        $request->attributes->set('oauth_access_token_id', self::ACCESS_TOKEN_ID);

        return $this->controller->logout($request);
    }

    /** @return array<string, mixed> */
    private static function body(JsonResponse $response): array
    {
        return json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}
