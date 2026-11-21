<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Domain\ValueObject;

use App\Catalog\Domain\ValueObject\PersonDate;
use PHPUnit\Framework\TestCase;

final class PersonDateTest extends TestCase
{
    public function testAnExactAgeAtDeathUsesCalendarYears(): void
    {
        $birth = PersonDate::parse('1935-11-30');
        $death = PersonDate::parse('2000-11-29');

        self::assertSame(64, $birth->ageAt($death));
    }

    public function testPartialDatesOnlyYieldAnAgeWhenEveryPossibleDayAgrees(): void
    {
        $birth = PersonDate::parse('1985-03');
        self::assertSame('1985-03', $birth->display());
        self::assertSame(41, $birth->ageAt(PersonDate::parse('2026-04')));
        self::assertNull($birth->ageAt(PersonDate::parse('2026-03-17')));
        self::assertNull(PersonDate::parse('1985')->ageAt(PersonDate::parse('2026-06-01')));
        self::assertNull(PersonDate::parse('2025-02-29'));
    }
}
