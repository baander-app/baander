<?php

namespace App\Primitives;

use App\Primitives\Traits\ImmutableBuilder;
use DateTimeImmutable;
use DateTimeZone;
use JsonSerializable;
use Stringable;

class Date implements Stringable, JsonSerializable
{
    use ImmutableBuilder;

    protected function __construct(protected DateTimeImmutable $date)
    {
    }

    /**
     * Create an independent copy of this instance with a deep-cloned DateTimeImmutable.
     */
    public function clone(): static
    {
        $copy = clone $this;
        $copy->date = clone $this->date;

        return $copy;
    }

    // ─── Static Factory Methods ──────────────────────────────────────────────

    public static function now(?string $timezone = null): static
    {
        return new static(
            $timezone !== null
                ? new DateTimeImmutable('now', new DateTimeZone($timezone))
                : new DateTimeImmutable()
        );
    }

    public static function today(?string $timezone = null): static
    {
        return static::now($timezone)->startOfDay();
    }

    public static function tomorrow(?string $timezone = null): static
    {
        return static::now($timezone)->addDays(1)->startOfDay();
    }

    public static function yesterday(?string $timezone = null): static
    {
        return static::now($timezone)->subDays(1)->startOfDay();
    }

    public static function parse(string $time, ?string $timezone = null): static
    {
        return new static(
            $timezone !== null
                ? new DateTimeImmutable($time, new DateTimeZone($timezone))
                : new DateTimeImmutable($time)
        );
    }

    public static function create(int $year, int $month, int $day, int $hour = 0, int $minute = 0, int $second = 0, ?string $timezone = null): static
    {
        $string = sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $hour, $minute, $second);

