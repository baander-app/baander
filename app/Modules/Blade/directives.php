<?php
return [
    'any_to_string' => function ($value) {
        if (is_object($value)) {
            return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }

        if (is_array($value)) {
            return implode(PHP_EOL, \App\Extensions\ArrExt::dotKeys($value));
        }

        return $value;
    },

    'humanize_duration' => function ($duration, $hoursPerDay = 24) {
        return (new \App\Modules\Humanize\HumanDuration($duration, $hoursPerDay))->humanize();
    },

    'humanize_bytes' => function ($bytes, $decimals = 2) {
        return \App\Modules\Humanize\humanize_bytes($bytes, $decimals);
    },
];
