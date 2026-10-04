<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger\Stamp;

final readonly class StarredStationResultStamp implements ResultStampInterface
{
    /** @param array<array-key, mixed> $result */
    public function __construct(private array $result)
    {
    }

    public static function fromResult(mixed $result): ?static
    {
        return is_array($result) && isset($result['stationId']) ? new self($result) : null;
    }

    /** @return array<array-key, mixed> */
    public function getResult(): array
    {
        return $this->result;
    }
}