        return new static(
            $timezone !== null
                ? new DateTimeImmutable($string, new DateTimeZone($timezone))
                : new DateTimeImmutable($string)
        );
    }

    public static function createFromTimestamp(int $timestamp, ?string $timezone = null): static
    {
        $tz = $timezone !== null ? new DateTimeZone($timezone) : null;

        return new static(
            $tz !== null
                ? DateTimeImmutable::createFromFormat('U', (string) $timestamp, $tz)
                : DateTimeImmutable::createFromFormat('U', (string) $timestamp)
        );
    }

    public static function createFromFormat(string $format, string $time, ?string $timezone = null): ?static
    {
        $datetime = $timezone !== null
            ? DateTimeImmutable::createFromFormat($format, $time, new DateTimeZone($timezone))
            : DateTimeImmutable::createFromFormat($format, $time);

        if ($datetime === false) {
            return null;
        }

        return new static($datetime);
    }

    // ─── Instance Query Methods ──────────────────────────────────────────────

    public function year(): int
    {
        return (int) $this->date->format('Y');
    }

    public function month(): int
    {
        return (int) $this->date->format('n');
    }

    public function day(): int
    {
        return (int) $this->date->format('j');
    }

    public function hour(): int
    {
        return (int) $this->date->format('G');
    }

    public function minute(): int
    {
        return (int) $this->date->format('i');
    }

    public function second(): int
    {
        return (int) $this->date->format('s');
    }

    public function dayOfWeek(): int
    {
        return (int) $this->date->format('w');
    }

    public function dayOfYear(): int
    {
        return (int) $this->date->format('z') + 1;
    }

    public function quarter(): int
    {
        return (int) ceil($this->month() / 3);
    }

    public function timestamp(): int
    {
        return $this->date->getTimestamp();
    }

    public function format(string $format): string
    {
        return $this->date->format($format);
    }

    public function isToday(): bool
    {
        return $this->isSameDay(static::now($this->date->getTimezone()->getName()));
    }

    public function isYesterday(): bool
    {
        return $this->isSameDay(static::now($this->date->getTimezone()->getName())->subDays(1));
    }

    public function isTomorrow(): bool
    {
        return $this->isSameDay(static::now($this->date->getTimezone()->getName())->addDays(1));
    }

    public function isFuture(): bool
    {
        return $this->date > static::now($this->date->getTimezone()->getName())->date;
    }

    public function isPast(): bool
    {
        return $this->date < static::now($this->date->getTimezone()->getName())->date;
    }

    public function isWeekend(): bool
    {
        $day = $this->dayOfWeek();

        return $day === 0 || $day === 6;
    }

    public function isWeekday(): bool
    {
        return ! $this->isWeekend();
    }

    public function isSameDay(Date $other): bool
    {
        return $this->format('Y-m-d') === $other->format('Y-m-d');
    }

    public function isSameMonth(Date $other): bool
    {
        return $this->format('Y-m') === $other->format('Y-m');
    }

    public function isSameYear(Date $other): bool
    {
        return $this->year() === $other->year();
    }

    public function diffInSeconds(Date $other): int
    {
        return abs($this->date->getTimestamp() - $other->date->getTimestamp());
    }

    public function diffInMinutes(Date $other): int
    {
        return (int) floor($this->diffInSeconds($other) / 60);
    }

    public function diffInHours(Date $other): int
    {
        return (int) floor($this->diffInMinutes($other) / 60);
    }

    public function diffInDays(Date $other): int
    {
        return (int) floor($this->diffInHours($other) / 24);
    }

    // ─── Instance Builder Methods (all return new instances) ─────────────────

    public function addYears(int $years): static
    {
        return $this->clone()->withDate($this->date->modify("+$years year"));
    }

    public function subYears(int $years): static
    {
        return $this->clone()->withDate($this->date->modify("-$years year"));
    }

    public function addMonths(int $months): static
    {
        return $this->clone()->withDate($this->date->modify("+$months month"));
    }

    public function subMonths(int $months): static
    {
        return $this->clone()->withDate($this->date->modify("-$months month"));
    }

    public function addDays(int $days): static
    {
        return $this->clone()->withDate($this->date->modify("+$days day"));
    }

    public function subDays(int $days): static
    {
        return $this->clone()->withDate($this->date->modify("-$days day"));
    }

    public function addHours(int $hours): static
    {
        return $this->clone()->withDate($this->date->modify("+$hours hour"));
    }

    public function subHours(int $hours): static
    {
        return $this->clone()->withDate($this->date->modify("-$hours hour"));
    }

    public function addMinutes(int $minutes): static
    {
        return $this->clone()->withDate($this->date->modify("+$minutes minute"));
    }

    public function subMinutes(int $minutes): static
    {
        return $this->clone()->withDate($this->date->modify("-$minutes minute"));
    }

    public function addSeconds(int $seconds): static
    {
        return $this->clone()->withDate($this->date->modify("+$seconds second"));
    }

    public function subSeconds(int $seconds): static
    {
        return $this->clone()->withDate($this->date->modify("-$seconds second"));
    }

    public function startOfDay(): static
    {
        return $this->clone()->withDate($this->date->setTime(0, 0, 0));
    }

    public function endOfDay(): static
    {
        return $this->clone()->withDate($this->date->setTime(23, 59, 59));
    }

    public function startOfWeek(): static
    {
        $day = $this->dayOfWeek();
        $daysToMonday = $day === 0 ? 6 : $day - 1;

        return $this->subDays($daysToMonday)->startOfDay();
    }

    public function endOfWeek(): static
    {
        $day = $this->dayOfWeek();
        $daysToSunday = $day === 0 ? 0 : 7 - $day;

        return $this->addDays($daysToSunday)->endOfDay();
    }

    public function startOfMonth(): static
    {
        return $this->clone()->withDate($this->date->setDate($this->year(), $this->month(), 1)->setTime(0, 0, 0));
    }

    public function endOfMonth(): static
    {
        $lastDay = (int) $this->date->format('t');

        return $this->clone()->withDate($this->date->setDate($this->year(), $this->month(), $lastDay)->setTime(23, 59, 59));
    }

    public function startOfYear(): static
    {
        return $this->clone()->withDate($this->date->setDate($this->year(), 1, 1)->setTime(0, 0, 0));
    }

    public function endOfYear(): static
    {
        return $this->clone()->withDate($this->date->setDate($this->year(), 12, 31)->setTime(23, 59, 59));
    }

    // ─── Interface Implementations ───────────────────────────────────────────

    public function __toString(): string
    {
        return $this->date->format('Y-m-d H:i:s');
    }

    public function jsonSerialize(): string
    {
        return $this->date->format(DateTimeImmutable::ATOM);
    }

    // ─── Internal Helpers ────────────────────────────────────────────────────

    protected function withDate(DateTimeImmutable $date): static
    {
        $this->date = $date;

        return $this;
    }
}
