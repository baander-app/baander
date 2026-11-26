<?php

declare(strict_types=1);

namespace App\Media\Interface\Controller;

use App\Media\Application\DTO\DeliveryDestination;
use App\Media\Application\DTO\DeliveryKind;
use App\Media\Application\Port\DeliveryDecisionPortInterface;
use App\Media\Application\Port\MediaReadScopeProviderInterface;
use App\Media\Interface\Request\DeliveryRequest;
use App\Media\Interface\Resource\DeliveryResource;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\Shared\Interface\DTO\ApiError;
use App\Transcode\Application\Port\StreamAuthPortInterface;
use App\Transcode\Application\Port\CompletedVideoDeliveryPort;
use OpenApi\Spec as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[OA\Tag(name: 'Media', description: 'Media file and streaming endpoints')]
#[IsGranted('ROLE_USER')]
final readonly class DeliveryController
{
    use ApiResponsesTrait;

    public function __construct(
        private DeliveryDecisionPortInterface $delivery,
        private MediaReadScopeProviderInterface $scopes,
        private StreamAuthPortInterface $signing,
        private CompletedVideoDeliveryPort $completed,
    ) {}

    #[OA\Operation\Post(
        path: '/api/stream/delivery',
        summary: 'Choose compatible delivery for the destination before loading media',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType\Json(
                schema: new OA\Schema(ref: DeliveryRequest::class),
            ),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'Delivery choice, or a terminal compatibility reason',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: DeliveryResource::class),
                ),
            ),
            new OA\Response(response: '400', description: 'Invalid capabilities or media identifier'),
            new OA\Response(response: '401', description: 'Authentication required'),
            new OA\Response(
                response: '409',
                description: 'Playback cannot start: the video file no longer resolves inside one of its libraries, the track file or a symlink on its path leads outside its library (`error.details.reason` is `media_unavailable`), or the stream copy the destination needs would leave the account holding more transcode sessions than the server allows and too few have been silent for two minutes to give way (`error.details.reason` is `transcode_session_limit` and `error.details.limit` the limit)',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '503',
                description: 'The stream copy did not start in time (with `Retry-After`), or the completed media chosen for the destination is unavailable',
                headers: [
                    new OA\Header(
                        header: 'Retry-After',
                        schema: new OA\Schema(type: 'integer', example: 2),
                    ),
                ],
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/api/stream/delivery', name: 'stream_delivery', methods: ['POST'])]
    public function decide(#[MapRequestPayload] DeliveryRequest $request): JsonResponse
    {
        $owner = $this->scopes->current()->getActorId();
        if ($owner === null) {
            return $this->unauthorized();
        }
        try {
            $requestId = $request->kind === 'video'
                ? Uuid::fromString($request->requestId ?? '')->toString()
                : null;
            $id = PublicId::fromString($request->id);
            $destination = new DeliveryDestination(
                $request->containers,
                $request->videoCodecs,
                $request->audioCodecs,
                $request->maxWidth,
                $request->maxHeight,
                $request->maxAudioChannels,
                $request->audioStreamIndex,
                $request->subtitleBurnIn,
                $request->transports,
                $request->maxBitDepth,
                $request->supportsHdr,
                $request->pixelFormats,
                $request->sourcePlaybackFailed,
                $request->audioOffsetMs,
                $request->qualityTier,
            );
        } catch (\InvalidArgumentException $error) {
            return $this->errorResponse($error->getMessage());
        }
        $decision = $requestId !== null
            ? $this->delivery->video($owner, $id, $destination, $requestId)->withRequestId($requestId)
            : $this->delivery->track($owner, $id, $destination);
        if (
            $decision->url !== null
            && ($decision->kind === DeliveryKind::Cached
                || ($request->kind === 'track' && $decision->kind === DeliveryKind::Copy))

        ) {
            $signed = $this->signing->signUrl($decision->url);
            if ($request->kind === 'video') {
                $artifact = $decision->artifact ?? throw new \LogicException('Completed video has no artifact identity.');
                if (!$this->completed->grant($artifact->jobId, $artifact->generation, $signed['exp'])) {
                    return $this->errorResponse('Completed media is unavailable.', 503);
                }
            }
            $decision = $decision->withUrl($signed['url']);
        }
        if ($decision->kind === DeliveryKind::Original) {
            $path = $request->kind === 'video' ? '/api/stream/video' : '/api/stream/original/track';
            $decision = $decision->withUrl($this->signing->signUrl($path . '?' . http_build_query([
                'id' => $id->toString(),
                'owner' => $owner->toString(),
            ]))['url']);
        }
        return $this->json(DeliveryResource::from($decision), headers: ['Cache-Control' => 'private, no-store']);
    }
}
