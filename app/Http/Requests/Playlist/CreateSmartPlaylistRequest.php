<?php

namespace App\Http\Requests\Playlist;

use Illuminate\Foundation\Http\FormRequest;

class CreateSmartPlaylistRequest extends FormRequest
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
            'name'                     => 'required|string|max:255',
            'description'              => 'nullable|string',
            'isPublic'                 => 'sometimes|boolean',
            'rules'                    => 'required|array',
            'rules.*'                  => 'array',
            'rules.*.operator'         => 'sometimes|string|in:and,or',
            'rules.*.rules'            => 'required|array',
            'rules.*.rules.*.field'    => 'required|string',
            'rules.*.rules.*.operator' => 'required|string',
            'rules.*.rules.*.value'    => 'required',
        ];
    }
}
