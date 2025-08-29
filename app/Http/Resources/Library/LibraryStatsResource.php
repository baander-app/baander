<?php

namespace App\Http\Resources\Library;

use App\Models\Data\FormattedLibraryStats;
use App\Models\Data\LibraryStats;
use App\Models\Enums\MetaKey;
use App\Models\Library;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Library resource with comprehensive statistics including both formatted and raw data.
 *
 * @mixin Library
 */
class LibraryStatsResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array{
     *     name: string,
     *     slug: string,
     *     path: string,
     *     type: string,
     *     order: int,
     *     lastScan: ?string,
     *     createdAt: ?string,
     *     updatedAt: ?string,
     *     stats: array{
     *         formatted: array{
     *             songs: string,
     *             albums: string,
     *             artists: string,
     *             genres: string,
     *             duration: string,
     *             size: string
     *         },
     *         raw?: array{
     *             songs: int,
     *             albums: int,
     *             artists: int,
     *             genres: int,
     *             duration: int,
     *             size: int
     *         }
     *     },
     *     metadata?: array{
     *         statsLoadedAt?: string,
     *         computationTimeMs?: float
     *     }
     * }
     */
    public function toArray(Request $request): array
    {
        /** @var FormattedLibraryStats $formattedStats */
        $formattedStats = $this->getFormattedStats();

        /** @var LibraryStats|null $rawStats */
        $rawStats = $this->getMetaAsType(MetaKey::RAW_STATS, LibraryStats::class);

        /** @var Carbon|null $statsLoadedAt */
        $statsLoadedAt = $this->getMeta(MetaKey::STATS_LOADED_AT);

        /** @var float|null $computationTime */
        $computationTime = $this->getMeta(MetaKey::COMPUTATION_TIME);

        return [
            /** @var string Library display name */
            'name'      => $this->name,

            /** @var string URL-friendly library identifier */
            'slug'      => $this->slug,

            /** @var string File system path to library */
            'path'      => $this->path,

            /** @var string Library type (music, video, etc.) */
            'type'      => $this->type,

            /** @var int Display order for library sorting */
            'order'     => $this->order,

            /** @var string|null Last scan timestamp in ISO format */
            'lastScan'  => $this->last_scan,

            /** @var string|null Creation timestamp in ISO format */
            'createdAt' => $this->created_at,

            /** @var string|null Last update timestamp in ISO format */
            'updatedAt' => $this->updated_at,

            'stats'     => [
                'formatted' => [
                    /** @var string Human-readable song count (e.g., "1,234") */
                    'songs'    => $formattedStats->totalSongs,

                    /** @var string Human-readable album count (e.g., "123") */
                    'albums'   => $formattedStats->totalAlbums,

                    /** @var string Human-readable artist count (e.g., "45") */
                    'artists'  => $formattedStats->totalArtists,

                    /** @var string Human-readable genre count (e.g., "12") */
                    'genres'   => $formattedStats->totalGenres,

                    /** @var string Human-readable duration (e.g., "2:30:45" or "45:20") */
                    'duration' => $formattedStats->totalDuration,

                    /** @var string Human-readable size (e.g., "1.23 GB") */
                    'size'     => $formattedStats->totalSize,
                ],

                /**
                 * Raw numerical values for calculations and sorting.
                 * Only included when raw stats are available.
                 */
                'raw' => $this->when($rawStats !== null, [
                    /** @var int Total number of songs */
                    'songs'    => $rawStats?->totalSongs ?? 0,

                    /** @var int Total number of albums */
                    'albums'   => $rawStats?->totalAlbums ?? 0,

                    /** @var int Total number of artists */
                    'artists'  => $rawStats?->totalArtists ?? 0,

                    /** @var int Total number of genres */
                    'genres'   => $rawStats?->totalGenres ?? 0,

                    /** @var int Total duration in seconds */
                    'duration' => $rawStats?->totalDuration ?? 0,

                    /** @var int Total size in bytes */
                    'size'     => $rawStats?->totalSize ?? 0,
                ]),
            ],

            /**
             * Statistics computation metadata.
             * Only included when metadata is available.
             */
            'metadata' => $this->when($statsLoadedAt || $computationTime, [
                /** @var string|null ISO timestamp when stats were computed */
                'statsLoadedAt' => $statsLoadedAt?->toISOString(),

                /** @var float|null Time taken to compute stats in milliseconds */
                'computationTimeMs' => $computationTime,
            ]),
        ];
    }
}
