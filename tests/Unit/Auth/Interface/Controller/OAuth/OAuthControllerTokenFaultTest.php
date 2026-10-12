<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Interface\Controller\OAuth;

use App\Auth\Application\Command\OAuth\ExchangeRefreshTokenCommand;
use App\Auth\Application\DTO\VerifiedDpopProof;
use App\Auth\Application\Exception\OAuthProtocolException;
use App\Auth\Interface\Controller\OAuth\OAuthController;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * R10: the token endpoint answers a server fault with 503 and Retry-After, as the
 * first-party refresh does, while a protocol error keeps its OAuth answer.
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

    private function controller(MessageBusInterface $bus): OAuthController
    {
        $controller = new OAuthController($this->createStub(Security::class), $bus, 'https://baander.app');
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
