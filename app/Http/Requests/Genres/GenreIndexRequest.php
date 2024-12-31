<?php

namespace App\Http\Requests\Genres;

use Illuminate\Foundation\Http\FormRequest;

class GenreIndexRequest extends FormRequest
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
             * Comma seperated string of fields you want to select. If nothing is defined `select *` is default.
             * - name
             * - slug
             */
            'fields'    => 'string',
            /**
             * @query
             * Comma seperated string of relations
             * - songs
             */
            'relations' => 'string',
            /** @query */
            'page' => 'int',
            /** @query */
            'perPage' => 'int',
        ];
    }
}
