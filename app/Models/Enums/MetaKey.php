<?php

namespace App\Models\Enums;

enum MetaKey: string
{
    case RAW_STATS = 'raw_stats';
    case FORMATTED_STATS = 'formatted_stats';
    case STATS_LOADED_AT = 'stats_loaded_at';
    case COMPUTATION_TIME = 'computation_time';
    case CACHE_KEY = 'cache_key';
}
