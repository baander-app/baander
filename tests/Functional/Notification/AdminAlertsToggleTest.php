<?php

declare(strict_types=1);

namespace App\Tests\Functional\Notification;

use App\Auth\Domain\Event\UserRegistered;
use App\Auth\Infrastructure\Event\AdminAlertSubscriber;
use App\Shared\Application\Port\AdminAlertPortInterface;
use App\Shared\Application\Port\SystemSettingStoreInterface;
use App\Shared\Application\Port\SystemSettingsPortInterface;
use App\Shared\Application\Settings\SharedSettingDefinitions;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Health\HealthAlertService;
use App\Shared\Infrastructure\Health\HealthCheckResult;
use App\Shared\Infrastructure\Health\HealthCheckService;
use App\Shared\Infrastructure\Health\HealthStatus;
use App\Tests\Functional\TestCase;
use Psr\Log\NullLogger;

/** `notifications.admin_alerts` silences health alerts only; the shared admin alert service stays open. */
final class AdminAlertsToggleTest extends TestCase
{
    public function testHealthDegradationCreatesNoAdminAlertWhileAdminAlertsAreOff(): void
    {
        $admin = $this->createSuperAdminUser();
        $this->turnAdminAlertsOff();
        $container = static::getContainer();
        $service = new HealthAlertService(
            (new \ReflectionClass(HealthCheckService::class))->newInstanceWithoutConstructor(),
            $container->get(AdminAlertPortInterface::class),
            new NullLogger(),
            $container->get(SystemSettingsPortInterface::class),
        );

        $service->evaluateAndAlert([new HealthCheckResult('redis', HealthStatus::Healthy, 1.0)]);
        $service->evaluateAndAlert([new HealthCheckResult('redis', HealthStatus::Unhealthy, 1.0)]);

        $this->assertSame(0, $this->alertCount($admin->getId(), 'admin.health_degraded'));
    }

    public function testNewRegistrationStillAlertsAdminsWhileAdminAlertsAreOff(): void
    {
        $admin = $this->createSuperAdminUser();
        $this->turnAdminAlertsOff();
        $subscriber = static::getContainer()->get(AdminAlertSubscriber::class);

        $subscriber->onUserRegistered(new UserRegistered(
            Uuid::generate(),
            new PublicId(),
            new Email('registered@baander.app'),
            'Registered User',
        ));

        $this->assertSame(1, $this->alertCount($admin->getId(), 'admin.user_registered'));
    }

    private function turnAdminAlertsOff(): void
    {
        static::getContainer()->get(SystemSettingStoreInterface::class)->save([SharedSettingDefinitions::ADMIN_ALERTS => false]);
    }

    private function alertCount(Uuid $userId, string $eventType): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM notifications WHERE user_id = ? AND event_type = ?',
            [$userId->toString(), $eventType],
        );
    }
}
