<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger\Stamp;

use App\Library\Domain\Model\Library;

final readonly class LibraryResultStamp implements ResultStampInterface
{
    public function __construct(
        private Library $library,
    ) {
    }

    public static function fromResult(mixed $result): ?static
    {
        return $result instanceof Library ? new self($result) : null;
    }

    public function getLibrary(): Library
    {
        return $this->library;
    }
}
