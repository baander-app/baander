<?php

namespace Tests\Unit\Primitives;

use App\Primitives\Date;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DateTest extends TestCase
{
    // ─── Static Factory: now ─────────────────────────────────────────────────

    #[Test]
    public function now_returns_current_time(): void
    {
        $before = (int) (microtime(true));
        $date = Date::now();
        $after = (int) (microtime(true));

        $this->assertGreaterThanOrEqual($before, $date->timestamp());
        $this->assertLessThanOrEqual($after, $date->timestamp());
    }

    #[Test]
    public function now_uses_default_timezone(): void
    {
        $date = Date::now();
        // The container runs in UTC
        $this->assertSame('+00:00', $date->format('P'));
    }

    #[Test]
    public function now_accepts_timezone(): void
    {
        $date = Date::now('Europe/Berlin');
        // Berlin is UTC+1 or UTC+2 depending on DST
        $this->assertMatchesRegularExpression('/^\+0[12]:00$/', $date->format('P'));
    }

    // ─── Static Factory: today ───────────────────────────────────────────────

    #[Test]
    public function today_returns_midnight(): void
    {
        $today = Date::today();
        $now = Date::now();

        $this->assertSame(0, $today->hour());
        $this->assertSame(0, $today->minute());
        $this->assertSame(0, $today->second());
        $this->assertSame($now->year(), $today->year());
        $this->assertSame($now->month(), $today->month());
        $this->assertSame($now->day(), $today->day());
    }

    // ─── Static Factory: tomorrow ────────────────────────────────────────────

    #[Test]
    public function tomorrow_returns_next_day_at_midnight(): void
    {
        $tomorrow = Date::tomorrow();
        $now = Date::now();

        $this->assertSame(0, $tomorrow->hour());
        $this->assertSame(0, $tomorrow->minute());
        $this->assertSame(0, $tomorrow->second());
        // Tomorrow's date should be one day ahead
        $expected = Date::now()->addDays(1)->startOfDay();
        $this->assertTrue($tomorrow->isSameDay($expected));
    }

    #[Test]
    public function tomorrow_handles_month_boundary(): void
    {
        // We can't easily test this with live clock, so we test the underlying logic:
        // Jan 31 + 1 day = Feb 1
        $result = Date::create(2025, 1, 31)->addDays(1)->startOfDay();
        $this->assertSame(2, $result->month());
        $this->assertSame(1, $result->day());
        $this->assertSame(0, $result->hour());
    }

    // ─── Static Factory: yesterday ───────────────────────────────────────────

    #[Test]
    public function yesterday_returns_previous_day_at_midnight(): void
    {
        $yesterday = Date::yesterday();
        $now = Date::now();

        $this->assertSame(0, $yesterday->hour());
        $this->assertSame(0, $yesterday->minute());
        $this->assertSame(0, $yesterday->second());
        // Yesterday should be one day behind
        $expected = Date::now()->subDays(1)->startOfDay();
        $this->assertTrue($yesterday->isSameDay($expected));
    }

    #[Test]
    public function yesterday_handles_month_boundary(): void
    {
        // Mar 1 - 1 day = Feb 28 (2025 is not a leap year)
        $result = Date::create(2025, 3, 1)->subDays(1)->startOfDay();
        $this->assertSame(2, $result->month());
        $this->assertSame(28, $result->day());
        $this->assertSame(0, $result->hour());
    }

    // ─── Static Factory: parse ───────────────────────────────────────────────

    #[Test]
    public function parse_creates_date_from_string(): void
    {
        $date = Date::parse('2025-06-15 14:30:00');
        $this->assertSame(2025, $date->year());
        $this->assertSame(6, $date->month());
        $this->assertSame(15, $date->day());
        $this->assertSame(14, $date->hour());
        $this->assertSame(30, $date->minute());
    }

    #[Test]
    public function parse_accepts_timezone(): void
    {
        $date = Date::parse('2025-06-15 12:00:00', 'UTC');
        $this->assertSame(12, $date->hour());
    }

    // ─── Static Factory: create ──────────────────────────────────────────────

    #[Test]
    public function create_builds_date_from_components(): void
    {
        $date = Date::create(2025, 6, 15, 14, 30, 45);
        $this->assertSame(2025, $date->year());
        $this->assertSame(6, $date->month());
        $this->assertSame(15, $date->day());
        $this->assertSame(14, $date->hour());
        $this->assertSame(30, $date->minute());
        $this->assertSame(45, $date->second());
    }

    #[Test]
    public function create_defaults_time_to_midnight(): void
    {
        $date = Date::create(2025, 1, 1);
        $this->assertSame(0, $date->hour());
        $this->assertSame(0, $date->minute());
        $this->assertSame(0, $date->second());
    }

    #[Test]
    public function create_accepts_timezone(): void
    {
        $date = Date::create(2025, 6, 15, 12, 0, 0, 'UTC');
        $this->assertSame('+00:00', $date->format('P'));
    }

    // ─── Static Factory: createFromTimestamp ─────────────────────────────────

    #[Test]
    public function createFromTimestamp_creates_date(): void
    {
        // 2025-06-15 12:00:00 UTC
        $timestamp = 1749988800;
        $date = Date::createFromTimestamp($timestamp, 'UTC');

        $this->assertSame(2025, $date->year());
        $this->assertSame(6, $date->month());
        $this->assertSame(15, $date->day());
        $this->assertSame(12, $date->hour());
    }

    #[Test]
    public function createFromTimestamp_accepts_timezone(): void
    {
        $timestamp = 1749988800; // 2025-06-15 12:00:00 UTC
        $date = Date::createFromTimestamp($timestamp, 'UTC');

        $this->assertSame(12, $date->hour());
    }

    // ─── Static Factory: createFromFormat ────────────────────────────────────

    #[Test]
    public function createFromFormat_parses_custom_format(): void
    {
        $date = Date::createFromFormat('Y-m-d', '2025-06-15');

        $this->assertNotNull($date);
        $this->assertSame(2025, $date->year());
        $this->assertSame(6, $date->month());
        $this->assertSame(15, $date->day());
    }

    #[Test]
    public function createFromFormat_returns_null_for_invalid_format(): void
    {
        $date = Date::createFromFormat('Y-m-d', 'not-a-date');
        $this->assertNull($date);
    }

    #[Test]
    public function createFromFormat_accepts_timezone(): void
    {
        $date = Date::createFromFormat('Y-m-d H:i', '2025-06-15 12:00', 'UTC');

        $this->assertNotNull($date);
        $this->assertSame('+00:00', $date->format('P'));
    }

    // ─── Instance Query: component accessors ─────────────────────────────────

    #[Test]
    public function query_methods_return_correct_values(): void
    {
        $date = Date::create(2025, 6, 15, 14, 30, 45);

        $this->assertSame(2025, $date->year());
        $this->assertSame(6, $date->month());
        $this->assertSame(15, $date->day());
        $this->assertSame(14, $date->hour());
        $this->assertSame(30, $date->minute());
        $this->assertSame(45, $date->second());
    }

    #[Test]
    public function dayOfWeek_returns_sunday_as_zero(): void
    {
        // 2025-06-15 is a Sunday
        $date = Date::create(2025, 6, 15);
        $this->assertSame(0, $date->dayOfWeek());
    }

    #[Test]
    public function dayOfWeek_returns_saturday_as_six(): void
    {
        // 2025-06-14 is a Saturday
        $date = Date::create(2025, 6, 14);
        $this->assertSame(6, $date->dayOfWeek());
    }

    #[Test]
    public function dayOfWeek_returns_monday_as_one(): void
    {
        // 2025-06-16 is a Monday
        $date = Date::create(2025, 6, 16);
        $this->assertSame(1, $date->dayOfWeek());
    }

    #[Test]
    public function dayOfYear_returns_correct_day(): void
    {
        $date = Date::create(2025, 1, 1);
        $this->assertSame(1, $date->dayOfYear());

        $date = Date::create(2025, 12, 31);
        $this->assertSame(365, $date->dayOfYear());
    }

    #[Test]
    public function quarter_returns_correct_quarter(): void
    {
        $this->assertSame(1, Date::create(2025, 1, 1)->quarter());
        $this->assertSame(1, Date::create(2025, 3, 31)->quarter());
        $this->assertSame(2, Date::create(2025, 4, 1)->quarter());
        $this->assertSame(2, Date::create(2025, 6, 30)->quarter());
        $this->assertSame(3, Date::create(2025, 7, 1)->quarter());
        $this->assertSame(3, Date::create(2025, 9, 30)->quarter());
        $this->assertSame(4, Date::create(2025, 10, 1)->quarter());
        $this->assertSame(4, Date::create(2025, 12, 31)->quarter());
    }

    #[Test]
    public function timestamp_returns_unix_timestamp(): void
    {
        $date = Date::create(2025, 6, 15, 12, 0, 0, 'UTC');
        $this->assertSame(1749988800, $date->timestamp());
    }

    #[Test]
    public function format_delegates_to_datetime_format(): void
    {
        $date = Date::create(2025, 6, 15, 14, 30, 45);
        $this->assertSame('2025-06-15', $date->format('Y-m-d'));
        $this->assertSame('14:30:45', $date->format('H:i:s'));
        $this->assertSame('2025', $date->format('Y'));
    }

    // ─── Instance Query: boolean checks ──────────────────────────────────────

    #[Test]
    public function isToday_works(): void
    {
        $now = Date::now();
        $today = $now->startOfDay();
        $other = Date::create(2020, 1, 1);

        $this->assertTrue($today->isToday());
        $this->assertFalse($other->isToday());
    }

    #[Test]
    public function isYesterday_works(): void
    {
        $yesterday = Date::now()->subDays(1);
        $today = Date::now();
        $future = Date::now()->addDays(2);

        $this->assertTrue($yesterday->isYesterday());
        $this->assertFalse($today->isYesterday());
        $this->assertFalse($future->isYesterday());
    }

    #[Test]
    public function isTomorrow_works(): void
    {
        $tomorrow = Date::now()->addDays(1);
        $today = Date::now();
        $past = Date::now()->subDays(2);

        $this->assertTrue($tomorrow->isTomorrow());
        $this->assertFalse($today->isTomorrow());
        $this->assertFalse($past->isTomorrow());
    }

    #[Test]
    public function isFuture_works(): void
    {
        $future = Date::now()->addDays(1);
        $past = Date::now()->subDays(1);

        $this->assertTrue($future->isFuture());
        $this->assertFalse($past->isFuture());
    }

    #[Test]
    public function isPast_works(): void
    {
        $past = Date::now()->subDays(1);
        $future = Date::now()->addDays(1);

        $this->assertTrue($past->isPast());
        $this->assertFalse($future->isPast());
    }

    #[Test]
    public function isWeekend_works(): void
    {
        $sunday = Date::create(2025, 6, 15); // Sunday
        $saturday = Date::create(2025, 6, 14); // Saturday
        $monday = Date::create(2025, 6, 16); // Monday

        $this->assertTrue($sunday->isWeekend());
        $this->assertTrue($saturday->isWeekend());
        $this->assertFalse($monday->isWeekend());
    }

    #[Test]
    public function isWeekday_works(): void
    {
        $monday = Date::create(2025, 6, 16);
        $sunday = Date::create(2025, 6, 15);

        $this->assertTrue($monday->isWeekday());
        $this->assertFalse($sunday->isWeekday());
    }

    #[Test]
    public function isSameDay_works(): void
    {
        $a = Date::create(2025, 6, 15, 10, 0, 0);
        $b = Date::create(2025, 6, 15, 22, 30, 0);
        $c = Date::create(2025, 6, 16, 10, 0, 0);

        $this->assertTrue($a->isSameDay($b));
        $this->assertFalse($a->isSameDay($c));
    }

    #[Test]
    public function isSameMonth_works(): void
    {
        $a = Date::create(2025, 6, 1);
        $b = Date::create(2025, 6, 30);
        $c = Date::create(2025, 7, 1);

        $this->assertTrue($a->isSameMonth($b));
        $this->assertFalse($a->isSameMonth($c));
    }

    #[Test]
    public function isSameYear_works(): void
    {
        $a = Date::create(2025, 1, 1);
        $b = Date::create(2025, 12, 31);
        $c = Date::create(2024, 12, 31);

        $this->assertTrue($a->isSameYear($b));
        $this->assertFalse($a->isSameYear($c));
    }

    // ─── Instance Query: diff methods ────────────────────────────────────────

    #[Test]
    public function diffInSeconds_returns_absolute_difference(): void
    {
        $a = Date::create(2025, 6, 15, 12, 0, 0);
        $b = Date::create(2025, 6, 15, 12, 1, 30);

        $this->assertSame(90, $a->diffInSeconds($b));
    }

    #[Test]
    public function diffInMinutes_works(): void
    {
        $a = Date::create(2025, 6, 15, 12, 0, 0);
        $b = Date::create(2025, 6, 15, 12, 5, 30);

        $this->assertSame(5, $a->diffInMinutes($b));
    }

    #[Test]
    public function diffInHours_works(): void
    {
        $a = Date::create(2025, 6, 15, 10, 0, 0);
        $b = Date::create(2025, 6, 15, 14, 30, 0);

        $this->assertSame(4, $a->diffInHours($b));
    }

    #[Test]
    public function diffInDays_works(): void
    {
        $a = Date::create(2025, 6, 15, 0, 0, 0);
        $b = Date::create(2025, 6, 18, 12, 0, 0);

        $this->assertSame(3, $a->diffInDays($b));
    }

    #[Test]
    public function diff_methods_return_zero_for_same_date(): void
    {
        $date = Date::create(2025, 6, 15, 12, 0, 0);
        $same = Date::create(2025, 6, 15, 12, 0, 0);

        $this->assertSame(0, $date->diffInSeconds($same));
        $this->assertSame(0, $date->diffInMinutes($same));
        $this->assertSame(0, $date->diffInHours($same));
        $this->assertSame(0, $date->diffInDays($same));
    }

    // ─── Builder: add/sub years ──────────────────────────────────────────────

    #[Test]
    public function addYears_works(): void
    {
        $date = Date::create(2025, 6, 15);
        $result = $date->addYears(1);

        $this->assertSame(2026, $result->year());
        $this->assertSame(6, $result->month());
    }

    #[Test]
    public function addYears_handles_leap_year(): void
    {
        // 2024 is a leap year
        $date = Date::create(2024, 2, 29);
        $result = $date->addYears(1);

        $this->assertSame(2025, $result->year());
        $this->assertSame(3, $result->month()); // March 1, not Feb 29
    }

    #[Test]
    public function subYears_works(): void
    {
        $date = Date::create(2025, 6, 15);
        $result = $date->subYears(2);

        $this->assertSame(2023, $result->year());
    }

    // ─── Builder: add/sub months ─────────────────────────────────────────────

    #[Test]
    public function addMonths_works(): void
    {
        $date = Date::create(2025, 6, 15);
        $result = $date->addMonths(3);

        $this->assertSame(9, $result->month());
        $this->assertSame(15, $result->day());
    }

    #[Test]
    public function addMonths_handles_year_boundary(): void
    {
        $date = Date::create(2025, 10, 15);
        $result = $date->addMonths(4);

        $this->assertSame(2026, $result->year());
        $this->assertSame(2, $result->month());
    }

    #[Test]
    public function addMonths_handles_day_overflow(): void
    {
        // Jan 31 + 1 month -> March 3 (PHP wraps)
        $date = Date::create(2025, 1, 31);
        $result = $date->addMonths(1);

        $this->assertSame(3, $result->month());
    }

    #[Test]
    public function subMonths_works(): void
    {
        $date = Date::create(2025, 6, 15);
        $result = $date->subMonths(1);

        $this->assertSame(5, $result->month());
    }

    #[Test]
    public function subMonths_handles_year_boundary(): void
    {
        $date = Date::create(2025, 3, 15);
        $result = $date->subMonths(4);

        $this->assertSame(2024, $result->year());
        $this->assertSame(11, $result->month());
    }

    // ─── Builder: add/sub days ───────────────────────────────────────────────

    #[Test]
    public function addDays_works(): void
    {
        $date = Date::create(2025, 6, 15);
        $result = $date->addDays(10);

        $this->assertSame(25, $result->day());
    }

    #[Test]
    public function addDays_handles_month_boundary(): void
    {
        $date = Date::create(2025, 6, 28);
        $result = $date->addDays(5);

        $this->assertSame(7, $result->month());
        $this->assertSame(3, $result->day());
    }

    #[Test]
    public function addDays_handles_leap_year_feb(): void
    {
        // 2024 is a leap year
        $date = Date::create(2024, 2, 28);
        $result = $date->addDays(1);

        $this->assertSame(2, $result->month());
        $this->assertSame(29, $result->day());
    }

    #[Test]
    public function addDays_handles_non_leap_year_feb(): void
    {
        // 2025 is not a leap year
        $date = Date::create(2025, 2, 28);
        $result = $date->addDays(1);

        $this->assertSame(3, $result->month());
        $this->assertSame(1, $result->day());
    }

    #[Test]
    public function subDays_works(): void
    {
        $date = Date::create(2025, 6, 15);
        $result = $date->subDays(10);

        $this->assertSame(5, $result->day());
    }

    #[Test]
    public function subDays_handles_month_boundary(): void
    {
        $date = Date::create(2025, 6, 3);
        $result = $date->subDays(5);

        $this->assertSame(5, $result->month());
        $this->assertSame(29, $result->day());
    }

    // ─── Builder: add/sub hours ──────────────────────────────────────────────

    #[Test]
    public function addHours_works(): void
    {
        $date = Date::create(2025, 6, 15, 10, 0, 0);
        $result = $date->addHours(5);

        $this->assertSame(15, $result->hour());
    }

    #[Test]
    public function addHours_handles_day_overflow(): void
    {
        $date = Date::create(2025, 6, 15, 22, 0, 0);
        $result = $date->addHours(5);

        $this->assertSame(16, $result->day());
        $this->assertSame(3, $result->hour());
    }

    #[Test]
    public function subHours_works(): void
    {
        $date = Date::create(2025, 6, 15, 10, 0, 0);
        $result = $date->subHours(3);

        $this->assertSame(7, $result->hour());
    }

    // ─── Builder: add/sub minutes ────────────────────────────────────────────

    #[Test]
    public function addMinutes_works(): void
    {
        $date = Date::create(2025, 6, 15, 10, 30, 0);
        $result = $date->addMinutes(45);

        $this->assertSame(11, $result->hour());
        $this->assertSame(15, $result->minute());
    }

    #[Test]
    public function subMinutes_works(): void
    {
        $date = Date::create(2025, 6, 15, 10, 30, 0);
        $result = $date->subMinutes(45);

        $this->assertSame(9, $result->hour());
        $this->assertSame(45, $result->minute());
    }

    // ─── Builder: add/sub seconds ────────────────────────────────────────────

    #[Test]
    public function addSeconds_works(): void
    {
        $date = Date::create(2025, 6, 15, 10, 0, 30);
        $result = $date->addSeconds(45);

        $this->assertSame(1, $result->minute());
        $this->assertSame(15, $result->second());
    }

    #[Test]
    public function subSeconds_works(): void
    {
        $date = Date::create(2025, 6, 15, 10, 1, 15);
        $result = $date->subSeconds(30);

        $this->assertSame(0, $result->minute());
        $this->assertSame(45, $result->second());
    }

    // ─── Builder: start/end of day ───────────────────────────────────────────

    #[Test]
    public function startOfDay_returns_midnight(): void
    {
        $date = Date::create(2025, 6, 15, 14, 30, 45);
        $result = $date->startOfDay();

        $this->assertSame(0, $result->hour());
        $this->assertSame(0, $result->minute());
        $this->assertSame(0, $result->second());
        $this->assertSame(15, $result->day());
    }

    #[Test]
    public function endOfDay_returns_last_second(): void
    {
        $date = Date::create(2025, 6, 15, 0, 0, 0);
        $result = $date->endOfDay();

        $this->assertSame(23, $result->hour());
        $this->assertSame(59, $result->minute());
        $this->assertSame(59, $result->second());
        $this->assertSame(15, $result->day());
    }

    // ─── Builder: start/end of week ──────────────────────────────────────────

    #[Test]
    public function startOfWeek_returns_monday(): void
    {
        // 2025-06-15 is a Sunday
        $sunday = Date::create(2025, 6, 15, 14, 0, 0);
        $result = $sunday->startOfWeek();

        $this->assertSame(9, $result->day()); // Monday June 9
        $this->assertSame(0, $result->hour());
    }

    #[Test]
    public function startOfWeek_returns_same_day_for_monday(): void
    {
        // 2025-06-16 is a Monday
        $monday = Date::create(2025, 6, 16, 14, 0, 0);
        $result = $monday->startOfWeek();

        $this->assertSame(16, $result->day());
        $this->assertSame(0, $result->hour());
    }

    #[Test]
    public function startOfWeek_handles_wednesday(): void
    {
        // 2025-06-18 is a Wednesday
        $wednesday = Date::create(2025, 6, 18, 14, 0, 0);
        $result = $wednesday->startOfWeek();

        $this->assertSame(16, $result->day()); // Monday June 16
        $this->assertSame(0, $result->hour());
    }

    #[Test]
    public function endOfWeek_returns_sunday(): void
    {
        // 2025-06-16 is a Monday -> Sunday June 22
        $monday = Date::create(2025, 6, 16, 0, 0, 0);
        $result = $monday->endOfWeek();

        $this->assertSame(22, $result->day()); // Sunday June 22
        $this->assertSame(23, $result->hour());
        $this->assertSame(59, $result->minute());
        $this->assertSame(59, $result->second());
    }

    #[Test]
    public function endOfWeek_from_saturday_returns_sunday(): void
    {
        // 2025-06-14 is a Saturday -> Sunday June 15
        $saturday = Date::create(2025, 6, 14, 0, 0, 0);
        $result = $saturday->endOfWeek();

        $this->assertSame(15, $result->day());
        $this->assertSame(23, $result->hour());
    }

    #[Test]
    public function endOfWeek_returns_same_day_for_sunday(): void
    {
        // 2025-06-15 is a Sunday
        $sunday = Date::create(2025, 6, 15, 0, 0, 0);
        $result = $sunday->endOfWeek();

        $this->assertSame(15, $result->day());
        $this->assertSame(23, $result->hour());
    }

    // ─── Builder: start/end of month ─────────────────────────────────────────

    #[Test]
    public function startOfMonth_returns_first_day(): void
    {
        $date = Date::create(2025, 6, 15, 14, 0, 0);
        $result = $date->startOfMonth();

        $this->assertSame(1, $result->day());
        $this->assertSame(6, $result->month());
        $this->assertSame(0, $result->hour());
    }

    #[Test]
    public function endOfMonth_returns_last_day(): void
    {
        $date = Date::create(2025, 6, 15, 0, 0, 0);
        $result = $date->endOfMonth();

        $this->assertSame(30, $result->day()); // June has 30 days
        $this->assertSame(6, $result->month());
        $this->assertSame(23, $result->hour());
        $this->assertSame(59, $result->minute());
        $this->assertSame(59, $result->second());
    }

    #[Test]
    public function endOfMonth_handles_february_leap_year(): void
    {
        $date = Date::create(2024, 2, 1);
        $result = $date->endOfMonth();

        $this->assertSame(29, $result->day());
    }

    #[Test]
    public function endOfMonth_handles_february_non_leap_year(): void
    {
        $date = Date::create(2025, 2, 1);
        $result = $date->endOfMonth();

        $this->assertSame(28, $result->day());
    }

    #[Test]
    public function endOfMonth_handles_31_day_month(): void
    {
        $date = Date::create(2025, 1, 1);
        $result = $date->endOfMonth();

        $this->assertSame(31, $result->day());
    }

    // ─── Builder: start/end of year ──────────────────────────────────────────

    #[Test]
    public function startOfYear_returns_january_first(): void
    {
        $date = Date::create(2025, 6, 15, 14, 0, 0);
        $result = $date->startOfYear();

        $this->assertSame(1, $result->month());
        $this->assertSame(1, $result->day());
        $this->assertSame(0, $result->hour());
    }

    #[Test]
    public function endOfYear_returns_december_31st(): void
    {
        $date = Date::create(2025, 6, 15, 0, 0, 0);
        $result = $date->endOfYear();

        $this->assertSame(12, $result->month());
        $this->assertSame(31, $result->day());
        $this->assertSame(23, $result->hour());
        $this->assertSame(59, $result->minute());
        $this->assertSame(59, $result->second());
    }

    // ─── Immutability ────────────────────────────────────────────────────────

    #[Test]
    public function builder_methods_return_new_instances(): void
    {
        $original = Date::create(2025, 6, 15, 14, 30, 0);
        $modified = $original->addDays(1);

        $this->assertNotSame($original, $modified);
        $this->assertSame(15, $original->day());
        $this->assertSame(16, $modified->day());
    }

    #[Test]
    public function builder_methods_do_not_mutate_original(): void
    {
        $original = Date::create(2025, 6, 15, 14, 30, 45);
        $original->addDays(1)->addHours(5)->addMinutes(30)->startOfMonth();

        $this->assertSame(15, $original->day());
        $this->assertSame(14, $original->hour());
        $this->assertSame(30, $original->minute());
    }

    #[Test]
    public function clone_method_creates_independent_copy(): void
    {
        $original = Date::create(2025, 6, 15);
        $clone = $original->clone();

        $this->assertSame($original->timestamp(), $clone->timestamp());
        $this->assertNotSame($original, $clone);
    }

    #[Test]
    public function mutated_clone_does_not_affect_original(): void
    {
        $original = Date::create(2025, 6, 15, 12, 0, 0);
        $clone = $original->clone();
        $clone->addDays(10);

        $this->assertSame(15, $original->day());
        $this->assertSame(15, $clone->day()); // clone() itself is not modified
    }

    // ─── Builder chaining ────────────────────────────────────────────────────

    #[Test]
    public function chaining_works(): void
    {
        $result = Date::create(2025, 6, 15, 14, 30, 0)
            ->addDays(1)
            ->subHours(2)
            ->addMinutes(15)
            ->startOfDay();

        $this->assertSame(16, $result->day());
        $this->assertSame(0, $result->hour());
        $this->assertSame(0, $result->minute());
    }

    // ─── Interface implementations ───────────────────────────────────────────

    #[Test]
    public function stringable_returns_formatted_date(): void
    {
        $date = Date::create(2025, 6, 15, 14, 30, 45);
        $this->assertSame('2025-06-15 14:30:45', (string) $date);
    }

    #[Test]
    public function jsonSerialize_returns_iso8601(): void
    {
        $date = Date::create(2025, 6, 15, 12, 0, 0, 'UTC');
        $result = $date->jsonSerialize();

        $this->assertSame('2025-06-15T12:00:00+00:00', $result);
    }

    #[Test]
    public function json_encode_works(): void
    {
        $date = Date::create(2025, 6, 15, 12, 0, 0, 'UTC');
        $this->assertSame('"2025-06-15T12:00:00+00:00"', json_encode($date));
    }
}
