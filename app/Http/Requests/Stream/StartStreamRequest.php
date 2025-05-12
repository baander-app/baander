<?php

namespace App\Http\Requests\Stream;

use Illuminate\Foundation\Http\FormRequest;

class StartStreamRequest extends FormRequest
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
            'sessionId'               => 'required|string',
            'audioProfile.bitrate'    => 'optional|int',
            'audioProfile.channels'   => 'optional|float',
            'audioProfile.sampleRate' => 'optional|int',
            'audioProfile.codec'      => 'optional|string',
            'videoProfile.height'     => 'optional|int',
            'videoProfile.width'      => 'optional|int',
            'videoProfile.bitrate'    => 'optional|int',
        ];
    }
}
