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
use App\Transcode\Application\Port\StreamAuthPortInterface;
use App\Transcode\Application\Port\CompletedVideoDeliveryPort;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
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
    ) {
    }

    #[OA\Post(
        path: '/api/stream/delivery',
        summary: 'Choose compatible delivery for the destination before loading media',
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: DeliveryRequest::class))),
        responses: [new OA\Response(response: '200', description: 'Delivery choice, or a terminal compatibility reason', content: new OA\JsonContent(ref: new Model(type: DeliveryResource::class))),
            new OA\Response(response: '400', description: 'Invalid capabilities or media identifier'), new OA\Response(response: '401', description: 'Authentication required')]
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
            $decision = $decision->withUrl($this->signing->signUrl($path . '?' . http_build_query(['id' => $id->toString(), 'owner' => $owner->toString()]))['url']);
        }
        return $this->json(DeliveryResource::from($decision), headers: ['Cache-Control' => 'private, no-store']);
    }
}
