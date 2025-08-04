<?php

use App\Extensions\ArrExt;
use App\Modules\Humanize\HumanDuration;
use function App\Modules\Humanize\humanize_bytes;

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
        return new HumanDuration($duration, $hoursPerDay)->humanize();
    },

    'humanize_bytes' => function ($bytes, $decimals = 2) {
        return humanize_bytes($bytes, $decimals);
    },
];
