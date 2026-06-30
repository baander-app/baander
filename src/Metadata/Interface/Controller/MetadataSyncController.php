<?php

declare(strict_types=1);

namespace App\Metadata\Interface\Controller;

use App\Metadata\Domain\Model\ExtractedMetadata;
use App\Metadata\Infrastructure\Matching\MatchingStrategy;
use App\Metadata\Infrastructure\Reader\FormatDetector;
use App\Metadata\Infrastructure\Reader\Id3Reader;
use App\Metadata\Infrastructure\Reader\FlacReader;
use App\Metadata\Infrastructure\Reader\OggReader;
use App\Metadata\Interface\Request\ExtractMetadataRequest;
use App\Metadata\Interface\Request\MatchMetadataRequest;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\Shared\Interface\Controller\TranslatorTrait;
use OpenApi\Attributes as OA;
use Nelmio\ApiDocBundle\Attribute\Model;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

#[OA\Tag(name: 'Metadata', description: 'External metadata enrichment endpoints')]
#[Route('/api/metadata', name: 'metadata_')]
final class MetadataSyncController
{
    use ApiResponsesTrait;
    use TranslatorTrait;
    public function __construct(
        private readonly FormatDetector $formatDetector,
        private readonly Id3Reader $id3Reader,
        private readonly FlacReader $flacReader,
        private readonly OggReader $oggReader,
        private readonly MatchingStrategy $matchingStrategy,
    ) {
    }

