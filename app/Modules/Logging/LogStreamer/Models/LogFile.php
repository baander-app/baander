<?php

namespace App\Modules\Logging\LogStreamer\Models;

use Illuminate\Support\Carbon;
use Spatie\LaravelData\Data;

class LogFile extends Data
{
    public function __construct(
        public string $id,
        public string $fileName,
        public string $path,
        public Carbon $createdAt,
        public Carbon $updatedAt,
    )
    {
    }
}