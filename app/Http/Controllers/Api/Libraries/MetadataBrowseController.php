<?php

namespace App\Http\Controllers\Api\Libraries;

use App\Http\Controllers\Controller;
use App\Http\Requests\MetadataBrowse\ApplyMetadataRequest;
use App\Http\Requests\MetadataBrowse\BrowseSearchRequest;
use App\Http\Integrations\Discogs\DiscogsClient;
use App\Http\Integrations\Discogs\Filters\ArtistFilter as DiscogsArtistFilter;
use App\Http\Integrations\Discogs\Filters\ReleaseFilter as DiscogsReleaseFilter;
use App\Http\Integrations\MusicBrainz\Filters\ArtistFilter as MusicBrainzArtistFilter;
use App\Http\Integrations\MusicBrainz\Filters\RecordingFilter;
use App\Http\Integrations\MusicBrainz\Filters\ReleaseFilter as MusicBrainzReleaseFilter;
use App\Http\Integrations\MusicBrainz\MusicBrainzClient;
use App\Jobs\Concerns\HasLogger;
use App\Jobs\Library\Music\ApplyManualMetadataJob;
use App\Modules\Logging\Attributes\LogChannel;
use App\Modules\Logging\Channel;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;
use Spatie\RouteAttributes\Attributes\{Middleware, Post, Prefix, Get};
use Throwable;

#[Prefix('/metadata/browse')]
#[Middleware([
    'auth:oauth',
    'scope:access-api',
    'force.json',
])]
class MetadataBrowseController extends Controller
{
    use HasLogger;

