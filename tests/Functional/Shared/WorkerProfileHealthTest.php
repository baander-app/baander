<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared;

use App\Shared\Application\DTO\DeploymentProfile;
use App\Tests\Functional\TestCase;
use Doctrine\DBAL\Connection;

/** Web health compares its own deployment profile with the one the active worker boot started with. */
final class WorkerProfileHealthTest extends TestCase
{
    private const string NAMESPACE = 'workers.baander.app';
    private const string BOOT = '0123456789abcdef0123456789abcdef';

    public function testHealthReportsAWorkerBootStartedWithAnotherProfile(): void
    {
        $web = $this->webProfile();
        $workers = new DeploymentProfile(videoEncoderSlots: 4, audioEncoderSlots: 12);
        self::assertNotSame($web->fingerprint(), $workers->fingerprint());
        $this->activeBoot($workers->fingerprint());

        $response = $this->anonymousRequest('GET', '/health');
        $check = $this->workerProfileCheck($response->getContent());

        self::assertSame('unhealthy', $check['status']);
        self::assertSame($web->fingerprint(), $check['details']['webProfile']);
        self::assertSame([['namespace' => self::NAMESPACE, 'bootId' => self::BOOT, 'workerProfile' => $workers->fingerprint()]], $check['details']['mismatched']);
    }

    public function testHealthIsHealthyWhenTheActiveBootRunsTheWebProfile(): void
    {
        $this->activeBoot($this->webProfile()->fingerprint());

        $check = $this->workerProfileCheck($this->anonymousRequest('GET', '/health')->getContent());

        self::assertSame('healthy', $check['status']);
        self::assertSame(1, $check['details']['boots']);
    }

    public function testWithoutAnActiveBootTheProfileCannotBeCompared(): void
    {
        $check = $this->workerProfileCheck($this->anonymousRequest('GET', '/health')->getContent());

        self::assertSame('not_available', $check['status']);
        self::assertSame('No active worker boot', $check['details']['reason']);
    }

    private function webProfile(): DeploymentProfile
    {
        $profile = static::getContainer()->get(DeploymentProfile::class);
        self::assertInstanceOf(DeploymentProfile::class, $profile);

        return $profile;
    }

    private function activeBoot(string $fingerprint): void
    {
        $connection = static::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $connection->executeStatement(
            'INSERT INTO worker_deployment_boots (namespace, boot_id, daemon_id, manifest_hash, deployment_profile_fingerprint) VALUES (?, ?, ?, ?, ?)',
            [self::NAMESPACE, self::BOOT, 'daemon.baander.app', str_repeat('f', 64), $fingerprint],
        );
        $connection->executeStatement(
            "INSERT INTO worker_deployment_leases (namespace, owner_boot_id, epoch, state, expires_at) VALUES (?, ?, 1, 'active', clock_timestamp() + interval '30 seconds')",
            [self::NAMESPACE, self::BOOT],
        );
    }

    /** @return array{component:string, status:string, details:array<string, mixed>} */
    private function workerProfileCheck(string|false $content): array
    {
        self::assertIsString($content);
        $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        $checks = array_values(array_filter($data['checks'], static fn (array $check): bool => $check['component'] === 'worker_profile'));
        self::assertCount(1, $checks);

        return $checks[0];
    }
}
