<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Application\Service;

use App\Auth\Application\DTO\ClientCredentials;
use App\Auth\Application\Exception\OAuthProtocolException;
use App\Auth\Application\Port\ClientAssertionVerifierInterface;
use App\Auth\Application\Service\OAuthClientAuthenticator;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Model\OAuth\ValueObject\ClientKeySource;
use App\Auth\Domain\Model\OAuth\ValueObject\ClientSecret;
use App\Auth\Domain\Repository\OAuth\ClientRepositoryInterface;
use App\Shared\Domain\Model\PublicId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Each client authenticates by its one registered method, and by no other. */
final class OAuthClientAuthenticatorTest extends TestCase
{
    private const string SECRET = 'confidential-client-secret';
    private const string ASSERTION = 'header.claims.signature';
    private const string JWT_BEARER = 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer';

    /** @var array<string, Client> */
    private array $clients = [];
    /** @var list<string> Assertions the verifier was asked about */
    private array $verified = [];

    public function testAPublicClientAuthenticatesWithItsIdAlone(): void
    {
        $client = $this->register(Client::registerPublic('Player', ['https://baander.app/callback']));

        self::assertSame($client, $this->authenticator()->authenticate(new ClientCredentials($this->id($client))));
        self::assertSame($client, $this->authenticator()->authenticate(new ClientCredentials($this->id($client), secret: '')), 'An empty secret counts as not sent.');
    }

    public function testASecretClientAuthenticatesWithItsSecret(): void
    {
        $client = $this->register(Client::registerConfidential('Server', ['https://baander.app/callback'], ClientSecret::fromString(self::SECRET)));

        self::assertSame($client, $this->authenticator()->authenticate(new ClientCredentials($this->id($client), secret: self::SECRET)));
    }

    public function testASecretClientAuthenticatesWithItsSecretInHttpBasic(): void
    {
        $client = $this->register(Client::registerConfidential('Server', ['https://baander.app/callback'], ClientSecret::fromString(self::SECRET)));

        self::assertSame($client, $this->authenticator()->authenticate(new ClientCredentials($this->id($client), basicSecret: self::SECRET)));
    }

    public function testAKeyClientAuthenticatesWithAVerifiedJwtBearerAssertion(): void
    {
        $client = $this->register($this->keyClient());

        self::assertSame($client, $this->authenticator()->authenticate(new ClientCredentials($this->id($client), assertion: self::ASSERTION, assertionType: self::JWT_BEARER)));
        self::assertSame([self::ASSERTION], $this->verified);
    }

    public function testAKeyClientFailsWhenItsAssertionDoesNotVerify(): void
    {
        $client = $this->register($this->keyClient());

        $this->assertInvalidClient(new ClientCredentials($this->id($client), assertion: self::ASSERTION, assertionType: self::JWT_BEARER), verifies: false);
    }

