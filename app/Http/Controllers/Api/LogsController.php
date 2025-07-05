<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TokenAbility;
use App\Modules\LogStreamer\{LogFileService, Models\LogFile, SearchableLogFile, ThreadedLogProcessor};
use Illuminate\Http\{JsonResponse, Request, Response};
use Spatie\RouteAttributes\Attributes\{Get, Middleware, Prefix};

#[Prefix('/logs')]
#[Middleware([
    'auth:sanctum',
    'ability:' . TokenAbility::ACCESS_API->value,
    'force.json',
])]
class LogsController extends Controller
{
    public function __construct(
        private readonly LogFileService $logFileService,
    )
    {
    }

    /**
     * Get a collection of log files
     *
     * @return LogFile[]
     */
    #[Get('/', 'api.logs.index')]
    public function index()
    {
        return $this->logFileService->getSortedFiles();
    }

    /**
     * Show a log file
     *
     * @param string $logFile
     * @return JsonResponse
     */
    #[Get('/{logFile}', 'api.logs.show')]
    public function show(string $logFile)
    {
        $file = $this->logFileService->getFileById($logFile);

        if (!$file) {
            return response()->json([
                'error' => 'Log file not found',
            ], Response::HTTP_NOT_FOUND);
        }

        try {
            $searchableFile = new SearchableLogFile($file->path);
            $fileInfo = $searchableFile->getFileInfo();

            return response()->json([
                'data' => [
                    'file' => $file,
                    'info' => $fileInfo,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to read log file: ' . $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get log file content
     *
     * @param Request $request
     * @param string $logFile
     * @return JsonResponse
     */
    #[Get('/{logFile}/content', 'api.logs.content')]
    public function content(Request $request, string $logFile)
    {
        $request->validate([
            'after_line' => 'sometimes|integer|min:0',
            'max_lines'  => 'sometimes|integer|min:1|max:10000',
        ]);

        $file = $this->logFileService->getFileById($logFile);

        if (!$file) {
            return response()->json([
                'error' => 'Log file not found',
            ], Response::HTTP_NOT_FOUND);
        }

        try {
            $searchableFile = new SearchableLogFile($file->path);
            $afterLine = $request->integer('after_line', 0);
            $maxLines = $request->integer('max_lines', 1000);

            $content = $searchableFile->contentAfterLine($afterLine, $maxLines);

            return response()->json([
                'data' => $content,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to read log content: ' . $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Count log file lines
     *
     * @param string $logFile
     * @return JsonResponse
     */
    #[Get('/{logFile}/lines', 'api.logs.lines')]
    public function lines(string $logFile)
    {
        $file = $this->logFileService->getFileById($logFile);

        if (!$file) {
            return response()->json([
                'error' => 'Log file not found',
            ], Response::HTTP_NOT_FOUND);
        }

        try {
            $processor = new ThreadedLogProcessor($file->path);
            $lineCount = $processor->countLines();

            return response()->json([
                'data' => [
                    'file'       => $logFile,
                    'totalLines' => $lineCount,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to count lines: ' . $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Search log file content
     *
     * @param Request $request
     * @param string $logFile
     * @return JsonResponse
     */
    #[Get('/{logFile}/search', 'api.logs.search')]
    public function search(Request $request, string $logFile)
    {
        $request->validate([
            'pattern'       => 'required|string|min:1|max:500',
            'caseSensitive' => 'sometimes|boolean',
            'maxResults'    => 'sometimes|integer|min:1|max:1000',
        ]);

        $file = $this->logFileService->getFileById($logFile);

        if (!$file) {
            return response()->json([
                'error' => 'Log file not found',
            ], 404);
        }

        try {
            $searchableFile = new SearchableLogFile($file->path);
            $pattern = $request->string('pattern');
            $caseSensitive = $request->boolean('case_sensitive', true);
            $maxResults = $request->integer('max_results', 100);

            $results = $searchableFile->search($pattern, $caseSensitive, $maxResults);

            return response()->json([
                'data' => $results,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Search failed: ' . $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get log file tail
     *
     * @param Request $request
     * @param string $logFile
     * @return JsonResponse
     */
    #[Get('/{logFile}/tail', 'api.logs.tail')]
    public function tail(Request $request, string $logFile): JsonResponse
    {
        $request->validate([
            'lines' => 'sometimes|integer|min:1|max:1000',
        ]);

        $file = $this->logFileService->getFileById($logFile);

        if (!$file) {
            return response()->json([
                'error' => 'Log file not found',
            ], 404);
        }

        try {
            $searchableFile = new SearchableLogFile($file->path);
            $lines = $request->integer('lines', 50);
            $totalLines = $searchableFile->numberOfLines();
            $startLine = max(0, $totalLines - $lines);

            $content = $searchableFile->contentAfterLine($startLine, $lines);

            return response()->json([
                'data' => [
                    'content'      => $content,
                    'totalLines'   => $totalLines,
                    'showingLines' => $lines,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to tail log: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get log file head
     *
     * @param Request $request
     * @param string $logFile
     * @return JsonResponse
     */
    #[Get('/{logFile}/head', 'api.logs.head')]
    public function head(Request $request, string $logFile): JsonResponse
    {
        $request->validate([
            'lines' => 'sometimes|integer|min:1|max:1000',
        ]);

        $file = $this->logFileService->getFileById($logFile);

        if (!$file) {
            return response()->json([
                'error' => 'Log file not found',
            ], 404);
        }

        try {
            $searchableFile = new SearchableLogFile($file->path);
            $lines = $request->integer('lines', 50);

            $content = $searchableFile->contentAfterLine(0, $lines);

            return response()->json([
                'data' => [
                    'content'      => $content,
                    'showingLines' => $lines,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to read log head: ' . $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get log file statistics
     *
     * @param string $logFile
     * @return JsonResponse
     */
    #[Get('/{logFile}/stats', 'api.logs.stats')]
    public function stats(string $logFile): JsonResponse
    {
        $file = $this->logFileService->getFileById($logFile);

        if (!$file) {
            return response()->json([
                'error' => 'Log file not found',
            ], 404);
        }

        try {
            $searchableFile = new SearchableLogFile($file->path);
            $fileInfo = $searchableFile->getFileInfo();

            // Get some basic log level statistics
            $errorCount = $searchableFile->search('ERROR', false, null)->totalMatches;
            $warningCount = $searchableFile->search('WARNING', false, null)->totalMatches;
            $infoCount = $searchableFile->search('INFO', false, null)->totalMatches;
            $debugCount = $searchableFile->search('DEBUG', false, null)->totalMatches;

            return response()->json([
                'data' => [
                    'fileInfo'   => $fileInfo,
                    'logLevels'  => [
                        'error'   => $errorCount,
                        'warning' => $warningCount,
                        'info'    => $infoCount,
                        'debug'   => $debugCount,
                    ],
                    'performance' => [
                        'isLargeFile'        => $fileInfo->isLargeFile(),
                        'shouldUseThreading' => $fileInfo->shouldUseThreading(),
                        'optimalThreads'      => $fileInfo->optimalThreads,
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to generate stats: ' . $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Download log file
     *
     * @param string $logFile
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse|JsonResponse
     */
    #[Get('/{logFile}/download', 'api.logs.download')]
    public function download(string $logFile)
    {
        $file = $this->logFileService->getFileById($logFile);

        if (!$file) {
            return response()->json([
                'error' => 'Log file not found',
            ], 404);
        }

        if (!file_exists($file->path)) {
            return response()->json([
                'error' => 'Log file does not exist on disk',
            ], 404);
        }

        return response()->download($file->path, $file->fileName, [
            'Content-Type' => 'text/plain',
        ]);
    }

    /**
     * Search across all log files
     *
     * @param Request $request
     * @return JsonResponse
     */
    #[Get('/search/all', 'api.logs.search-all')]
    public function searchAll(Request $request)
    {
        $request->validate([
            'pattern'              => 'required|string|min:1|max:500',
            'caseSensitive'       => 'sometimes|boolean',
            'maxResultsPerFile' => 'sometimes|integer|min:1|max:100',
            'files'                => 'sometimes|array',
            'files.*'              => 'string',
        ]);

        try {
            $pattern = $request->string('pattern');
            $caseSensitive = $request->boolean('case_sensitive', true);
            $maxResultsPerFile = $request->integer('max_results_per_file', 10);
            $requestedFiles = $request->input('files', []);

            $files = $this->logFileService->getFiles();

            if (!empty($requestedFiles)) {
                $files = $files->whereIn('id', $requestedFiles);
            }

            $allResults = [];
            $totalMatches = 0;
            $searchTime = 0;

            foreach ($files as $file) {
                try {
                    $searchableFile = new SearchableLogFile($file->path);
                    $results = $searchableFile->search($pattern, $caseSensitive, $maxResultsPerFile);

                    if (!$results->isEmpty()) {
                        $allResults[] = [
                            'file'    => $file,
                            'results' => $results,
                        ];
                        $totalMatches += $results->totalMatches;
                        $searchTime += $results->searchTimeMs;
                    }
                } catch (\Exception $e) {
                    // Log error but continue with other files
                    logger()->warning("Failed to search in log file {$file->path}: " . $e->getMessage());
                }
            }

            return response()->json([
                'data' => [
                    'pattern'              => $pattern,
                    'caseSensitive'       => $caseSensitive,
                    'totalFilesSearched' => $files->count(),
                    'filesWithMatches'   => count($allResults),
                    'totalMatches'        => $totalMatches,
                    'searchTimeMs'       => round($searchTime, 2),
                    'results'              => $allResults,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Search failed: ' . $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}