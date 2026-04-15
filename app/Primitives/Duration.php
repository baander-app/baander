<?php

namespace App\Primitives;

use App\Primitives\Traits\ImmutableBuilder;

/**
 * Format and parse time durations in various formats (seconds, colon-separated, human-readable).
 *
 * All instance methods are immutable — they return new instances rather than modifying $this.
 *
 * @example
 * Duration::fromSeconds(3600)->humanize() // "1h 0s"
 * Duration::fromSeconds(3600)->toMinutes() // 60.0
 * (new Duration)->humanize('2h 30m') // "2h 30m 0s"
 */
class Duration
{
    use ImmutableBuilder;

    private const int SECONDS_IN_MINUTE = 60;
    private const int MINUTES_IN_HOUR = 60;
    private const int HOURS_IN_DAY = 24;

    public readonly int $days;
    public readonly int $hours;
    public readonly int $minutes;
    public readonly float $seconds;
    public readonly int $hoursPerDay;

    private static string $daysRegex = '/([0-9.]+)\s?[dD]/';
    private static string $hoursRegex = '/([0-9.]+)\s?[hH]/';
    private static string $minutesRegex = '/(\d{1,2})\s?[mM]/';
    private static string $secondsRegex = '/(\d{1,2}(\.\d+)?)\s?[sS]/';

    public function __construct(string|float|int|null $duration = null, int $hoursPerDay = self::HOURS_IN_DAY)
    {
        $parsed = self::parseDuration($duration, $hoursPerDay);
        $this->days = $parsed['days'];
        $this->hours = $parsed['hours'];
        $this->minutes = $parsed['minutes'];
        $this->seconds = $parsed['seconds'];
        $this->hoursPerDay = $hoursPerDay;
    }

    /**
     * Create a Duration instance from seconds.
     */
    public static function fromSeconds(float|int $seconds, int $hoursPerDay = self::HOURS_IN_DAY): self
    {
        return new self($seconds, $hoursPerDay);
    }

    /**
     * Create a Duration instance from minutes.
     */
    public static function fromMinutes(float|int $minutes, int $hoursPerDay = self::HOURS_IN_DAY): self
    {
        return new self($minutes * self::SECONDS_IN_MINUTE, $hoursPerDay);
    }

    /**
     * Create a Duration instance from hours.
     */
    public static function fromHours(float|int $hours, int $hoursPerDay = self::HOURS_IN_DAY): self
    {
        return new self($hours * self::MINUTES_IN_HOUR * self::SECONDS_IN_MINUTE, $hoursPerDay);
    }

    /**
     * Create a Duration instance from days.
     */
    public static function fromDays(float|int $days, int $hoursPerDay = self::HOURS_IN_DAY): self
    {
        return new self($days * $hoursPerDay * self::MINUTES_IN_HOUR * self::SECONDS_IN_MINUTE, $hoursPerDay);
    }

    /**
     * Parse a duration string/number and return a new instance.
     */
    public function parse(string|float|int|null $duration): self
    {
        return new self($duration, $this->hoursPerDay);
    }

    /**
     * Convert the duration to total seconds.
     */
    public function toSeconds(string|float|int|null $duration = null): float|int
    {
        $instance = $duration !== null ? new self($duration, $this->hoursPerDay) : $this;

        return $instance->seconds +
            $instance->minutes * self::SECONDS_IN_MINUTE +
            $instance->hours * self::MINUTES_IN_HOUR * self::SECONDS_IN_MINUTE +
            $instance->days * $instance->hoursPerDay * self::MINUTES_IN_HOUR * self::SECONDS_IN_MINUTE;
    }

    /**
     * Convert the duration to total minutes.
     */
    public function toMinutes(string|float|int|null $duration = null, int|false $precision = false): float|int
    {
        $instance = $duration !== null ? new self($duration, $this->hoursPerDay) : $this;
        $totalMinutes = $instance->toSeconds() / self::SECONDS_IN_MINUTE;

        return $precision !== false ? round($totalMinutes, $precision) : $totalMinutes;
    }

    /**
     * Convert the duration to total hours.
     */
    public function toHours(string|float|int|null $duration = null, int|false $precision = false): float|int
    {
        $instance = $duration !== null ? new self($duration, $this->hoursPerDay) : $this;
        $totalHours = $instance->toMinutes() / self::MINUTES_IN_HOUR;

        return $precision !== false ? round($totalHours, $precision) : $totalHours;
    }

    /**
     * Convert the duration to total days.
     */
    public function toDays(string|float|int|null $duration = null, int|false $precision = false): float|int
    {
        $instance = $duration !== null ? new self($duration, $this->hoursPerDay) : $this;
        $totalDays = $instance->toHours() / $instance->hoursPerDay;

        return $precision !== false ? round($totalDays, $precision) : $totalDays;
    }

    /**
     * Format the duration in a human-readable format.
     */
    public function humanize(string|float|int|null $duration = null): string
    {
        $instance = $duration !== null ? new self($duration, $this->hoursPerDay) : $this;

        $output = '';

        if ($instance->seconds > 0 || ($instance->seconds === 0.0 && $instance->minutes === 0 && $instance->hours === 0 && $instance->days === 0)) {
            $output .= number_format($instance->seconds) . 's ';
        }

        if ($instance->minutes > 0) {
            $output = $instance->minutes . __('date.time.minute.char') . ' ' . $output;
        }

        if ($instance->hours > 0) {
            $output = $instance->hours . __('date.time.hour.char') . ' ' . $output;
        }

        if ($instance->days > 0) {
            $output = $instance->days . __('date.time.day.char') . ' ' . $output;
        }

        return trim($output);
    }

