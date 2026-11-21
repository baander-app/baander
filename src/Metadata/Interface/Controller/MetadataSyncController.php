<?php

declare(strict_types=1);

namespace App\Metadata\Interface\Controller;

use App\Metadata\Application\Port\MediaFileMetadataInterface;
use App\Metadata\Interface\Request\ExtractMetadataRequest;
use App\Metadata\Interface\Request\MatchMetadataRequest;
use App\Shared\Interface\Attribute\CliCounterpart;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\Shared\Interface\DTO\ValidationError;
use OpenApi\Attributes as OA;
use Nelmio\ApiDocBundle\Attribute\Model;
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
                            new OA\Property(property: 'format', description: 'Detected file format (mp3, m4a, flac, ogg, opus, wav, wma, aiff or aac)', type: 'string', example: 'flac'),
                            new OA\Property(property: 'metadata', properties: [
                                new OA\Property(property: 'title', type: 'string', nullable: true),
                                new OA\Property(property: 'artist', type: 'string', nullable: true),
                                new OA\Property(property: 'album', type: 'string', nullable: true),
                                new OA\Property(property: 'albumArtist', type: 'string', nullable: true),
                                new OA\Property(property: 'trackNumber', type: 'integer', nullable: true),
                                new OA\Property(property: 'discNumber', type: 'integer', nullable: true),
                                new OA\Property(property: 'year', type: 'integer', nullable: true),
                                new OA\Property(property: 'genre', description: 'Genre values in tag order', type: 'array', items: new OA\Items(type: 'string')),
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
            new OA\Response(response: '401', description: 'Authentication required'),
            new OA\Response(response: '403', description: 'Administrator role required'),
            new OA\Response(
                response: '422',
                description: 'Invalid request, or no file exists at the path, or the file is in a format the tag readers do not support',
                content: new OA\JsonContent(ref: new Model(type: ValidationError::class)),
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
                            new OA\Property(property: 'source', description: 'Match source identifier when supplied by the candidate', type: 'string', nullable: true),
                            new OA\Property(property: 'sourceId', description: 'Source-specific ID when supplied by the candidate', type: 'string', nullable: true),
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
            new OA\Response(response: '401', description: 'Authentication required'),
            new OA\Response(response: '403', description: 'Administrator role required'),
            new OA\Response(
                response: '422',
                description: 'Invalid request, or no file exists at the path, or the file is in a format the tag readers do not support',
                content: new OA\JsonContent(ref: new Model(type: ValidationError::class)),
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
