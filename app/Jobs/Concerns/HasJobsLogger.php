<?php

namespace App\Jobs\Concerns;

use Illuminate\Support\Facades\Log;

trait HasJobsLogger
{
    protected function logger()
    {
        return Log::channel('jobs_stack');
    }
}