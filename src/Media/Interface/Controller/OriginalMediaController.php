<?php

declare(strict_types=1);

namespace App\Media\Interface\Controller;

use App\Catalog\Application\Port\VideoPortInterface;
use App\Media\Application\Port\MediaReadScopeProviderInterface;
use App\Media\Application\Port\PlaybackMediaAccessInterface;
use App\Media\Application\Port\StreamPortInterface;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Port\SystemSettingsPortInterface;
use App\Transcode\Application\DTO\MediaOperation;
use App\Transcode\Application\Exception\AudioRenditionBusyException;
use App\Transcode\Application\Exception\AudioRenditionFailedException;
use App\Transcode\Application\Port\MediaExecutionPolicyInterface;
use App\Transcode\Application\Settings\TranscodeSettingDefinitions;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\Shared\Interface\DTO\ApiError;
use App\Transcode\Application\Port\AudioRenditionFormat;
use App\Transcode\Application\Port\AudioRenditionPortInterface;
use App\Transcode\Application\Port\StreamAuthPortInterface;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use SwooleBundle\SwooleBundle\Bridge\Symfony\HttpFoundation\SwooleBinaryFileResponse;

#[OA\Tag(name: 'Media', description: 'Media file and streaming endpoints')]
final readonly class OriginalMediaController
{
    use ApiResponsesTrait;

    public function __construct(
        private MediaExecutionPolicyInterface $executionPolicy,
        private SystemSettingsPortInterface $settings,
        private MediaReadScopeProviderInterface $scopes,
        private PlaybackMediaAccessInterface $access,
        private VideoPortInterface $videos,
        private StreamPortInterface $tracks,
        private AudioRenditionPortInterface $renditions,
        private StreamAuthPortInterface $signatures,
    ) {
    }

    #[OA\Get(path: '/api/stream/video', summary: 'Deliver an authorized original video with byte ranges')]
    #[OA\Head(path: '/api/stream/video', summary: 'Return the original video response headers without a body')]
    #[OA\Parameter(name: 'id', in: 'query', required: true, schema: new OA\Schema(type: 'string'))]
    #[OA\Response(response: 200, description: 'Original video')]
    #[OA\Response(response: 206, description: 'Byte range')]
    #[OA\Response(response: 403, description: 'Media access denied', content: new OA\JsonContent(ref: new Model(type: ApiError::class)))]
    #[OA\Response(response: 404, description: 'Playback media is unavailable', content: new OA\JsonContent(ref: new Model(type: ApiError::class)))]
    #[Route('/api/stream/video', name: 'stream_original_video', methods: ['GET', 'HEAD'])]
    public function video(Request $request): Response
    {
        $owner = $this->owner($request);
        if ($owner === null) {
            return $this->forbidden('A valid media signature or authenticated user is required.');
        }
        try {
            $id = PublicId::fromString($request->query->getString('id'));
            $this->access->assertVideoAccess($owner, $id);
            $source = $this->videos->getSourceMetadata($id, $this->scopes->forUser($owner)->getLibraries());
        } catch (\InvalidArgumentException|ConflictException) {
            return $this->forbidden('Playback media is unavailable.');
        }
        return $source === null ? $this->notFound('Playback media is unavailable.') : $this->file($source->filePath, $source->mimeType);
    }

    #[OA\Get(path: '/api/stream/original/track', summary: 'Deliver authorized track audio with byte ranges')]
    #[OA\Head(path: '/api/stream/original/track', summary: 'Return the track audio response headers without a body')]
    #[OA\Parameter(name: 'id', in: 'query', required: true, schema: new OA\Schema(type: 'string'))]
    #[OA\Response(response: 200, description: 'Track audio')]
    #[OA\Response(response: 206, description: 'Byte range')]
    #[OA\Response(response: 400, description: 'Invalid audio presentation mode, rendition format or bitrate', content: new OA\JsonContent(ref: new Model(type: ApiError::class)))]
    #[OA\Response(response: 403, description: 'Media access denied', content: new OA\JsonContent(ref: new Model(type: ApiError::class)))]
    #[OA\Response(response: 404, description: 'Playback media is unavailable', content: new OA\JsonContent(ref: new Model(type: ApiError::class)))]
    #[OA\Response(response: 409, description: 'Completed rendition unavailable or the selected audio cannot be copied; negotiate delivery again. The error details carry the reason.', content: new OA\JsonContent(ref: new Model(type: ApiError::class)))]
    #[OA\Response(
        response: 503,
        description: 'Every audio slot stayed busy for the whole start wait, so the stream copy has not started yet. The copy stays queued; retry after the Retry-After delay to follow it. The error details carry reason audio_slots_busy.',
        headers: [new OA\Header(header: 'Retry-After', description: 'Seconds to wait before retrying', schema: new OA\Schema(type: 'integer', example: AudioRenditionBusyException::RETRY_AFTER_SECONDS))],
        content: new OA\JsonContent(ref: new Model(type: ApiError::class)),
    )]
    #[Route('/api/stream/original/track', name: 'stream_original_track', methods: ['GET', 'HEAD'])]
    public function track(Request $request): Response
    {
        $owner = $this->owner($request);
        if ($owner === null) {
            return $this->forbidden('A valid media signature or authenticated user is required.');
        }
        try {
            $id = PublicId::fromString($request->query->getString('id'));
            $this->access->assertTrackAccess($owner, $id);
            $path = $this->tracks->resolveTrackPath($id);
            $metadata = $this->tracks->getTrackMetadata($id);
        } catch (\InvalidArgumentException|ConflictException) {
            return $this->forbidden('Playback media is unavailable.');
        }
        $mode = $request->query->getString('mode');
        if (!in_array($mode, ['', 'copy'], true)) {
            return $this->errorResponse('Invalid audio presentation mode.');
        }
        if ($mode === 'copy') {
            $index = $request->query->getString('index');
            if ($request->query->getString('format') !== 'aac' || $request->query->getString('container') !== 'mp4'
                || $request->query->getString('bitrate') !== '' || !ctype_digit($index)
                || $metadata->streamIndex !== (int) $index || $metadata->codec !== 'aac' || $metadata->sourceHash === null) {
                return $this->errorResponse('The selected audio cannot be copied.', Response::HTTP_CONFLICT, ['reason' => 'source_stream_metadata_unavailable']);
            }
            try {
                $copy = $this->renditions->findCompleteCopy($id->toString(), $path, $metadata->sourceHash, $metadata->streamIndex)
                    ?? $this->renditions->openCopy($id->toString(), $path, $metadata->sourceHash, $metadata->streamIndex);
            } catch (AudioRenditionBusyException) {
                $response = $this->errorResponse('Every audio slot is busy. Try again shortly.', Response::HTTP_SERVICE_UNAVAILABLE, ['reason' => 'audio_slots_busy']);
                $response->headers->set('Retry-After', (string) AudioRenditionBusyException::RETRY_AFTER_SECONDS);
                return $response;
            } catch (AudioRenditionFailedException) {
                return $this->errorResponse('Audio copy failed.', Response::HTTP_CONFLICT, ['reason' => 'copy_failed']);
            }
            return $this->file((string) $copy->path, $copy->mimeType());
        }
        $format = $request->query->getString('format');
        if ($format !== '') {
            if ($this->executionPolicy->permits(MediaOperation::AudioEncode) && $this->settings->get(TranscodeSettingDefinitions::ENABLED) !== true) {
                return $this->forbidden('Audio transcoding is turned off on this server.');
            }
            $target = AudioRenditionFormat::tryFrom($format);
            $bitrate = $request->query->getString('bitrate');
            if ($target === null || !ctype_digit($bitrate) || (int) $bitrate < 1) {
                return $this->errorResponse('Invalid rendition format or bitrate.');
            }
            $cached = $this->renditions->findComplete($id->toString(), $path, $target, (int) $bitrate);
            return $cached === null ? $this->errorResponse('Negotiate delivery again.', 409, ['reason' => 'artifact_unavailable']) : $this->file((string) $cached->path, $target->mimeType());
        }
        return $this->file($path, $metadata->mimeType);
    }

    private function owner(Request $request): ?Uuid
    {
        if ($request->query->has('sig') || $request->query->has('exp') || $request->query->has('owner')) {
            if (!$this->validSignature($request)) {
                return null;
            }
            try {
                return Uuid::fromString($request->query->getString('owner'));
            } catch (\InvalidArgumentException) {
                return null;
            }
        }
        return $this->scopes->current()->getActorId();
    }

    private function validSignature(Request $request): bool
    {
        $query = $request->query->all();
        $signature = $query['sig'] ?? null;
        $expiry = $query['exp'] ?? null;
        if (!is_string($signature) || !is_string($expiry) || !ctype_digit($expiry) || filter_var($expiry, FILTER_VALIDATE_INT) === false) {
            return false;
        }
        unset($query['sig'], $query['exp']);
        return $this->signatures->validateUrl($request->getPathInfo() . '?' . http_build_query($query), $signature, (int) $expiry);
    }

    private function file(string $path, string $mime): Response
    {
        if (!is_file($path) || !is_readable($path)) {
            return $this->notFound('Playback media is unavailable.');
        }
        $response = new SwooleBinaryFileResponse($path);
        $response->headers->set('Content-Type', $mime);
        $response->headers->set('Cache-Control', 'private, no-store');
        return $response;
    }
}
