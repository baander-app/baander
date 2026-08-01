<?php

declare(strict_types=1);

namespace App\Library\Domain\Event;

use App\Shared\Domain\Event\AbstractDomainEvent;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

final readonly class LibraryScanCompleted extends AbstractDomainEvent
{
    public function __construct(
        public readonly Uuid $libraryId,
        public readonly int $filesDiscovered,
        public readonly int $filesProcessed,
        ?DateTimeImmutable $occurredAt = null,
    ) {
        parent::__construct($occurredAt);
    }

    public function eventName(): string
    {
        return 'library.scan_completed';
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromPayload(array $payload): static
    {
        return new self(
            Uuid::fromString($payload['library_id']),
            (int) $payload['files_discovered'],
            (int) $payload['files_processed'],
            new DateTimeImmutable($payload['occurred_at']),
        );
    }

    public function toPayload(): array
    {
        return [
            'library_id' => $this->libraryId->toString(),
            'files_discovered' => $this->filesDiscovered,
            'files_processed' => $this->filesProcessed,
            'occurred_at' => $this->occurredAt->format(DateTimeImmutable::ATOM),
        ];
    }
}
