<?php

namespace App\Http\Requests\QueueMetrics;

use App\Packages\QueueMonitor\MonitorStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ShowQueueMetricsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            /**
             * @query
             * Current page
             */
            'page'        => 'int',
            /**
             * @query
             * Items per page
             */
            'limit'       => 'int',
            /**
             * @query
             * MonitorStatus
             * - 0=RUNNING
             * - 1=SUCCEEDED
             * - 2=FAILED
             * - 3=STALE
             * - 4=QUEUED
             */
            'status'      => [Rule::in(MonitorStatus::values())],
            /**
             * @query
             * Name of the queue
             */
            'queue'       => 'string',
            /**
             * @query
             * Name of the job
             */
            'name'        => 'string',
            /**
             * @query
             * Order queued jobs first
             *
             * @default false
             */
            'queuedFirst' => 'bool',
        ];
    }
}
