<?php

namespace App\Http\Requests\Album;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AlbumUpdateRequest extends FormRequest
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
            // Basic Info
            'title'           => 'required|string|min:1|max:255',
            'type'            => 'nullable|string|in:studio,live,compilation,soundtrack,remix,ep,single,demo,mixtape,bootleg,interview,audiobook,spoken_word,other',
            'year'            => 'nullable|numeric|min:0|max:9999',
            'disambiguation'  => 'nullable|string|max:4000',

            // External IDs
            'mbid'            => 'nullable|string|max:255',
            'discogsId'       => 'nullable|numeric|min:0|max:999999999999',
            'spotifyId'       => 'nullable|string|max:255',

            // Release Details
            'label'           => 'nullable|string|max:260',
            'catalogNumber'   => 'nullable|string|max:60',
            'barcode'         => 'nullable|string|max:60',
            'country'         => 'nullable|string|max:2',
            'language'        => 'nullable|string|max:10',

            // Notes
            'annotation'      => 'nullable|string|max:4000',

            // Relations
            'genres'          => 'array|distinct',
            'genres.*'        => 'exists:genres,id',

            // Metadata
            'lockedFields'    => 'array',
            'lockedFields.*'  => 'string',
        ];
    }

    public function messages(): array
    {
        return [
            'title.required'           => 'The album title is required.',
            'type.in'                  => 'Invalid album type selected.',
            'year.numeric'             => 'Year must be a number.',
            'discogsId.numeric'        => 'Discogs ID must be a number.',
            'country.max'              => 'Invalid country code format.',
            'genres.distinct'          => 'Each genre can only be selected once.',
        ];
    }
}
