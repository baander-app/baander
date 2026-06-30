<?php

declare(strict_types=1);

namespace App\Shared\Interface\DTO;

final readonly class ApiError
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
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
