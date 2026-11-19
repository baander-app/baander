<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Domain\Model\OAuth\ValueObject;

use App\Auth\Domain\Model\OAuth\ValueObject\ClientKeySource;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ClientKeySourceTest extends TestCase
{
    public function testAnInlineSetOfPublicKeysIsKept(): void
    {
        $jwks = ['keys' => [self::ecKey('signing-1'), self::rsaKey('signing-2')]];

        $source = ClientKeySource::fromJwks($jwks);

        self::assertSame($jwks, $source->jwks());
        self::assertNull($source->jwksUri());
    }

    public function testASingleKeyNeedsNoKid(): void
    {
        $key = self::ecKey('unused');
        unset($key['kid']);

        self::assertSame(['keys' => [$key]], ClientKeySource::fromJwks(['keys' => [$key]])->jwks());
    }

    /** @return iterable<string, array{string}> */
    public static function privateKeyMembers(): iterable
    {
        foreach (['d', 'p', 'q', 'dp', 'dq', 'qi', 'oth'] as $member) {
            yield $member => [$member];
        }
    }

    #[DataProvider('privateKeyMembers')]
    public function testAKeyWithAPrivateMemberIsRejected(string $member): void
    {
        $key = self::rsaKey('signing-1');
        $key[$member] = 'AQAB';

        $this->expectException(InvalidArgumentException::class);
        ClientKeySource::fromJwks(['keys' => [$key]]);
    }

    public function testASymmetricKeyIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ClientKeySource::fromJwks(['keys' => [['kty' => 'oct', 'kid' => 'shared', 'k' => 'c2VjcmV0']]]);
    }

    public function testElevenKeysAreRejected(): void
    {
        $keys = array_map(static fn (int $i): array => self::ecKey('signing-' . $i), range(1, 11));

        $this->expectException(InvalidArgumentException::class);
        ClientKeySource::fromJwks(['keys' => $keys]);
    }

    public function testTwoKeysSharingAKidAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ClientKeySource::fromJwks(['keys' => [self::ecKey('signing-1'), self::rsaKey('signing-1')]]);
    }

    public function testOneOfSeveralKeysWithoutAKidIsRejected(): void
    {
        $unnamed = self::rsaKey('unused');
        unset($unnamed['kid']);

        $this->expectException(InvalidArgumentException::class);
        ClientKeySource::fromJwks(['keys' => [self::ecKey('signing-1'), $unnamed]]);
    }

    public function testASetLargerThan64KilobytesIsRejected(): void
    {
        $key = self::rsaKey('signing-1');
        $key['n'] = str_repeat('A', 64 * 1024);

        $this->expectException(InvalidArgumentException::class);
        ClientKeySource::fromJwks(['keys' => [$key]]);
    }

    public function testASetWithoutKeysIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ClientKeySource::fromJwks(['keys' => []]);
    }

    public function testAnHttpsUriOnTheDefaultPortIsKept(): void
    {
        foreach (['https://keys.baander.app/jwks.json', 'https://keys.baander.app:443/jwks.json'] as $uri) {
            $source = ClientKeySource::fromJwksUri($uri);

            self::assertSame($uri, $source->jwksUri());
            self::assertNull($source->jwks());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function unsafeUris(): iterable
    {
        yield 'plain http' => ['http://keys.baander.app/jwks.json'];
        yield 'another port' => ['https://keys.baander.app:8443/jwks.json'];
        yield 'no host' => ['https:///jwks.json'];
        yield 'credentials' => ['https://user:pass@keys.baander.app/jwks.json'];
    }

    #[DataProvider('unsafeUris')]
    public function testAnUnsafeUriIsRejected(string $uri): void
    {
        $this->expectException(InvalidArgumentException::class);
        ClientKeySource::fromJwksUri($uri);
    }

    /** @return array<string, string> */
    private static function ecKey(string $kid): array
    {
        return [
            'kty' => 'EC',
            'crv' => 'P-256',
            'kid' => $kid,
            'x' => 'f83OJ3D2xF1Bg8vub9tLe1gHMzV76e8Tus9uPHvRVEU',
            'y' => 'x_FEzRu9m36HLN_tue659LNpXW6pCyStikYjKIWI5a0',
        ];
    }

    /** @return array<string, string> */
    private static function rsaKey(string $kid): array
    {
        return ['kty' => 'RSA', 'kid' => $kid, 'e' => 'AQAB', 'n' => str_repeat('u1SU1LfVLPHCozMxH2Mo', 17)];
    }
}
