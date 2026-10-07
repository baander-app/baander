<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Interface\Controller;

use App\Auth\Application\Exception\EmailVerificationException;
use App\Auth\Application\Port\DpopJtiCacheInterface;
use App\Auth\Application\Port\UserPortInterface;
use App\Auth\Infrastructure\Security\OAuth\DpopNonceManager;
use App\Auth\Infrastructure\Security\OAuth\DpopProofValidator;
use App\Auth\Infrastructure\Security\Passkey\PasskeyService;
use App\Auth\Interface\Controller\User\AuthController;
use App\Auth\Interface\Request\AcceptLanguageMatcher;
use App\Auth\Interface\Request\User\VerifyEmailRequest;
use App\Shared\Infrastructure\Redis\RedisClientFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Contracts\Translation\TranslatorInterface;
use Webauthn\Counter\CounterChecker;

/** Verifies the HTTP contract for successful and rejected email tokens. */
final class AuthControllerVerifyEmailTest extends TestCase
{
    private Security&Stub $security;
    private MessageBusInterface&MockObject $commandBus;
    private UserPortInterface $userService;
    private JsonEncoder $jsonEncoder;
    private AuthController $controller;

    protected function setUp(): void
    {
        $this->security = $this->createStub(Security::class);
        $this->commandBus = $this->createMock(MessageBusInterface::class);
        $this->userService = $this->createStub(UserPortInterface::class);
        $this->jsonEncoder = new JsonEncoder();

        // verifyEmail() does not use these dependencies, but they are final and
        // cannot be doubled, so we provide lightweight real instances.
        $passkeyService = new PasskeyService(
            appDomain: 'localhost',
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
            jsonEncoder: $this->jsonEncoder,
        );

        $dpopProofValidator = new DpopProofValidator(
            jtiCache: $this->createStub(DpopJtiCacheInterface::class),
        );

        $redisClientFactory = new RedisClientFactory('redis://localhost:6379');
        $dpopNonceManager = new DpopNonceManager($redisClientFactory);

        $this->controller = new AuthController(
            $this->security,
            $this->commandBus,
            $this->userService,
            $passkeyService,
            $dpopProofValidator,
            $dpopNonceManager,
            $this->jsonEncoder,
            new NullLogger(),
            new AcceptLanguageMatcher(),
        );

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);
        $this->controller->setTranslator($translator);
    }

    public function testMeRejectsPrincipalWithoutApplicationIdentity(): void
    {
        $this->security->method('getUser')->willReturn($this->createStub(\Symfony\Component\Security\Core\User\UserInterface::class));
        $this->commandBus->expects($this->never())->method('dispatch');
        $this->assertSame(Response::HTTP_NOT_FOUND, $this->controller->me()->getStatusCode());
    }

    public function testVerifyEmailDispatchesCommandAndMarksVerified(): void
    {
        $payload = new VerifyEmailRequest(token: 'valid-verification-token');

        $this->commandBus
            ->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function ($command) {
                return new Envelope($command, [new HandledStamp(null, 'handler')]);
            });

        $response = $this->controller->verifyEmail($payload);
        $data = json_decode((string) $response->getContent(), true);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertStringContainsStringIgnoringCase('verified', $data['data']['message']);
        $this->assertStringNotContainsStringIgnoringCase('not yet implemented', $data['data']['message']);
    }

    /** @return iterable<string, array{bool}> */
    public static function verificationFailures(): iterable
    {
        yield 'direct' => [false];
        yield 'Messenger-wrapped' => [true];
    }

    #[DataProvider('verificationFailures')]
    public function testVerifyEmailMapsUseCaseFailuresWithoutLeakingDetails(bool $wrapped): void
    {
        $failure = EmailVerificationException::invalid();
        $this->commandBus
            ->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(static function (object $command) use ($failure, $wrapped): never {
                if ($wrapped) {
                    throw new HandlerFailedException(new Envelope($command), [$failure]);
                }

                throw $failure;
            });

        $response = $this->controller->verifyEmail(new VerifyEmailRequest(token: 'invalid-token'));
        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertSame(Response::HTTP_BAD_REQUEST, $body['error']['code']);
        self::assertSame('errors.email_verification_failed', $body['error']['message']);
        self::assertStringNotContainsString($failure->getMessage(), (string) $response->getContent());
    }
}
