<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Transcode\Application\DTO\MediaWorkerIdentity;
use App\Transcode\Infrastructure\Worker\DoctrineMediaWorkerAuthority;
use App\Tests\Support\FinalSchemaBootstrap;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\TestCase;

final class MediaWorkerAuthorityTest extends TestCase
{
    public function testCommittedLeaseReadDoesNotRenewOrWaitForUncommittedReplacementAndCommitRevokesIt(): void
    {
        $url = getenv('OUTBOX_TEST_DATABASE_URL');
        if (!$url) {
            self::markTestSkipped('Set OUTBOX_TEST_DATABASE_URL to disposable PostgreSQL.');
        }
        $params = (new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url);
        $first = DriverManager::getConnection($params);
        $second = DriverManager::getConnection($params);
        $schema = 'media_authority_test_' . bin2hex(random_bytes(8));
        $first->executeStatement('CREATE SCHEMA ' . $schema);
        try {
            foreach ([$first, $second] as $connection) {
                $connection->executeStatement('SET search_path TO ' . $schema . ', public');
            }
            FinalSchemaBootstrap::bootstrap($first);
            $identity = new MediaWorkerIdentity('authority.baander.app', str_repeat('a', 32), 'media', 7, 3);
            $first->executeStatement("INSERT INTO worker_deployment_boots (namespace, boot_id, daemon_id, manifest_hash) VALUES (:namespace, :boot, 'authority.baander.app', :manifest)", ['namespace' => $identity->namespace, 'boot' => $identity->bootId, 'manifest' => str_repeat('f', 64)]);
            $first->executeStatement("INSERT INTO worker_deployment_leases (namespace, owner_boot_id, epoch, state, expires_at) VALUES (:namespace, :boot, 3, 'active', clock_timestamp() + interval '1 hour')", ['namespace' => $identity->namespace, 'boot' => $identity->bootId]);
            $before = $second->fetchAssociative('SELECT * FROM worker_deployment_leases');
            $authority = new DoctrineMediaWorkerAuthority($first, $identity);
            $authority->assertActive();
            self::assertSame($before, $second->fetchAssociative('SELECT * FROM worker_deployment_leases'));
            self::assertSame('0', $first->fetchOne('SHOW statement_timeout'));
            self::assertSame('0', $first->fetchOne('SHOW lock_timeout'));
            $second->beginTransaction();
            $second->executeStatement('UPDATE worker_deployment_leases SET epoch = epoch + 1');
            $authority->assertActive();
            self::assertSame(0, $first->getTransactionNestingLevel());
            $second->commit();
            try {
                $authority->assertActive();
                self::fail('Committed successor authority did not revoke the old media worker.');
            } catch (\RuntimeException $error) {
                self::assertSame('Media requires active deployment authority.', $error->getMessage());
            }
            self::assertFalse($first->isConnected());
            $second->executeStatement('UPDATE worker_deployment_leases SET epoch = 3');
            $second->executeStatement('UPDATE worker_deployment_boots SET retirement_requested_at = clock_timestamp()');
            $first->executeStatement('SET search_path TO ' . $schema . ', public');
            try {
                $authority->assertActive();
                self::fail('Boot retirement did not revoke media authority with an otherwise active lease.');
            } catch (\RuntimeException $error) {
                self::assertSame('Media requires active deployment authority.', $error->getMessage());
            }
            self::assertFalse($first->isConnected());
        } finally {
            if ($second->isTransactionActive()) {
                $second->rollBack();
            }
            $second->executeStatement('DROP SCHEMA ' . $schema . ' CASCADE');
            $first->close();
            $second->close();
        }
    }
}
