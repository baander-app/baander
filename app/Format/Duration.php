<?php

namespace App\Format;

/**
 * Format and parse time durations in various formats (seconds, colon-separated, human-readable).
 *
 * @example
 * Duration::humanize(3600) // "1h 0s"
 * Duration::fromSeconds(3600)->toMinutes() // 60.0
 * Duration::parse('2h 30m')->toSeconds() // 9000
 */
class Duration
{
    private const int SECONDS_IN_MINUTE = 60;
    private const int MINUTES_IN_HOUR = 60;
    private const int HOURS_IN_DAY = 24;

    public int $days = 0;
    public int $hours = 0;
    public int $minutes = 0;
    public float $seconds = 0.0;
    public int $hoursPerDay;

    private string $daysRegex = '/([0-9.]+)\s?[dD]/';
    private string $hoursRegex = '/([0-9.]+)\s?[hH]/';
    private string $minutesRegex = '/(\d{1,2})\s?[mM]/';
    private string $secondsRegex = '/(\d{1,2}(\.\d+)?)\s?[sS]/';

    public function __construct(string|float|int|null $duration = null, int $hoursPerDay = self::HOURS_IN_DAY)
    {
        $this->hoursPerDay = $hoursPerDay;
        if ($duration !== null) {
            $this->parse($duration);
        }
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
     * Parse a duration from a string or number.
     */
    public function parse(string|float|int|null $duration): self|false
    {
        $this->reset();
        if ($duration === null) {
            return false;
        }

        if (is_numeric($duration)) {
            return $this->parseNumericDuration((float)$duration);
        }

        if (str_contains($duration, ':')) {
            return $this->parseColonFormattedDuration($duration);
        }

        return $this->parseRegexFormattedDuration($duration);
    }

    /**
     * Convert the duration to total seconds.
     */
    public function toSeconds(string|float|int|null $duration = null, int|false $precision = false): float|int
    {
        if ($duration !== null) {
            $this->parse($duration);
        }

        $totalSeconds = $this->seconds +
            $this->minutes * self::SECONDS_IN_MINUTE +
            $this->hours * self::MINUTES_IN_HOUR * self::SECONDS_IN_MINUTE +
            $this->days * $this->hoursPerDay * self::MINUTES_IN_HOUR * self::SECONDS_IN_MINUTE;

        return $precision !== false ? round($totalSeconds, $precision) : $totalSeconds;
    }

    /**
     * Convert the duration to total minutes.
     */
    public function toMinutes(string|float|int|null $duration = null, int|false $precision = false): float|int
    {
        if ($duration !== null) {
            $this->parse($duration);
        }

        $totalMinutes = $this->toSeconds() / self::SECONDS_IN_MINUTE;

        return $precision !== false ? round($totalMinutes, $precision) : $totalMinutes;
    }

    /**
     * Convert the duration to total hours.
     */
    public function toHours(string|float|int|null $duration = null, int|false $precision = false): float|int
    {
        if ($duration !== null) {
            $this->parse($duration);
        }

        $totalHours = $this->toMinutes() / self::MINUTES_IN_HOUR;

        return $precision !== false ? round($totalHours, $precision) : $totalHours;
    }

    /**
     * Convert the duration to total days.
     */
    public function toDays(string|float|int|null $duration = null, int|false $precision = false): float|int
    {
        if ($duration !== null) {
            $this->parse($duration);
        }

        $totalDays = $this->toHours() / $this->hoursPerDay;

        return $precision !== false ? round($totalDays, $precision) : $totalDays;
    }

    /**
     * Format the duration in a human-readable format.
     *
     * @example Duration::humanize(3600) // "1h 0s"
     */
    public function humanize(string|float|int|null $duration = null): string
    {
        if ($duration !== null) {
            $this->parse($duration);
        }

        $output = '';

        if ($this->seconds > 0 || ($this->seconds === 0.0 && $this->minutes === 0 && $this->hours === 0 && $this->days === 0)) {
            $output .= number_format($this->seconds) . 's ';
        }

        if ($this->minutes > 0) {
            $output = $this->minutes . __('date.time.minute.char') . ' ' . $output;
        }

        if ($this->hours > 0) {
            $output = $this->hours . __('date.time.hour.char') . ' ' . $output;
        }

        if ($this->days > 0) {
            $output = $this->days . __('date.time.day.char') . ' ' . $output;
        }

        return trim($output);
    }

    /**
     * Format as ISO 8601 duration (e.g., PT1H30M).
     */
    public function toIso8601(string|float|int|null $duration = null): string
    {
        if ($duration !== null) {
            $this->parse($duration);
        }

        $parts = ['P'];

        if ($this->days > 0) {
            $parts[] = $this->days . 'D';
        }

        $timeParts = ['T'];

        if ($this->hours > 0) {
            $timeParts[] = $this->hours . 'H';
        }

        if ($this->minutes > 0) {
            $timeParts[] = $this->minutes . 'M';
        }

        if ($this->seconds > 0 || ($this->seconds === 0.0 && $this->hours === 0 && $this->days === 0)) {
            $timeParts[] = number_format($this->seconds, 1, '.', '') . 'S';
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
        if ($duration !== null) {
            $this->parse($duration);
        }

        $parts = [];

        if ($this->days > 0) {
            $parts[] = $this->days + $this->hours;
        } else if ($this->hours > 0 || $this->minutes > 0) {
            $parts[] = $this->hours ?: '0';
        }

        $parts[] = str_pad((string)$this->minutes, 2, '0', STR_PAD_LEFT);

        if ($showSeconds) {
            $parts[] = str_pad(number_format($this->seconds, 0, '', ''), 2, '0', STR_PAD_LEFT);
        }

        return implode(':', $parts);
    }

    /**
     * Create a copy of this Duration instance.
     */
    public function copy(): self
    {
        $copy = new self(null, $this->hoursPerDay);
        $copy->days = $this->days;
        $copy->hours = $this->hours;
        $copy->minutes = $this->minutes;
        $copy->seconds = $this->seconds;

        return $copy;
    }

    private function reset(): void
    {
        $this->seconds = 0.0;
        $this->minutes = 0;
        $this->hours = 0;
        $this->days = 0;
    }

    private function parseNumericDuration(float $duration): self
    {
        $this->seconds = $duration;
        $this->minutes = (int)floor($this->seconds / self::SECONDS_IN_MINUTE);
        $this->seconds = fmod($this->seconds, self::SECONDS_IN_MINUTE);

        $this->hours = (int)floor($this->minutes / self::MINUTES_IN_HOUR);
        $this->minutes %= self::MINUTES_IN_HOUR;

        $this->days = (int)floor($this->hours / $this->hoursPerDay);
        $this->hours %= $this->hoursPerDay;

        return $this;
    }

    private function parseColonFormattedDuration(string $duration): self
    {
        $parts = explode(':', $duration);
        $count = count($parts);

        if ($count === 2) {
            $this->minutes = (int)$parts[0];
            $this->seconds = (float)$parts[1];
        } else if ($count === 3) {
            $this->hours = (int)$parts[0];
            $this->minutes = (int)$parts[1];
            $this->seconds = (float)$parts[2];
        }

        return $this;
    }

    private function parseRegexFormattedDuration(string $duration): self
    {
        if ($matches = $this->matchRegex($this->daysRegex, $duration)) {
            [$whole, $fraction] = $this->numberBreakdown((float)$matches[1]);
            $this->days += $whole;
            $this->hours += $fraction * $this->hoursPerDay;
        }

        if ($matches = $this->matchRegex($this->hoursRegex, $duration)) {
            [$whole, $fraction] = $this->numberBreakdown((float)$matches[1]);
            $this->hours += $whole;
            $this->minutes += $fraction * self::MINUTES_IN_HOUR;
        }

        if ($matches = $this->matchRegex($this->minutesRegex, $duration)) {
            $this->minutes += (int)$matches[1];
        }

        if ($matches = $this->matchRegex($this->secondsRegex, $duration)) {
            $this->seconds += (float)$matches[1];
        }

        return $this;
    }

    private function matchRegex(string $regex, string $duration): ?array
    {
        preg_match($regex, $duration, $matches);

        return $matches ?: null;
    }

    private function numberBreakdown(float $number): array
    {
        $whole = (int)floor($number);
        $fraction = $number - $whole;

        return [$whole, $fraction];
    }
}
