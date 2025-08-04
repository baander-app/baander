<?php

namespace App\Http\Requests\Playlist;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSmartPlaylistRulesRequest extends FormRequest
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
            'rules'              => 'required|array',
            'rules.*'            => 'array',
            'rules.*.*.field'    => 'required|string',
            'rules.*.*.operator' => 'required|string',
            'rules.*.*.value'    => 'required',
        ];
    }
}
