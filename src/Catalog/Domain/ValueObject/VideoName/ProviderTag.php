<?php

declare(strict_types=1);

namespace App\Catalog\Domain\ValueObject\VideoName;

final readonly class ProviderTag implements \JsonSerializable
{
    public function __construct(
        public string $provider,
        public string $id,
    ) {}

    /** @return array{provider: string, id: string} */
    public function jsonSerialize(): array
    {
        return ['provider' => $this->provider, 'id' => $this->id];
    }
}