    /** @return iterable<string, array{'public'|'secret'|'keys', array{secret?: string, assertion?: string, assertionType?: string, basicSecret?: string}}> */
    public static function otherMethods(): iterable
    {
        yield 'a public client sending a secret' => ['public', ['secret' => self::SECRET]];
        yield 'a public client sending an assertion' => ['public', ['assertion' => self::ASSERTION, 'assertionType' => self::JWT_BEARER]];
        yield 'a secret client sending an assertion' => ['secret', ['assertion' => self::ASSERTION, 'assertionType' => self::JWT_BEARER]];
        yield 'a secret client sending its secret and an assertion' => ['secret', ['secret' => self::SECRET, 'assertion' => self::ASSERTION, 'assertionType' => self::JWT_BEARER]];
        yield 'a secret client sending a wrong secret' => ['secret', ['secret' => 'another-secret']];
        yield 'a secret client sending nothing' => ['secret', []];
        yield 'a secret client sending its secret in HTTP Basic and in the body' => ['secret', ['secret' => self::SECRET, 'basicSecret' => self::SECRET]];
        yield 'a secret client sending HTTP Basic and an assertion' => ['secret', ['basicSecret' => self::SECRET, 'assertion' => self::ASSERTION, 'assertionType' => self::JWT_BEARER]];
        yield 'a secret client sending a wrong secret in HTTP Basic' => ['secret', ['basicSecret' => 'another-secret']];
        yield 'a secret client sending an empty secret in HTTP Basic' => ['secret', ['basicSecret' => '']];
        yield 'a public client sending HTTP Basic' => ['public', ['basicSecret' => '']];
        yield 'a key client sending HTTP Basic' => ['keys', ['basicSecret' => self::SECRET]];
        yield 'a key client sending HTTP Basic and an assertion' => ['keys', ['basicSecret' => self::SECRET, 'assertion' => self::ASSERTION, 'assertionType' => self::JWT_BEARER]];
        yield 'a key client sending a secret' => ['keys', ['secret' => self::SECRET]];
        yield 'a key client sending a secret and an assertion' => ['keys', ['secret' => self::SECRET, 'assertion' => self::ASSERTION, 'assertionType' => self::JWT_BEARER]];
        yield 'a key client sending nothing' => ['keys', []];
        yield 'a key client sending an assertion without its type' => ['keys', ['assertion' => self::ASSERTION]];
        yield 'a key client sending another assertion type' => ['keys', ['assertion' => self::ASSERTION, 'assertionType' => 'urn:ietf:params:oauth:client-assertion-type:saml2-bearer']];
    }

    /** @param array{secret?: string, assertion?: string, assertionType?: string, basicSecret?: string} $presented */
    #[DataProvider('otherMethods')]
    public function testAnyMethodButTheRegisteredOneFails(string $registered, array $presented): void
    {
        $client = $this->register(match ($registered) {
            'public' => Client::registerPublic('Player', ['https://baander.app/callback']),
            'secret' => Client::registerConfidential('Server', ['https://baander.app/callback'], ClientSecret::fromString(self::SECRET)),
            default => $this->keyClient(),
        });

        $this->assertInvalidClient(new ClientCredentials($this->id($client), ...$presented));
        self::assertSame([], $this->verified, 'Only an assertion presented alone to a key client is verified.');
    }

    public function testAnUnknownOrRevokedClientFails(): void
    {
        $revoked = $this->register(Client::registerPublic('Gone', ['https://baander.app/callback']));
        $revoked->revoke();

        $this->assertInvalidClient(new ClientCredentials($this->id($revoked)));
        $this->assertInvalidClient(new ClientCredentials((new PublicId())->toString()));
        $this->assertInvalidClient(new ClientCredentials('not a public id'));
    }

    private function assertInvalidClient(ClientCredentials $credentials, bool $verifies = true): void
    {
        try {
            $this->authenticator($verifies)->authenticate($credentials);
            self::fail('The client must not authenticate.');
        } catch (OAuthProtocolException $exception) {
            self::assertSame('invalid_client', $exception->error);
            self::assertSame(401, $exception->statusCode);
        }
    }

    private function keyClient(): Client
    {
        return Client::registerConfidentialWithKeys('Signing server', ['https://baander.app/callback'], ClientKeySource::fromJwksUri('https://keys.baander.app/jwks.json'));
    }

    private function id(Client $client): string
    {
        return $client->getPublicId()->toString();
    }

    private function register(Client $client): Client
    {
        $this->clients[$this->id($client)] = $client;

        return $client;
    }

    private function authenticator(bool $verifies = true): OAuthClientAuthenticator
    {
        $repository = $this->createStub(ClientRepositoryInterface::class);
        $repository->method('findClientByPublicId')->willReturnCallback(
            fn (PublicId $publicId): ?Client => $this->clients[$publicId->toString()] ?? null,
        );
        $verifier = $this->createStub(ClientAssertionVerifierInterface::class);
        $verifier->method('verify')->willReturnCallback(function (string $assertion) use ($verifies): bool {
            $this->verified[] = $assertion;

            return $verifies;
        });

        return new OAuthClientAuthenticator($repository, $verifier);
    }
}
