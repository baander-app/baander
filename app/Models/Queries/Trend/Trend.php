<?php

namespace App\Models\Queries\Trend;

use Illuminate\Support\Collection;
use Carbon\{CarbonInterface, CarbonPeriod};
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

class Trend
{
    public const array INTERVALS = [
        'minute' => 'YYYY-MM-DD HH24:MI',
        'hour'   => 'YYYY-MM-DD HH24',
        'day'    => 'YYYY-MM-DD',
        'week'   => 'IYYY-IW',
        'month'  => 'YYYY-MM',
        'year'   => 'YYYY',
    ];

    public string $interval;
    public CarbonInterface $start;
    public CarbonInterface $end;

    public string $dateColumn = 'created_at';
    public string $dateAlias = 'date';

    public ?string $groupingColumn = null;
    protected Builder $builder;

    public function __construct(Builder $builder)
    {
        $this->builder = $builder;
    }

    public static function query(Builder $builder): self
    {
        return new self($builder);
    }

    public static function model(string $model): self
    {
        return new self($model::newQuery());
    }

    public function between(CarbonInterface $start, CarbonInterface $end): self
    {
        $this->start = $start;
        $this->end = $end;

        return $this;
    }

    public function interval(string $interval): self
    {
        if (!array_key_exists($interval, self::INTERVALS)) {
            throw new InvalidArgumentException("Unsupported interval: {$interval}");
        }

        $this->interval = $interval;

        return $this;
    }

    public function perInterval(string $interval): self
    {
        return $this->interval($interval);
    }

    public function dateColumn(string $column): self
    {
        $this->dateColumn = $column;

        return $this;
    }

    public function dateAlias(string $alias): self
    {
        $this->dateAlias = $alias;

        return $this;
    }

    public function groupBy(string $groupingColumn): self
    {
        $this->groupingColumn = $groupingColumn;

        return $this;
    }

    public function aggregate(string $column, string $aggregate): Collection
    {
        $sqlDate = $this->getSqlDate();
        $query = $this->builder->toBase()->selectRaw("
            {$sqlDate} as {$this->dateAlias},
            {$aggregate}({$column}) as aggregate" .
            ($this->groupingColumn ? ", {$this->groupingColumn} as group" : ""),
        )->whereBetween($this->dateColumn, [$this->start, $this->end])
            ->groupBy($this->dateAlias);

        if ($this->groupingColumn) {
            $query->groupBy($this->groupingColumn);
        }

        $query->orderBy($this->dateAlias);

        $values = $query->get();

        if ($this->groupingColumn) {
            return $values->groupBy('group')->map(fn($group) => $this->mapValuesToDates($group));
        }

        return $this->mapValuesToDates($values);
    }

    public function average(string $column): Collection
    {
        return $this->aggregate($column, 'avg');
    }

    public function min(string $column): Collection
    {
        return $this->aggregate($column, 'min');
    }

    public function max(string $column): Collection
    {
        return $this->aggregate($column, 'max');
    }

    public function sum(string $column): Collection
    {
        return $this->aggregate($column, 'sum');
    }

    public function count(string $column = '*'): Collection
    {
        return $this->aggregate($column, 'count');
    }

    public function rate(string $column, string $aggregate): Collection
    {
        $sqlDate = $this->getSqlDate();
        $values = $this->builder->toBase()->selectRaw("
            {$sqlDate} as {$this->dateAlias},
            {$column},
            {$aggregate}({$column}) over (order by {$this->dateAlias} rows between 1 preceding and current row) as rate",
        )->whereBetween($this->dateColumn, [$this->start, $this->end])
            ->groupBy($this->dateAlias)
            ->orderBy($this->dateAlias)
            ->get();

        return $this->mapValuesToDates($values)->map(function ($value, $key) use ($values) {
            if ($key === 0) {
                $value->aggregate = 0;
            }
            return $value;
        });
    }

