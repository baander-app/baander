<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Health;

use App\Shared\Infrastructure\Health\HealthAlertDelivery;
use App\Shared\Infrastructure\Health\HealthAlertRow;
use App\Shared\Infrastructure\Health\HealthAlertState;
use App\Shared\Infrastructure\Health\HealthAlertTransition;
use App\Shared\Infrastructure\Health\HealthStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The state diagram in the plan, edge by edge. */
final class HealthAlertTransitionTest extends TestCase
{
    private const int NOW = 1_791_633_600;
    private const int EARLIER = 1_791_633_000;

    #[DataProvider('observations')]
    public function testACheckResultMovesTheRow(HealthAlertRow $before, HealthStatus $status, HealthAlertRow $expected): void
    {
        self::assertEquals($expected, HealthAlertTransition::observe($before, $status, self::NOW));
    }

    /** @return iterable<string, array{HealthAlertRow, HealthStatus, HealthAlertRow}> */
    public static function observations(): iterable
    {
        $healthy = new HealthAlertRow();
        $pending = new HealthAlertRow(HealthAlertState::Pending, self::EARLIER);
        $recovered = new HealthAlertRow(HealthAlertState::Pending, self::EARLIER, self::EARLIER + 60);
        $acknowledged = new HealthAlertRow(HealthAlertState::Acknowledged, self::EARLIER);

        yield 'healthy stays healthy' => [$healthy, HealthStatus::Healthy, $healthy];
        yield 'not available keeps healthy' => [$healthy, HealthStatus::NotAvailable, $healthy];
        yield 'unhealthy owes an alert from now' => [$healthy, HealthStatus::Unhealthy, new HealthAlertRow(HealthAlertState::Pending, self::NOW)];
        yield 'pending stays pending while unhealthy' => [$pending, HealthStatus::Unhealthy, $pending];
        yield 'pending keeps waiting on not available' => [$pending, HealthStatus::NotAvailable, $pending];
        yield 'a recovery while pending is remembered' => [$pending, HealthStatus::Healthy, new HealthAlertRow(HealthAlertState::Pending, self::EARLIER, self::NOW)];
        yield 'the first recovery time is kept' => [$recovered, HealthStatus::Healthy, $recovered];
        yield 'unhealthy again reopens the window' => [$recovered, HealthStatus::Unhealthy, $pending];
        yield 'acknowledged stays while unhealthy' => [$acknowledged, HealthStatus::Unhealthy, $acknowledged];
        yield 'acknowledged stays on not available' => [$acknowledged, HealthStatus::NotAvailable, $acknowledged];
        yield 'acknowledged turns healthy on recovery' => [$acknowledged, HealthStatus::Healthy, $healthy];
    }

    #[DataProvider('settlements')]
    public function testADeliveryOutcomeSettlesAPendingRow(HealthAlertRow $before, HealthAlertDelivery $delivery, HealthAlertRow $expected): void
    {
        self::assertEquals($expected, HealthAlertTransition::settle($before, $delivery));
    }

    /** @return iterable<string, array{HealthAlertRow, HealthAlertDelivery, HealthAlertRow}> */
    public static function settlements(): iterable
    {
        $pending = new HealthAlertRow(HealthAlertState::Pending, self::EARLIER);
        $recovered = new HealthAlertRow(HealthAlertState::Pending, self::EARLIER, self::NOW);
        $acknowledged = new HealthAlertRow(HealthAlertState::Acknowledged, self::EARLIER);

        yield 'delivered while unhealthy' => [$pending, HealthAlertDelivery::Delivered, $acknowledged];
        yield 'dropped while alerts are off' => [$pending, HealthAlertDelivery::Disabled, $acknowledged];
        yield 'delivered after recovery' => [$recovered, HealthAlertDelivery::Delivered, new HealthAlertRow()];
        yield 'dropped after recovery' => [$recovered, HealthAlertDelivery::Disabled, new HealthAlertRow()];
        yield 'a failed delivery stays owed' => [$pending, HealthAlertDelivery::Failed, $pending];
        yield 'a row that owes nothing is unchanged' => [$acknowledged, HealthAlertDelivery::Delivered, $acknowledged];
    }
}
