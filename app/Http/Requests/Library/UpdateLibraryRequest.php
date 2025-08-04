<?php

namespace App\Http\Requests\Library;

use App\Models\LibraryType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLibraryRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array|string>
     */
    public function rules(): array
    {
        return [
            'name'  => 'string:min:1|max:100',
            'path'  => 'string:min:1|max:1000',
            'type'  => ['optional', Rule::enum(LibraryType::class)],
            'order' => 'integer',
        ];
    }
}
