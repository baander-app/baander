<?php

namespace App\Http\Requests\Library;

use App\Models\LibraryType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateLibraryRequest extends FormRequest
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
            'name'  => 'required|string:min:1|max:100',
            'path'  => 'required|string:min:1|max:1000',
            'type'  => ['required', Rule::enum(LibraryType::class)],
            'order' => 'required|integer',
        ];
    }
}
