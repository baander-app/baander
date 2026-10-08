<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Health;

use App\Shared\Application\Port\AdminAlertPortInterface;
use App\Shared\Application\Port\SystemSettingsPortInterface;
use App\Shared\Application\Settings\SharedSettingDefinitions;
use App\Shared\Infrastructure\Health\HealthAlertService;
use App\Shared\Infrastructure\Health\HealthCheckResult;
use App\Shared\Infrastructure\Health\HealthCheckService;
use App\Shared\Infrastructure\Health\HealthStatus;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class HealthAlertServiceTest extends TestCase
{
    public function testDegradationAlertsAdminsWhileAdminAlertsAreOn(): void
    {
        $alerts = $this->createMock(AdminAlertPortInterface::class);
        $alerts->expects($this->once())->method('alertAdmins')->with(
            'redis health degraded',
            $this->stringContains('from healthy to unhealthy'),
            'admin.health_degraded',
            ['component' => 'redis'],
        );
        $service = $this->service($alerts, $this->settings(true), new NullLogger());

        $service->evaluateAndAlert([$this->check(HealthStatus::Healthy)]);
        $service->evaluateAndAlert([$this->check(HealthStatus::Unhealthy)]);
    }

    public function testDegradationIsLoggedWithoutAnAlertWhileAdminAlertsAreOff(): void
    {
        $alerts = $this->createMock(AdminAlertPortInterface::class);
        $alerts->expects($this->never())->method('alertAdmins');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with(
            $this->stringContains('Health degradation detected'),
            ['component' => 'redis', 'from' => 'healthy', 'to' => 'unhealthy'],
        );
        $logger->expects($this->once())->method('info')->with(
            $this->stringContains('admin alerts are turned off'),
            $this->callback(static fn (array $context): bool => $context['component'] === 'redis'
                && $context['setting'] === SharedSettingDefinitions::ADMIN_ALERTS),
        );
        $service = $this->service($alerts, $this->settings(false), $logger);

        $service->evaluateAndAlert([$this->check(HealthStatus::Healthy)]);
        $service->evaluateAndAlert([$this->check(HealthStatus::Unhealthy)]);
    }

    public function testAdminAlertsSettingIsReadForEveryDegradation(): void
    {
        $alerts = $this->createMock(AdminAlertPortInterface::class);
        $alerts->expects($this->once())->method('alertAdmins');
        $settings = $this->createMock(SystemSettingsPortInterface::class);
        $settings->expects($this->exactly(2))->method('get')
            ->with(SharedSettingDefinitions::ADMIN_ALERTS)
            ->willReturnOnConsecutiveCalls(false, true);
        $service = $this->service($alerts, $settings, new NullLogger());

        $service->evaluateAndAlert([$this->check(HealthStatus::Healthy)]);
        $service->evaluateAndAlert([$this->check(HealthStatus::Unhealthy)]);
        $service->evaluateAndAlert([$this->check(HealthStatus::Healthy)]);
        $service->evaluateAndAlert([$this->check(HealthStatus::NotAvailable)]);
    }

    public function testFailedAlertDeliveryIsRetriedOnTheNextCheck(): void
    {
        $alerts = $this->createMock(AdminAlertPortInterface::class);
        $alerts->expects($this->exactly(2))->method('alertAdmins')
            ->willReturnOnConsecutiveCalls(
                $this->throwException(new \RuntimeException('PostgreSQL is down')),
                null,
            );
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with(
            $this->stringContains('alert for {component} failed'),
            $this->callback(static fn (array $context): bool => $context['component'] === 'redis'
                && $context['exception'] instanceof \RuntimeException),
        );
        $service = $this->service($alerts, $this->settings(true), $logger);

        $service->evaluateAndAlert([$this->check(HealthStatus::Healthy)]);
        $service->evaluateAndAlert([$this->check(HealthStatus::Unhealthy)]);
        $service->evaluateAndAlert([$this->check(HealthStatus::Unhealthy)]);
    }

    public function testDeliveredAlertIsNotRepeatedWhileTheComponentStaysDegraded(): void
    {
        $alerts = $this->createMock(AdminAlertPortInterface::class);
        $alerts->expects($this->once())->method('alertAdmins');
        $service = $this->service($alerts, $this->settings(true), new NullLogger());

        $service->evaluateAndAlert([$this->check(HealthStatus::Healthy)]);
        $service->evaluateAndAlert([$this->check(HealthStatus::Unhealthy)]);
        $service->evaluateAndAlert([$this->check(HealthStatus::Unhealthy)]);
        $service->evaluateAndAlert([$this->check(HealthStatus::Unhealthy)]);
    }

    public function testFailedAlertForOneComponentDoesNotBlockTheOthers(): void
    {
        $alerts = $this->createMock(AdminAlertPortInterface::class);
        $alerts->expects($this->exactly(2))->method('alertAdmins')
            ->willReturnCallback(static function (string $title): void {
                if ($title === 'redis health degraded') {
                    throw new \RuntimeException('PostgreSQL is down');
                }
            });
        $service = $this->service($alerts, $this->settings(true), new NullLogger());

        $service->evaluateAndAlert([$this->check(HealthStatus::Healthy), new HealthCheckResult('meilisearch', HealthStatus::Healthy, 1.0)]);
        $service->evaluateAndAlert([$this->check(HealthStatus::Unhealthy), new HealthCheckResult('meilisearch', HealthStatus::Unhealthy, 1.0)]);
    }

    private function service(AdminAlertPortInterface $alerts, SystemSettingsPortInterface $settings, LoggerInterface $logger): HealthAlertService
    {
        // evaluateAndAlert() takes results directly, so the check service itself is never called.
        $checks = (new \ReflectionClass(HealthCheckService::class))->newInstanceWithoutConstructor();

        return new HealthAlertService($checks, $alerts, $logger, $settings);
    }

    private function settings(bool $adminAlerts): SystemSettingsPortInterface
    {
        $settings = $this->createStub(SystemSettingsPortInterface::class);
        $settings->method('get')->willReturnMap([[SharedSettingDefinitions::ADMIN_ALERTS, $adminAlerts]]);

        return $settings;
    }

    private function check(HealthStatus $status): HealthCheckResult
    {
        return new HealthCheckResult('redis', $status, 1.5);
    }
}
