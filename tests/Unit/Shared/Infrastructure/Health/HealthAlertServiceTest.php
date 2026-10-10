<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Health;

use App\Shared\Application\Port\AdminAlertPortInterface;
use App\Shared\Application\Port\SystemSettingsPortInterface;
use App\Shared\Application\Settings\SharedSettingDefinitions;
use App\Shared\Infrastructure\Health\HealthAlertService;
use App\Shared\Infrastructure\Health\HealthAlertState;
use App\Shared\Infrastructure\Health\HealthAlertTable;
use App\Shared\Infrastructure\Health\HealthCheckResult;
use App\Shared\Infrastructure\Health\HealthCheckService;
use App\Shared\Infrastructure\Health\HealthStatus;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Clock\MockClock;

final class HealthAlertServiceTest extends TestCase
{
    private RecordingAdminAlerts $alerts;
    private ScriptedAdminAlertsSetting $settings;
    private RecordingHealthLogger $logger;
    private MockClock $clock;
    private HealthAlertTable $table;

    protected function setUp(): void
    {
        $this->alerts = new RecordingAdminAlerts();
        $this->settings = new ScriptedAdminAlertsSetting();
        $this->logger = new RecordingHealthLogger();
        $this->clock = new MockClock('2026-10-10 12:00:00 UTC');
        $this->table = new HealthAlertTable();
    }

    public function testServerStartDuringAPostgresqlOutageAlertsOnceItReturnsAndNamesTheOutageWindow(): void
    {
        $service = $this->service();

        $service->evaluateAndAlert([$this->check('postgresql', HealthStatus::Unhealthy), $this->check('redis', HealthStatus::Healthy)]);
        $this->clock->sleep(60);
        $service->evaluateAndAlert([$this->check('postgresql', HealthStatus::Unhealthy), $this->check('redis', HealthStatus::Healthy)]);
        self::assertSame([], $this->alerts->sent);

        $this->clock->sleep(60);
        $service->evaluateAndAlert([$this->check('postgresql', HealthStatus::Healthy), $this->check('redis', HealthStatus::Healthy)]);
        $this->clock->sleep(60);
        $service->evaluateAndAlert([$this->check('postgresql', HealthStatus::Healthy), $this->check('redis', HealthStatus::Healthy)]);

        self::assertCount(1, $this->alerts->sent);
        [$title, $body, $eventType, $reference] = $this->alerts->sent[0];
        self::assertSame('postgresql health degraded', $title);
        self::assertStringContainsString('from 2026-10-10 12:00:00 UTC', $body);
        self::assertStringContainsString('until 2026-10-10 12:02:00 UTC', $body);
        self::assertSame('admin.health_degraded', $eventType);
        self::assertSame(['component' => 'postgresql'], $reference);
        self::assertSame(HealthAlertState::Healthy, $this->table->get('postgresql')->state);
    }

    public function testTwoUnhealthyChecksInARowDeliverOneAlert(): void
    {
        $service = $this->service();

        $service->evaluateAndAlert([$this->check('redis', HealthStatus::Unhealthy)]);
        $service->evaluateAndAlert([$this->check('redis', HealthStatus::Unhealthy)]);

        self::assertSame(['redis health degraded'], $this->alerts->titles());
        self::assertStringContainsString('unhealthy since 2026-10-10 12:00:00 UTC', $this->alerts->sent[0][1]);
    }

    public function testAComponentThatRecoversAndFailsAgainAlertsAgain(): void
    {
        $service = $this->service();

        $service->evaluateAndAlert([$this->check('redis', HealthStatus::Unhealthy)]);
        $service->evaluateAndAlert([$this->check('redis', HealthStatus::Healthy)]);
        $service->evaluateAndAlert([$this->check('redis', HealthStatus::Unhealthy)]);

        self::assertSame(['redis health degraded', 'redis health degraded'], $this->alerts->titles());
    }

