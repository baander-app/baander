<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger\Stamp;

use App\Library\Domain\Model\Library;
use Symfony\Component\Messenger\Stamp\StampInterface;

final readonly class LibraryResultStamp implements StampInterface
{
    public function __construct(
        private Library $library,
    ) {
    }

    public static function fromResult(mixed $result): ?self
    {
        return $result instanceof Library ? new self($result) : null;
    }

    public function getLibrary(): Library
    {
        return $this->library;
    }
}
