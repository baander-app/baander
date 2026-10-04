<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger\Stamp;

use App\Auth\Domain\Model\Passkey\Passkey;

final readonly class PasskeyResultStamp implements ResultStampInterface
{
    public function __construct(
        private Passkey $passkey,
    ) {
    }

    public static function fromResult(mixed $result): ?static
    {
        return $result instanceof Passkey ? new self($result) : null;
    }

    public function getPasskey(): Passkey
    {
        return $this->passkey;
    }
}
