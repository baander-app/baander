<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger\Stamp;

use Symfony\Component\Messenger\Stamp\StampInterface;

interface ResultStampInterface extends StampInterface
{
    public static function fromResult(mixed $result): ?static;
}
