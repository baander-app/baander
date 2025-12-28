<?php

namespace App\Http\Requests\MetadataBrowse;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class BrowseSearchRequest extends FormRequest
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
            'query' => 'required|string|min:2',
            'source' => 'sometimes|in:musicbrainz,discogs,all',
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:100',
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            'query.required' => 'A search query is required.',
            'query.min' => 'The search query must be at least 2 characters.',
            'source.in' => 'The source must be one of: musicbrainz, discogs, or all.',
            'page.integer' => 'The page number must be an integer.',
            'page.min' => 'The page number must be at least 1.',
            'per_page.integer' => 'The per page value must be an integer.',
            'per_page.min' => 'The per page value must be at least 1.',
            'per_page.max' => 'The per page value may not be greater than 100.',
        ];
    }

    /**
     * Get the page number.
     */
    public function getPage(): int
    {
        return (int) $this->input('page', 1);
    }

    /**
     * Get the number of items per page.
     */
    public function getPerPage(): int
    {
        $perPage = (int) $this->input('per_page', 20);

        return min($perPage, 100);
    }

    /**
     * Get the search query.
     */
    public function getQuery(): string
    {
        return $this->input('query');
    }

    /**
     * Get the source filter.
     */
    public function getSource(): ?string
    {
        return $this->input('source');
    }
}
