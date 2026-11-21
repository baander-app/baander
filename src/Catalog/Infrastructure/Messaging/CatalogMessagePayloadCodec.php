<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Messaging;

use App\Catalog\Application\Command\BatchExtractCoversCommand;
use App\Catalog\Application\Command\Tv\RegisterTvSeriesCommand;
use App\Catalog\Application\Command\Tv\RegisterTvEpisodeCommand;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Catalog\Application\Message\DrainAlbumMatchesMessage;
use App\Shared\Application\Messaging\MessagePayloadCodecInterface;

final readonly class CatalogMessagePayloadCodec implements MessagePayloadCodecInterface
{
    private const string BATCH_EXTRACT_COVERS = 'catalog.batch_extract_covers';
    private const string DRAIN_ALBUM_MATCHES = 'catalog.drain_album_matches';

    private const array FIELDS = [
        self::BATCH_EXTRACT_COVERS => [],
        self::DRAIN_ALBUM_MATCHES => ['chain', 'outages'],
        'catalog.tv_series_register' => ['library_id', 'tmdb_id', 'force_update'],
        'catalog.tv_episode_register' => ['series_id', 'season_number', 'episode_number', 'force_update'],
    ];

    public static function types(): array
    {
        return [
            self::BATCH_EXTRACT_COVERS => BatchExtractCoversCommand::class,
            self::DRAIN_ALBUM_MATCHES => DrainAlbumMatchesMessage::class,
            'catalog.tv_series_register' => RegisterTvSeriesCommand::class,
            'catalog.tv_episode_register' => RegisterTvEpisodeCommand::class,
        ];
    }

    public function fields(string $type): array
    {
        return self::FIELDS[$type] ?? throw new \InvalidArgumentException('Unsupported message type.');
    }

    public function encode(object $message): array
    {
        return match (true) {
            $message instanceof BatchExtractCoversCommand => [],
            $message instanceof RegisterTvSeriesCommand => [$message->libraryId->toString(), $message->tmdbId, $message->forceUpdate],
            $message instanceof RegisterTvEpisodeCommand => [$message->seriesId->toString(), $message->seasonNumber, $message->episodeNumber, $message->forceUpdate],
            $message instanceof DrainAlbumMatchesMessage => [$message->chain, $message->outages],
            default => throw new \InvalidArgumentException('Unsupported message type.'),
        };
    }

    public function decode(string $type, array $payload): object
    {
        return match (true) {
            $type === self::BATCH_EXTRACT_COVERS && $payload === [] => new BatchExtractCoversCommand(),
            $type === 'catalog.tv_series_register' && self::isSeries($payload) => new RegisterTvSeriesCommand(
                Uuid::fromString($payload['library_id']),
                $payload['tmdb_id'],
                $payload['force_update'],
            ),
            $type === 'catalog.tv_episode_register' && self::isEpisode($payload) => new RegisterTvEpisodeCommand(
                new PublicId($payload['series_id']),
                $payload['season_number'],
                $payload['episode_number'],
                $payload['force_update'],
            ),
            $type === self::DRAIN_ALBUM_MATCHES && self::isDrain($payload) => new DrainAlbumMatchesMessage($payload['chain'], $payload['outages']),
            default => throw new \InvalidArgumentException('Unsupported message type or payload.'),
        };
    }

    /**
     * @param array<string, mixed> $payload
     * @phpstan-assert-if-true array{library_id: string, tmdb_id: positive-int, force_update: bool} $payload
     */
    private static function isSeries(array $payload): bool
    {
        return is_string($payload['library_id'] ?? null)
            && is_int($payload['tmdb_id'] ?? null) && $payload['tmdb_id'] > 0
            && is_bool($payload['force_update'] ?? null);
    }

    /**
     * @param array<string, mixed> $payload
     * @phpstan-assert-if-true array{series_id: string, season_number: int<0,max>, episode_number: positive-int, force_update: bool} $payload
     */
    private static function isEpisode(array $payload): bool
    {
        return is_string($payload['series_id'] ?? null)
            && is_int($payload['season_number'] ?? null) && $payload['season_number'] >= 0
            && is_int($payload['episode_number'] ?? null) && $payload['episode_number'] > 0
            && is_bool($payload['force_update'] ?? null);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @phpstan-assert-if-true array{chain: string|null, outages: int<0, max>} $payload
     */
    private static function isDrain(array $payload): bool
    {
        return array_key_exists('chain', $payload) && ($payload['chain'] === null || is_string($payload['chain']))
            && is_int($payload['outages'] ?? null) && $payload['outages'] >= 0;
    }
}
