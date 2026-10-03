<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Application\DTO;

use App\Shared\Application\DTO\WorkerRuntimeConfiguration;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WorkerRuntimeConfigurationTest extends TestCase
{
    public function testInclusiveMinimumReservationsFitExactCeiling(): void
    {
        $configuration = $this->configuration();
        self::assertSame(768 * 1024 * 1024, $configuration->memoryLimitBytes);
        self::assertSame($configuration->memoryLimitBytes, $configuration->managementReservationBytes + $configuration->consumerReservationBytes + $configuration->relayReservationBytes);
        self::assertSame(0, $configuration->scheduledConsoleReservationBytes);
    }

    public function testConsoleReservationIsAdditionalAndFitsAnExactCeiling(): void
    {
        $configuration = $this->configuration(['memoryLimitBytes' => 960 * 1024 * 1024, 'scheduledConsoleReservationBytes' => 192 * 1024 * 1024]);
        self::assertSame($configuration->memoryLimitBytes,
            $configuration->managementReservationBytes + $configuration->consumerReservationBytes + $configuration->relayReservationBytes + $configuration->scheduledConsoleReservationBytes);
    }

    #[DataProvider('invalidFields')]
    public function testRejectsInvalidIdentityAndAdmissionBudget(string $field, mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->configuration([$field => $value]);
    }

    /** @return iterable<string,array{string,mixed}> */
    public static function invalidFields(): iterable
    {
        yield 'unsafe namespace' => ['namespace', 'worker baander.app'];
        yield 'short boot' => ['bootId', 'abcd'];
        yield 'uppercase boot' => ['bootId', str_repeat('A', 32)];
        yield 'zero total' => ['memoryLimitBytes', 0];
        yield 'unbounded total' => ['memoryLimitBytes', 1024 * 1024 * 1024 * 1024 + 1];
        yield 'sum exceeds ceiling' => ['memoryLimitBytes', 768 * 1024 * 1024 - 1];
        yield 'insufficient management' => ['managementReservationBytes', 128 * 1024 * 1024 - 1];
        yield 'insufficient consumer' => ['consumerReservationBytes', 320 * 1024 * 1024 - 1];
        yield 'insufficient relay' => ['relayReservationBytes', 320 * 1024 * 1024 - 1];
        yield 'oversized management' => ['managementReservationBytes', PHP_INT_MAX];
        yield 'oversized consumer' => ['consumerReservationBytes', PHP_INT_MAX];
        yield 'oversized relay' => ['relayReservationBytes', PHP_INT_MAX];
        yield 'negative console reservation' => ['scheduledConsoleReservationBytes', -1];
        yield 'insufficient console reservation' => ['scheduledConsoleReservationBytes', 192 * 1024 * 1024 - 1];
        yield 'console exceeds remaining ceiling' => ['scheduledConsoleReservationBytes', 192 * 1024 * 1024];
        yield 'oversized console reservation' => ['scheduledConsoleReservationBytes', PHP_INT_MAX];
        yield 'relative lock path' => ['lockDirectory', 'worker-lock'];
        yield 'NUL lock path' => ['lockDirectory', "/tmp/worker\0lock"];
        yield 'oversized lock path' => ['lockDirectory', '/' . str_repeat('x', 4096)];
    }

    /** @param array<string,mixed> $overrides */
    private function configuration(array $overrides = []): WorkerRuntimeConfiguration
    {
        return new WorkerRuntimeConfiguration(...array_replace([
            'namespace' => 'worker.baander.app', 'bootId' => str_repeat('a', 32), 'memoryLimitBytes' => 768 * 1024 * 1024,
            'managementReservationBytes' => 128 * 1024 * 1024, 'consumerReservationBytes' => 320 * 1024 * 1024,
            'relayReservationBytes' => 320 * 1024 * 1024, 'lockDirectory' => '/tmp/baander-worker',
        ], $overrides));
    }
}
