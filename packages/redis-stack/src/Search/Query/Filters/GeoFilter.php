<?php

namespace Baander\RedisStack\Search\Query\Filters;

use InvalidArgumentException;

class GeoFilter
{
    private const array VALID_UNITS = ['m', 'km', 'mi', 'ft'];

    public function __construct(
        private readonly string $field,
        private readonly float  $longitude,
        private readonly float  $latitude,
        private readonly float  $radius,
        private readonly string $unit = 'km'
    ) {
        if (!in_array($this->unit, self::VALID_UNITS, true)) {
            throw new InvalidArgumentException("$unit is not a valid unit.");
        }
    }

    public function __toString(): string
    {
        return sprintf('@%s:[%f %f %f %s]', $this->field, $this->longitude, $this->latitude, $this->radius, $this->unit);
    }
}