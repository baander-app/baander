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
            'name'            => 'required|string|min:1|max:255',
            'disambiguation'  => 'nullable|string|max:255',
            'type'            => 'nullable|string|in:person,group,orchestra,choir,character,other,undefined',
            'country'         => 'nullable|string|max:2|regex:/^[A-Z]{2}$/',
            'gender'          => 'nullable|string|in:male,female,non_binary,other,unknown',
            'sortName'        => 'nullable|string|max:255',
            'mbid'            => 'nullable|string|max:255',
            'discogsId'       => 'nullable|numeric|min:0|max:999999999999',
            'spotifyId'       => 'nullable|string|max:255',
            'biography'       => 'nullable|string|max:4000',
            'lifeSpanBegin'   => 'nullable|string|date',
            'lifeSpanEnd'     => 'nullable|string|date|after:lifeSpanBegin',
            'lockedFields'    => 'array',
            'lockedFields.*'  => 'string',
        ];
    }
}
