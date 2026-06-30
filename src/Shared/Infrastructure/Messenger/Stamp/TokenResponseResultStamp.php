<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger\Stamp;

use App\Auth\Application\DTO\TokenResponseDTO;
use Symfony\Component\Messenger\Stamp\StampInterface;

final readonly class TokenResponseResultStamp implements StampInterface
{
    public function __construct(
        private TokenResponseDTO $tokenResponse,
    ) {
    }

    public static function fromResult(mixed $result): ?self
    {
        return $result instanceof TokenResponseDTO ? new self($result) : null;
    }

    public function getTokenResponse(): TokenResponseDTO
    {
        return $this->tokenResponse;
    }
}
