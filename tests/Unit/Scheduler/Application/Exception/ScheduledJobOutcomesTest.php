<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scheduler\Application\Exception;

use App\Scheduler\Application\Exception\InvalidScheduledJob;
use App\Scheduler\Application\Exception\ScheduledJobConflict;
use App\Scheduler\Application\Exception\ScheduledJobStatusConflict;
use App\Scheduler\Domain\Exception\DisabledScheduledJob;
use App\Shared\Infrastructure\EventListener\ExceptionSubscriber;
use App\Shared\Interface\Console\AdminCommandSupport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Throwable;

/** The scheduler's failures give the same outcome over HTTP and on the console. */
final class ScheduledJobOutcomesTest extends TestCase
{
    #[DataProvider('outcomes')]
    public function testHttpAndConsoleReportTheSameOutcome(Throwable $failure, int $status, int $exitCode, string $message): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');
        $event = new ExceptionEvent(
            $this->createStub(HttpKernelInterface::class),
            Request::create('https://baander.app/api/admin/scheduler/jobs'),
            HttpKernelInterface::MAIN_REQUEST,
            $failure,
        );

        (new ExceptionSubscriber($logger))($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame($status, $response->getStatusCode());
        self::assertSame(
            ['error' => ['message' => $message, 'code' => $status]],
            json_decode($response->getContent() ?: '', true, 32, JSON_THROW_ON_ERROR),
        );
        self::assertSame($exitCode, AdminCommandSupport::exitCode($failure));
    }

    /** @return iterable<string, array{Throwable, int, int, string}> */
    public static function outcomes(): iterable
    {
        yield 'concurrent change' => [
            new ScheduledJobConflict(new \RuntimeException()),
            409,
            Command::FAILURE,
            'Scheduled job changed. Reload it and try again.',
        ];
        yield 'disabled job' => [
            new ScheduledJobStatusConflict(new DisabledScheduledJob('A disabled job cannot be paused. Enable it first.')),
            409,
            Command::FAILURE,
            'A disabled job cannot be paused. Enable it first.',
        ];
        yield 'unschedulable command' => [
            new InvalidScheduledJob('Command "app:rogue" is not registered as a schedulable console command.'),
            422,
            Command::INVALID,
            'Command "app:rogue" is not registered as a schedulable console command.',
        ];
    }
}