    #[LogChannel(
        channel: Channel::Metadata,
    )]
    private LoggerInterface $logger;

    public function __construct(
        private readonly MusicBrainzClient $musicBrainzClient,
        private readonly DiscogsClient     $discogsClient,
    )
    {
    }

    /**
     * Search albums by query
     *
     * @response array{
     *   data: array,
     *   pagination: array{page: int, per_page: int, total: int},
     *   sources: array{musicbrainz: array, discogs: array}
     * }
     */
    #[Get('/albums', 'api.metadata.browse.albums')]
    public function searchAlbums(BrowseSearchRequest $request): JsonResponse
    {
        $query = $request->getQuery();
        $source = $request->getSource() ?? 'all';
        $page = $request->getPage();
        $perPage = $request->getPerPage();

        Log::info('Album browse search requested', [
            'query' => $query,
            'source' => $source,
            'page' => $page,
            'per_page' => $perPage,
        ]);

        try {
            $data = [];
            $pagination = ['page' => $page, 'per_page' => $perPage, 'total' => 0];

            if ($source === 'all' || $source === 'musicbrainz') {
                $musicbrainzResults = $this->searchMusicBrainzAlbums($query, $page, $perPage);
                foreach ($musicbrainzResults['results'] ?? [] as $item) {
                    $data[] = [
                        'source' => 'musicbrainz',
                        'quality_score' => 0.0, // Will be calculated by quality validator if needed
                        'item' => $item,
                    ];
                }
                $pagination['total'] += $musicbrainzResults['total'] ?? 0;
            }

            if ($source === 'all' || $source === 'discogs') {
                $discogsResults = $this->searchDiscogsAlbums($query, $page, $perPage);
                foreach ($discogsResults['results'] ?? [] as $item) {
                    $data[] = [
                        'source' => 'discogs',
                        'quality_score' => 0.0, // Will be calculated by quality validator if needed
                        'item' => $item,
                    ];
                }
                $pagination['total'] += $discogsResults['total'] ?? 0;
            }

            return response()->json([
                'data' => $data,
                'pagination' => $pagination,
            ]);

        } catch (Exception $e) {
            Log::error('Album browse search failed', [
                'query' => $query,
                'source' => $source,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Search failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Search artists by query
     *
     * @response array{
     *   data: array,
     *   pagination: array{page: int, per_page: int, total: int},
     *   sources: array{musicbrainz: array, discogs: array}
     * }
     */
    #[Get('/artists', 'api.metadata.browse.artists')]
    public function searchArtists(BrowseSearchRequest $request): JsonResponse
    {
        $query = $request->getQuery();
        $source = $request->getSource() ?? 'all';
        $page = $request->getPage();
        $perPage = $request->getPerPage();

        $this->logger->info('Artist browse search requested', [
            'query' => $query,
            'source' => $source,
            'page' => $page,
            'per_page' => $perPage,
        ]);

        try {
            $data = [];
            $pagination = ['page' => $page, 'per_page' => $perPage, 'total' => 0];

            if ($source === 'all' || $source === 'musicbrainz') {
                $musicbrainzResults = $this->searchMusicBrainzArtists($query, $page, $perPage);
                foreach ($musicbrainzResults['results'] ?? [] as $item) {
                    $data[] = [
                        'source' => 'musicbrainz',
                        'quality_score' => 0.0,
                        'item' => $item,
                    ];
                }
                $pagination['total'] += $musicbrainzResults['total'] ?? 0;
            }

            if ($source === 'all' || $source === 'discogs') {
                $discogsResults = $this->searchDiscogsArtists($query, $page, $perPage);
                foreach ($discogsResults['results'] ?? [] as $item) {
                    $data[] = [
                        'source' => 'discogs',
                        'quality_score' => 0.0,
                        'item' => $item,
                    ];
                }
                $pagination['total'] += $discogsResults['total'] ?? 0;
            }

            return response()->json([
                'data' => $data,
                'pagination' => $pagination,
            ]);

        } catch (Exception $e) {
            $this->logger->error('Artist browse search failed', [
                'query' => $query,
                'source' => $source,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Search failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Search songs by query
     *
     * @response array{
     *   data: array,
     *   pagination: array{page: int, per_page: int, total: int},
     *   sources: array{musicbrainz: array}
     * }
     */
    #[Get('/songs', 'api.metadata.browse.songs')]
    public function searchSongs(BrowseSearchRequest $request): JsonResponse
    {
        $query = $request->getQuery();
        $source = $request->getSource() ?? 'all';
        $page = $request->getPage();
        $perPage = $request->getPerPage();

        $this->logger->info('Song browse search requested', [
            'query' => $query,
            'source' => $source,
            'page' => $page,
            'per_page' => $perPage,
        ]);

        try {
            $data = [];
            $pagination = ['page' => $page, 'per_page' => $perPage, 'total' => 0];

            // Only MusicBrainz supports song/recordings search
            if ($source === 'all' || $source === 'musicbrainz') {
                $musicbrainzResults = $this->searchMusicBrainzSongs($query, $page, $perPage);
                foreach ($musicbrainzResults['results'] ?? [] as $item) {
                    $data[] = [
                        'source' => 'musicbrainz',
                        'quality_score' => $item['quality_score'] ?? 0,
                        'item' => $item,
                    ];
                }
                $pagination['total'] += $musicbrainzResults['total'] ?? 0;
            }

            return response()->json([
                'data' => $data,
                'pagination' => $pagination,
            ]);

        } catch (Exception $e) {
            $this->logger->error('Song browse search failed', [
                'query' => $query,
                'source' => $source,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Search failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Lookup by provider-specific ID
     *
     * @response array{source: string, id: string, data: array}
     */
    #[Get('/lookup/{source}/{id}', 'api.metadata.browse.lookup')]
    public function lookup(string $source, string $id): JsonResponse
    {
        $this->logger->info('Metadata lookup requested', [
            'source' => $source,
            'id' => $id,
        ]);

        try {
            if (!in_array($source, ['musicbrainz', 'discogs'])) {
                return response()->json([
                    'error' => 'Invalid source',
                    'message' => 'Source must be either musicbrainz or discogs',
                ], 400);
            }

            $metadata = match ($source) {
                'musicbrainz' => $this->lookupMusicBrainz($id),
                'discogs' => $this->lookupDiscogs($id),
            };

            if (!$metadata) {
                return response()->json([
                    'error' => 'Not found',
                    'message' => "Could not find metadata for {$source} ID: {$id}",
                ], 404);
            }

            return response()->json($metadata);

        } catch (Exception $e) {
            $this->logger->error('Metadata lookup failed', [
                'source' => $source,
                'id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Lookup failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Apply selected metadata to local entity
     *
     * @response array{job_id: string, status: string, message: string}
     */
    #[Post('/apply', 'api.metadata.browse.apply')]
    public function apply(ApplyMetadataRequest $request): JsonResponse
    {
        $entityType = $request->getEntityType();
        $entityId = $request->getEntityId();
        $source = $request->getSource();
        $externalId = $request->getExternalId();

        $this->logger->info('Manual metadata apply requested', [
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'source' => $source,
            'external_id' => $externalId,
        ]);

        try {
            // Validate that the entity exists
            $entity = match ($entityType) {
                'album' => \App\Models\Album::find($entityId),
                'artist' => \App\Models\Artist::find($entityId),
                'song' => \App\Models\Song::find($entityId),
            };

            if (!$entity) {
                return response()->json([
                    'error' => 'Entity not found',
                    'message' => "Could not find {$entityType} with ID: {$entityId}",
                ], 404);
            }

            // Dispatch the job
            $job = new ApplyManualMetadataJob(
                entityType: $entityType,
                entityId: $entityId,
                source: $source,
                externalId: $externalId,
            );

            $jobId = dispatch($job);

            $this->logger->info('Manual metadata apply job dispatched', [
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'job_id' => $jobId,
            ]);

            return response()->json([
                'job_id' => $jobId,
                'status' => 'queued',
                'message' => 'Metadata application job has been queued',
                'entity_type' => $entityType,
                'entity_id' => $entityId,
            ], 202);

        } catch (Exception $e) {
            $this->logger->error('Manual metadata apply failed', [
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'source' => $source,
                'external_id' => $externalId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Apply failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Search MusicBrainz for albums
     */
    private function searchMusicBrainzAlbums(string $query, int $page, int $perPage): array
    {
        try {
            $filter = new MusicBrainzReleaseFilter();
            $filter->setTitle($query);
            $filter->setLimit($perPage);
            $filter->setOffset(($page - 1) * $perPage);

            $searchResults = $this->musicBrainzClient->search->release($filter);

            return [
                'source' => 'musicbrainz',
                'total' => $searchResults->count(),
                'results' => $searchResults->map(fn($release) => [
                    'id' => $release->id,
                    'title' => $release->title,
                    'artist' => collect($release->artist_credit ?? [])->pluck('artist.name')->first() ?? 'Unknown',
                    'date' => $release->date ?? null,
                    'country' => $release->iso_3166_2_code ?? null,
                    'format' => $release->media[0]['format'] ?? null,
                    'track_count' => collect($release->media ?? [])->sum('track-count'),
                ])->toArray(),
            ];

        } catch (Exception $e) {
            $this->logger->warning('MusicBrainz album search failed', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);

            return [
                'source' => 'musicbrainz',
                'total' => 0,
                'results' => [],
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Search Discogs for albums
     */
    private function searchDiscogsAlbums(string $query, int $page, int $perPage): array
    {
        try {
            $filter = new DiscogsReleaseFilter();
            $filter->setTitle($query);
            $filter->setPerPage($perPage);
            $filter->setPage($page);

            $searchResults = $this->discogsClient->search->release($filter);
            $pagination = $this->discogsClient->search->getPagination();

            return [
                'source' => 'discogs',
                'total' => $pagination['items'] ?? 0,
                'results' => $searchResults->map(fn($release) => [
                    'id' => $release->id,
                    'title' => $release->title,
                    'artist' => collect($release->artists ?? [])->pluck('name')->first() ?? 'Unknown',
                    'year' => $release->year ?? null,
                    'country' => $release->country ?? null,
                    'format' => collect($release->formats ?? [])->pluck('name')->first() ?? null,
                    'label' => collect($release->labels ?? [])->pluck('name')->first() ?? null,
                ])->toArray(),
            ];

        } catch (Exception $e) {
            $this->logger->warning('Discogs album search failed', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);

            return [
                'source' => 'discogs',
                'total' => 0,
                'results' => [],
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Search MusicBrainz for artists
     */
    private function searchMusicBrainzArtists(string $query, int $page, int $perPage): array
    {
        try {
            $filter = new MusicBrainzArtistFilter();
            $filter->setName($query);
            $filter->setLimit($perPage);
            $filter->setOffset(($page - 1) * $perPage);

            $searchResults = $this->musicBrainzClient->search->artist($filter);

            return [
                'source' => 'musicbrainz',
                'total' => $searchResults->count(),
                'results' => $searchResults->map(fn($artist) => [
                    'id' => $artist->id,
                    'name' => $artist->name,
                    'type' => $artist->type ?? null,
                    'country' => $artist->country ?? null,
                    'disambiguation' => $artist->disambiguation ?? null,
                    'tags' => collect($artist->tags ?? [])->pluck('name')->take(5)->toArray(),
                ])->toArray(),
            ];

        } catch (Exception $e) {
            $this->logger->warning('MusicBrainz artist search failed', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);

            return [
                'source' => 'musicbrainz',
                'total' => 0,
                'results' => [],
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Search Discogs for artists
     */
    private function searchDiscogsArtists(string $query, int $page, int $perPage): array
    {
        try {
            $filter = new DiscogsArtistFilter();
            $filter->setTitle($query);
            $filter->setPerPage($perPage);
            $filter->setPage($page);

            $searchResults = $this->discogsClient->search->artist($filter);
            $pagination = $this->discogsClient->search->getPagination();

            return [
                'source' => 'discogs',
                'total' => $pagination['items'] ?? 0,
                'results' => $searchResults->map(fn($artist) => [
                    'id' => $artist->id,
                    'name' => $artist->title ?? $artist->name ?? 'Unknown',
                    'profile' => $artist->profile ?? null,
                ])->toArray(),
            ];

        } catch (Exception $e) {
            $this->logger->warning('Discogs artist search failed', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);

            return [
                'source' => 'discogs',
                'total' => 0,
                'results' => [],
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Search MusicBrainz for songs
     */
    private function searchMusicBrainzSongs(string $query, int $page, int $perPage): array
    {
        try {
            $filter = new RecordingFilter();
            $filter->setTitle($query);
            $filter->setLimit($perPage);
            $filter->setOffset(($page - 1) * $perPage);

            $searchResults = $this->musicBrainzClient->search->recording($filter);

            return [
                'source' => 'musicbrainz',
                'total' => $searchResults->count(),
                'results' => $searchResults->map(fn($recording) => [
                    'id' => $recording->id,
                    'title' => $recording->title,
                    'artist' => collect($recording->artist_credit ?? [])->pluck('artist.name')->first() ?? 'Unknown',
                    'length' => $recording->length ?? null,
                    'releases' => collect($recording->releases ?? [])->take(3)->map(fn($release) => [
                        'id' => $release->id,
                        'title' => $release->title,
                    ])->toArray(),
                ])->toArray(),
            ];

        } catch (Exception $e) {
            $this->logger->warning('MusicBrainz song search failed', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);

            return [
                'source' => 'musicbrainz',
                'total' => 0,
                'results' => [],
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Lookup from MusicBrainz
     */
    private function lookupMusicBrainz(string $id): ?array
    {
        try {
            // Try to determine the type and fetch appropriately
            // For now, we'll try release, artist, then recording in order

            // Try release first
            try {
                $release = $this->musicBrainzClient->lookup->release($id);
                if ($release) {
                    return [
                        'source' => 'musicbrainz',
                        'type' => 'release',
                        'id' => $id,
                        'data' => $release->toArray(),
                    ];
                }
            } catch (Throwable) {
                // Not a release, continue
            }

            // Try artist
            try {
                $artist = $this->musicBrainzClient->lookup->artist($id);
                if ($artist) {
                    return [
                        'source' => 'musicbrainz',
                        'type' => 'artist',
                        'id' => $id,
                        'data' => $artist->toArray(),
                    ];
                }
            } catch (Throwable) {
                // Not an artist, continue
            }

            // Try recording
            try {
                $recording = $this->musicBrainzClient->lookup->recording($id);
                if ($recording) {
                    return [
                        'source' => 'musicbrainz',
                        'type' => 'recording',
                        'id' => $id,
                        'data' => $recording->toArray(),
                    ];
                }
            } catch (Throwable) {
                // Not a recording
            }

            return null;

        } catch (Exception $e) {
            $this->logger->error('MusicBrainz lookup failed', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Lookup from Discogs
     */
    private function lookupDiscogs(string $id): ?array
    {
        try {
            // Try release first
            try {
                $release = $this->discogsClient->lookup->release((int) $id);
                if ($release) {
                    return [
                        'source' => 'discogs',
                        'type' => 'release',
                        'id' => $id,
                        'data' => $release->toArray(),
                    ];
                }
            } catch (Throwable) {
                // Not a release, continue
            }

            // Try artist
            try {
                $artist = $this->discogsClient->lookup->artist((int) $id);
                if ($artist) {
                    return [
                        'source' => 'discogs',
                        'type' => 'artist',
                        'id' => $id,
                        'data' => $artist->toArray(),
                    ];
                }
            } catch (Throwable) {
                // Not an artist
            }

            return null;

        } catch (Exception $e) {
            $this->logger->error('Discogs lookup failed', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
