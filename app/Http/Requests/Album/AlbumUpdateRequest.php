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
            'title'      => 'required|string|min:1|max:255',
            'year'       => 'numeric|min:0|max:9999',
            'mbid'       => 'string|min:1|max:255',
            'discogsId' => 'numeric|min:0|max:999999999999',
            'genres'     => 'array|distinct',
            'genres.*'   => 'exists:genres,id',
        ];
    }

    public function messages(): array
    {
        return [
            'genres.distinct' => 'Each genre can only be selected once.',
        ];
    }
}
