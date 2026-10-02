<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Kernel;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/** Run against a disposable fully migrated database via test-functional-container.sh. */
final class WorkerDeploymentLeaseSchemaTest extends TestCase
{
    public function testProductionDoctrineIntrospectionPreservesMigrationOwnedLeaseTable(): void
    {
        if (!getenv('OUTBOX_TEST_DATABASE_URL')) {
            self::markTestSkipped('Requires the fully migrated disposable functional database.');
        }
        $kernel = new Kernel('test', false);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            $manager = $container->get('doctrine')->getManager();
            self::assertInstanceOf(EntityManagerInterface::class, $manager);
            $connection = $manager->getConnection();
            self::assertSame('worker_deployment_leases', $connection->fetchOne("SELECT to_regclass('worker_deployment_leases')::text"));
            self::assertSame('worker_deployment_containers', $connection->fetchOne("SELECT to_regclass('worker_deployment_containers')::text"));
            self::assertSame('worker_deployment_creations', $connection->fetchOne("SELECT to_regclass('worker_deployment_creations')::text"));
            self::assertSame('scheduler_occurrences', $connection->fetchOne("SELECT to_regclass('scheduler_occurrences')::text"));
            $tables = $connection->createSchemaManager()->listTableNames();
            self::assertNotContains('scheduler_occurrences', $tables, 'ORM introspection must preserve durable scheduler intents.');
            self::assertNotContains('worker_deployment_creations', $tables, 'ORM introspection must preserve creation intents.');
            self::assertNotContains('worker_deployment_containers', $tables, 'ORM introspection must preserve the immutable DBAL-owned inventory.');
            self::assertContains('albums', $tables, 'Ordinary ORM-managed tables remain visible.');
            self::assertNotContains('worker_deployment_leases', $tables, 'ORM introspection must not propose deleting the DBAL-owned lease table.');
            self::assertNotContains('domain_event_outbox', $tables, 'The existing migration-owned outbox exclusion remains intact.');
        } finally {
            $kernel->shutdown();
        }
    }
}
