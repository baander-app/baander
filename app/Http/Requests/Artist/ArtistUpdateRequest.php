<?php

namespace App\Http\Requests\Artist;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ArtistUpdateRequest extends FormRequest
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
            'name'           => 'required|string|min:1|max:255',
            'mbid'           => 'string|min:1|max:255',
            'discogsId'      => 'numeric|min:0|max:999999999999',
            'spotifyId'      => 'string|min:1|max:255',
            'biography'      => 'string|nullable',
            'disambiguation' => 'string|min:1|max:255|nullable',
            'locked_fields'  => 'array',
            'locked_fields.*' => 'string',
        ];
    }
}
