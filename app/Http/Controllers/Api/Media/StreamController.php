<?php

namespace App\Http\Controllers\Api\Media;

use App\Models\Song;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Spatie\RouteAttributes\Attributes\{Get, Prefix};
use Symfony\Component\HttpFoundation\Response;

#[Prefix('/stream')]
#[Group('Media')]
class StreamController
{
    /**
     * Stream a song file with HTTP Range request support.
     *
     * Supports partial content requests for seeking and progressive download.
     * Returns 206 Partial Content when Range header is present, 200 otherwise.
     *
     * @param Song $song The song to stream
     * @param Request $request The HTTP request
     * @return Response
     */
    #[Get('/song/{song}/direct', 'api.stream.song-direct', [
        'auth:oauth',
        'scope:access-api',
    ])]
    public function songDirect(Song $song, Request $request)
    {
        $filePath = $song->getPath();

        if (!file_exists($filePath)) {
            abort(404, 'Audio file not found');
        }

        $fileSize = filesize($filePath);
        $fileHandle = fopen($filePath, 'rb');

        if ($fileHandle === false) {
            abort(500, 'Could not open audio file');
        }

        // Check for Range header
        $rangeHeader = $request->header('Range');

        if ($rangeHeader) {
            return $this->handleRangeRequest($fileHandle, $fileSize, $rangeHeader, $filePath, $song);
        }

        // No Range header, return entire file
        return $this->streamEntireFile($fileHandle, $fileSize, $filePath, $song);
    }

    /**
     * Handle HTTP Range request for partial content.
     */
    private function handleRangeRequest($fileHandle, int $fileSize, string $rangeHeader, string $filePath, Song $song)
    {
        // Parse Range header (format: "bytes=start-end")
        if (!preg_match('/^bytes=(\d*)-(\d*)$/', $rangeHeader, $matches)) {
            fclose($fileHandle);
            return response()->json(['error' => 'Invalid Range header format'], 400);
        }

        $start = $matches[1] !== '' ? (int)$matches[1] : null;
        $end = $matches[2] !== '' ? (int)$matches[2] : null;

        // Validate and normalize range
        if ($start === null) {
            // Suffix range: "bytes=-500" means last 500 bytes
            $start = max(0, $fileSize - ($end ?? 0));
            $end = $fileSize - 1;
        } else {
            // Normal range: "bytes=0-499" means first 500 bytes
            if ($start >= $fileSize) {
                fclose($fileHandle);
                return response()->json(['error' => 'Range start exceeds file size'], 416);
            }
            $end = min($end ?? $fileSize - 1, $fileSize - 1);

            if ($end < $start) {
                fclose($fileHandle);
                return response()->json(['error' => 'Invalid range: end before start'], 416);
            }
        }

        $contentLength = $end - $start + 1;

        // Seek to start position
        fseek($fileHandle, $start);

        // Stream the requested range
        return response()->stream(
            function () use ($fileHandle, $end) {
                $bytesToRead = $end - ftell($fileHandle) + 1;
                $chunkSize = 8192; // 8KB chunks

                while ($bytesToRead > 0 && !feof($fileHandle)) {
                    $readSize = min($chunkSize, $bytesToRead);
                    echo fread($fileHandle, $readSize);
                    $bytesToRead -= $readSize;
                }
                fclose($fileHandle);
            },
            206,
            [
                'Accept-Ranges'       => 'bytes',
                'Content-Type'        => $this->getMimeType($filePath, $song),
                'Content-Length'      => $contentLength,
                'Content-Range'       => "bytes $start-$end/{$fileSize}",
                'Cache-Control'       => 'public, max-age=31536000',
                'Content-Disposition' => 'inline; filename="' . basename($filePath) . '"',
            ],
        );
    }

    /**
     * Stream entire file without Range header.
     */
    private function streamEntireFile($fileHandle, int $fileSize, string $filePath, Song $song)
    {
        return response()->stream(
            function () use ($fileHandle) {
                $chunkSize = 8192; // 8KB chunks

                while (!feof($fileHandle)) {
                    echo fread($fileHandle, $chunkSize);
                }
                fclose($fileHandle);
            },
            200,
            [
                'Accept-Ranges'       => 'bytes',
                'Content-Type'        => $this->getMimeType($filePath, $song),
                'Content-Length'      => $fileSize,
                'Cache-Control'       => 'public, max-age=31536000',
                'Content-Disposition' => 'inline; filename="' . basename($filePath) . '"',
            ],
        );
    }

    /**
     * Get MIME type for the audio file.
     */
    private function getMimeType(string $filePath, Song $song): string
    {
        // Use stored MIME type if available
        if (!empty($song->mime_type)) {
            return $song->mime_type;
        }

        // Fallback to file extension detection
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        return match ($extension) {
            'm4a', 'mp4' => 'audio/mp4',
            'flac' => 'audio/flac',
            'wav' => 'audio/wav',
            'ogg' => 'audio/ogg',
            'wma' => 'audio/x-ms-wma',
            'aac' => 'audio/aac',
            default => 'audio/mpeg',
        };
    }
}
