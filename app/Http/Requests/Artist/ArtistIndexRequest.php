<?php

namespace App\Http\Requests\Artist;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ArtistIndexRequest extends FormRequest
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
             * - title
             */
            'fields'    => 'string',
            /**
             * @query
             * Comma seperated string of relations
             * - portrait
             * - songs
             */
            'relations' => 'string',
            /**
             * @query
             * Current page
             */
            'page'      => 'int',
            /**
             * @query
             * Items per page
             */
            'limit'     => 'int',
            /**
             * @query
             * _Extension_ Comma seperated list of genres
             */
            'genres'    => 'string',
        ];
    }
}
