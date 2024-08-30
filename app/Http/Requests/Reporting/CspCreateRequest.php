<?php

namespace App\Http\Requests\Reporting;

use Illuminate\Foundation\Http\FormRequest;

class CspCreateRequest extends FormRequest
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
    public function rules()
    {
        return [
            'csp-report' => [
                'blocked-uri'         => 'required|string',
                'document-uri'        => 'string',
                'effective-directive' => 'string',
                'original-policy'     => 'string',
                'referrer'            => 'string',
                'status-code'         => 'integer',
                'violated-directive'  => 'string',
                'source-file'         => 'string',
                'line-number'         => 'integer',
                'column-number'       => 'integer',
            ],
        ];
    }
}
