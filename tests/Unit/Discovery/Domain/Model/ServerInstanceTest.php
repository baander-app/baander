<?php

declare(strict_types=1);

namespace App\Tests\Unit\Discovery\Domain\Model;

use App\Discovery\Domain\Model\ServerInstance;
use App\Discovery\Domain\Model\ServerInstanceState;
use App\Discovery\Domain\ValueObject\ServerStatus;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ServerInstanceTest extends TestCase
{
    public function testCreateSetsIdentityAndDefaults(): void
    {
        $server = ServerInstance::create(
            serverUrl: 'https://music.example.com',
            name: 'Home Server',
            version: '1.2.3',
            apiKey: 'secret-key',
        );

        $this->assertSame('https://music.example.com', $server->getServerUrl());
        $this->assertSame('Home Server', $server->getName());
        $this->assertSame('1.2.3', $server->getVersion());
        $this->assertSame('secret-key', $server->getApiKey());
        $this->assertSame(ServerStatus::Online, $server->getStatus());
        $this->assertNotNull($server->getLastHeartbeatAt());
        $this->assertInstanceOf(Uuid::class, $server->getId());
        $this->assertInstanceOf(PublicId::class, $server->getPublicId());
    }

    public function testNewlyCreatedServerIsHealthy(): void
    {
        $server = ServerInstance::create(
            serverUrl: 'https://music.example.com',
            name: 'Home Server',
            version: '1.2.3',
            apiKey: 'secret-key',
        );

        $this->assertTrue($server->isHealthy());
    }

    public function testUpdateHeartbeatRefreshesTimestamp(): void
    {
        $server = $this->reconstituteWith(staleHeartbeat: true);
        $this->assertFalse($server->isHealthy());

        $server->updateHeartbeat();

        $this->assertTrue($server->isHealthy());
    }

    public function testUpdateStatusChangesStatus(): void
    {
        $server = ServerInstance::create(
            serverUrl: 'https://music.example.com',
            name: 'Home Server',
            version: '1.2.3',
            apiKey: 'secret-key',
        );

        $server->updateStatus(ServerStatus::Maintenance);

        $this->assertSame(ServerStatus::Maintenance, $server->getStatus());
    }

    public function testUpdateVersionChangesVersion(): void
    {
        $server = ServerInstance::create(
            serverUrl: 'https://music.example.com',
            name: 'Home Server',
            version: '1.2.3',
            apiKey: 'secret-key',
        );

        $server->updateVersion('2.0.0');

        $this->assertSame('2.0.0', $server->getVersion());
    }

    public function testIsHealthyReturnsFalseForOfflineStatus(): void
    {
        $server = $this->reconstituteWith(status: ServerStatus::Offline);

        $this->assertFalse($server->isHealthy());
    }

    public function testIsHealthyReturnsFalseForNullHeartbeat(): void
    {
        $server = ServerInstance::reconstitute(new ServerInstanceState(
            id: Uuid::v4(),
            publicId: new PublicId(),
            serverUrl: 'https://music.example.com',
            name: 'Home Server',
            apiKey: 'secret-key',
            createdAt: new DateTimeImmutable('-1 hour'),
            version: '1.0.0',
            status: ServerStatus::Online,
            lastHeartbeatAt: null,
        ));

        $this->assertFalse($server->isHealthy());
    }

    public function testIsHealthyRespectsCustomThreshold(): void
    {
        // Heartbeat 50 seconds ago: healthy at 60s threshold, unhealthy at 30s.
        $server = $this->reconstituteWith(heartbeatSecondsAgo: 50);

        $this->assertTrue($server->isHealthy(60));
        $this->assertFalse($server->isHealthy(30));
    }

    public function testReconstitutePreservesState(): void
    {
        $state = new ServerInstanceState(
            id: Uuid::v4(),
            publicId: new PublicId(),
            serverUrl: 'https://music.example.com',
            name: 'Home Server',
            apiKey: 'secret-key',
            createdAt: new DateTimeImmutable('-1 hour'),
            version: '1.0.0',
            status: ServerStatus::Offline,
            lastHeartbeatAt: new DateTimeImmutable('-30 minutes'),
        );

        $server = ServerInstance::reconstitute($state);

        $this->assertSame($state, $server->getState());
        $this->assertSame(ServerStatus::Offline, $server->getStatus());
        $this->assertFalse($server->isHealthy());
    }

    /**
     * @param \App\Discovery\Domain\ValueObject\ServerStatus::* $status
     */
    private function reconstituteWith(
        bool $staleHeartbeat = false,
        ?ServerStatus $status = null,
        ?int $heartbeatSecondsAgo = null,
    ): ServerInstance {
        $heartbeatSecondsAgo ??= $staleHeartbeat ? 600 : null;

        return ServerInstance::reconstitute(new ServerInstanceState(
            id: Uuid::v4(),
            publicId: new PublicId(),
            serverUrl: 'https://music.example.com',
            name: 'Home Server',
            apiKey: 'secret-key',
            createdAt: new DateTimeImmutable('-1 hour'),
            version: '1.0.0',
            status: $status ?? ServerStatus::Online,
            lastHeartbeatAt: $heartbeatSecondsAgo !== null
                ? new DateTimeImmutable("-{$heartbeatSecondsAgo} seconds")
                : new DateTimeImmutable(),
        ));
    }
}
