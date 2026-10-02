<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification\Infrastructure\Webhook;

use App\Notification\Infrastructure\Webhook\WebhookSecretCodec;
use Defuse\Crypto\Exception\WrongKeyOrModifiedCiphertextException;
use PHPUnit\Framework\TestCase;

final class WebhookSecretCodecTest extends TestCase
{
    public function testCiphertextRequiresTheApplicationSecret(): void
    {
        $codec = new WebhookSecretCodec('test-application-secret');
        $secret = bin2hex(random_bytes(32));
        $ciphertext = $codec->encrypt($secret);
        self::assertStringNotContainsString($secret, $ciphertext);
        self::assertSame($secret, $codec->decrypt($ciphertext));
        $this->expectException(WrongKeyOrModifiedCiphertextException::class);
        (new WebhookSecretCodec('different-application-secret'))->decrypt($ciphertext);
    }
}
