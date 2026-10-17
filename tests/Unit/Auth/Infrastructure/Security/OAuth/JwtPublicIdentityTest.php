<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Infrastructure\Security\OAuth;

use App\Auth\Domain\Model\OAuth\AccessToken;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Model\User;
use App\Auth\Infrastructure\Security\OAuth\JwtGenerator;
use App\Shared\Domain\Model\Email;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\UnencryptedToken;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JwtPublicIdentityTest extends TestCase
{
    /** @return iterable<string, array{bool}> */
    public static function identities(): iterable
    {
        yield 'user' => [true];
        yield 'client only' => [false];
    }

    #[DataProvider('identities')]
    public function testCustomIssuerPublishesOnlyPublicEntityIdentities(bool $withUser): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        self::assertNotFalse($key);
        self::assertTrue(openssl_pkey_export($key, $pem));
        $path = tempnam(sys_get_temp_dir(), 'baander-jwt-');
        self::assertNotFalse($path);
        chmod($path, 0600);
        file_put_contents($path, $pem);
        try {
            $client = Client::create('Public claims', ['https://baander.app/callback']);
            $user = $withUser ? User::register(new Email('claims@baander.app'), 'hashed', 'Claims') : null;
            $token = AccessToken::issue($client, $user);
            $serialized = (new JwtGenerator($path, 'https://baander.app'))->generate($token, 'proof-thumbprint');
            $jwt = (new Parser(new JoseEncoder()))->parse($serialized);
            self::assertInstanceOf(UnencryptedToken::class, $jwt);
            self::assertSame($user?->getPublicId()->toString() ?? $client->getPublicId()->toString(), $jwt->claims()->get('sub'));
            self::assertSame($client->getPublicId()->toString(), $jwt->claims()->get('client_id'));
            self::assertSame($token->getTokenId()->toString(), $jwt->claims()->get('jti'));
            self::assertSame(['jkt' => 'proof-thumbprint'], $jwt->claims()->get('cnf'));
            $claims = json_encode($jwt->claims()->all(), JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString($client->getId()->toString(), $claims);
            self::assertStringNotContainsString($token->getId()->toString(), $claims);
            if ($user !== null) {
                self::assertStringNotContainsString($user->getId()->toString(), $claims);
            }
        } finally {
            unlink($path);
        }
    }
}
