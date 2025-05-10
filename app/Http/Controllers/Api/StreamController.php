<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Stream\StartStreamRequest;
use Baander\Common\Streaming\AudioProfile;
use Illuminate\Http\Request;
use App\Services\Streaming\StreamService;
use Spatie\RouteAttributes\Attributes\Get;
use Spatie\RouteAttributes\Attributes\Post;
use Spatie\RouteAttributes\Attributes\Prefix;
use Symfony\Component\HttpFoundation\Response;

#[Prefix('/stream')]
class StreamController extends Controller
{

    public function __construct(private readonly StreamService $streamService)
    {
    }

    /**
     * Generate a unique session ID for streams.
     */
    #[Get(('/session'))]
    public function generateSessionId()
    {
        $sessionId = (string) \Illuminate\Support\Str::uuid();

        return response()->json([
            'session_id' => $sessionId,
        ]);
    }

    /**
     * Start a stream.
     */
    #[Post('/start')]
    public function start(StartStreamRequest $request)
    {
        $data = $request->validated();

        $audioProfile = new AudioProfile(
            bitrate: $data['audio_profile']['bitrate'] ?? null,
            channels: $data['audio_profile']['channels'] ?? null,
            sampleRate: $data['audio_profile']['sample_rate'] ?? null,
            codec: $data['audio_profile']['codec'] ?? null,
        );

        $videoProfile = new \Baander\Common\Streaming\VideoProfile(
            width: $data['video_profile']['width'] ?? null,
            height: $data['video_profile']['height'] ?? null,
            bitrate: $data['video_profile']['bitrate'] ?? null,
            codec: $data['video_profile']['codec'] ?? null,
        );

        $sessionId = $request->input('session_id', (string) \Illuminate\Support\Str::uuid());

        $options = $request->input('options');
        $options['protocol'] = $request->input('protocol');

        $this->streamService->startStream($sessionId, $this->mapTranscodeOptions($options), $request->input('start_time', 0));

        return response()->json([
            'message' => 'Stream started successfully.',
            'session_id' => $sessionId,
        ], Response::HTTP_OK);
    }

    /**
     * Stop a stream.
     */
    #[Post('/stop')]
    public function stop(Request $request)
    {
        $request->validate([
            'session_id' => 'required|string',
        ]);

        $this->streamService->stopStream($request->input('session_id'));

        return response()->json(['message' => 'Stream stopped successfully.'], Response::HTTP_OK);
    }

    /**
     * Seek within a stream.
     */
    #[Post('/seek')]
    public function seek(Request $request)
    {
        $request->validate([
            'session_id' => 'required|string',
            'options'    => 'required|array',
            'seek_time'  => 'required|integer',
        ]);

        $options = $request->input('options');
        $options['protocol'] = $options['protocol'] ?? 'hls'; // Default protocol, if needed.

        $this->streamService->seekStream($request->input('session_id'), $this->mapTranscodeOptions($options), $request->input('seek_time'));

        return response()->json(['message' => 'Seek operation completed.'], Response::HTTP_OK);
    }

    private function mapTranscodeOptions(array $options): \Baander\Common\Streaming\TranscodeOptions
    {
        $videoProfile = new \Baander\Common\Streaming\VideoProfile(
            $options['video_profile']['width'] ?? null,
            $options['video_profile']['height'] ?? null,
            $options['video_profile']['bitrate'] ?? null
        );

        $audioProfile = new \Baander\Common\Streaming\AudioProfile(
            $options['audio_profile']['bitrate'] ?? null
        );

        return new \Baander\Common\Streaming\TranscodeOptions(
            $options['input_file_path'],
            $options['output_directory_path'],
            $videoProfile,
            $audioProfile
        );
    }
}
