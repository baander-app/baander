<?php

namespace App\Http\Requests\Genre;

use Illuminate\Contracts\Validation\ValidationRule;
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
     * @return array<string, ValidationRule|array|string>
     */
    public function rules(): array
    {
        return [
            /**
             * @query
             * Comma seperated string of fields you want to select. If nothing is defined `select *` is default.
             * - name
             */
            'fields'      => 'string',
            /**
             * @query
             * Comma seperated string of relations
             * - songs
             */
            'relations'   => 'string',
            /**
             * @query
             *
             * Constrain the query to only fetch genres that are contained within the given library
             */
            'librarySlug' => 'string',
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
