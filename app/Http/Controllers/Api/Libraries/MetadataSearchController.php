<?php

namespace App\Http\Controllers\Api\Libraries;

use App\Models\{Album, TokenAbility};
use App\Modules\Metadata\Search\AlbumSearchService;
use Illuminate\Http\Request;
use Spatie\RouteAttributes\Attributes\{Get, Middleware, Prefix};

#[Prefix('/metadata/search')]
#[Middleware([
    'auth:sanctum',
    'ability:' . TokenAbility::ACCESS_API->value,
    'force.json',
])]
class MetadataSearchController
{
    public function __construct(
        private readonly AlbumSearchService $albumSearchService,
    )
    {
    }

    /**
     * Search for album
     *
     *
     * Looks up album in all providers
     *
     * @response array{
     *   discogs: array{
     *     source: 'discogs',
     *     data: \App\Http\Integrations\Discogs\Models\Release[],
     *     quality_score: int,
     *     search_results_count: int,
     *     processed_results_count: int,
     *     pagination: array{
     *       page: int,
     *       pages: int,
     *       items: int,
     *       per_page: int
     *     },
     *     best_match: \App\Http\Integrations\Discogs\Models\Release
     *   },
     *   musicbrainz: array{
     *     source: 'musicbrainz',
     *     data: \App\Http\Integrations\MusicBrainz\Models\Release[],
     *     quality_score: int,
     *     search_results_count: int,
     *     processed_results_count: int,
     *     best_match: \App\Http\Integrations\MusicBrainz\Models\Release
     *   }
     * }
     */
    #[Get('/album/{album:slug}', 'api.metadata.search.album')]
    public function searchForAlbum(Album $album)
    {
        $result = $this->albumSearchService->searchAllSources($album);

        return response()->json($result);
    }

    /**
     * Search for album (fuzzy)
     *
     * Generates title variations based on the album name and then searches in all providers
     *
     * @response array{
     *   total_results: int,
     *   variations_tried: string[],
     *   results: (array{
     *     id: string,
     *     source: 'discogs',
     *     variation_used: string,
     *     data: \App\Http\Integrations\Discogs\Models\Release,
     *     raw_result: array,
     *     quality_score: int
     *   }|array{
     *     id: string,
     *     source: 'musicbrainz',
     *     variation_used: string,
     *     data: \App\Http\Integrations\MusicBrainz\Models\Release,
     *     raw_result: array,
     *     quality_score: int
     *   })[],
     *   best_match: (array{
     *     id: string,
     *     source: 'discogs',
     *     variation_used: string,
     *     data: \App\Http\Integrations\Discogs\Models\Release,
     *     raw_result: array,
     *     quality_score: int
     *   }|array{
     *     id: string,
     *     source: 'musicbrainz',
     *     variation_used: string,
     *     data: \App\Http\Integrations\MusicBrainz\Models\Release,
     *     raw_result: array,
     *     quality_score: int
     *   })
     * }
     */
    #[Get('/album/{album:slug}/fuzzy', 'api.metadata.search-fuzzy.album')]
    public function fuzzySearchForAlbum(Album $album)
    {
        $result = $this->albumSearchService->searchFuzzy($album);

        return response()->json($result);
    }
}