    #[OA\Post(
        path: '/api/metadata/extract',
        summary: 'Extract embedded metadata from a local media file',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['path'],
                properties: [
                    new OA\Property(property: 'path', type: 'string'),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: '200', description: 'Extracted metadata from the file',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', properties: [
                            new OA\Property(property: 'format', description: 'Detected file format (mp3, m4a, flac, ogg)', type: 'string', example: 'flac'),
                            new OA\Property(property: 'metadata', properties: [
                                new OA\Property(property: 'title', type: 'string', nullable: true),
                                new OA\Property(property: 'artist', type: 'string', nullable: true),
                                new OA\Property(property: 'album', type: 'string', nullable: true),
                                new OA\Property(property: 'albumArtist', type: 'string', nullable: true),
                                new OA\Property(property: 'trackNumber', type: 'integer', nullable: true),
                                new OA\Property(property: 'discNumber', type: 'integer', nullable: true),
                                new OA\Property(property: 'year', type: 'integer', nullable: true),
                                new OA\Property(property: 'genre', type: 'string', nullable: true),
                                new OA\Property(property: 'composer', type: 'string', nullable: true),
                                new OA\Property(property: 'bpm', type: 'integer', nullable: true),
                                new OA\Property(property: 'duration', description: 'Duration in seconds', type: 'number', nullable: true),
                                new OA\Property(property: 'bitrate', description: 'Bitrate in kbps', type: 'integer', nullable: true),
                                new OA\Property(property: 'mbid', description: 'MusicBrainz recording ID', type: 'string', nullable: true),
                                new OA\Property(property: 'mbAlbumId', description: 'MusicBrainz release ID', type: 'string', nullable: true),
                                new OA\Property(property: 'mbArtistId', description: 'MusicBrainz artist ID', type: 'string', nullable: true),
                            ], type: 'object'),
                        ], type: 'object'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(response: '400', description: 'File not found or unsupported format', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '422', description: 'Validation error', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ValidationError::class))),
        ],
    )]
    #[Route('/extract', name: 'extract', methods: ['POST'])]
    public function extract(#[MapRequestPayload] ExtractMetadataRequest $payload): JsonResponse
    {
        if (!file_exists($payload->path)) {
            return $this->errorResponse($this->trans('errors.valid_path_required', domain: 'metadata'));
        }

        $format = $this->formatDetector->detect($payload->path);

        if ($format === null) {
            return $this->errorResponse($this->trans('errors.unsupported_format', domain: 'metadata'));
        }

        $metadata = match ($format) {
            'mp3', 'm4a' => $this->id3Reader->read($payload->path),
            'flac' => $this->flacReader->read($payload->path),
            'ogg' => $this->oggReader->read($payload->path),
            default => new ExtractedMetadata(),
        };

        return $this->successResponse([
            'format' => $format,
            'metadata' => [
                'title' => $metadata->getTitle(),
                'artist' => $metadata->getArtist(),
                'album' => $metadata->getAlbum(),
                'albumArtist' => $metadata->getAlbumArtist(),
                'trackNumber' => $metadata->getTrackNumber(),
                'discNumber' => $metadata->getDiscNumber(),
                'year' => $metadata->getYear(),
                'genre' => $metadata->getGenre(),
                'composer' => $metadata->getComposer(),
                'bpm' => $metadata->getBpm(),
                'duration' => $metadata->getDuration(),
                'bitrate' => $metadata->getBitrate(),
                'mbid' => $metadata->getMbid(),
                'mbAlbumId' => $metadata->getMbAlbumId(),
                'mbArtistId' => $metadata->getMbArtistId(),
            ],
        ]);
    }

    #[OA\Post(
        path: '/api/metadata/match',
        summary: 'Match extracted metadata against candidate sources',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['path', 'candidates'],
                properties: [
                    new OA\Property(property: 'path', type: 'string'),
                    new OA\Property(property: 'candidates', type: 'array', items: new OA\Items(type: 'object')),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: '200', description: 'Matched results ranked by confidence',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(properties: [
                            new OA\Property(property: 'source', description: 'Match source identifier', type: 'string'),
                            new OA\Property(property: 'sourceId', description: 'Source-specific ID', type: 'string'),
                            new OA\Property(property: 'confidence', description: 'Match confidence score', type: 'number', format: 'float'),
                            new OA\Property(property: 'data', properties: [
                                new OA\Property(property: 'title', type: 'string', nullable: true),
                                new OA\Property(property: 'artist', type: 'string', nullable: true),
                                new OA\Property(property: 'album', type: 'string', nullable: true),
                                new OA\Property(property: 'year', type: 'integer', nullable: true),
                                new OA\Property(property: 'mbid', type: 'string', nullable: true),
                            ], type: 'object'),
                        ], type: 'object')),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(response: '400', description: 'File not found or unsupported format', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '422', description: 'Validation error', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ValidationError::class))),
        ],
    )]
    #[Route('/match', name: 'match', methods: ['POST'])]
    public function match(#[MapRequestPayload] MatchMetadataRequest $payload): JsonResponse
    {
        if (!file_exists($payload->path)) {
            return $this->errorResponse($this->trans('errors.valid_path', domain: 'metadata'));
        }

        $format = $this->formatDetector->detect($payload->path);

        if ($format === null) {
            return $this->errorResponse($this->trans('errors.unsupported_format', domain: 'metadata'));
        }

        $metadata = match ($format) {
            'mp3', 'm4a' => $this->id3Reader->read($payload->path),
            'flac' => $this->flacReader->read($payload->path),
            'ogg' => $this->oggReader->read($payload->path),
            default => new ExtractedMetadata(),
        };

        $matches = $this->matchingStrategy->match($metadata, $payload->candidates);

        $matchData = array_map(
            static fn (\App\Metadata\Domain\Model\MetadataMatch $m) => [
                'source' => $m->getSource(),
                'sourceId' => $m->getSourceId(),
                'confidence' => $m->getConfidence(),
                'data' => [
                    'title' => $m->getData()->getTitle(),
                    'artist' => $m->getData()->getArtist(),
                    'album' => $m->getData()->getAlbum(),
                    'year' => $m->getData()->getYear(),
                    'mbid' => $m->getData()->getMbid(),
                ],
            ],
            $matches,
        );

        return $this->successResponse($matchData);
    }
}
