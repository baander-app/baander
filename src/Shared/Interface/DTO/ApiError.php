<?php

declare(strict_types=1);

namespace App\Shared\Interface\DTO;

use App\Shared\Application\PublicDiagnosticText;
use OpenApi\Spec as OA;

#[OA\Schema(
    type: 'object',
    properties: [
        'error' => new OA\Schema(
            type: 'object',
            properties: [
                'message' => new OA\Schema(type: 'string'),
                'code' => new OA\Schema(type: 'integer'),
                'details' => new OA\Schema(description: 'Additional error details, omitted when empty', type: 'object'),
            ],
            required: ['message', 'code'],
        ),
    ],
    required: ['error'],
    component: 'ApiError',
)]
final readonly class ApiError
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        // The HTTP contract is the envelope from toArray(), not these fields.
        public string $message,
        public int $code,
        public array $details = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $error = [
            'error' => [
                'message' => PublicDiagnosticText::redact($this->message),
                'code' => $this->code,
            ],
        ];

        if (!empty($this->details)) {
            $error['error']['details'] = PublicDiagnosticText::details($this->details);
        }

        return $error;
    }
}
