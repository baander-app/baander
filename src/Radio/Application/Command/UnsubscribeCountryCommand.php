<?php

declare(strict_types=1);

namespace App\Radio\Application\Command;

use App\Shared\Domain\Model\Uuid;

final readonly class UnsubscribeCountryCommand
{
    public function __construct(
        private Uuid $userId,
        private Uuid $sourceId,
        private string $countryCode,
    ) {
    }

    public function getUserId(): Uuid
    {
        return $this->userId;
    }

    public function getSourceId(): Uuid
    {
        return $this->sourceId;
    }

    public function getCountryCode(): string
    {
        return $this->countryCode;
    }
}
