<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Discovery\Application\Port\ServerInstancePortInterface;
use App\Discovery\Domain\Model\PairingSession;
use App\Discovery\Domain\Model\PairingSessionState;
use App\Discovery\Domain\Model\ServerInstance;
use App\Discovery\Domain\Repository\PairingSessionRepositoryInterface;
use App\Discovery\Domain\ValueObject\AuthenticationMethod;
use App\Discovery\Domain\ValueObject\PairingCode;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Functional\Auth\RateLimitTestCase;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;

final class DiscoveryControllerSecurityTest extends RateLimitTestCase
{
    private const string QR_CLIENT_IP = '203.0.113.119';

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

    public function testAQrPayloadIsCreatedByPostAndAGetCreatesNoPairingSession(): void
    {
        $server = $this->serverPort()->register('https://discovery-security.baander.app', 'Home Server', '1.0.0', 'private-key');
        $user = $this->createTestUser();
        $uri = '/api/discovery/qr-payload/' . $server->getPublicId()->toString();

        self::assertSame(405, $this->authenticatedRequest('GET', $uri, $user)->getStatusCode());
        self::assertSame(0, $this->pairingSessionCount($server));

        $payload = $this->assertJsonResponse($this->authenticatedRequest('POST', $uri, $user), 200, 'data')['data'];

        self::assertSame(
            'baander://pair?server=' . $server->getPublicId()->toString() . '&code=' . $payload['pairingCode'],
            $payload['qrData'],
        );
        self::assertSame(1, $this->pairingSessionCount($server));
    }

    public function testAQrPayloadSpendsTheDiscoveryLimit(): void
    {
        $server = $this->serverPort()->register('https://discovery-security.baander.app', 'Home Server', '1.0.0', 'private-key');
        $this->exhaust('discovery_endpoint_ip', self::QR_CLIENT_IP);

        $response = $this->requestFrom(
            self::QR_CLIENT_IP,
            'POST',
            '/api/discovery/qr-payload/' . $server->getPublicId()->toString(),
            $this->createTestUser()->getId()->toString(),
        );

        $this->assertTooManyRequests($response);
        self::assertSame(0, $this->pairingSessionCount($server));
    }

    public function testCreatingAPairingCodeRemovesExpiredPairingSessions(): void
    {
        $server = $this->serverPort()->register('https://discovery-security.baander.app', 'Home Server', '1.0.0', 'private-key');
        $expired = $this->storedPairingSession($server, new DateTimeImmutable('-1 minute'));
        $pending = $this->storedPairingSession($server, new DateTimeImmutable('+4 minutes'));

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
        self::assertFalse($this->pairingSessionExists($expired));
        self::assertTrue($this->pairingSessionExists($pending));
        self::assertSame(2, $this->pairingSessionCount($server));
    }

    private function storedPairingSession(ServerInstance $server, DateTimeImmutable $expiresAt): PairingSession
    {
        $session = PairingSession::reconstitute(new PairingSessionState(
            id: new Uuid(),
            publicId: new PublicId(),
            serverId: $server->getId(),
            serverPublicId: $server->getPublicId(),
            serverUrl: $server->getServerUrl(),
            serverName: $server->getName(),
            pairingCode: PairingCode::generate(),
            method: AuthenticationMethod::ServerCode,
            expiresAt: $expiresAt,
            createdAt: $expiresAt->modify('-5 minutes'),
        ));
        static::getContainer()->get(PairingSessionRepositoryInterface::class)->save($session);

        return $session;
    }

    private function pairingSessionExists(PairingSession $session): bool
    {
        $count = $this->connection()->fetchOne(
            'SELECT count(*) FROM pairing_sessions WHERE id = ?',
            [$session->getId()->toString()],
        );

        return (int) $count === 1;
    }

    private function pairingSessionCount(ServerInstance $server): int
    {
        return (int) $this->connection()->fetchOne(
            'SELECT count(*) FROM pairing_sessions WHERE server_id = ?',
            [$server->getId()->toString()],
        );
    }

    private function connection(): Connection
    {
        return static::getContainer()->get(Connection::class);
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
