<?php

namespace App\Models;

class FailedJob extends BaseModel
{
    protected $fillable = [
      'job_uuid',
      'name',
      'queue',
      'status',
      'attempt',
      'retried',
      'progress',
      'exception_class',
      'data',
    ];

    protected $casts = [
        'queued_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime'
    ];
}
