<?php

namespace App\Http\Requests\QueueMetrics;

use Illuminate\Foundation\Http\FormRequest;

class MetricsRequest extends FormRequest
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
             * @default 14
             *
             * Days to aggregate
             */
            'aggregateDays' => 'int',
        ];
    }
}
