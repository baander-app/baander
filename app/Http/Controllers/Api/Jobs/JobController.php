<?php

namespace App\Http\Controllers\Api\Jobs;

use App\Http\Controllers\Controller;
use App\Jobs\Library\ScanMusicLibraryJob;
use App\Models\Library;
use App\Models\TokenAbility;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Http\Request;
use Spatie\RouteAttributes\Attributes\{Middleware, Post, Prefix};

#[Prefix('jobs')]
#[Middleware([
    'auth:sanctum',
    'ability:' . TokenAbility::ACCESS_API->value,
    'force.json',
])]
class JobController extends Controller
{
    use DispatchesJobs;

    #[Post('/scanLibrary/{slug}', 'api.job.library-scan')]
    /**
     * Scan a library
     */
    public function startLibraryScan(Request $request)
    {
        $slug = $request->route('slug');

        try {
            $library = Library::whereSlug($slug)->firstOrFail();
        } catch (ModelNotFoundException) {
            abort(404);
            return;
        }

        $job = new ScanMusicLibraryJob($library);
        $this->dispatch($job);

        return [
            'message' => 'Job started successfully',
        ];
    }
}
