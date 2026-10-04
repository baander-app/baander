<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger\Stamp;

use App\Auth\Application\DTO\TokenResponseDTO;

final readonly class TokenResponseResultStamp implements ResultStampInterface
{
    public function __construct(
        private TokenResponseDTO $tokenResponse,
    ) {
    }

    public static function fromResult(mixed $result): ?static
    {
        return $result instanceof TokenResponseDTO ? new self($result) : null;
    }

    public function getTokenResponse(): TokenResponseDTO
    {
        return $this->tokenResponse;
    }
}
