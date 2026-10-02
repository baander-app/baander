<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messaging;

use App\Library\Application\Command\ScanLibraryCommand;
use App\Library\Application\Message\FilesDiscovered;
use App\Library\Domain\Model\DiscoveredFile;
use App\Library\Domain\ValueObject\LibrarySlug;
use App\Media\Application\Command\PruneMissingImagesCommand;
use App\Metadata\Application\Command\ExtractAlbumCoverCommand;
use App\Metadata\Application\Message\SyncAlbumMessage;
use App\Metadata\Application\Message\SyncLibraryMessage;
use App\Metadata\Application\Message\SyncSongMessage;
use App\Notification\Application\DTO\SendEmailCommand;
use App\Notification\Application\DTO\SendPushCommand;
use App\Notification\Application\DTO\SendWebhookCommand;
use App\Notification\Domain\ValueObject\NotificationCategory;
use App\Radio\Application\Command\SyncCountryStationsCommand;
use App\Scheduler\Application\Command\ExecuteScheduledJobCommand;
use App\Shared\Domain\Event\Outbox\RelayOutboxCommand;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Application\Command\UpdateTranscodePositionCommand;

/** Versioned JSON contract. No reflection, class names, PHP serialization, or framework serializer. */
final readonly class JsonMessageCodec
{
    private const array FIELDS = [
        'library.scan' => ['library_slug', 'rescan'],
        'library.files_discovered' => ['library_id', 'library_type', 'directory', 'files'],
        'metadata.extract_album_cover' => ['album_id'],
        'metadata.sync_song' => ['song_id', 'force_update'],
        'metadata.sync_album' => ['album_id', 'force_update'],
        'metadata.sync_library' => ['library_id', 'force_update', 'include_songs', 'include_artists'],
        'notification.send_email' => ['user_id', 'user_email', 'category', 'title', 'body', 'created_at', 'notification_id'],
        'notification.send_push' => ['user_id', 'category', 'title', 'body', 'notification_id'],
        'notification.send_webhook' => ['user_id', 'category', 'title', 'body', 'notification_id'],
        'media.prune_missing_images' => [],
        'radio.sync_country_stations' => ['source_id', 'country_code'],
        'scheduler.execute_job' => ['job_id', 'job_type', 'command', 'parameters'],
        'outbox.relay' => ['batch_size'],
        'transcode.update_position' => ['session_id', 'position', 'action'],
    ];

    public function __construct(private int $maxPayloadSize = 1_048_576)
    {
    }

    /** @param array<string, mixed> $metadata */
    public function encode(object $message, array $metadata = []): string
    {
        [$type, $payload] = match (true) {
            $message instanceof ScanLibraryCommand => ['library.scan', [$message->getLibrarySlug()->toString(), $message->isRescan()]],
            $message instanceof FilesDiscovered => ['library.files_discovered', [$message->libraryId->toString(), $message->libraryType, $message->directory, array_map(
                static fn (DiscoveredFile $file): array => [
                    'absolute_path' => $file->absolutePath, 'relative_path' => $file->relativePath, 'extension' => $file->extension,
                    'size' => $file->size, 'modified_at' => $file->modifiedAt, 'hash' => $file->hash,
                ], $message->files,
            )]],
            $message instanceof ExtractAlbumCoverCommand => ['metadata.extract_album_cover', [$message->getAlbumId()->toString()]],
            $message instanceof SyncSongMessage => ['metadata.sync_song', [$message->songId->toString(), $message->forceUpdate]],
            $message instanceof SyncAlbumMessage => ['metadata.sync_album', [$message->albumId->toString(), $message->forceUpdate]],
            $message instanceof SyncLibraryMessage => ['metadata.sync_library', [$message->libraryId->toString(), $message->forceUpdate, $message->includeSongs, $message->includeArtists]],
            $message instanceof SendEmailCommand => ['notification.send_email', [$message->userId->toString(), $message->userEmail, $message->category->value, $message->title, $message->body, $message->createdAt->format(\DateTimeInterface::RFC3339_EXTENDED), $message->notificationPublicId]],
            $message instanceof SendPushCommand => ['notification.send_push', [$message->userId->toString(), $message->category->value, $message->title, $message->body, $message->notificationPublicId]],
            $message instanceof SendWebhookCommand => ['notification.send_webhook', [$message->userId->toString(), $message->category->value, $message->title, $message->body, $message->notificationPublicId]],
            $message instanceof PruneMissingImagesCommand => ['media.prune_missing_images', []],
            $message instanceof SyncCountryStationsCommand => ['radio.sync_country_stations', [$message->getSourceId()->toString(), $message->getCountryCode()]],
            $message instanceof ExecuteScheduledJobCommand => ['scheduler.execute_job', [$message->jobId, $message->jobType, $message->command, $message->parameters]],
            $message instanceof RelayOutboxCommand => ['outbox.relay', [$message->batchSize]],
            $message instanceof UpdateTranscodePositionCommand => ['transcode.update_position', [$message->sessionId->toString(), $message->position, $message->action]],
            default => throw new \InvalidArgumentException('Unsupported message type.'),
        };
        $this->validateJsonData($payload);
        $this->validateJsonData($metadata);
        $data = json_encode([
            'format' => 'baander.message', 'version' => 1, 'type' => $type,
            'payload' => (object) array_combine(self::FIELDS[$type], $payload), 'metadata' => (object) $metadata,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES, 32);
        if (strlen($data) > $this->maxPayloadSize) {
            throw new \InvalidArgumentException('Message exceeds the payload limit.');
        }
        return $data;
    }

    public function decode(string $data): DecodedMessage
    {
        if (strlen($data) > $this->maxPayloadSize) {
            throw new \InvalidArgumentException('Message exceeds the payload limit.');
        }
        try {
            $document = json_decode($data, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($document)) {
                throw new \InvalidArgumentException('Message must be an object.');
            }
            $this->requireFields($document, ['format', 'version', 'type', 'payload', 'metadata']);
            if ($document['format'] !== 'baander.message' || $document['version'] !== 1 ||
                !is_string($document['type']) || !isset(self::FIELDS[$document['type']]) ||
                !is_array($document['payload']) || !is_array($document['metadata'])) {
                throw new \InvalidArgumentException('Unsupported message format, version, or type.');
            }
            $type = $document['type'];
            $p = $document['payload'];
            $this->requireFields($p, self::FIELDS[$type]);
            $message = match ($type) {
                'library.scan' => new ScanLibraryCommand(new LibrarySlug($p['library_slug']), $p['rescan']),
                'library.files_discovered' => new FilesDiscovered(Uuid::fromString($p['library_id']), $p['library_type'], $p['directory'], $this->decodeFiles($p['files'])),
                'metadata.extract_album_cover' => new ExtractAlbumCoverCommand(Uuid::fromString($p['album_id'])),
                'metadata.sync_song' => new SyncSongMessage(Uuid::fromString($p['song_id']), $p['force_update']),
                'metadata.sync_album' => new SyncAlbumMessage(Uuid::fromString($p['album_id']), $p['force_update']),
                'metadata.sync_library' => new SyncLibraryMessage(Uuid::fromString($p['library_id']), $p['force_update'], $p['include_songs'], $p['include_artists']),
                'notification.send_email' => new SendEmailCommand(Uuid::fromString($p['user_id']), $p['user_email'], NotificationCategory::from($p['category']), $p['title'], $p['body'], $this->decodeDate($p['created_at']), $p['notification_id']),
                'notification.send_push' => new SendPushCommand(Uuid::fromString($p['user_id']), NotificationCategory::from($p['category']), $p['title'], $p['body'], $p['notification_id']),
                'notification.send_webhook' => new SendWebhookCommand(Uuid::fromString($p['user_id']), NotificationCategory::from($p['category']), $p['title'], $p['body'], $p['notification_id']),
                'media.prune_missing_images' => new PruneMissingImagesCommand(),
                'radio.sync_country_stations' => new SyncCountryStationsCommand(Uuid::fromString($p['source_id']), $p['country_code']),
                'scheduler.execute_job' => new ExecuteScheduledJobCommand($p['job_id'], $p['job_type'], $p['command'], $p['parameters']),
                'outbox.relay' => new RelayOutboxCommand($p['batch_size']),
                'transcode.update_position' => new UpdateTranscodePositionCommand(Uuid::fromString($p['session_id']), $p['position'], $p['action']),
            };
            return new DecodedMessage($message, $document['metadata']);
        } catch (\Throwable $error) {
            throw new \InvalidArgumentException('Invalid message payload.', previous: $error);
        }
    }

    /** @param array<mixed> $payload @param list<string> $fields */
    private function requireFields(array $payload, array $fields): void
    {
        if (array_diff($fields, array_keys($payload)) !== [] || array_diff(array_keys($payload), $fields) !== []) {
            throw new \InvalidArgumentException('Message fields do not match its versioned schema.');
        }
    }

    /** @return list<DiscoveredFile> */
    private function decodeFiles(mixed $files): array
    {
        if (!is_array($files) || !array_is_list($files)) {
            throw new \InvalidArgumentException('Files must be a list.');
        }
        return array_map(function (array $file): DiscoveredFile {
            $this->requireFields($file, ['absolute_path', 'relative_path', 'extension', 'size', 'modified_at', 'hash']);
            return new DiscoveredFile($file['absolute_path'], $file['relative_path'], $file['extension'], $file['size'], $file['modified_at'], $file['hash']);
        }, $files);
    }

    private function decodeDate(string $date): \DateTimeImmutable
    {
        $result = \DateTimeImmutable::createFromFormat(\DateTimeInterface::RFC3339_EXTENDED, $date);
        if ($result === false || $result->format(\DateTimeInterface::RFC3339_EXTENDED) !== $date) {
            throw new \InvalidArgumentException('Timestamp must be RFC3339 with milliseconds.');
        }
        return $result;
    }

    private function validateJsonData(mixed $value, int $depth = 0): void
    {
        if ($depth > 24 || is_object($value) || is_resource($value)) {
            throw new \InvalidArgumentException('Payloads must contain only bounded JSON data.');
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                $this->validateJsonData($item, $depth + 1);
            }
        }
    }
}
