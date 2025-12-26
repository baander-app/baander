<?php

namespace App\Http\Requests\Song;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SongUpdateRequest extends FormRequest
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
            'title'        => 'required|string|min:1|max:255',
            'track_number' => 'numeric|min:0|max:9999',
            'disc_number'  => 'numeric|min:0|max:9999',
            'year'         => 'numeric|min:0|max:9999',
            'mbid'         => 'string|min:1|max:255',
            'discogs_id'   => 'numeric|min:0|max:999999999999',
            'spotify_id'   => 'string|max:255',
            'explicit'     => 'boolean',
            'lyrics'       => 'nullable|string',
            'comment'      => 'nullable|string',
            'genres'       => 'array|distinct',
            'genres.*'     => 'exists:genres,id',
            'locked_fields' => 'array',
            'locked_fields.*' => 'string',
        ];
    }

    public function messages(): array
    {
        return [
            'genres.distinct' => 'Each genre can only be selected once.',
        ];
    }
}
