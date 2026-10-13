<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Interface\Controller\OAuth;

use App\Auth\Application\Command\OAuth\ExchangeRefreshTokenCommand;
use App\Auth\Application\Command\OAuth\RevokeTokenCommand;
use App\Auth\Application\DTO\VerifiedDpopProof;
use App\Auth\Application\Exception\OAuthProtocolException;
use App\Auth\Application\Port\AuthenticatedUserIdentityInterface;
use App\Auth\Interface\Controller\OAuth\OAuthController;
use App\Auth\Interface\Request\OAuth\RevokeTokenRequest;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The token endpoint answers a server fault with 503 and Retry-After, as the
 * first-party refresh does, while a protocol error keeps its OAuth answer. The
 * revocation endpoint does the same: 200 once the handler completed, whether or not
 * it found a token to revoke, and 503 for a fault that left the chain unrevoked.
 */
final class OAuthControllerTokenFaultTest extends TestCase
{
    public function testAServerFaultDuringTheGrantIsAnsweredWith503AndRetryAfter(): void
    {
        $fault = new \RuntimeException('SQLSTATE[08006] the database system is shutting down');
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())->method('dispatch')->willReturnCallback(
            static function (object $command) use ($fault): never {
                self::assertInstanceOf(ExchangeRefreshTokenCommand::class, $command);

                throw new HandlerFailedException(new Envelope($command), [$fault]);
            },
        );

        try {
            $this->controller($bus)->token($this->tokenRequest());
            self::fail('A server fault is not an OAuth error and must not be answered as one.');
        } catch (ServiceUnavailableHttpException $exception) {
            self::assertSame(503, $exception->getStatusCode());
            self::assertEqualsCanonicalizing(['retry-after' => '5', 'cache-control' => 'no-store'], array_change_key_case(array_map(strval(...), $exception->getHeaders()), CASE_LOWER));
            self::assertSame($fault, $exception->getPrevious());
        }
    }

    public function testAProtocolErrorKeepsItsOAuthAnswerAndTheNextNonce(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())->method('dispatch')->willReturnCallback(
            static function (object $command): never {
                throw new HandlerFailedException(new Envelope($command), [OAuthProtocolException::invalidGrant('The refresh token is revoked.')]);
            },
        );

        $response = $this->controller($bus)->token($this->tokenRequest());

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('invalid_grant', json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR)['error']);
        self::assertSame('next-nonce', $response->headers->get('DPoP-Nonce'));
    }

    public function testARevocationThatCompletedAnswers200(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())->method('dispatch')->willReturnCallback(
            static function (object $command): Envelope {
                self::assertInstanceOf(RevokeTokenCommand::class, $command);
                self::assertSame('user-7', $command->getOwnerUserId());
                self::assertTrue($command->shouldRevokeChain());

                return new Envelope($command);
            },
        );

        $response = $this->controller($bus, $this->signedInUser())->revoke(new RevokeTokenRequest(token: str_repeat('r', 80)));

        self::assertSame(200, $response->getStatusCode());
    }

    public function testAStringThatIsNoTokenIdIsAnsweredLikeAnUnknownToken(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())->method('dispatch')->willReturnCallback(
            static fn (object $command): never => throw new HandlerFailedException(new Envelope($command), [new \InvalidArgumentException('Token ID must be at least 32 characters long, got 5.')]),
        );

        $response = $this->controller($bus, $this->signedInUser())->revoke(new RevokeTokenRequest(token: 'short'));

        self::assertSame(200, $response->getStatusCode());
    }

    public function testARevocationTheDatabaseRefusedIsAnsweredWith503AndRetryAfter(): void
    {
        $fault = new \RuntimeException('SQLSTATE[08006] the database system is shutting down');
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())->method('dispatch')->willReturnCallback(
            static fn (object $command): never => throw new HandlerFailedException(new Envelope($command), [$fault]),
        );

        try {
            $this->controller($bus, $this->signedInUser())->revoke(new RevokeTokenRequest(token: str_repeat('r', 80)));
            self::fail('A chain that was not revoked still exists; the client must retry.');
        } catch (ServiceUnavailableHttpException $exception) {
            self::assertSame(503, $exception->getStatusCode());
            self::assertEqualsCanonicalizing(['retry-after' => '5', 'cache-control' => 'no-store'], array_change_key_case(array_map(strval(...), $exception->getHeaders()), CASE_LOWER));
            self::assertSame($fault, $exception->getPrevious());
        }
    }

    public function testARevocationWithoutASignedInUserIsRefused(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');

        $response = $this->controller($bus)->revoke(new RevokeTokenRequest(token: str_repeat('r', 80)));

        self::assertSame(401, $response->getStatusCode());
    }

    private function signedInUser(): UserInterface&AuthenticatedUserIdentityInterface
    {
        /** @var Stub&UserInterface&AuthenticatedUserIdentityInterface $user */
        $user = $this->createStubForIntersectionOfInterfaces([UserInterface::class, AuthenticatedUserIdentityInterface::class]);
        $user->method('getId')->willReturn('user-7');

        return $user;
    }

    private function controller(MessageBusInterface $bus, (UserInterface&AuthenticatedUserIdentityInterface)|null $user = null): OAuthController
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);
        $controller = new OAuthController($security, $bus, 'https://baander.app');
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);
        $controller->setTranslator($translator);

        return $controller;
    }

    private function tokenRequest(): Request
    {
        $request = Request::create('https://baander.app/api/oauth/token', 'POST', [
            'grant_type' => 'refresh_token',
            'client_id' => 'player',
            'refresh_token' => str_repeat('r', 80),
        ], server: ['REMOTE_ADDR' => '10.0.0.7']);
        $request->attributes->set(VerifiedDpopProof::class, new VerifiedDpopProof('thumbprint', 'next-nonce'));

        return $request;
    }
}
