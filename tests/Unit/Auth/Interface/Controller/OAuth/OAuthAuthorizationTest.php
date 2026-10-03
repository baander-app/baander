<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Interface\Controller\OAuth;

use App\Auth\Domain\Model\User;
use App\Auth\Application\Port\DpopJtiCacheInterface;
use App\Shared\Infrastructure\Redis\RedisClientFactory;
use App\Auth\Domain\Repository\OAuth\AccessTokenRepositoryInterface as DomainAccessTokens;
use App\Auth\Domain\Repository\OAuth\AuthCodeRepositoryInterface as DomainAuthCodes;
use App\Auth\Domain\Repository\OAuth\ClientRepositoryInterface as DomainClients;
use App\Auth\Domain\Repository\OAuth\DeviceCodeRepositoryInterface as DomainDevices;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Auth\Infrastructure\Security\OAuth\DpopNonceManager;
use App\Auth\Infrastructure\Security\OAuth\DpopProofValidator;
use App\Auth\Infrastructure\Security\SecurityUser;
use App\Auth\Interface\Controller\OAuth\OAuthController;
use App\Shared\Domain\Model\Email;
use DateInterval;
use DateTimeImmutable;
use Defuse\Crypto\Crypto;
use Defuse\Crypto\Key;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\CryptKey;
use League\OAuth2\Server\Entities\AuthCodeEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Grant\AuthCodeGrant;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use League\OAuth2\Server\Repositories\DeviceCodeRepositoryInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;
use League\OAuth2\Server\ResourceServer;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Exercise League's actual PKCE authorization validation, issuance and encryption. */
final class OAuthAuthorizationTest extends TestCase
{
    #[DataProvider('httpMethods')]
    public function testAuthenticatedAuthorizationIssuesCodeBoundToUserAndPkce(string $method): void
    {
        $user = User::register(Email::fromString('authorization@baander.app'), 'hashed-password', 'Authorization user');
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new SecurityUser($user->getId()->toString(), 'authorization@baander.app', 'hashed-password'));
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->expects(self::once())->method('findByUuid')->with(self::equalTo($user->getId()))->willReturn($user);
        $key = Key::createNewRandomKey();
        $controller = $this->controller($security, $users, $key, $user->getId()->toString());
        $challenge = str_repeat('a', 43);
        $request = Request::create('https://baander.app/api/oauth/authorize?' . http_build_query([
            'response_type' => 'code', 'client_id' => 'authorization-client',
            'redirect_uri' => 'https://client.baander.app/callback', 'state' => 'authorization-state',
            'code_challenge' => $challenge, 'code_challenge_method' => 'S256',
        ]), $method);

        $response = $controller->authorize($request);

        self::assertSame(302, $response->getStatusCode());
        $location = $response->headers->get('Location');
        self::assertNotNull($location);
        self::assertStringStartsWith('https://client.baander.app/callback?', $location);
        $query = parse_url($location, PHP_URL_QUERY);
        self::assertIsString($query);
        parse_str($query, $parameters);
        self::assertSame('authorization-state', $parameters['state']);
        self::assertSame('https://baander.app', $parameters['iss']);
        self::assertIsString($parameters['code']);
        $payload = json_decode(Crypto::decrypt($parameters['code'], $key), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertSame($user->getId()->toString(), $payload['user_id']);
        self::assertSame('authorization-client', $payload['client_id']);
        self::assertSame($challenge, $payload['code_challenge']);
        self::assertSame('S256', $payload['code_challenge_method']);
    }

    public function testUnsupportedSecurityPrincipalIsUnauthorizedWithoutUserLookup(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->createStub(UserInterface::class));
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->expects(self::never())->method('findByUuid');
        $controller = $this->controller($security, $users, Key::createNewRandomKey());
        $request = Request::create('https://baander.app/api/oauth/authorize?' . http_build_query([
            'response_type' => 'code', 'client_id' => 'authorization-client',
            'redirect_uri' => 'https://client.baander.app/callback',
            'code_challenge' => str_repeat('a', 43), 'code_challenge_method' => 'S256',
        ]));
        self::assertSame(401, $controller->authorize($request)->getStatusCode());
    }

    /** @return iterable<string, array{string}> */
    public static function httpMethods(): iterable
    {
        yield 'GET' => ['GET'];
        yield 'POST' => ['POST'];
    }

    private function controller(Security $security, UserRepositoryInterface $users, Key $key, ?string $userId = null): OAuthController
    {
        $client = $this->createStub(ClientEntityInterface::class);
        $client->method('getIdentifier')->willReturn('authorization-client');
        $client->method('getRedirectUri')->willReturn('https://client.baander.app/callback');
        $client->method('isConfidential')->willReturn(false);
        $clients = $this->createStub(ClientRepositoryInterface::class);
        $clients->method('getClientEntity')->willReturn($client);
        $scopes = $this->createStub(ScopeRepositoryInterface::class);
        $scopes->method('finalizeScopes')->willReturn([]);
        $accessTokens = $this->createStub(AccessTokenRepositoryInterface::class);
        $refreshTokens = $this->createStub(RefreshTokenRepositoryInterface::class);
        $authCodes = $this->createMock(AuthCodeRepositoryInterface::class);
        if ($userId !== null) {
            $code = $this->createMock(AuthCodeEntityInterface::class);
            $code->expects(self::once())->method('setUserIdentifier')->with($userId);
            $code->method('getUserIdentifier')->willReturn($userId);
            $code->method('getIdentifier')->willReturn('issued-authorization-code');
            $code->method('getClient')->willReturn($client);
            $code->method('getRedirectUri')->willReturn('https://client.baander.app/callback');
            $code->method('getScopes')->willReturn([]);
            $code->method('getExpiryDateTime')->willReturn(new DateTimeImmutable('+10 minutes'));
            $authCodes->method('getNewAuthCode')->willReturn($code);
            $authCodes->expects(self::once())->method('persistNewAuthCode')->with($code);
        } else {
            $authCodes->expects(self::never())->method('persistNewAuthCode');
        }
        $privateKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($privateKey);
        self::assertTrue(openssl_pkey_export($privateKey, $pem));
        $server = new AuthorizationServer($clients, $accessTokens, $scopes, new CryptKey($pem), $key);
        $server->enableGrantType(new AuthCodeGrant($authCodes, $refreshTokens, new DateInterval('PT10M')), new DateInterval('PT1H'));
        $psr17 = new Psr17Factory();
        $controller = new OAuthController(
            $security, $server, $this->createStub(ResourceServer::class),
            $accessTokens, $refreshTokens, $this->createStub(DeviceCodeRepositoryInterface::class),
            $this->createStub(DomainAccessTokens::class), $this->createStub(DomainClients::class),
            $this->createStub(DomainDevices::class), $this->createStub(DomainAuthCodes::class), $users,
            new PsrHttpFactory($psr17, $psr17, $psr17, $psr17), new HttpFoundationFactory(),
            new DpopProofValidator($this->createStub(DpopJtiCacheInterface::class)),
            new DpopNonceManager($this->createStub(RedisClientFactory::class)),
            new JsonEncoder(), 'https://baander.app',
        );
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);
        $controller->setTranslator($translator);

        return $controller;
    }
}
