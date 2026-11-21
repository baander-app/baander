<?php

declare(strict_types=1);

namespace App\Metadata\Infrastructure\Messaging;

use App\Metadata\Application\Command\ExtractAlbumCoverCommand;
use App\Metadata\Application\Command\SyncMetadataCommand;
use App\Metadata\Application\Message\SyncAlbumMessage;
use App\Metadata\Application\Message\SyncArtistMessage;
use App\Metadata\Application\Message\SyncArtistsMessage;
use App\Metadata\Application\Message\SyncLibraryMessage;
use App\Metadata\Application\Message\SyncSongMessage;
use App\Metadata\Application\Message\SyncMovieMessage;
use App\Shared\Application\Messaging\MessagePayloadCodecInterface;
use App\Shared\Domain\Model\Uuid;

final readonly class MetadataMessagePayloadCodec implements MessagePayloadCodecInterface
{
    private const array FIELDS = [
        'metadata.extract_album_cover' => ['album_id'],
        'metadata.sync_song' => ['song_id', 'force_update'],
        'metadata.sync_movie' => ['movie_id', 'force_update'],
        'metadata.sync_album' => ['album_id', 'force_update'],
        'metadata.sync_library' => ['library_id', 'force_update', 'include_songs'],
        'metadata.sync_artists' => [],
        'metadata.sync_artist' => ['artist_id', 'run_id', 'outages'],
        'metadata.sync' => ['source'],
    ];

    public static function types(): array
    {
        return [
            'metadata.extract_album_cover' => ExtractAlbumCoverCommand::class,
            'metadata.sync_song' => SyncSongMessage::class,
            'metadata.sync_movie' => SyncMovieMessage::class,
            'metadata.sync_album' => SyncAlbumMessage::class,
            'metadata.sync_library' => SyncLibraryMessage::class,
            'metadata.sync_artists' => SyncArtistsMessage::class,
            'metadata.sync_artist' => SyncArtistMessage::class,
            'metadata.sync' => SyncMetadataCommand::class,
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
            $message instanceof SyncMovieMessage => [$message->movieId->toString(), $message->forceUpdate],
            $message instanceof SyncAlbumMessage => [$message->albumId->toString(), $message->forceUpdate],
            $message instanceof SyncLibraryMessage => [$message->libraryId->toString(), $message->forceUpdate, $message->includeSongs],
            $message instanceof SyncArtistsMessage => [],
            $message instanceof SyncArtistMessage => [$message->artistId->toString(), $message->runId?->toString(), $message->outages],
            $message instanceof SyncMetadataCommand => [$message->source],
            default => throw new \InvalidArgumentException('Unsupported message type.'),
        };
    }

    public function decode(string $type, array $p): object
    {
        return match ($type) {
            'metadata.extract_album_cover' => new ExtractAlbumCoverCommand(Uuid::fromString($p['album_id'])),
            'metadata.sync_song' => new SyncSongMessage(Uuid::fromString($p['song_id']), $p['force_update']),
            'metadata.sync_movie' => new SyncMovieMessage(Uuid::fromString($p['movie_id']), $p['force_update']),
            'metadata.sync_album' => new SyncAlbumMessage(Uuid::fromString($p['album_id']), $p['force_update']),
            'metadata.sync_library' => new SyncLibraryMessage(Uuid::fromString($p['library_id']), $p['force_update'], $p['include_songs']),
            'metadata.sync_artists' => new SyncArtistsMessage(),
            'metadata.sync_artist' => new SyncArtistMessage(
                Uuid::fromString($p['artist_id']),
                $p['run_id'] === null ? null : Uuid::fromString($p['run_id']),
                $p['outages'],
            ),
            'metadata.sync' => new SyncMetadataCommand($p['source']),
            default => throw new \InvalidArgumentException('Unsupported message type.'),
        };
    }
}
