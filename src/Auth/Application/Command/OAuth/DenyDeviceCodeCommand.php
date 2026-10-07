<?php

declare(strict_types=1);

namespace App\Auth\Application\Command\OAuth;

use App\Shared\Domain\Model\Uuid;

/**
 * The signed-in user rejects a device authorization request (RFC 8628 section 3.3).
 */
final readonly class DenyDeviceCodeCommand
{
    public function __construct(
        public string $userCode,
        public Uuid $userId,
    ) {
    }
}
