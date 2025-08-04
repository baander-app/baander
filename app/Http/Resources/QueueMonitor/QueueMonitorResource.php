<?php

namespace App\Http\Resources\QueueMonitor;

use App\Http\Resources\HasJsonCollection;
use App\Models\QueueMonitor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin QueueMonitor
 */
class QueueMonitorResource extends JsonResource
{
    use HasJsonCollection;

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'jobId'           => $this->job_id,
            'name'            => $this->name,
            'queue'           => $this->queue,
            'startedAt'       => $this->started_at,
            'finishedAt'      => $this->finished_at,
            'attempt'         => $this->attempt,
            'progress'        => $this->progress,
            'exception'       => $this->exception,
            'exceptionClass'  => $this->exception_class,
            'data'            => $this->data,
            'status'          => $this->status,
            'jobUuid'         => $this->job_uuid,
            'retried'         => $this->retried,
            'queuedAt'        => $this->queued_at,
        ];
    }
}