    public function testNotAvailableNeitherAlertsNorResetsAnAlert(): void
    {
        $service = $this->service();

        $service->evaluateAndAlert([$this->check('messenger', HealthStatus::Healthy)]);
        $service->evaluateAndAlert([$this->check('messenger', HealthStatus::NotAvailable)]);
        self::assertSame([], $this->alerts->sent);
        self::assertSame(HealthAlertState::Healthy, $this->table->get('messenger')->state);

        $service->evaluateAndAlert([$this->check('messenger', HealthStatus::Unhealthy)]);
        $service->evaluateAndAlert([$this->check('messenger', HealthStatus::NotAvailable)]);
        $service->evaluateAndAlert([$this->check('messenger', HealthStatus::Unhealthy)]);

        self::assertSame(['messenger health degraded'], $this->alerts->titles());
        self::assertSame(HealthAlertState::Acknowledged, $this->table->get('messenger')->state);
    }

    public function testAFailedDeliveryStaysPendingAndTheNextCheckDeliversItOnce(): void
    {
        $this->alerts->failures = 1;
        $service = $this->service();

        $service->evaluateAndAlert([$this->check('redis', HealthStatus::Unhealthy)]);
        self::assertSame([], $this->alerts->sent);
        self::assertSame(HealthAlertState::Pending, $this->table->get('redis')->state);
        self::assertTrue($this->logger->has('error', 'alert for {component} failed', 'redis'));

        $service->evaluateAndAlert([$this->check('redis', HealthStatus::Unhealthy)]);
        $service->evaluateAndAlert([$this->check('redis', HealthStatus::Unhealthy)]);

        self::assertSame(['redis health degraded'], $this->alerts->titles());
        self::assertSame(HealthAlertState::Acknowledged, $this->table->get('redis')->state);
    }

    public function testAFailedSettingReadLeavesItsRowPendingAndTheOtherComponentsAreStillEvaluated(): void
    {
        $this->settings->failures = 1;
        $service = $this->service();

        $service->evaluateAndAlert([$this->check('redis', HealthStatus::Unhealthy), $this->check('memory', HealthStatus::Unhealthy)]);

        self::assertSame(['memory health degraded'], $this->alerts->titles());
        self::assertSame(HealthAlertState::Pending, $this->table->get('redis')->state);

        $service->evaluateAndAlert([$this->check('redis', HealthStatus::Unhealthy), $this->check('memory', HealthStatus::Unhealthy)]);

        self::assertSame(['memory health degraded', 'redis health degraded'], $this->alerts->titles());
    }

    public function testAdminAlertsOffDropsThePendingAlertWithALogLineAndTurningThemOnDoesNotRevive(): void
    {
        $this->alerts->failures = 1;
        $service = $this->service();
        $service->evaluateAndAlert([$this->check('redis', HealthStatus::Unhealthy)]);

        $this->settings->adminAlerts = false;
        $service->evaluateAndAlert([$this->check('redis', HealthStatus::Unhealthy)]);

        self::assertSame([], $this->alerts->sent);
        self::assertTrue($this->logger->has('info', 'admin alerts are turned off', 'redis'));
        self::assertSame(HealthAlertState::Acknowledged, $this->table->get('redis')->state);

        $this->settings->adminAlerts = true;
        $service->evaluateAndAlert([$this->check('redis', HealthStatus::Unhealthy)]);

        self::assertSame([], $this->alerts->sent);
    }

    public function testARecoveryBeforeAFailedDeliveryIsRetriedStillDeliversOneAlertNamingTheOutageWindow(): void
    {
        $this->alerts->failures = 2;
        $service = $this->service();

        $service->evaluateAndAlert([$this->check('redis', HealthStatus::Unhealthy)]);
        $this->clock->sleep(60);
        $service->evaluateAndAlert([$this->check('redis', HealthStatus::Healthy)]);
        $this->clock->sleep(60);
        $service->evaluateAndAlert([$this->check('redis', HealthStatus::Healthy)]);
        $service->evaluateAndAlert([$this->check('redis', HealthStatus::Healthy)]);

        self::assertSame(['redis health degraded'], $this->alerts->titles());
        self::assertStringContainsString('from 2026-10-10 12:00:00 UTC until 2026-10-10 12:01:00 UTC', $this->alerts->sent[0][1]);
        self::assertSame(HealthAlertState::Healthy, $this->table->get('redis')->state);
    }

