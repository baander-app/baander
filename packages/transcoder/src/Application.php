<?php

namespace Baander\Transcoder;

use Amp\Redis\Connection\RedisConnector;
use Amp\Redis\RedisClient;
use Amp\Redis\RedisSubscriber;
use Baander\Common\Streaming\AudioProfile;
use Baander\Common\Streaming\TranscodeOptions;
use Baander\Common\Streaming\VideoProfile;
use Baander\Transcoder\Services\MediaQualityService;
use League\Container\ContainerAwareTrait;

class Application
{
    use ContainerAwareTrait;

    /** @var array<string, TranscodeSession> */
    private array $sessions = [];

    public function __construct(
        private readonly RedisClient         $redis,
        private readonly MediaQualityService $mediaQualityService,
    )
    {
    }

    /**
     * Start a transcoding session.
     *
     * @param string $sessionId A unique session ID for the user.
     * @param TranscodeOptions $options Transcoding options (input, output, profile).
     * @param int $startTime Start time in seconds for the transcode operation.
     */
    public function startTranscoding(string $sessionId, TranscodeOptions $options, int $startTime = 0): void
    {
        $payload = [
            'session_id'    => $sessionId,
            'type'          => 'start',
            'position'      => $startTime,
            'input'         => $options->inputFilePath,
            'output'        => $options->outputDirectoryPath,
            'video_profile' => [
                'width'   => $options->videoProfile->width ?? null,
                'height'  => $options->videoProfile->height ?? null,
                'bitrate' => $options->videoProfile->bitrate ?? null,
            ],
            'audio_profile' => [
                'bitrate' => $options->audioProfile->bitrate ?? null,
            ],
        ];

        // Publish Start Command
        $this->redis->publish(TranscoderPubSubChannel::Commands->value, json_encode($payload));
    }

    /**
     * Stop an active transcoding session.
     *
     * @param string $sessionId The session ID to stop.
     */
    public function stopTranscoding(string $sessionId): void
    {
        $payload = [
            'session_id' => $sessionId,
            'type'       => 'stop',
        ];

        // Publish Stop Command
        $this->redis->publish(TranscoderPubSubChannel::Commands->value, json_encode($payload));
    }

    /**
     * Handle seeking by stopping the current session and starting a new one.
     *
     * @param string $sessionId A unique session ID for the user.
     * @param TranscodeOptions $options Transcoding options.
     * @param int $seekTime Seek time in seconds.
     */
    public function seek(string $sessionId, TranscodeOptions $options, int $seekTime): void
    {
        $this->stopTranscoding($sessionId);
        $this->startTranscoding($sessionId, $options, $seekTime);
    }

    /**
     * Subscribe to state updates from the Redis state channel.
     */
    public function subscribeToStateUpdates(callable $callback): void
    {
        $subscriber = new RedisSubscriber($this->container->get(RedisConnector::class));
        $subscriber->subscribe(TranscoderPubSubChannel::State->value)
            ->getIterator()->each(function ($message) use ($callback) {
                $data = json_decode($message, true);
                $callback($data);
            });
    }

    public function run(): void
    {
        $subscriber = new RedisSubscriber($this->container->get(RedisConnector::class));

        // Subscribe to the transcoder command channel
        $subscriber->subscribe(TranscoderPubSubChannel::Commands->value)
            ->getIterator()->each(function ($message) {
                $data = json_decode($message, true);

                $sessionId = $data['session_id'];
                switch ($data['type']) {
                    case 'start':
                        $this->startSession($sessionId, $data);
                        break;

                    case 'stop':
                        $this->stopSession($sessionId);
                        break;
                    case 'quality':
                        $qualities = $this->mediaQualityService->getAvailableQualities($data['path'])->await();

                        $this->redis->publish(TranscoderPubSubChannel::Commands->value, json_encode([
                            'session_id' => $sessionId,
                            'qualities'  => $qualities,
                        ]));
                        break;
                    default:
                        echo "Unknown command type: {$data['type']}";
                        break;
                }
            });
    }

    private function startSession(string $sessionId, array $data): void
    {
        // If a session with the same ID exists, terminate it first
        if (isset($this->sessions[$sessionId])) {
            $this->stopSession($sessionId);
        }

        // Start a new transcode session
        $transcodeOptions = new TranscodeOptions(
            inputFilePath: $data['input'],
            outputDirectoryPath: $data['output'],
            segmentPrefix: 'segment', // Customize based on your needs
            segmentOffset: $data['position'],
            segmentTimes: [], // Provide breakpoints/segments here
            videoProfile: new VideoProfile(
                width: $data['video_profile']['width'],
                height: $data['video_profile']['height'],
                bitrate: $data['video_profile']['bitrate'],
            ),
            audioProfile: new AudioProfile(
                bitrate: $data['audio_profile']['bitrate'],
            ),
        );

        $session = new TranscodeSession($sessionId, $transcodeOptions);
        $this->sessions[$sessionId] = $session;

        // Begin transcoding asynchronously
        $session->transcode();
    }

    private function stopSession(string $sessionId): void
    {
        if (isset($this->sessions[$sessionId])) {
            $this->sessions[$sessionId]->stop();
            unset($this->sessions[$sessionId]);
        }
    }
}
