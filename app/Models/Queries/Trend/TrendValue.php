<?php

namespace App\Models\Queries\Trend;

class TrendValue
{
    public function __construct(
        public string $date,
        public mixed  $aggregate,
    )
    {
    }
}