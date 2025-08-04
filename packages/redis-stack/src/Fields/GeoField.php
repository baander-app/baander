<?php

namespace Baander\RedisStack\Fields;

use InvalidArgumentException;

class GeoField extends Field
{
    private float $longitude;
    private float $latitude;
    private float $radius;
    private string $unit;

    private const array ALLOWED_UNITS = ['m', 'km', 'mi', 'ft'];

    public function __construct(string $fieldName, float $longitude, float $latitude, float $radius, string $unit = 'km')
    {
        parent::__construct($fieldName);
        $this->longitude = $longitude;
        $this->latitude = $latitude;
        $this->radius = $radius;

        if (!in_array($unit, self::ALLOWED_UNITS, true)) {
            throw new InvalidArgumentException("$unit is not a valid unit. Allowed units are " . implode(', ', self::ALLOWED_UNITS));
        }
        $this->unit = $unit;
    }

    public function __toString(): string
    {
        return sprintf('@%s:[%f %f %f %s]', $this->fieldName, $this->longitude, $this->latitude, $this->radius, $this->unit);
    }
}