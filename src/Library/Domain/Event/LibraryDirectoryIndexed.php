<?php

declare(strict_types=1);

namespace App\Library\Domain\Event;

use App\Shared\Domain\Event\AbstractDomainEvent;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * @phpstan-type IndexedFile array{absolute_path: string, relative_path: string, extension: string, size: int, modified_at: int, hash: string}
 */
final readonly class LibraryDirectoryIndexed extends AbstractDomainEvent
{
    /** @param list<IndexedFile> $files */
    public function __construct(
        public string $libraryId,
        public string $libraryType,
        public string $directory,
        public array $files,
        ?DateTimeImmutable $occurredAt = null,
    ) {
        parent::__construct($occurredAt);
    }

    public function eventName(): string
    {
        return 'library.directory_indexed';
    }

    /** @return array{library_id: string, library_type: string, directory: string, files: list<IndexedFile>, occurred_at: string} */
    public function toPayload(): array
    {
        return [
            'library_id' => $this->libraryId,
            'library_type' => $this->libraryType,
            'directory' => $this->directory,
            'files' => $this->files,
            'occurred_at' => $this->occurredAt->format('Y-m-d\TH:i:s.uP'),
        ];
    }

    /** @param array<string, mixed> $payload */
    public static function fromPayload(array $payload): static
    {
        foreach (['library_id', 'library_type', 'directory', 'occurred_at'] as $field) {
            if (!is_string($payload[$field] ?? null)) {
                throw new InvalidArgumentException('Directory event field '.$field.' must be a string.');
            }
        }
        if (!is_array($payload['files'] ?? null) || !array_is_list($payload['files'])) {
            throw new InvalidArgumentException('Directory event files must be a list.');
        }
        foreach ($payload['files'] as $file) {
            if (!is_array($file)) {
                throw new InvalidArgumentException('Directory event files must contain file payloads.');
            }
            foreach (['absolute_path', 'relative_path', 'extension', 'hash'] as $field) {
                if (!is_string($file[$field] ?? null)) {
                    throw new InvalidArgumentException('Directory event file field '.$field.' must be a string.');
                }
            }
            foreach (['size', 'modified_at'] as $field) {
                if (!is_int($file[$field] ?? null)) {
                    throw new InvalidArgumentException('Directory event file field '.$field.' must be an integer.');
                }
            }
        }

        $occurredAt = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.uP', $payload['occurred_at']);
        if ($occurredAt === false || $occurredAt->format('Y-m-d\TH:i:s.uP') !== $payload['occurred_at']) {
            throw new InvalidArgumentException('Directory event occurrence time must be an ISO timestamp with microseconds.');
        }

        return new self($payload['library_id'], $payload['library_type'], $payload['directory'], $payload['files'], $occurredAt);
    }
}
