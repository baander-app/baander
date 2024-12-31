<?php

namespace App\Modules\Humanize;

function humanize_bytes(int|float $bytes, $decimals = 2): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }

    $factor = floor(log($bytes, 1024));

    return sprintf("%.{$decimals}f ", $bytes / (1024 ** $factor)) . ['B', 'KB', 'MB', 'GB', 'TB', 'PB'][$factor];
}
