<?php

declare(strict_types=1);

namespace App\Catalog\Domain\ValueObject;

use DateTimeImmutable;

final readonly class PersonDate
{
    private function __construct(
        public DateTimeImmutable $firstDay,
        public int $precision,
    ) {}

    public static function parse(?string $value): ?self
    {
        if ($value === null || preg_match('/^([0-9]{4})(?:-([0-9]{2})(?:-([0-9]{2}))?)?$/D', $value, $parts) !== 1) {
            return null;
        }

        $year = (int) $parts[1];
        $month = (int) ($parts[2] ?? 1);
        $day = (int) ($parts[3] ?? 1);
        if (!checkdate($month, $day, $year)) {
            return null;
        }

        return new self(new DateTimeImmutable(sprintf('%04d-%02d-%02dT00:00:00Z', $year, $month, $day)), count($parts) - 1);
    }

    public static function fromStorage(?string $date, ?int $precision): ?self
    {
        return match ($precision) {
            1 => self::parse(substr($date ?? '', 0, 4)),
            2 => self::parse(substr($date ?? '', 0, 7)),
            3 => self::parse($date),
            default => null,
        };
    }

    public function display(): string
    {
        return $this->firstDay->format(match ($this->precision) {
            1 => 'Y',
            2 => 'Y-m',
            default => 'Y-m-d',
        });
    }

    public function ageAt(self $end): ?int
    {
        if ($end->firstDay < $this->lastDay()) {
            return null;
        }

        $low = $this->lastDay()->diff($end->firstDay)->y;
        $high = $this->firstDay->diff($end->lastDay())->y;

        return $low === $high ? $low : null;
    }

    private function lastDay(): DateTimeImmutable
    {
        return match ($this->precision) {
            1 => $this->firstDay->modify('last day of December'),
            2 => $this->firstDay->modify('last day of this month'),
            default => $this->firstDay,
        };
    }
}
