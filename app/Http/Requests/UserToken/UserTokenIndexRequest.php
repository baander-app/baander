<?php

namespace App\Http\Requests\UserToken;

use Illuminate\Foundation\Http\FormRequest;

class UserTokenIndexRequest extends FormRequest
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
            /** @query */
            'page'    => 'int',
            /** @query */
            'perPage' => 'int',

        ];
    }
}
