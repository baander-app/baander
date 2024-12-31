<?php

namespace App\Http\Requests\Song;

use Illuminate\Foundation\Http\FormRequest;

class SongIndexRequest extends FormRequest
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
             * Comma seperated string of relations
             * - album
             * - artists
             * - albumArtist
             * - genres
             */
            /**
             * @query
             * Current page
             */
            'page'      => 'int',
            /**
             * @query
             * Items per page
             */
            'perPage'   => 'int',
            /**
             * @query
             * Comma seperated list of genre names
             *
             * You can only search for names or slugs. Not both.
             */
            'genreNames' => 'string',
            /**
             * @query
             * Comma seperated list of genre slugs
             */
            'genreSlugs' => 'string',
        ];
    }
}
