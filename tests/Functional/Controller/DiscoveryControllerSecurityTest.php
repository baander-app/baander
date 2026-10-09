<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Discovery\Application\Port\ServerInstancePortInterface;
use App\Tests\Functional\TestCase;

final class DiscoveryControllerSecurityTest extends TestCase
{
    public function testAnonymousRegistrationCannotCreateServer(): void
    {
        $response = $this->anonymousRequest('POST', '/api/discovery/register', $this->registration());

        $this->assertJsonResponse($response, 401);
        self::assertNull($this->serverPort()->findByServerUrl('https://discovery-security.baander.app'));

        $this->serverPort()->register('https://discovery-security.baander.app', 'Home Server', '1.0.0', 'existing-private-key');
        $response = $this->anonymousRequest('POST', '/api/discovery/register', $this->registration());

        $this->assertJsonResponse($response, 401);
        self::assertStringNotContainsString('existing-private-key', $response->getContent());
        self::assertSame(
            '1.0.0',
            $this->serverPort()->findByServerUrl('https://discovery-security.baander.app')?->getVersion(),
        );
    }

    public function testOrdinaryUserCannotReregisterExistingServer(): void
    {
        $this->serverPort()->register('https://discovery-security.baander.app', 'Home Server', '1.0.0', 'existing-private-key');

        $response = $this->authenticatedRequest(
            'POST',
            '/api/discovery/register',
            $this->createTestUser(),
            $this->registration(),
        );
        $this->assertJsonResponse($response, 403);

        $server = $this->serverPort()->findByServerUrl('https://discovery-security.baander.app');
        self::assertNotNull($server);
        self::assertSame('1.0.0', $server->getVersion());
        self::assertSame('existing-private-key', $server->getApiKey());
    }

    public function testAdministratorCanRegisterWithoutExposingCredential(): void
    {
        $admin = $this->createAdminUser();
        $firstResponse = $this->authenticatedRequest('POST', '/api/discovery/register', $admin, $this->registration());
        $first = $this->assertJsonResponse($firstResponse, 201, 'data')['data'];

        self::assertArrayNotHasKey('apiKey', $first);

        $server = $this->serverPort()->findByServerUrl('https://discovery-security.baander.app');
        self::assertNotNull($server);
        self::assertNotEmpty($server->getApiKey());
        self::assertStringNotContainsString($server->getApiKey(), json_encode($first, JSON_THROW_ON_ERROR));

        $repeatResponse = $this->authenticatedRequest('POST', '/api/discovery/register', $admin, $this->registration());
        $repeat = $this->assertJsonResponse($repeatResponse, 201, 'data')['data'];

        self::assertSame($first['publicId'], $repeat['publicId']);
        self::assertArrayNotHasKey('apiKey', $repeat);
        self::assertStringNotContainsString($server->getApiKey(), json_encode($repeat, JSON_THROW_ON_ERROR));
    }

    public function testAuthenticatedUserCanStillCreatePairingCode(): void
    {
        $server = $this->serverPort()->register('https://discovery-security.baander.app', 'Home Server', '1.0.0', 'private-key');
        $response = $this->authenticatedRequest(
            'POST',
            '/api/discovery/pairing-code',
            $this->createTestUser(),
            [
                'serverPublicId' => $server->getPublicId()->toString(),
                'method' => 'server_code',
            ],
        );

        $this->assertJsonResponse($response, 201);
    }

    /** @return array{serverUrl: string, name: string, version: string} */
    private function registration(): array
    {
        return [
            'serverUrl' => 'https://discovery-security.baander.app',
            'name' => 'Home Server',
            'version' => '2.0.0',
        ];
    }

    private function serverPort(): ServerInstancePortInterface
    {
        return static::getContainer()->get(ServerInstancePortInterface::class);
    }
}
