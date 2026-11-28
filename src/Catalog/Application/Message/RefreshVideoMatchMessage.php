<?php

declare(strict_types=1);

namespace App\Catalog\Application\Message;

use App\Metadata\Application\DTO\Video\VideoKind;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class RefreshVideoMatchMessage
{
    public function __construct(
        public VideoKind $kind,
        public Uuid $titleId,
        public Uuid $operationId,
        public DateTimeImmutable $requestedAt,
    ) {}

    public static function fromKindValue(string $kind, Uuid $titleId, Uuid $operationId, DateTimeImmutable $requestedAt): self
    {
        $videoKind = VideoKind::tryFrom($kind) ?? throw new InvalidArgumentException('Unsupported video kind for refresh.');

        return new self($videoKind, $titleId, $operationId, $requestedAt);
    }

    public function kindValue(): string
    {
        return $this->kind->value;
    }
}