    public function testRedisUnhealthyAtStartWithPostgresqlHealthyAlertsOnTheFirstCheck(): void
    {
        $this->service()->evaluateAndAlert([$this->check('postgresql', HealthStatus::Healthy), $this->check('redis', HealthStatus::Unhealthy)]);

        self::assertSame(['redis health degraded'], $this->alerts->titles());
    }

    public function testAWorkerReloadDuringAnOutageDeliversNothingNew(): void
    {
        $this->table->boot();
        $this->service()->evaluateAndAlert([$this->check('redis', HealthStatus::Unhealthy)]);

        // The reloaded worker builds a new service around the same boot-time table.
        $this->service()->evaluateAndAlert([$this->check('redis', HealthStatus::Unhealthy)]);

        self::assertSame(['redis health degraded'], $this->alerts->titles());
    }

    public function testACheckWithPostgresqlUnhealthyLeavesPendingAlertsPendingWithoutTryingToDeliver(): void
    {
        $service = $this->service();

        $service->evaluateAndAlert([$this->check('postgresql', HealthStatus::Unhealthy), $this->check('redis', HealthStatus::Unhealthy)]);

        self::assertSame(0, $this->settings->reads);
        self::assertSame(0, $this->alerts->attempts);
        self::assertSame(HealthAlertState::Pending, $this->table->get('postgresql')->state);
        self::assertSame(HealthAlertState::Pending, $this->table->get('redis')->state);
        self::assertSame([], $this->logger->lines('error'));
    }

    private function service(): HealthAlertService
    {
        // evaluateAndAlert() takes results directly, so the check service itself is never called.
        $checks = (new \ReflectionClass(HealthCheckService::class))->newInstanceWithoutConstructor();

        return new HealthAlertService($checks, $this->alerts, $this->settings, $this->table, $this->clock, $this->logger);
    }

    private function check(string $component, HealthStatus $status): HealthCheckResult
    {
        return new HealthCheckResult($component, $status, 1.5);
    }
}

final class RecordingAdminAlerts implements AdminAlertPortInterface
{
    /** @var list<array{string, string, string, array<string, mixed>|null}> */
    public array $sent = [];
    public int $attempts = 0;
    /** The next this many deliveries throw, as they do while PostgreSQL is down. */
    public int $failures = 0;

    public function alertAdmins(string $title, string $body, string $eventType, ?array $referenceData = null): void
    {
        ++$this->attempts;
        if ($this->failures > 0) {
            --$this->failures;
            throw new \RuntimeException('PostgreSQL is down');
        }
        $this->sent[] = [$title, $body, $eventType, $referenceData];
    }

    /** @return list<string> */
    public function titles(): array
    {
        return array_column($this->sent, 0);
    }
}

final class ScriptedAdminAlertsSetting implements SystemSettingsPortInterface
{
    public bool $adminAlerts = true;
    public int $reads = 0;
    /** The next this many reads throw. */
    public int $failures = 0;

    public function get(string $key): bool
    {
        TestCase::assertSame(SharedSettingDefinitions::ADMIN_ALERTS, $key);
        ++$this->reads;
        if ($this->failures > 0) {
            --$this->failures;
            throw new \RuntimeException('PostgreSQL is down');
        }

        return $this->adminAlerts;
    }
}

final class RecordingHealthLogger extends AbstractLogger
{
    /** @var list<array{string, string, array<mixed>}> */
    public array $records = [];

    /** @param mixed[] $context */
    public function log(mixed $level, \Stringable|string $message, array $context = []): void
    {
        $this->records[] = [(string) $level, (string) $message, $context];
    }

    public function has(string $level, string $messagePart, string $component): bool
    {
        foreach ($this->records as [$recordLevel, $message, $context]) {
            if ($recordLevel === $level && str_contains($message, $messagePart) && ($context['component'] ?? null) === $component) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public function lines(string $level): array
    {
        return array_values(array_map(
            static fn (array $record): string => $record[1],
            array_filter($this->records, static fn (array $record): bool => $record[0] === $level),
        ));
    }
}
