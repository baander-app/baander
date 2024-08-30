<?php

namespace App\Http\Requests\Library;

use App\Models\LibraryType;
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
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name'  => 'optional|string:min:1|max:100',
            'path'  => 'optional|string:min:1|max:1000',
            'type'  => ['optional', Rule::enum(LibraryType::class)],
            'order' => 'optional|integer',
        ];
    }
}
