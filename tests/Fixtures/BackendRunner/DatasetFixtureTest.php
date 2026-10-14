<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\BackendRunner;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DatasetFixtureTest extends TestCase
{
    /** @return iterable<string, array{int}> */
    public static function labels(): iterable
    {
        yield 'alpha # "quoted"' => [1];
        yield 'beta' => [2];
    }

    #[DataProvider('labels')]
    public function testLabels(int $value): void
    {
        self::assertGreaterThan(0, $value);
    }
}
