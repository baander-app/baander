<?php

declare(strict_types=1);

namespace App\Transcode\Domain\Exception;

use App\Shared\Domain\Model\Uuid;

final class SessionNotFoundException extends \RuntimeException
{
    public static function forId(Uuid $id): self
    {
        return new self(sprintf('Session not found: %s', $id->toString()));
    }
}
