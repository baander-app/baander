<?php

namespace App\Modules\Humanize;

class HumanDuration
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

    public function toMinutes(string|float|int|null $duration = null, int|false $precision = false): float|int
    {
        if ($duration !== null) {
            $this->parse($duration);
        }

        $totalMinutes = $this->toSeconds() / self::SECONDS_IN_MINUTE;

        return $precision !== false ? round($totalMinutes, $precision) : $totalMinutes;
    }

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