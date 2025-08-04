<?php

namespace App\Http\Requests\Song;

use Illuminate\Contracts\Validation\ValidationRule;
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
     * @return array<string, ValidationRule|array|string>
     */
    public function rules(): array
    {
        return [
            /**
             * @query
             * Current page
             */
            'page'       => 'int',
            /**
             * @query
             * Items per page
             */
            'limit'      => 'int',
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
            /**
             * @query
             * Comma seperated string of relations
             * - album
             * - artists
             * - album.albumArtist
             * - genres
             */
            'relations'  => 'string',
        ];
    }
}
