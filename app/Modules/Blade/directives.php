<?php

use App\Extensions\ArrExt;
use App\Format\Bytes;
use App\Format\Duration;

return [
    'any_to_string' => function ($value) {
        if (is_object($value)) {
            return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }

        if (is_array($value)) {
            return implode(PHP_EOL, ArrExt::dotKeys($value));
        }

        return $value;
    },

    'humanize_duration' => function ($duration, $hoursPerDay = 24) {
        return new Duration($duration, $hoursPerDay)->humanize();
    },

    'humanize_bytes' => function ($bytes, $decimals = 2) {
        return Bytes::format($bytes, $decimals);
    },
];
