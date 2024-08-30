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
            /** @query */
            'albumArtist' => 'string',
            /** @query */
            'genreIds'       => 'string',
            /** @query */
            'title'       => 'string',
            /** @query */
            'albumId' => 'integer',
            /** @query */
            'page' => 'int',
            /** @query */
            'perPage' => 'int',
        ];
    }
}
