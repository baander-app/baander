<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Interface\Controller;

use App\Shared\Application\DTO\JobMonitorPruneResult;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Port\JobMonitorAdministrationInterface;
use App\Shared\Application\Port\SignedInUserInterface;
use App\Shared\Interface\Controller\JobMonitorController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/** POST /api/monitor/prune takes its days as `app:monitor:prune --days` does: a whole number, or the default of 7. */
final class JobMonitorControllerPruneTest extends TestCase
{
    /** @return iterable<string, array{mixed}> */
    public static function daysThatAreNotWholeNumbers(): iterable
    {
        yield 'letters' => ['abc'];
        yield 'digits followed by letters' => ['5x'];
        yield 'a fraction' => [1.5];
        yield 'a fraction in a string' => ['1.5'];
        yield 'a boolean' => [true];
        yield 'a list' => [[7]];
    }

    #[DataProvider('daysThatAreNotWholeNumbers')]
    public function testDaysThatAreNotAWholeNumberAreRefusedBeforeAnythingIsPruned(mixed $days): void
    {
        $monitor = $this->createMock(JobMonitorAdministrationInterface::class);
        $monitor->expects(self::never())->method('prune');

        try {
            new JobMonitorController($monitor, $this->createStub(SignedInUserInterface::class))->prune(self::post(['days' => $days]));
            self::fail('The days value must be refused.');
        } catch (InvalidInputException $refused) {
            self::assertSame('Days must be an integer.', $refused->getMessage());
            self::assertSame(['days' => 'Days must be an integer.'], $refused->details);
        }
    }

    /** @return iterable<string, array{string, int}> */
    public static function acceptedBodies(): iterable
    {
        yield 'an integer' => ['{"days":30}', 30];
        yield 'an integer in a string, as the command reads it' => ['{"days":"30"}', 30];
        yield 'no days' => ['{}', 7];
        yield 'no body' => ['', 7];
    }

    #[DataProvider('acceptedBodies')]
    public function testPrunesTheRecordsOlderThanTheGivenOrDefaultDays(string $body, int $days): void
    {
        $monitor = $this->createMock(JobMonitorAdministrationInterface::class);
        $monitor->expects(self::once())
            ->method('prune')
            ->with($days, false)
            ->willReturn(new JobMonitorPruneResult(3, new \DateTimeImmutable('2026-11-10T10:00:00+00:00')));

        $response = new JobMonitorController($monitor, $this->createStub(SignedInUserInterface::class))->prune(self::request($body));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            ['data' => ['pruned' => 3, 'olderThan' => '2026-11-10T10:00:00+00:00']],
            json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR),
        );
    }

    /** @param array<string, mixed> $body */
    private static function post(array $body): Request
    {
        return self::request(json_encode($body, JSON_THROW_ON_ERROR));
    }

    private static function request(string $body): Request
    {
        return Request::create('/api/monitor/prune', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: $body);
    }
}
