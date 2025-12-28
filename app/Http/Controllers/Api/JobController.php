<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\Jobs\Manager\CouldNotFindJobException;
use App\Http\Controllers\Controller;
use App\Jobs\Library\Music\ScanMusicLibraryJob;
use App\Jobs\Movies\ScanMovieLibraryJob;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;
use App\Models\Library;
use App\Services\JobCleanupService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Http\{JsonResponse, Request};
use Spatie\RouteAttributes\Attributes\{Delete, Get, Middleware, Post, Prefix};

/**
 * Job management and monitoring controller
 *
 * Handles background job operations including queue monitoring, lock management,
 * cleanup operations, and library scanning job dispatching. Provides administrative
 * tools for job system maintenance and troubleshooting.
 */
#[Prefix('jobs')]
#[Group('System')]
#[Middleware([
    'auth:oauth',
    'scope:access-api',
    'force.json',
])]
class JobController extends Controller
{
    use DispatchesJobs;

    public function __construct(private readonly JobCleanupService $jobCleanupService)
    {
    }

    /**
     * Get all job locks for a specific job type
     *
     * Returns information about active job locks including TTL, age, and status.
     * Used for monitoring job execution and identifying stuck or long-running jobs.
     *
     * @param string $jobId The job identifier to get locks for
     *
     * @response array<array{
     *   key: string,
     *   ttl: int,
     *   age_hours: int|string,
     *   exists: boolean
     * }>
     */
    #[Get('/locks', 'api.job.locks')]
    public function getJobLocks(string $jobId): JsonResponse
    {
        /** @var array $locks Job lock information */
        $locks = $this->jobCleanupService->getJobLocks($jobId);

        // Job locks information for monitoring.
        return response()->json($locks);
    }

    /**
     * Get specific job lock information
     *
     * Retrieves detailed information about a specific job lock including
     * its current state, time-to-live, and age for debugging purposes.
     *
     * @param string $jobClass The job class name
     * @param string $jobId The specific job instance ID
     *
     * @throws CouldNotFindJobException When job lock is not found
     * @response array{
     *   key: string,
     *   ttl: int,
     *   age_hours: int|string,
     *   exists: boolean
     * }
     */
    #[Get('/locks/{jobClass}/lock/{jobId}', 'api.job.lock')]
    public function getJobLock(string $jobClass, string $jobId): JsonResponse
    {
        /** @var array $lock Specific job lock information */
        $lock = $this->jobCleanupService->getJobLockInfo($jobClass, $jobId);

        // Specific job lock information.
        return response()->json($lock);
    }

    /**
     * Force remove a specific job lock
     *
     * Manually removes a job lock, typically used to clear stuck jobs that
     * are preventing new instances from running. Use with caution as this
     * can interfere with actively running jobs.
     *
     * @param string $jobClass The job class name
     * @param string $jobId The specific job instance ID to unlock
     *
     * @response array{
     *   success: boolean,
     *   message: string
     * }
     */
    #[Delete('/locks/{jobClass}/lock/{jobId}', 'api.job.lock-delete')]
    public function destroyJobLock(string $jobClass, string $jobId): JsonResponse
    {
        $status = $this->jobCleanupService->clearSpecificJobLock($jobClass, $jobId);

        return response()->json([
            'success' => $status === true,
            'message' => $status === true ? 'Job lock cleared.' : 'Job lock not found.',
        ]);
    }

    /**
     * Get job cleanup summary and optionally perform cleanup
     *
     * Analyzes the job system for stuck locks and failed jobs, providing a summary
     * of issues found. Can perform actual cleanup when dry_run is set to false.
     *
     * @param Request $request Request with optional dryRun boolean parameter
     *
     * @response array{
     *   stuck_locks: array{
     *     count: int,
     *     locks: array<array{
     *       key: string,
     *       ttl: int,
     *       age_hours: int|string
     *     }>
     *   },
     *   failed_jobs: array<array{
     *     id: int,
     *     uuid: string,
     *     connection: string,
     *     queue: string,
     *     payload: string,
     *     exception: string,
     *     failed_at: string
     *   }>,
     *   dry_run: boolean
     * }
     */
    #[Post('/cleanup', 'api.job.cleanup')]
    public function cleanupJobs(Request $request): JsonResponse
    {
        $dryRun = $request->boolean('dryRun', true);
        $result = $this->jobCleanupService->getCleanupSummary($dryRun);

        return response()->json($result);
    }

    /**
     * Clear failed jobs from the queue
     *
     * Removes failed jobs older than the specified time threshold from the
     * failed jobs table. Helps maintain system performance and storage efficiency.
     *
     * @param Request $request Request with optional hoursOld and dryRun parameters
     *
     * @response array{
     *   success: boolean,
     *   message: string
     * }
     */
    #[Post('/failed', 'api.job.failed-cleanup')]
    public function clearFailedJobs(Request $request): JsonResponse
    {
        $hoursOld = $request->integer('hoursOld', 24);
        $dryRun = $request->boolean('dryRun', true);

        $this->jobCleanupService->clearFailedJobs($hoursOld, $dryRun);

        // Failed jobs cleanup confirmation.
        return response()->json([
            'success' => true,
            'message' => $dryRun ? 'Failed jobs analyzed (dry run).' : 'All failed jobs cleared.',
        ]);
    }

    /**
     * Start a library scanning job
     *
     * Dispatches a background job to scan a library for new media content.
     * The job type (music or movie) is automatically determined by the library type.
     *
     * @param Request $request Request containing the library slug in the route
     *
     * @throws CouldNotFindJobException When library is not found
     * @throws AuthorizationException When user lacks job execution privileges
     * @response array{
     *   message: string
     * }
     * @status 202
     */
    #[Post('/scanLibrary/{slug}', 'api.job.library-scan')]
    public function startLibraryScan(Request $request): JsonResponse
    {
        $this->gateCheckExecuteJob();
        $slug = $request->route('slug');

        try {
            /** @var Library $library */
            $library = (new Library)->whereSlug($slug)->firstOrFail();
        } catch (ModelNotFoundException $e) {
            throw CouldNotFindJobException::throwFromController($e);
        }

        $job = match ($library->type) {
            'movie' => ScanMovieLibraryJob::dispatch($library),
            'music' => ScanMusicLibraryJob::dispatch($library),
            default => throw new InvalidArgumentException("Unsupported library type: {$library->type}")
        };
        $jobName = get_class($job);

        // Library scanning job dispatched successfully.
        return response()->json([
            'message' => "Job $jobName dispatched.",
        ], 202);
    }
}