    public function cumulative(string $column, string $aggregate): Collection
    {
        $sqlDate = $this->getSqlDate();
        $values = $this->builder->toBase()->selectRaw("
            {$sqlDate} as {$this->dateAlias},
            {$column},
            sum({$aggregate}({$column})) over (order by {$this->dateAlias}) as aggregate",
        )->whereBetween($this->dateColumn, [$this->start, $this->end])
            ->groupBy($this->dateAlias)
            ->orderBy($this->dateAlias)
            ->get();

        return $this->mapValuesToDates($values);
    }

    public function movingAverage(string $column, string $aggregate, int $period): Collection
    {
        $sqlDate = $this->getSqlDate();
        $values = $this->builder->toBase()->selectRaw("
            {$sqlDate} as {$this->dateAlias},
            {$column},
            avg({$column}) over (order by {$this->dateAlias} rows between {$period} preceding and current row) as aggregate",
        )->whereBetween($this->dateColumn, [$this->start, $this->end])
            ->groupBy($this->dateAlias)
            ->orderBy($this->dateAlias)
            ->get();

        return $this->mapValuesToDates($values);
    }

    public function percentageChange(string $column): Collection
    {
        $sqlDate = $this->getSqlDate();
        $values = $this->builder->toBase()->selectRaw("
            {$sqlDate} as {$this->dateAlias},
            {$column},
            (first_value({$column}) over (order by {$this->dateAlias}) - 
             lag(first_value({$column}) over (order by {$this->dateAlias}))) /
             lag(first_value({$column}) over (order by {$this->dateAlias})) * 100 as aggregate",
        )->whereBetween($this->dateColumn, [$this->start, $this->end])
            ->groupBy($this->dateAlias)
            ->orderBy($this->dateAlias)
            ->get();

        return $this->mapValuesToDates($values)->map(function ($value, $key) use ($values) {
            if ($key === 0) {
                $value->aggregate = 0; // Set initial change to 0 since there's no previous value
            }
            return $value;
        });
    }

    public function deviation(string $column, string $aggregate): Collection
    {
        $values = $this->aggregate($column, $aggregate);
        $mean = $values->avg('aggregate');

        return $values->map(function ($value) use ($mean) {
            $deviation = pow($value->aggregate - $mean, 2);
            return new TrendValue(date: $value->date, aggregate: $deviation);
        });
    }

    public function distribution(string $column, string $aggregate): Collection
    {
        $values = $this->aggregate($column, $aggregate);

        return $values->mapToGroups(function ($item) {
            $bucket = floor($item->aggregate / 10) * 10; // Example of bucketing by tens
            return [$bucket => $item];
        });
    }

    protected function mapValuesToDates(Collection $values): Collection
    {
        $formattedValues = $values->map(fn($value) => new TrendValue(
            date: $value->{$this->dateAlias},
            aggregate: $value->aggregate,
        ));

        $placeholders = $this->getDatePeriod()->map(
            fn(CarbonInterface $date) => new TrendValue(
                date: $date->format($this->getCarbonDateFormat()),
                aggregate: 0,
            ),
        );

        return $formattedValues->merge($placeholders)->unique('date')->sortBy('date')->values();
    }

    protected function getDatePeriod(): Collection
    {
        return collect(CarbonPeriod::between($this->start, $this->end)->interval("1 {$this->interval}"));
    }

    protected function getSqlDate(): string
    {
        return self::INTERVALS[$this->interval]
            ?? throw new InvalidArgumentException("Unsupported interval: {$this->interval}");
    }

    protected function getCarbonDateFormat(): string
    {
        return match ($this->interval) {
            'minute' => 'Y-m-d H:i',
            'hour' => 'Y-m-d H',
            'day' => 'Y-m-d',
            'week' => 'o-\WW',
            'month' => 'Y-m',
            'year' => 'Y',
            default => throw new InvalidArgumentException("Unsupported interval: {$this->interval}")
        };
    }
}