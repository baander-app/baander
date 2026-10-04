<?php

declare(strict_types=1);

namespace App\Shared\Interface\DTO;

use Nelmio\ApiDocBundle\Attribute\Ignore;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ApiError',
    required: ['error'],
    properties: [
        new OA\Property(property: 'error', required: ['message', 'code'], properties: [
            new OA\Property(property: 'message', type: 'string'),
            new OA\Property(property: 'code', type: 'integer'),
            new OA\Property(property: 'details', type: 'object', description: 'Additional error details, omitted when empty'),
        ], type: 'object'),
    ],
)]
final readonly class ApiError
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        // The HTTP contract is the envelope from toArray(), not these fields.
        #[Ignore]
        public string $message,
        #[Ignore]
        public int $code,
        #[Ignore]
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
                'message' => $this->message,
                'code' => $this->code,
            ],
        ];

        if (!empty($this->details)) {
            $error['error']['details'] = $this->details;
        }

        return $error;
    }
}
