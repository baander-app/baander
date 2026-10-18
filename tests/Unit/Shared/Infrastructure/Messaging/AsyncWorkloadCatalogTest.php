<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Messaging;

use App\Shared\Infrastructure\Messaging\AsyncWorkloadCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AsyncWorkloadCatalogTest extends TestCase
{
    public function testCatalogPreservesConfigurationAndReceiverOrder(): void
    {
        $catalog = $this->catalog();
        self::assertSame(['async', 'scheduler'], $catalog->receivers());
        self::assertSame('io', $catalog->workloads()[CatalogWorkload::class]['resource_class']);
        self::assertSame(3, $catalog->transportPolicies()['async']['max_retries']);
        $catalog->assertSenders(new CatalogWorkload(), ['async']);
        $catalog->assertSenders(new ExplicitWorkload(), []);
        $catalog->assertSenders(new ExplicitWorkload(), ['scheduler']);
        $catalog->assertSenders(new \stdClass(), []);
    }

    #[DataProvider('invalidSenders')]
    public function testInvalidSenderSelectionFailsBeforeDispatch(object $message, array $aliases): void
    {
        $this->expectException(\LogicException::class);
        $this->catalog()->assertSenders($message, $aliases);
    }

    public static function invalidSenders(): iterable
    {
        yield 'unknown workload' => [new \stdClass(), ['async']];
        yield 'wrong transport' => [new CatalogWorkload(), ['scheduler']];
        yield 'multiple transports' => [new CatalogWorkload(), ['async', 'scheduler']];
        yield 'duplicate transport' => [new CatalogWorkload(), ['async', 'async']];
        yield 'empty default override' => [new CatalogWorkload(), []];
        yield 'unknown transport' => [new ExplicitWorkload(), ['failed']];
    }

    public function testUnknownResourceClassFails(): void
    {
        $this->expectException(\LogicException::class);
        new AsyncWorkloadCatalog([CatalogWorkload::class => ['transport' => 'async', 'resource_class' => 'unbounded', 'default' => true]], self::policies());
    }

    #[DataProvider('invalidCatalogs')]
    public function testMalformedCatalogFails(array $workloads, array $policies): void
    {
        $this->expectException(\LogicException::class);
        new AsyncWorkloadCatalog($workloads, $policies);
    }

    public static function invalidCatalogs(): iterable
    {
        $row = ['transport' => 'async', 'resource_class' => 'io', 'default' => true];
        yield 'unknown class' => [['MissingWorkload' => $row], self::policies()];
        yield 'abstract class' => [[AbstractWorkload::class => $row], self::policies()];
        yield 'transport list' => [[CatalogWorkload::class => array_replace($row, ['transport' => ['async']])], self::policies()];
        yield 'unknown transport' => [[CatalogWorkload::class => array_replace($row, ['transport' => 'failed'])], self::policies()];
        yield 'nonboolean routing' => [[CatalogWorkload::class => array_replace($row, ['default' => 'yes'])], self::policies()];
        yield 'extra field' => [[CatalogWorkload::class => $row + ['queue' => 'async']], self::policies()];
        yield 'missing policies' => [[], []];
        yield 'reordered receivers' => [[], array_reverse(self::policies(), true)];
        foreach (['max_retries' => -1, 'delay' => -1, 'multiplier' => 0, 'max_delay' => 0] as $key => $value) {
            $policies = self::policies();
            $policies['async'][$key] = $value;
            yield 'invalid ' . $key => [[], $policies];
        }
    }

    private function catalog(): AsyncWorkloadCatalog
    {
        return new AsyncWorkloadCatalog([
            CatalogWorkload::class => ['transport' => 'async', 'resource_class' => 'io', 'default' => true],
            ExplicitWorkload::class => ['transport' => 'scheduler', 'resource_class' => 'coordination', 'default' => false],
        ], self::policies());
    }

    public static function policies(): array
    {
        $policy = ['max_retries' => 3, 'delay' => 1000, 'multiplier' => 2, 'max_delay' => 60000];

        return ['async' => $policy, 'scheduler' => $policy];
    }
}

final class CatalogWorkload {}
final class ExplicitWorkload {}
abstract class AbstractWorkload {}
