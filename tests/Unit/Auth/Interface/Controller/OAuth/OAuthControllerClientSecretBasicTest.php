<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Interface\Controller\OAuth;

use App\Auth\Application\Command\OAuth\ExchangeRefreshTokenCommand;
use App\Auth\Application\Command\OAuth\PushAuthorizationRequestCommand;
use App\Auth\Application\DTO\ClientCredentials;
use App\Auth\Application\DTO\VerifiedDpopProof;
use App\Auth\Application\Exception\OAuthProtocolException;
use App\Auth\Interface\Controller\OAuth\OAuthController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The token and pushed authorization request endpoints read a client's credentials from
 * an HTTP Basic header (`client_secret_basic`, RFC 6749 section 2.3.1) as well as from
 * the body, and answer a failed Basic authentication with 401 and a Basic challenge.
 */
final class OAuthControllerClientSecretBasicTest extends TestCase
{
    public function testBasicCredentialsNameTheClientAndCarryItsSecret(): void
    {
        $bus = $this->failingBus(static function (object $command): void {
            self::assertInstanceOf(ExchangeRefreshTokenCommand::class, $command);
            // Both parts are form-urlencoded before they are joined.
            self::assertEquals(new ClientCredentials('server:1', basicSecret: 'a secret:with/specials'), $command->credentials);
        });

        $response = $this->controller($bus)->token($this->tokenRequest(basic: self::basic('server%3A1', 'a+secret%3Awith%2Fspecials')));

        self::assertSame(400, $response->getStatusCode());
    }

    public function testABodyClientIdEqualToTheBasicOneIsAccepted(): void
    {
        $bus = $this->failingBus(static function (object $command): void {
            self::assertInstanceOf(ExchangeRefreshTokenCommand::class, $command);
            self::assertEquals(new ClientCredentials('server', basicSecret: 'secret'), $command->credentials);
        });

        $this->controller($bus)->token($this->tokenRequest(['client_id' => 'server'], self::basic('server', 'secret')));
    }

    public function testAPushedRequestReadsBasicCredentialsToo(): void
    {
        $bus = $this->failingBus(static function (object $command): void {
            self::assertInstanceOf(PushAuthorizationRequestCommand::class, $command);
            self::assertEquals(new ClientCredentials('server', basicSecret: 'secret'), $command->credentials);
        });
        $request = Request::create('https://baander.app/api/oauth/par', 'POST', ['response_type' => 'code'], server: ['HTTP_AUTHORIZATION' => self::basic('server', 'secret')]);

        $response = $this->controller($bus)->par($request);

        self::assertSame(400, $response->getStatusCode());
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function refusedBasicRequests(): iterable
    {
        yield 'a body client_id naming another client' => [['client_id' => 'player'], self::basic('server', 'secret')];
        yield 'a header without a colon' => [[], 'Basic ' . base64_encode('server')];
        yield 'a header that is not base64' => [[], 'Basic !!!'];
        yield 'a header without a client id' => [[], self::basic('', 'secret')];
    }

    /** @param array<string, string> $body */
    #[DataProvider('refusedBasicRequests')]
    public function testAnInconsistentOrMalformedBasicHeaderIsRefusedWithAChallenge(array $body, string $authorization): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');

        $response = $this->controller($bus)->token($this->tokenRequest($body, $authorization));

        self::assertInvalidClientWithChallenge($response);
    }

    public function testAFailedBasicAuthenticationIsAnsweredWithAChallenge(): void
    {
        $bus = $this->failingBus(static fn (): null => null, OAuthProtocolException::invalidClient());

        self::assertInvalidClientWithChallenge($this->controller($bus)->token($this->tokenRequest(basic: self::basic('server', 'wrong'))));
    }

    public function testAFailedBodyAuthenticationCarriesNoChallenge(): void
    {
        $bus = $this->failingBus(static fn (): null => null, OAuthProtocolException::invalidClient());

        $response = $this->controller($bus)->token($this->tokenRequest(['client_id' => 'server', 'client_secret' => 'wrong']));

        self::assertSame(401, $response->getStatusCode());
        self::assertFalse($response->headers->has('WWW-Authenticate'));
    }

    private static function assertInvalidClientWithChallenge(Response $response): void
    {
        self::assertSame(401, $response->getStatusCode());
        self::assertSame('invalid_client', json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR)['error']);
        self::assertSame('Basic realm="baander", charset="UTF-8"', $response->headers->get('WWW-Authenticate'));
        self::assertSame('next-nonce', $response->headers->get('DPoP-Nonce'));
    }

    private static function basic(string $clientId, string $secret): string
    {
        return 'Basic ' . base64_encode($clientId . ':' . $secret);
    }

    /**
     * A bus that inspects the dispatched command, then fails it with a protocol error so the
     * controller answers without a token response.
     *
     * @param callable(object): mixed $inspect
     */
    private function failingBus(callable $inspect, ?OAuthProtocolException $error = null): MessageBusInterface
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())->method('dispatch')->willReturnCallback(
            static function (object $command) use ($inspect, $error): never {
                $inspect($command);

                throw new HandlerFailedException(new Envelope($command), [$error ?? OAuthProtocolException::invalidGrant('The refresh token is revoked.')]);
            },
        );

        return $bus;
    }

    private function controller(MessageBusInterface $bus): OAuthController
    {
        $controller = new OAuthController($this->createStub(Security::class), $bus, 'https://baander.app');
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);
        $controller->setTranslator($translator);

        return $controller;
    }

    /** @param array<string, string> $body */
    private function tokenRequest(array $body = [], ?string $basic = null): Request
    {
        $request = Request::create('https://baander.app/api/oauth/token', 'POST', [
            'grant_type' => 'refresh_token',
            'refresh_token' => str_repeat('r', 80),
            ...$body,
        ], server: $basic === null ? [] : ['HTTP_AUTHORIZATION' => $basic]);
        $request->attributes->set(VerifiedDpopProof::class, new VerifiedDpopProof('thumbprint', 'next-nonce'));

        return $request;
    }
}
