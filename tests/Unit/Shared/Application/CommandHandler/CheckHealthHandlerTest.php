<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Application\CommandHandler;

use App\Scheduler\Domain\Model\SchedulableCommandInterface;
use App\Shared\Application\Command\CheckHealthCommand;
use App\Shared\Application\CommandHandler\CheckHealthHandler;
use App\Shared\Application\Port\HealthAlertPortInterface;
use App\Shared\Infrastructure\Health\HealthAlertService;
use PHPUnit\Framework\TestCase;

/** Migration Version20261007140000 schedules CheckHealthCommand every five minutes. */
final class CheckHealthHandlerTest extends TestCase
{
    public function testCommandIsSchedulableWithoutParameters(): void
    {
        self::assertContains(SchedulableCommandInterface::class, class_implements(CheckHealthCommand::class));
        self::assertSame([], CheckHealthCommand::schedulerParameters());
        self::assertNotSame('', CheckHealthCommand::schedulerDescription());
    }

    public function testHandlerChecksHealthAndAlertsOnDegradation(): void
    {
        $alerts = $this->createMock(HealthAlertPortInterface::class);
        $alerts->expects(self::once())->method('checkAndAlert');

        (new CheckHealthHandler($alerts))(new CheckHealthCommand());
    }

    public function testHealthAlertServiceImplementsThePort(): void
    {
        self::assertContains(HealthAlertPortInterface::class, class_implements(HealthAlertService::class));
    }
}
