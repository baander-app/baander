<?php

namespace App\Http\Requests\User;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UserIndexRequest extends FormRequest
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
     * @return array<string, ValidationRule|array|string>
     */
    public function rules(): array
    {
        return [
            /**
             * @query
             * Current page
             */
            'page'         => 'int',
            /**
             * @query
             * Items per page
             */
            'limit'        => 'int',
            /**
             * @query
             */
            'globalFilter' => 'string',
            /**
             * @query
             *
             * JSON object
             */
            'filters'      => 'string',
            /**
             * @query
             *
             * JSON object
             */
            'filterModes'  => 'string',
            /**
             * @query
             *
             * JSON object
             */
            'sorting'      => 'string',
        ];
    }
}
