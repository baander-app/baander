<?php

declare(strict_types=1);

namespace App\Tests\Functional\Discovery;

use App\Shared\Domain\Model\PublicId;
use App\Tests\Functional\TestCase;

/**
 * The routes that open a pairing session answer an unknown server with 404 and a malformed
 * server public ID with a client error, not a server error.
 */
final class PairingCodeRefusalTest extends TestCase
{
    public function testAPairingCodeForAnUnknownServerIsNotFound(): void
    {
        $response = $this->authenticatedRequest('POST', '/api/discovery/pairing-code', $this->createTestUser(), [
            'serverPublicId' => (new PublicId())->toString(),
            'method' => 'server_code',
        ]);

        $this->assertJsonResponse($response, 404);
    }

    public function testAPairingCodeForAMalformedServerPublicIdIsAValidationError(): void
    {
        $response = $this->authenticatedRequest('POST', '/api/discovery/pairing-code', $this->createTestUser(), [
            'serverPublicId' => 'not-a-public-id',
            'method' => 'server_code',
        ]);

        $this->assertJsonResponse($response, 422);
    }

    public function testAQrPayloadForAMalformedServerPublicIdIsNotFound(): void
    {
        $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/discovery/qr-payload/not-a-public-id', $this->createTestUser()), 404);
    }
}
