<?php

declare(strict_types=1);

namespace App\Shared\Application\Http;

/** Shared names for Baander-defined HTTP headers. */
enum BaanderHeader: string
{
    case Client = 'X-Baander-Client';
    case ClientFingerprint = 'X-Baander-Client-Fingerprint';
    case CorrelationId = 'X-Baander-Correlation-ID';
    case DeviceId = 'X-Baander-Device-Id';
    case TestUserId = 'X-Baander-Test-User-Id';
    case WebhookSignature = 'X-Baander-Webhook-Signature';
    case WebhookSignatureVersion = 'X-Baander-Webhook-Signature-Version';
    case WebhookTimestamp = 'X-Baander-Webhook-Timestamp';

    public function serverKey(): string
    {
        return 'HTTP_' . strtoupper(str_replace('-', '_', $this->value));
    }
}
