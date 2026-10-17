<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Scheduler\Application\Port\ScheduledJobPortInterface;
use App\Scheduler\Domain\Service\SchedulerRegistry;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;

/** The retired health check is absent from the database and scheduler registry. */
final class HealthCheckJobRemovalTest extends TestCase
{
    use OwnershipPersistenceHarness;

    public function testFullyMigratedDatabaseHasNoHealthCheckJob(): void
    {
        self::assertFalse($this->healthCheckJobExists());

        $jobs = $this->kernel->getContainer()->get('test.service_container')->get(ScheduledJobPortInterface::class);
        self::assertInstanceOf(ScheduledJobPortInterface::class, $jobs);
        self::assertNull($jobs->getById(Uuid::fromString('0199bf3c-8a00-7000-8000-0a07c0de9e03')));
    }

    public function testSchedulerNoLongerOffersTheHealthCheckCommand(): void
    {
        $registry = $this->kernel->getContainer()->get('test.service_container')->get(SchedulerRegistry::class);
        self::assertInstanceOf(SchedulerRegistry::class, $registry);
        self::assertFalse($registry->isMessengerCommandAllowed('App\\Shared\\Application\\Command\\CheckHealthCommand'));
    }

    private function healthCheckJobExists(): bool
    {
        return $this->manager->getConnection()->fetchOne(
            'SELECT 1 FROM scheduled_jobs WHERE id = ?',
            ['0199bf3c-8a00-7000-8000-0a07c0de9e03'],
        ) !== false;
    }
}
