<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification\Application\Service;

use App\Notification\Application\Service\NotificationCategoryFilter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NotificationCategoryFilterTest extends TestCase
{
    /** @return iterable<string, array{mixed, bool}> */
    public static function filters(): iterable
    {
        yield 'absent filter allows every category' => [null, true];
        yield 'empty list' => [[], true];
        yield 'supported categories' => [['security', 'background_jobs'], true];
        yield 'unknown category' => [['unknown'], false];
        yield 'keyed map' => [['key' => 'security'], false];
        yield 'non-string entry' => [[1], false];
        yield 'scalar' => ['security', false];
    }

    #[DataProvider('filters')]
    public function testAcceptsOnlyListsOfSupportedCategories(mixed $filter, bool $valid): void
    {
        self::assertSame($valid, NotificationCategoryFilter::isValid($filter));
    }
}
