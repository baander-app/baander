<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Infrastructure\Security;

use App\Auth\Application\Exception\TotpSecretUnavailableException;
use App\Auth\Infrastructure\Security\Totp\SodiumTotpSecretCipher;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

#[RequiresPhpExtension('sodium')]
final class SodiumTotpSecretCipherTest extends TestCase
{
    private const string SECRET = 'JBSWY3DPEHPK3PXP';

    public function testASealedSecretOpensUnderTheSameKeyAndHidesThePlaintext(): void
    {
        $cipher = new SodiumTotpSecretCipher(base64_encode(str_repeat("\x01", 32)));

        $sealed = $cipher->seal(self::SECRET);

        $this->assertStringNotContainsString(self::SECRET, $sealed);
        $this->assertNotSame($sealed, $cipher->seal(self::SECRET), 'Each seal uses a fresh nonce.');
        $this->assertSame(self::SECRET, $cipher->open($sealed));
    }

    /** @return iterable<string, array{string}> */
    public static function unreadable(): iterable
    {
        $other = new SodiumTotpSecretCipher(base64_encode(str_repeat("\x02", 32)));
        yield 'another key' => [$other->seal(self::SECRET)];
        yield 'truncated' => ['short'];
        yield 'empty' => [''];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unreadable')]
    public function testACiphertextThatDoesNotOpenIsUnavailable(string $ciphertext): void
    {
        $cipher = new SodiumTotpSecretCipher(base64_encode(str_repeat("\x01", 32)));

        $this->expectException(TotpSecretUnavailableException::class);

        $cipher->open($ciphertext);
    }

    /** @return iterable<string, array{string}> */
    public static function malformedKeys(): iterable
    {
        yield 'not base64' => ['not base64!'];
        yield 'too short' => [base64_encode(str_repeat("\x01", 16))];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedKeys')]
    public function testAKeyThatIsNot32BytesIsRejected(string $key): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SodiumTotpSecretCipher($key);
    }
}
