<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Application\Service;

use App\Auth\Application\Service\PendingTotpSecrets;
use App\Auth\Infrastructure\Security\Totp\SodiumTotpSecretCipher;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class PendingTotpSecretsTest extends TestCase
{
    private const string SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

    private ArrayAdapter $cache;
    private PendingTotpSecrets $pending;
    private Uuid $userId;

    protected function setUp(): void
    {
        $this->cache = new ArrayAdapter();
        $this->pending = new PendingTotpSecrets($this->cache, self::cipher());
        $this->userId = Uuid::generate();
    }

    public function testTheCacheHoldsTheSecretSealed(): void
    {
        $this->pending->hold($this->userId, self::SECRET);

        $stored = implode('', $this->cache->getValues());
        self::assertNotSame('', $stored);
        self::assertStringNotContainsString(self::SECRET, $stored);
        self::assertStringNotContainsString(base64_encode(self::SECRET), $stored);
        self::assertSame(self::SECRET, $this->pending->find($this->userId));
    }

    /** A setup held under another key, as before AUTH_TOTP_ENCRYPTION_KEY changed, counts as no setup. */
    public function testASecretThatDoesNotOpenUnderTheKeyIsNotFound(): void
    {
        $this->pending->hold($this->userId, self::SECRET);

        self::assertNull(new PendingTotpSecrets($this->cache, self::cipher())->find($this->userId));
    }

    private static function cipher(): SodiumTotpSecretCipher
    {
        return new SodiumTotpSecretCipher(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES) |> base64_encode(...));
    }
}
