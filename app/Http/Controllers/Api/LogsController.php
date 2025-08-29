<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TokenAbility;
use App\Modules\Logging\Attributes\LogChannel;
use App\Modules\Logging\Channel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use App\Modules\Logging\LogStreamer\{Models\LogFile};
use App\Modules\Logging\LogStreamer\LogFileService;
use App\Modules\Logging\LogStreamer\SearchableLogFile;
use App\Modules\Logging\LogStreamer\ThreadedLogProcessor;
use Exception;
use Illuminate\Http\{JsonResponse, Request, Response};
use Spatie\RouteAttributes\Attributes\{Get, Middleware, Prefix};
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Application log file management and analysis controller
 *
 * Provides comprehensive log file operations including viewing, searching, downloading,
 * and statistical analysis. Supports high-performance operations on large log files
 * with threading and optimized search capabilities.
 */
#[Prefix('/logs')]
#[Middleware([
    'auth:sanctum',
    'ability:' . TokenAbility::ACCESS_API->value,
    'force.json',
])]
class LogsController extends Controller
{
    /** @noinspection PhpPropertyOnlyWrittenInspection */
    #[LogChannel(Channel::Daily)]
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly LogFileService $logFileService,
    )
    {
    }

    /**
     * Get a collection of available log files
     *
     * Returns a sorted list of all available log files in the system
     * with metadata including file sizes, modification dates, and identifiers.
     *
     * @response array<LogFile>
     */
    #[Get('/', 'api.logs.index')]
    public function index(): array
    {
        /** @var array<LogFile> $files Sorted array of available log files */
        $files = $this->logFileService->getSortedFiles();

        // Array of available log files sorted by modification date.
        return $files;
    }

    /**
     * Get detailed information about a specific log file
     *
     * Returns comprehensive metadata about a log file including file statistics,
     * line counts, size information, and performance characteristics.
     *
     * @param string $logFile The log file identifier
     *
     * @throws ModelNotFoundException When log file is not found
     * @response array{
     *   data: array{
     *     file: LogFile,
     *     info: array{
     *       size: int,
     *       lines: int,
     *       lastModified: string,
     *       isLargeFile: boolean,
     *       shouldUseThreading: boolean
     *     }
     *   }
     * }|array{error: string}
     */
    #[Get('/{logFile}', 'api.logs.show')]
    public function show(string $logFile): JsonResponse
    {
        /** @var LogFile|null $file */
        $file = $this->logFileService->getFileById($logFile);

        if (!$file) {
            return response()->json([
                'error' => 'Log file not found',
            ], Response::HTTP_NOT_FOUND);
        }

        try {
            $searchableFile = new SearchableLogFile($file->path);
            /** @var object $fileInfo Comprehensive file information */
            $fileInfo = $searchableFile->getFileInfo();

            // Log file information with detailed metadata.
            return response()->json([
                'data' => [
                    'file' => $file,
                    'info' => $fileInfo,
                ],
            ]);
        } catch (Exception $e) {
            return response()->json([
                'error' => 'Failed to read log file: ' . $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get paginated content from a log file
     *
     * Retrieves log file content starting from a specific line number
     * with configurable line limits for efficient pagination through large files.
     *
     * @param Request $request Request with optional after_line and max_lines parameters
     * @param string $logFile The log file identifier
     *
     * @throws ValidationException When parameters are invalid
     * @response array{
     *   data: array{
     *     lines: array<string>,
     *     startLine: int,
     *     endLine: int,
     *     hasMore: boolean
     *   }
     * }|array{error: string}
     */
    #[Get('/{logFile}/content', 'api.logs.content')]
    public function content(Request $request, string $logFile): JsonResponse
    {
        $request->validate([
            'after_line' => 'sometimes|integer|min:0',
            'max_lines'  => 'sometimes|integer|min:1|max:10000',
        ]);

        /** @var LogFile|null $file */
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

            // Paginated log file content.
            return response()->json([
                'data' => $content,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'error' => 'Failed to read log content: ' . $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Count total lines in a log file
     *
     * Returns the total line count for a log file using optimized counting
     * algorithms that can handle very large files efficiently.
     *
     * @param string $logFile The log file identifier
     *
     * @response array{
     *   data: array{
     *     file: string,
     *     totalLines: int
     *   }
     * }|array{error: string}
     */
    #[Get('/{logFile}/lines', 'api.logs.lines')]
    public function lines(string $logFile): JsonResponse
    {
        /** @var LogFile|null $file */
        $file = $this->logFileService->getFileById($logFile);

        if (!$file) {
            return response()->json([
                'error' => 'Log file not found',
            ], Response::HTTP_NOT_FOUND);
        }

        try {
            $processor = new ThreadedLogProcessor($file->path);
            $lineCount = $processor->countLines();

            // Log file line count information.
            return response()->json([
                'data' => [
                    'file'       => $logFile,
                    'totalLines' => $lineCount,
                ],
            ]);
        } catch (Exception $e) {
            return response()->json([
                'error' => 'Failed to count lines: ' . $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get the last N lines from a log file (tail functionality)
     *
     * Returns the most recent lines from a log file, similar to the Unix tail command.
     * Useful for monitoring recent activity and debugging current issues.
     *
     * @param Request $request Request with optional lines parameter
     * @param string $logFile The log file identifier
     *
     * @throws ValidationException When lines parameter is invalid
     * @response array{
     *   data: array{
     *     content: array<string>,
     *     totalLines: int,
     *     showingLines: int
     *   }
     * }|array{error: string}
     */
    #[Get('/{logFile}/tail', 'api.logs.tail')]
    public function tail(Request $request, string $logFile): JsonResponse
    {
        $request->validate([
            'lines' => 'sometimes|integer|min:1|max:1000',
        ]);

        /** @var LogFile|null $file */
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

            // Tail content from log file.
            return response()->json([
                'data' => [
                    'content'      => $content,
                    'totalLines'   => $totalLines,
                    'showingLines' => $lines,
                ],
            ]);
        } catch (Exception $e) {
            return response()->json([
                'error' => 'Failed to tail log: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get the first N lines from a log file (head functionality)
     *
     * Returns the first lines from a log file, similar to the Unix head command.
     * Useful for examining log file structure and initial entries.
     *
     * @param Request $request Request with optional lines parameter
     * @param string $logFile The log file identifier
     *
     * @throws ValidationException When lines parameter is invalid
     * @response array{
     *   data: array{
     *     content: array<string>,
     *     showingLines: int
     *   }
     * }|array{error: string}
     */
    #[Get('/{logFile}/head', 'api.logs.head')]
    public function head(Request $request, string $logFile): JsonResponse
    {
        $request->validate([
            'lines' => 'sometimes|integer|min:1|max:1000',
        ]);

        /** @var LogFile|null $file */
        $file = $this->logFileService->getFileById($logFile);

        if (!$file) {
            return response()->json([
                'error' => 'Log file not found',
            ], 404);
        }

        try {
            $searchableFile = new SearchableLogFile($file->path);

            /** @var int $lines Number of lines to show from the beginning */
            $lines = $request->integer('lines', 50);

            /** @var object $content Head content from the log file */
            $content = $searchableFile->contentAfterLine(0, $lines);

            // Head content from log file.
            return response()->json([
                'data' => [
                    'content'      => $content,
                    'showingLines' => $lines,
                ],
            ]);
        } catch (Exception $e) {
            return response()->json([
                'error' => 'Failed to read log head: ' . $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get comprehensive statistics for a log file
     *
     * Analyzes log file content to provide detailed statistics including
     * log level counts, performance metrics, and optimization recommendations.
     *
     * @param string $logFile The log file identifier
     *
     * @response array{
     *   data: array{
     *     fileInfo: array{
     *       size: int,
     *       lines: int,
     *       lastModified: string
     *     },
     *     logLevels: array{
     *       error: int,
     *       warning: int,
     *       info: int,
     *       debug: int
     *     },
     *     performance: array{
     *       isLargeFile: boolean,
     *       shouldUseThreading: boolean,
     *       optimalThreads: int
     *     }
     *   }
     * }|array{error: string}
     */
    #[Get('/{logFile}/stats', 'api.logs.stats')]
    public function stats(string $logFile): JsonResponse
    {
        /** @var LogFile|null $file */
        $file = $this->logFileService->getFileById($logFile);

        if (!$file) {
            return response()->json([
                'error' => 'Log file not found',
            ], 404);
        }

        try {
            $searchableFile = new SearchableLogFile($file->path);
            $fileInfo = $searchableFile->getFileInfo();
            $errorCount = $searchableFile->search('ERROR', false, null)->totalMatches;
            $warningCount = $searchableFile->search('WARNING', false, null)->totalMatches;
            $infoCount = $searchableFile->search('INFO', false, null)->totalMatches;
            $debugCount = $searchableFile->search('DEBUG', false, null)->totalMatches;

            // Comprehensive log file statistics.
            return response()->json([
                'data' => [
                    'fileInfo'    => $fileInfo,
                    'logLevels'   => [
                        'error'   => $errorCount,
                        'warning' => $warningCount,
                        'info'    => $infoCount,
                        'debug'   => $debugCount,
                    ],
                    'performance' => [
                        'isLargeFile'        => $fileInfo->isLargeFile(),
                        'shouldUseThreading' => $fileInfo->shouldUseThreading(),
                        'optimalThreads'     => $fileInfo->optimalThreads,
                    ],
                ],
            ]);
        } catch (Exception $e) {
            return response()->json([
                'error' => 'Failed to generate stats: ' . $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Search for patterns within a log file
     *
     * Performs high-performance pattern matching within log files with support
     * for case-sensitive/insensitive searches and configurable result limits.
     *
     * @param Request $request Request with pattern, caseSensitive, and maxResults parameters
     * @param string $logFile The log file identifier to search
     *
     * @throws ValidationException When search parameters are invalid
     * @response array{
     *   data: array{
     *     pattern: string,
     *     caseSensitive: boolean,
     *     totalMatches: int,
     *     searchTimeMs: float,
     *     results: array<array{
     *       lineNumber: int,
     *       content: string,
     *       matchPosition: int
     *     }>
     *   }
     * }|array{error: string}
     */
    #[Get('/{logFile}/search', 'api.logs.search')]
    public function search(Request $request, string $logFile): JsonResponse
    {
        $request->validate([
            'pattern'       => 'required|string|min:1|max:500',
            'caseSensitive' => 'sometimes|boolean',
            'maxResults'    => 'sometimes|integer|min:1|max:1000',
        ]);

        /** @var LogFile|null $file */
        $file = $this->logFileService->getFileById($logFile);

        if (!$file) {
            return response()->json([
                'error' => 'Log file not found',
            ], 404);
        }

        try {
            $searchableFile = new SearchableLogFile($file->path);
            $pattern = $request->string('pattern');
            $caseSensitive = $request->boolean('caseSensitive', true);
            $maxResults = $request->integer('maxResults', 100);
            $results = $searchableFile->search($pattern, $caseSensitive, $maxResults);

            // Search results from log file.
            return response()->json([
                'data' => $results,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'error' => 'Search failed: ' . $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Download a log file
     *
     * Provides direct download access to log files for offline analysis
     * or archival purposes. Returns the file as a plain text download.
     *
     * @param string $logFile The log file identifier to download
     *
     * @throws ModelNotFoundException When log file is not found
     * @response BinaryFileResponse|array{error: string}
     */
    #[Get('/{logFile}/download', 'api.logs.download')]
    public function download(string $logFile): BinaryFileResponse|JsonResponse
    {
        /** @var LogFile|null $file */
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

        // Log file download stream.
        return response()->download($file->path, $file->fileName, [
            'Content-Type' => 'text/plain',
        ]);
    }

    /**
     * Search across multiple log files simultaneously
     *
     * Performs pattern matching across multiple log files in parallel,
     * providing consolidated search results with performance metrics
     * and per-file result breakdowns.
     *
     * @param Request $request Request with search parameters and optional file filtering
     *
     * @throws ValidationException When search parameters are invalid
     * @response array{
     *   data: array{
     *     pattern: string,
     *     caseSensitive: boolean,
     *     totalFilesSearched: int,
     *     filesWithMatches: int,
     *     totalMatches: int,
     *     searchTimeMs: float,
     *     results: array<array{
     *       file: LogFile,
     *       results: array{
     *         totalMatches: int,
     *         searchTimeMs: float,
     *         matches: array<array{
     *           lineNumber: int,
     *           content: string,
     *           matchPosition: int
     *         }>
     *       }
     *     }>
     *   }
     * }|array{error: string}
     */
    #[Get('/search/all', 'api.logs.search-all')]
    public function searchAll(Request $request): JsonResponse
    {
        $request->validate([
            'pattern'           => 'required|string|min:1|max:500',
            'caseSensitive'     => 'sometimes|boolean',
            'maxResultsPerFile' => 'sometimes|integer|min:1|max:100',
            'files'             => 'sometimes|array',
            'files.*'           => 'string',
        ]);

        try {
            $pattern = $request->string('pattern');
            $caseSensitive = $request->boolean('caseSensitive', true);
            $maxResultsPerFile = $request->integer('maxResultsPerFile', 10);
            $requestedFiles = $request->input('files', []);
            $files = $this->logFileService->getFiles();

            // Filter to specific files if requested
            if (!empty($requestedFiles)) {
                $files = $files->whereIn('id', $requestedFiles);
            }

            $allResults = [];
            $totalMatches = 0;
            $searchTime = 0;

            // Search through each file
            foreach ($files as $file) {
                try {
                    $searchableFile = new SearchableLogFile($file->path);
                    /** @var object $results Results from searching this file */
                    $results = $searchableFile->search($pattern, $caseSensitive, $maxResultsPerFile);

                    if (!$results->isEmpty()) {
                        $allResults[] = [
                            'file'    => $file,
                            'results' => $results,
                        ];
                        $totalMatches += $results->totalMatches;
                        $searchTime += $results->searchTimeMs;
                    }
                } catch (Exception $e) {
                    // Log error but continue with other files
                    $this->logger->warning("Failed to search in log file {$file->path}: " . $e->getMessage());
                }
            }

            // Consolidated search results across all log files.
            return response()->json([
                'data' => [
                    'pattern'            => $pattern,
                    'caseSensitive'      => $caseSensitive,
                    'totalFilesSearched' => $files->count(),
                    'filesWithMatches'   => count($allResults),
                    'totalMatches'       => $totalMatches,
                    'searchTimeMs'       => round($searchTime, 2),
                    'results'            => $allResults,
                ],
            ]);
        } catch (Exception $e) {
            return response()->json([
                'error' => 'Search failed: ' . $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
