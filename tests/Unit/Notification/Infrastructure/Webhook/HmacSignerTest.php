<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification\Infrastructure\Webhook;

use App\Notification\Infrastructure\Webhook\HmacSigner;
use PHPUnit\Framework\TestCase;

final class HmacSignerTest extends TestCase
{
    private HmacSigner $signer;

    protected function setUp(): void
    {
        $this->signer = new HmacSigner();
    }

    public function testSignProducesDeterministicSignature(): void
    {
        $payload = '{"title":"Test","body":"Hello"}';
        $secret = 'my-webhook-secret';

        $sig1 = $this->signer->sign($payload, $secret);
        $sig2 = $this->signer->sign($payload, $secret);

        $this->assertSame($sig1, $sig2);
    }

    public function testSignDifferentPayloadsProduceDifferentSignatures(): void
    {
        $secret = 'my-webhook-secret';

        $sig1 = $this->signer->sign('{"title":"First"}', $secret);
        $sig2 = $this->signer->sign('{"title":"Second"}', $secret);

        $this->assertNotSame($sig1, $sig2);
    }

    public function testSignFormatIncludesSha256Prefix(): void
    {
        $signature = $this->signer->sign('test', 'secret');

        $this->assertStringStartsWith('sha256=', $signature);
    }

    public function testVerifySucceedsWithCorrectSignature(): void
    {
        $payload = '{"test": true}';
        $secret = 'my-secret';

        $signature = $this->signer->sign($payload, $secret);

        // verify() takes the original secret, same as sign()
        $this->assertTrue($this->signer->verify($payload, $signature, $secret));
    }

    public function testVerifyFailsWithIncorrectSignature(): void
    {
        $payload = '{"test": true}';
        $secret = 'my-secret';
        $wrongSecret = 'wrong-secret';

        $signature = $this->signer->sign($payload, $secret);

        $this->assertFalse($this->signer->verify($payload, $signature, $wrongSecret));
    }

    public function testVerifyFailsWithTamperedPayload(): void
    {
        $payload = '{"test": true}';
        $secret = 'my-secret';

        $signature = $this->signer->sign($payload, $secret);

        $this->assertFalse($this->signer->verify('{"test": false}', $signature, $secret));
    }

    public function testHashSecretProducesConsistentHash(): void
    {
        $hash1 = $this->signer->hashSecret('my-secret');
        $hash2 = $this->signer->hashSecret('my-secret');

        $this->assertSame($hash1, $hash2);
    }

    public function testHashSecretDifferentSecretsProduceDifferentHashes(): void
    {
        $hash1 = $this->signer->hashSecret('secret-one');
        $hash2 = $this->signer->hashSecret('secret-two');

        $this->assertNotSame($hash1, $hash2);
    }
}
