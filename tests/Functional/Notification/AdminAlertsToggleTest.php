<?php

declare(strict_types=1);

namespace App\Tests\Functional\Notification;

use App\Auth\Domain\Event\UserRegistered;
use App\Auth\Infrastructure\Event\AdminAlertSubscriber;
use App\Shared\Application\Port\SystemSettingStoreInterface;
use App\Shared\Application\Settings\SharedSettingDefinitions;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Health\HealthAlertService;
use App\Shared\Infrastructure\Health\HealthCheckResult;
use App\Shared\Infrastructure\Health\HealthStatus;
use App\Tests\Functional\TestCase;

/** `notifications.admin_alerts` silences health alerts only; the shared admin alert service stays open. */
final class AdminAlertsToggleTest extends TestCase
{
    public function testHealthDegradationCreatesNoAdminAlertWhileAdminAlertsAreOff(): void
    {
        $admin = $this->createSuperAdminUser();
        $this->setAdminAlerts(false);

        $this->healthAlerts()->evaluateAndAlert([new HealthCheckResult('redis', HealthStatus::Unhealthy, 1.0)]);

        $this->assertSame(0, $this->alertCount($admin->getId(), 'admin.health_degraded'));
    }

    public function testHealthDegradationCreatesOneAdminAlertPerOutageWhileAdminAlertsAreOn(): void
    {
        $admin = $this->createSuperAdminUser();
        $this->setAdminAlerts(true);
        $alerts = $this->healthAlerts();

        $alerts->evaluateAndAlert([new HealthCheckResult('redis', HealthStatus::Unhealthy, 1.0)]);
        $alerts->evaluateAndAlert([new HealthCheckResult('redis', HealthStatus::Unhealthy, 1.0)]);

        $this->assertSame(1, $this->alertCount($admin->getId(), 'admin.health_degraded'));
    }

    public function testNewRegistrationStillAlertsAdminsWhileAdminAlertsAreOff(): void
    {
        $admin = $this->createSuperAdminUser();
        $this->setAdminAlerts(false);
        $subscriber = static::getContainer()->get(AdminAlertSubscriber::class);

        $subscriber->onUserRegistered(new UserRegistered(
            Uuid::generate(),
            new PublicId(),
            new Email('registered@baander.app'),
            'Registered User',
        ));

        $this->assertSame(1, $this->alertCount($admin->getId(), 'admin.user_registered'));
    }

    private function setAdminAlerts(bool $on): void
    {
        static::getContainer()->get(SystemSettingStoreInterface::class)->save([SharedSettingDefinitions::ADMIN_ALERTS => $on]);
    }

    /** The container's service, with its own process-memory alert state. */
    private function healthAlerts(): HealthAlertService
    {
        $alerts = static::getContainer()->get(HealthAlertService::class);
        $this->assertInstanceOf(HealthAlertService::class, $alerts);

        return $alerts;
    }

    private function alertCount(Uuid $userId, string $eventType): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM notifications WHERE user_id = ? AND event_type = ?',
            [$userId->toString(), $eventType],
        );
    }
}
