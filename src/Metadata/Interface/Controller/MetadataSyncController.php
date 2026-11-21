<?php

declare(strict_types=1);

namespace App\Metadata\Interface\Controller;

use App\Metadata\Application\Port\MediaFileMetadataInterface;
use App\Metadata\Interface\Request\ExtractMetadataRequest;
use App\Metadata\Interface\Request\MatchMetadataRequest;
use App\Shared\Interface\Attribute\CliCounterpart;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\Shared\Interface\DTO\ValidationError;
use OpenApi\Spec as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Reads the tags of a file on the server and ranks candidate records against them.
 *
 * Both routes open any path the server can read, so only administrators reach them.
 */
#[OA\Tag(name: 'Metadata', description: 'External metadata enrichment endpoints')]
#[Route('/api/metadata', name: 'metadata_')]
final class MetadataSyncController
{
    use ApiResponsesTrait;

    public function __construct(
        private readonly MediaFileMetadataInterface $files,
    ) {
    }

    #[OA\Operation\Post(
        path: '/api/metadata/extract',
        summary: 'Extract embedded metadata from a local media file',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType\Json(
                schema: new OA\Schema(
                    properties: [
                        'path' => new OA\Schema(type: 'string'),
                    ],
                    required: ['path'],
                ),
            ),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'Extracted metadata from the file',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(
                                type: 'object',
                                properties: [
                                    'format' => new OA\Schema(
                                        description: 'Detected file format (mp3, m4a, flac, ogg, opus, wav, wma, aiff or aac)',
                                        type: 'string',
                                        example: 'flac',
                                    ),
                                    'metadata' => new OA\Schema(
                                        type: 'object',
                                        properties: [
                                            'title' => new OA\Schema(type: 'string', nullable: true),
                                            'artist' => new OA\Schema(type: 'string', nullable: true),
                                            'album' => new OA\Schema(type: 'string', nullable: true),
                                            'albumArtist' => new OA\Schema(type: 'string', nullable: true),
                                            'trackNumber' => new OA\Schema(type: 'integer', nullable: true),
                                            'discNumber' => new OA\Schema(type: 'integer', nullable: true),
                                            'year' => new OA\Schema(type: 'integer', nullable: true),
                                            'genre' => new OA\Schema(
                                                description: 'Genre values in tag order',
                                                type: 'array',
                                                items: new OA\Schema(type: 'string'),
                                            ),
                                            'composer' => new OA\Schema(type: 'string', nullable: true),
                                            'bpm' => new OA\Schema(type: 'integer', nullable: true),
                                            'duration' => new OA\Schema(
                                                description: 'Duration in seconds',
                                                type: 'number',
                                                nullable: true,
                                            ),
                                            'bitrate' => new OA\Schema(
                                                description: 'Bitrate in kbps',
                                                type: 'integer',
                                                nullable: true,
                                            ),
                                            'mbid' => new OA\Schema(
                                                description: 'MusicBrainz recording ID',
                                                type: 'string',
                                                nullable: true,
                                            ),
                                            'mbAlbumId' => new OA\Schema(
                                                description: 'MusicBrainz release ID',
                                                type: 'string',
                                                nullable: true,
                                            ),
                                            'mbArtistId' => new OA\Schema(
                                                description: 'MusicBrainz artist ID',
                                                type: 'string',
                                                nullable: true,
                                            ),
                                        ],
                                    ),
                                ],
                            ),
                        ],
                    ),
                ),
            ),
            new OA\Response(response: '401', description: 'Authentication required'),
            new OA\Response(response: '403', description: 'Administrator role required'),
            new OA\Response(
                response: '422',
                description: 'Invalid request, or no file exists at the path, or the file is in a format the tag readers do not support',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ValidationError::class),
                ),
            ),
        ],
    )]
    #[Route('/extract', name: 'extract', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    #[CliCounterpart('app:metadata:file:extract')]
    public function extract(#[MapRequestPayload] ExtractMetadataRequest $payload): JsonResponse
    {
        return $this->files->extract($payload->path) |> $this->successResponse(...);
    }

    #[OA\Operation\Post(
        path: '/api/metadata/match',
        summary: 'Match extracted metadata against candidate sources',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType\Json(
                schema: new OA\Schema(
                    properties: [
                        'path' => new OA\Schema(type: 'string'),
                        'candidates' => new OA\Schema(
                            type: 'array',
                            items: new OA\Schema(type: 'object'),
                        ),
                    ],
                    required: ['path', 'candidates'],
                ),
            ),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'Matched results ranked by confidence',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(
                                type: 'array',
                                items: new OA\Schema(
                                    type: 'object',
                                    properties: [
                                        'source' => new OA\Schema(
                                            description: 'Match source identifier when supplied by the candidate',
                                            type: 'string',
                                            nullable: true,
                                        ),
                                        'sourceId' => new OA\Schema(
                                            description: 'Source-specific ID when supplied by the candidate',
                                            type: 'string',
                                            nullable: true,
                                        ),
                                        'confidence' => new OA\Schema(
                                            description: 'Match confidence score',
                                            type: 'number',
                                            format: 'float',
                                        ),
                                        'data' => new OA\Schema(
                                            type: 'object',
                                            properties: [
                                                'title' => new OA\Schema(type: 'string', nullable: true),
                                                'artist' => new OA\Schema(type: 'string', nullable: true),
                                                'album' => new OA\Schema(type: 'string', nullable: true),
                                                'year' => new OA\Schema(type: 'integer', nullable: true),
                                                'mbid' => new OA\Schema(type: 'string', nullable: true),
                                            ],
                                        ),
                                    ],
                                ),
                            ),
                        ],
                    ),
                ),
            ),
            new OA\Response(response: '401', description: 'Authentication required'),
            new OA\Response(response: '403', description: 'Administrator role required'),
            new OA\Response(
                response: '422',
                description: 'Invalid request, or no file exists at the path, or the file is in a format the tag readers do not support',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ValidationError::class),
                ),
            ),
        ],
    )]
    #[Route('/match', name: 'match', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    #[CliCounterpart('app:metadata:file:match')]
    public function match(#[MapRequestPayload] MatchMetadataRequest $payload): JsonResponse
    {
        return $this->files->match($payload->path, $payload->candidates) |> $this->successResponse(...);
    }
}
