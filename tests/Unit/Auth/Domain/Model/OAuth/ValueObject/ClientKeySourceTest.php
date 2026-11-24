<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Domain\Model\OAuth\ValueObject;

use App\Auth\Domain\Model\OAuth\ValueObject\ClientKeySource;
use App\Tests\Fixtures\Auth\SignedDpopProof;
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

    public function testAnEd25519KeyIsKept(): void
    {
        $key = ['kty' => 'OKP', 'crv' => 'Ed25519', 'x' => '11qYAYKxCrfVS_7TyWQHOg7hcvPapiMlrwIaaPcHURo'];

        self::assertSame(['keys' => [$key]], ClientKeySource::fromJwks(['keys' => [$key]])->jwks());
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function nonSigningOkpKeys(): iterable
    {
        yield 'X25519' => [['kty' => 'OKP', 'crv' => 'X25519', 'x' => 'hSDwCYkwp1R0i33ctD73Wg2_Og0mOBr066SpjqqbTmo']];
        yield 'Ed448' => [['kty' => 'OKP', 'crv' => 'Ed448', 'x' => 'X9dEm1m0Yf0s54fsYWrUah2hNCSFpw4fig6nXYDpZ3jt8SR2m0bHBhvWeD3x5Q9s0foavq_oJWGA']];
        yield 'no curve' => [['kty' => 'OKP', 'x' => '11qYAYKxCrfVS_7TyWQHOg7hcvPapiMlrwIaaPcHURo']];
    }

    /** @param array<string, string> $key */
    #[DataProvider('nonSigningOkpKeys')]
    public function testAnOkpKeyMustBeEd25519(array $key): void
    {
        $this->expectException(InvalidArgumentException::class);
        ClientKeySource::fromJwks(['keys' => [$key]]);
    }

    public function testAnRsaKeyOf4096BitsIsKept(): void
    {
        $key = self::rsaKey('signing-1', modulusBytes: 512);

        self::assertSame(['keys' => [$key]], ClientKeySource::fromJwks(['keys' => [$key]])->jwks());
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function rsaKeysOutsideTheBounds(): iterable
    {
        yield 'a 4104-bit modulus' => [self::rsaKey('signing-1', modulusBytes: 513)];
        yield 'an 8192-bit modulus' => [self::rsaKey('signing-1', modulusBytes: 1024)];
        yield 'exponent 3' => [self::rsaKey('signing-1', exponent: "\x03")];
        yield 'a 2048-bit exponent' => [self::rsaKey('signing-1', exponent: str_repeat("\xff", 256))];
    }

    /**
     * No assertion verifies with such a key, so it is refused when it is registered or fetched.
     *
     * @param array<string, string> $key
     */
    #[DataProvider('rsaKeysOutsideTheBounds')]
    public function testAnRsaKeyOutsideTheBoundsIsRejected(array $key): void
    {
        $this->expectException(InvalidArgumentException::class);
        ClientKeySource::fromJwks(['keys' => [$key]]);
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
        yield 'an IPv4 literal' => ['https://10.0.0.5/jwks.json'];
        yield 'an IPv6 literal' => ['https://[2001:db8::1]/jwks.json'];
        yield 'a single-label host' => ['https://localhost/jwks'];
        yield 'a host with a trailing dot' => ['https://keys.baander.app./jwks.json'];
        yield 'a fragment' => ['https://keys.baander.app/jwks.json#keys'];
        yield 'an empty fragment' => ['https://keys.baander.app/jwks.json#'];
        yield 'a backslash' => ['https://keys.baander.app\\@evil.baander.app/jwks.json'];
        yield 'a space' => ['https://keys.baander.app/jw ks.json'];
        yield 'an invalid hostname' => ['https://keys_baander.app/jwks.json'];
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

    /** @return array<string, string> A 2040-bit key with the exponent 65537 unless a test asks for another shape */
    private static function rsaKey(string $kid, ?int $modulusBytes = null, string $exponent = "\x01\x00\x01"): array
    {
        return [
            'kty' => 'RSA',
            'kid' => $kid,
            'e' => SignedDpopProof::encode($exponent),
            'n' => $modulusBytes === null ? str_repeat('u1SU1LfVLPHCozMxH2Mo', 17) : SignedDpopProof::encode(str_repeat("\xff", $modulusBytes)),
        ];
    }
}
