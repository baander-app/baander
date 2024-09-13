<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TokenAbility;
use App\Services\OpCacheService;
use Illuminate\Http\Request;
use Spatie\RouteAttributes\Attributes\{Get, Middleware, Post, Prefix};

#[Prefix('opcache')]
#[Middleware([
    'auth:sanctum',
    'ability:' . TokenAbility::ACCESS_API->value,
    'force.json',
])]
class OpCacheController extends Controller
{

    public function __construct(private readonly OpCacheService $opCacheService)
    {
    }

    /**
     * Get status
     *
     * @response array{
     *  opcache_enabled: bool,
     *  file_cache: string,
     *  file_cache_only: bool,
     *  cache_full: bool,
     *  restart_pending: bool,
     *  restart_in_progress,
     *  memory_usage: array{
     *    used_memory: int,
     *    free_memory: int,
     *    wasted_memory: int,
     *    current_wasted_percentage: float,
     *  },
     *  interned_strings_usage: array{
     *    buffer_size: int,
     *    used_memory: int,
     *    free_memory: int,
     *    number_of_strings: int,
     *  },
     *  opcache_statistics: array{
     *   num_cached_scripts: int,
     *   num_cached_keys: int,
     *   max_cached_keys: int,
     *   hits: int,
     *   start_time: int,
     *   last_restart_time: int,
     *   oom_restarts: int,
     *   hash_restarts: int,
     *   manual_restarts: int,
     *   misses: int,
     *   blacklist_misses: int,
     *   blacklist_miss_ratio: int,
     *   opcache_hit_rate: float
     *  },
     *  jit: array{
     *   enabled: bool,
     *   on: bool,
     *   kind: int,
     *   opt_level: int,
     *   opt_flags: int,
     *   buffer_size: int,
     *   buffer_free: int
     *  }
     * }
     */
    #[Get('/status')]
    public function getStatus()
    {
        $status = opcache_get_status(false);

        return response()->json($status);
    }

    /**
     * Get config
     *
     * @response array{
     *   directives: array{property: int|float|bool|string},
     *   version: array{version: string, opcache_product_name: string},
     *   blacklist: string[]
     * }
     */
    #[Get('/config', 'api.opcache.getConfig')]
    public function getConfig()
    {
        $config = opcache_get_configuration();

        return response()->json($config);
    }

    /**
     * Clear
     *
     * @response array{
     *  success: bool
     * }
     */
    #[Post('/clear', 'api.opcache.clear')]
    public function clearCache()
    {
        $result = opcache_reset();

        return response()->json([
            'success' => (bool)$result,
        ])->setStatusCode($result ? 200 : 500);
    }

    /**
     * Compile cache
     *
     * @response array{
     *   totalFiles: int,
     *   compiled: int
     * }
     */
    #[Post('/compile', 'api.opcache.compile')]
    public function compileCache(Request $request)
    {
        $result = $this->opCacheService->compile($request->query('force'));

        return response()->json([
            'totalFiles' => $result['total_files_count'],
            'compiled'   => $result['compiled_count'],
        ]);
    }
}
