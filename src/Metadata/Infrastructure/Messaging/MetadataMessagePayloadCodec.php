<?php

declare(strict_types=1);

namespace App\Metadata\Infrastructure\Messaging;

use App\Metadata\Application\Command\ExtractAlbumCoverCommand;
use App\Metadata\Application\Message\SyncAlbumMessage;
use App\Metadata\Application\Message\SyncLibraryMessage;
use App\Metadata\Application\Message\SyncSongMessage;
use App\Shared\Application\Messaging\MessagePayloadCodecInterface;
use App\Shared\Domain\Model\Uuid;

final readonly class MetadataMessagePayloadCodec implements MessagePayloadCodecInterface
{
    private const array FIELDS = [
        'metadata.extract_album_cover' => ['album_id'],
        'metadata.sync_song' => ['song_id', 'force_update'],
        'metadata.sync_album' => ['album_id', 'force_update'],
        'metadata.sync_library' => ['library_id', 'force_update', 'include_songs', 'include_artists'],
    ];

    public function types(): array
    {
        return [
            'metadata.extract_album_cover' => ExtractAlbumCoverCommand::class,
            'metadata.sync_song' => SyncSongMessage::class,
            'metadata.sync_album' => SyncAlbumMessage::class,
            'metadata.sync_library' => SyncLibraryMessage::class,
        ];
    }

    public function fields(string $type): array
    {
        return self::FIELDS[$type] ?? throw new \InvalidArgumentException('Unsupported message type.');
    }

    public function encode(object $message): array
    {
        return match (true) {
            $message instanceof ExtractAlbumCoverCommand => [$message->getAlbumId()->toString()],
            $message instanceof SyncSongMessage => [$message->songId->toString(), $message->forceUpdate],
            $message instanceof SyncAlbumMessage => [$message->albumId->toString(), $message->forceUpdate],
            $message instanceof SyncLibraryMessage => [$message->libraryId->toString(), $message->forceUpdate, $message->includeSongs, $message->includeArtists],
            default => throw new \InvalidArgumentException('Unsupported message type.'),
        };
    }

    public function decode(string $type, array $p): object
    {
        return match ($type) {
            'metadata.extract_album_cover' => new ExtractAlbumCoverCommand(Uuid::fromString($p['album_id'])),
            'metadata.sync_song' => new SyncSongMessage(Uuid::fromString($p['song_id']), $p['force_update']),
            'metadata.sync_album' => new SyncAlbumMessage(Uuid::fromString($p['album_id']), $p['force_update']),
            'metadata.sync_library' => new SyncLibraryMessage(Uuid::fromString($p['library_id']), $p['force_update'], $p['include_songs'], $p['include_artists']),
            default => throw new \InvalidArgumentException('Unsupported message type.'),
        };
    }
}
