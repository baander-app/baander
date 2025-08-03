<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\Jobs\Manager\CouldNotFindJobException;
use App\Http\Controllers\Controller;
use App\Jobs\Library\Music\ScanMusicLibraryJob;
use App\Jobs\Movies\ScanMovieLibraryJob;
use App\Models\{Library, TokenAbility};
use App\Services\JobCleanupService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Http\Request;
use Spatie\RouteAttributes\Attributes\{Delete, Get, Middleware, Post, Prefix};

#[Prefix('jobs')]
#[Middleware([
    'auth:sanctum',
    'ability:' . TokenAbility::ACCESS_API->value,
    'force.json',
])]
class JobController extends Controller
{
    use DispatchesJobs;

    public function __construct(private readonly JobCleanupService $jobCleanupService)
    {
    }

    /**
     * Get job locks
     *
     * @response array{
     *   key: string,
     *   ttl: int,
     *   age_hours: int|string,
     *   exists: boolean
     * }[]
     */
    #[Get('/locks', 'api.job.locks')]
    public function getJobLocks(string $jobId)
    {
        $locks = $this->jobCleanupService->getJobLocks($jobId);

        return response()->json($locks);
    }

    /**
     * Get job lock
     *
     * @response array{
     *   key: string,
     *   ttl: int,
     *   age_hours: int|string,
     *   exists: boolean
     * }
     */
    #[Get('/locks/{jobClass}/lock/{jobId}', 'api.job.lock')]
    public function getJobLock(string $jobClass, string $jobId)
    {
        $lock = $this->jobCleanupService->getJobLockInfo($jobClass, $jobId);

        return response()->json($lock);
    }

    /**
     * Destroy job lock
     */
    #[Delete('/locks/{jobClass}/lock/{jobId}', 'api.job.lock-delete')]
    public function destroyJobLock(string $jobClass, string $jobId)
    {
        $status = $this->jobCleanupService->clearSpecificJobLock($jobClass, $jobId);

        return response()->json([
            'success' => $status === true,
            'message' => $status === true ? 'Job lock cleared.' : 'Job lock not found.',
        ]);
    }

    /**
     * Cleanup jobs
     *
     * @response array{
     *   stuck_locks: array{
     *     count: integer,
     *     locks: array {
     *       key: string,
     *       ttl: int,
     *       age_hours: int|string,
     *     }
     *   }[],
     *   failed_jobs: array{
     *     id: integer,
     *     uuid: string,
     *     connection: string,
     *     queue: string,
     *     payload: string,
     *     exception: string,
     *     failed_at: string,
     *    }[],
     *    dry_run: boolean
     * }
     */
    #[Post('/cleanup', 'api.job.cleanup')]
    public function cleanupJobs(Request $request)
    {
        $result = $this->jobCleanupService->getCleanupSummary($request->boolean('dryRun', true));

        return response()->json($result);
    }

    /**
     * Clear failed jobs
     */
    #[Post('/failed', 'api.job.failed-cleanup')]
    public function clearFailedJobs(Request $request)
    {
        $this->jobCleanupService->clearFailedJobs($request->integer('hoursOld', 24), $request->boolean('dryRun', true));

        return response()->json([
            'success' => true,
            'message' => 'All failed jobs cleared.',
        ]);

    }

    /**
     * Scan a library
     */
    #[Post('/scanLibrary/{slug}', 'api.job.library-scan')]
    public function startLibraryScan(Request $request)
    {
        $this->gateCheckExecuteJob();

        $slug = $request->route('slug');

        try {
            $library = Library::whereSlug($slug)->firstOrFail();
        } catch (ModelNotFoundException $e) {
            throw CouldNotFindJobException::throwFromController($e);
        }

        $job = match ($library->type) {
            'movie' => ScanMovieLibraryJob::dispatch($library),
            'music' => ScanMusicLibraryJob::dispatch($library),
        };

        $name = get_class($job);

        return response()->json([
            'message' => "Job $name dispatched.",
        ]);
    }
}
