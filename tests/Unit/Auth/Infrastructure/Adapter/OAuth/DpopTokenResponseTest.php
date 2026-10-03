<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Infrastructure\Adapter\OAuth;

use App\Auth\Infrastructure\Adapter\OAuth\DpopTokenResponse;
use DateTimeImmutable;
use Defuse\Crypto\Crypto;
use Defuse\Crypto\Key;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DpopTokenResponseTest extends TestCase
{
    #[DataProvider('refreshModes')]
    public function testTokenTypeRewriteLeavesExactlyOneValidJsonDocument(bool $withRefresh): void
    {
        $key = Key::createNewRandomKey();
        $client = $this->createStub(ClientEntityInterface::class);
        $client->method('getIdentifier')->willReturn('client.baander.app');
        $access = $this->createStub(AccessTokenEntityInterface::class);
        $access->method('toString')->willReturn('signed-access-token');
        $access->method('getExpiryDateTime')->willReturn(new DateTimeImmutable('+1 hour'));
        $access->method('getIdentifier')->willReturn('access-id');
        $access->method('getUserIdentifier')->willReturn('user-id');
        $access->method('getClient')->willReturn($client);
        $access->method('getScopes')->willReturn([]);
        $generator = new DpopTokenResponse();
        $generator->setEncryptionKey($key);
        $generator->setAccessToken($access);
        if ($withRefresh) {
            $refresh = $this->createStub(RefreshTokenEntityInterface::class);
            $refresh->method('getIdentifier')->willReturn('refresh-id');
            $refresh->method('getExpiryDateTime')->willReturn(new DateTimeImmutable('+1 day'));
            $generator->setRefreshToken($refresh);
        }
        $response = $generator->generateHttpResponse(new Response());
        $wire = (string) $response->getBody();
        $decoded = json_decode($wire, true, 16, JSON_THROW_ON_ERROR);
        self::assertSame('DPoP', $decoded['token_type']);
        self::assertSame('signed-access-token', $decoded['access_token']);
        self::assertGreaterThan(0, $decoded['expires_in']);
        self::assertSame($wire, json_encode($decoded, JSON_THROW_ON_ERROR));
        self::assertSame('no-store', $response->getHeaderLine('cache-control'));
        if ($withRefresh) {
            $payload = json_decode(Crypto::decrypt($decoded['refresh_token'], $key), true, 16, JSON_THROW_ON_ERROR);
            self::assertSame('refresh-id', $payload['refresh_token_id']);
            self::assertSame('access-id', $payload['access_token_id']);
        }
    }

    /** @return iterable<string,array{bool}> */
    public static function refreshModes(): iterable
    {
        yield 'access only' => [false];
        yield 'access and refresh' => [true];
    }
}
