<?php

namespace App\Http\Requests\MetadataBrowse;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ApplyMetadataRequest extends FormRequest
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
            'entity_type' => 'required|in:album,artist,song',
            'entity_id' => 'required|integer',
            'source' => 'required|in:musicbrainz,discogs',
            'external_id' => 'required|string',
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
            'entity_type.required' => 'The entity type is required.',
            'entity_type.in' => 'The entity type must be one of: album, artist, or song.',
            'entity_id.required' => 'The entity ID is required.',
            'entity_id.integer' => 'The entity ID must be an integer.',
            'source.required' => 'The metadata source is required.',
            'source.in' => 'The source must be one of: musicbrainz or discogs.',
            'external_id.required' => 'The external ID is required.',
            'external_id.string' => 'The external ID must be a string.',
        ];
    }

    /**
     * Get the entity type.
     */
    public function getEntityType(): string
    {
        return $this->input('entity_type');
    }

    /**
     * Get the entity ID.
     */
    public function getEntityId(): int
    {
        return (int) $this->input('entity_id');
    }

    /**
     * Get the metadata source.
     */
    public function getSource(): string
    {
        return $this->input('source');
    }

    /**
     * Get the external ID from the provider.
     */
    public function getExternalId(): string
    {
        return $this->input('external_id');
    }
}