    /**
     * Format as ISO 8601 duration (e.g., PT1H30M).
     */
    public function toIso8601(string|float|int|null $duration = null): string
    {
        $instance = $duration !== null ? new self($duration, $this->hoursPerDay) : $this;

        $parts = ['P'];

        if ($instance->days > 0) {
            $parts[] = $instance->days . 'D';
        }

        $timeParts = ['T'];

        if ($instance->hours > 0) {
            $timeParts[] = $instance->hours . 'H';
        }

        if ($instance->minutes > 0) {
            $timeParts[] = $instance->minutes . 'M';
        }

        if ($instance->seconds > 0 || ($instance->seconds === 0.0 && $instance->hours === 0 && $instance->days === 0)) {
            $timeParts[] = number_format($instance->seconds, 1, '.', '') . 'S';
        }

        if (count($timeParts) > 1) {
            $parts[] = implode('', $timeParts);
        }

        return implode('', $parts) ?: 'PT0S';
    }

    /**
     * Format in colon notation (e.g., "1:30:00").
     */
    public function toColonFormat(string|float|int|null $duration = null, bool $showSeconds = true): string
    {
        $instance = $duration !== null ? new self($duration, $this->hoursPerDay) : $this;

        $parts = [];

        if ($instance->days > 0) {
            $parts[] = $instance->days + $instance->hours;
        } elseif ($instance->hours > 0 || $instance->minutes > 0) {
            $parts[] = $instance->hours ?: '0';
        }

        $parts[] = str_pad((string) $instance->minutes, 2, '0', STR_PAD_LEFT);

        if ($showSeconds) {
            $parts[] = str_pad(number_format($instance->seconds, 0, '', ''), 2, '0', STR_PAD_LEFT);
        }

        return implode(':', $parts);
    }

    /**
     * Create a copy of this Duration instance.
     */
    public function copy(): self
    {
        return clone $this;
    }

    private static function parseDuration(string|float|int|null $duration, int $hoursPerDay): array
    {
        $defaults = ['days' => 0, 'hours' => 0, 'minutes' => 0, 'seconds' => 0.0];

        if ($duration === null) {
            return $defaults;
        }

        if (is_numeric($duration)) {
            return self::parseNumeric((float) $duration, $hoursPerDay);
        }

        if (str_contains($duration, ':')) {
            return self::parseColonFormatted($duration, $hoursPerDay);
        }

        return self::parseRegexFormatted($duration, $hoursPerDay);
    }

    private static function parseNumeric(float $duration, int $hoursPerDay): array
    {
        $seconds = $duration;
        $minutes = (int) floor($seconds / self::SECONDS_IN_MINUTE);
        $seconds = fmod($seconds, self::SECONDS_IN_MINUTE);

        $hours = (int) floor($minutes / self::MINUTES_IN_HOUR);
        $minutes %= self::MINUTES_IN_HOUR;

        $days = (int) floor($hours / $hoursPerDay);
        $hours %= $hoursPerDay;

        return ['days' => $days, 'hours' => $hours, 'minutes' => $minutes, 'seconds' => $seconds];
    }

    private static function parseColonFormatted(string $duration, int $hoursPerDay): array
    {
        $parts = explode(':', $duration);
        $count = count($parts);

        $hours = 0;
        $minutes = 0;
        $seconds = 0.0;

        if ($count === 2) {
            $minutes = (int) $parts[0];
            $seconds = (float) $parts[1];
        } elseif ($count === 3) {
            $hours = (int) $parts[0];
            $minutes = (int) $parts[1];
            $seconds = (float) $parts[2];
        }

        return ['days' => 0, 'hours' => $hours, 'minutes' => $minutes, 'seconds' => $seconds];
    }

    private static function parseRegexFormatted(string $duration, int $hoursPerDay): array
    {
        $days = 0;
        $hours = 0;
        $minutes = 0;
        $seconds = 0.0;

        if ($matches = self::matchRegex(self::$daysRegex, $duration)) {
            [$whole, $fraction] = self::numberBreakdown((float) $matches[1]);
            $days += $whole;
            $hours += (int) ($fraction * $hoursPerDay);
        }

        if ($matches = self::matchRegex(self::$hoursRegex, $duration)) {
            [$whole, $fraction] = self::numberBreakdown((float) $matches[1]);
            $hours += $whole;
            $minutes += (int) ($fraction * self::MINUTES_IN_HOUR);
        }

        if ($matches = self::matchRegex(self::$minutesRegex, $duration)) {
            $minutes += (int) $matches[1];
        }

        if ($matches = self::matchRegex(self::$secondsRegex, $duration)) {
            $seconds += (float) $matches[1];
        }

        return ['days' => $days, 'hours' => $hours, 'minutes' => $minutes, 'seconds' => $seconds];
    }

    private static function matchRegex(string $regex, string $duration): ?array
    {
        preg_match($regex, $duration, $matches);

        return $matches ?: null;
    }

    private static function numberBreakdown(float $number): array
    {
        $whole = (int) floor($number);
        $fraction = $number - $whole;

        return [$whole, $fraction];
    }
}
