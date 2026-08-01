<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger\Stamp;

use Symfony\Component\Messenger\Stamp\StampInterface;

final readonly class RadioStationResultStamp implements StampInterface
{
    public function __construct(private array $result)
    {
    }

    public static function fromResult(mixed $result): ?self
    {
        return is_array($result) && isset($result['type']) && $result['type'] === 'radio_station' ? new self($result) : null;
    }

    public function getResult(): array
    {
        return $this->result;
    }
}
