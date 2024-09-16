<?php

namespace App\Http\Requests\Library;

use Illuminate\Foundation\Http\FormRequest;

class LibraryIndexRequest extends FormRequest
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
        ];
    }
}
