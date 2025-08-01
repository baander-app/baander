<?php

namespace App\Http\Requests\Playlist;

use Illuminate\Foundation\Http\FormRequest;

class PlaylistShowRequest extends FormRequest
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
             * Comma separated string of relations
             * - cover
             * - songs
             * - songs.artists
             * - songs.album
             * - songs.genres
             * - songs.cover
             */
            'relations' => 'string',
        ];
    }
}
