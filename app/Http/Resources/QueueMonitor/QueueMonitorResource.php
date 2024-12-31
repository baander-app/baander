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
            'id'                => $this->id,
            'job_id'            => $this->job_id,
            'name'              => $this->name,
            'queue'             => $this->queue,
            'started_at'        => $this->started_at,
            'started_at_exact'  => $this->started_at_exact,
            'finished_at'       => $this->finished_at,
            'finished_at_exact' => $this->finished_at_exact,
            'attempt'           => $this->attempt,
            'progress'          => $this->progress,
            'exception'         => $this->exception,
            'exception_message' => $this->exception_message,
            'exception_class'   => $this->exception_class,
            'data'              => $this->data,
            'status'            => $this->status,
            'job_uuid'          => $this->job_uuid,
            'retried'           => $this->retried,
            'queued_at'         => $this->queued_at,
        ];
    }
}
