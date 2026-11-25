<?php

declare(strict_types=1);

namespace App\UserPreference\Interface\Controller;

use App\Auth\Infrastructure\Security\SecurityUser;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\Shared\Interface\DTO\ApiError;
use App\Shared\Interface\DTO\ValidationError;
use App\UserPreference\Application\Port\SidebarConfigPortInterface;
use App\UserPreference\Interface\Request\UpdateSidebarSectionsRequest;
use OpenApi\Spec as OA;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[AsController]
#[OA\Tag(name: 'User Preferences', description: 'User preference management endpoints')]
#[Route('/api/user/sidebar-config', name: 'sidebar_config_')]
final class SidebarConfigController
{
    use ApiResponsesTrait;

    private const VALID_MEDIA_TYPES = ['music', 'movies', 'tv', 'podcasts', 'concerts', 'ebooks'];

    public function __construct(
        private readonly SidebarConfigPortInterface $sidebarConfigPort,
        private readonly Security $security,
    ) {
    }

    #[OA\Operation\Get(
        path: '/api/user/sidebar-config/{mediaType}',
        summary: 'Get sidebar configuration for a media type',
        description: 'Returns the sidebar configuration for the authenticated user and given media type. Returns defaults if no custom config exists.',
        responses: [
            new OA\Response(
                response: '200',
                description: 'Sidebar configuration',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(
                                type: 'object',
                                properties: [
                                    'mediaType' => new OA\Schema(type: 'string'),
                                    'sections' => new OA\Schema(
                                        type: 'array',
                                        items: new OA\Schema(
                                            type: 'object',
                                            properties: [
                                                'id' => new OA\Schema(type: 'string'),
                                                'label' => new OA\Schema(type: 'string'),
                                                'type' => new OA\Schema(type: 'string'),
                                                'items' => new OA\Schema(
                                                    type: 'array',
                                                    items: new OA\Schema(
                                                        type: 'object',
                                                        properties: [
                                                            'id' => new OA\Schema(type: 'string'),
                                                            'type' => new OA\Schema(type: 'string'),
                                                            'label' => new OA\Schema(type: 'string'),
                                                            'icon' => new OA\Schema(type: 'string'),
                                                            'config' => new OA\Schema(type: 'object'),
                                                        ],
                                                    ),
                                                ),
                                            ],
                                        ),
                                    ),
                                    'updatedAt' => new OA\Schema(type: 'string', format: 'date-time'),
                                ],
                            ),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '401',
                description: 'Not authenticated',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '422',
                description: 'Invalid media type',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ValidationError::class),
                ),
            ),
        ],
    )]
    #[Route('/{mediaType}', name: 'get', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function getConfig(string $mediaType): JsonResponse
    {
        $error = $this->validateMediaType($mediaType);
        if ($error !== null) {
            return $error;
        }

        /** @var SecurityUser $user */
        $user = $this->security->getUser();
        $userId = Uuid::fromString($user->getId());

        $config = $this->sidebarConfigPort->getConfigOrDefault($userId, $mediaType);

        return $this->successResponse($this->buildSectionsResponse($config, $mediaType));
    }

    #[OA\Operation\Put(
        path: '/api/user/sidebar-config/{mediaType}',
        summary: 'Update sidebar configuration for a media type',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(ref: UpdateSidebarSectionsRequest::class),
            ),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'Configuration updated',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(
                                type: 'object',
                                properties: [
                                    'mediaType' => new OA\Schema(type: 'string'),
                                    'sections' => new OA\Schema(
                                        type: 'array',
                                        items: new OA\Schema(
                                            type: 'object',
                                            properties: [
                                                'id' => new OA\Schema(type: 'string'),
                                                'label' => new OA\Schema(type: 'string'),
                                                'type' => new OA\Schema(type: 'string'),
                                                'items' => new OA\Schema(
                                                    type: 'array',
                                                    items: new OA\Schema(
                                                        type: 'object',
                                                        properties: [
                                                            'id' => new OA\Schema(type: 'string'),
                                                            'type' => new OA\Schema(type: 'string'),
                                                            'label' => new OA\Schema(type: 'string'),
                                                            'icon' => new OA\Schema(type: 'string'),
                                                            'config' => new OA\Schema(type: 'object'),
                                                        ],
                                                    ),
                                                ),
                                            ],
                                        ),
                                    ),
                                    'updatedAt' => new OA\Schema(type: 'string', format: 'date-time'),
                                ],
                            ),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '400',
                description: 'The body is not a JSON object',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '401',
                description: 'Not authenticated',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '422',
                description: 'Invalid input',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ValidationError::class),
                ),
            ),
        ],
    )]
    #[Route('/{mediaType}', name: 'update', methods: ['PUT'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function update(string $mediaType, #[MapRequestPayload] UpdateSidebarSectionsRequest $dto): JsonResponse
    {
        $error = $this->validateMediaType($mediaType);
        if ($error !== null) {
            return $error;
        }

        /** @var SecurityUser $user */
        $user = $this->security->getUser();
        $userId = Uuid::fromString($user->getId());

        $config = $this->sidebarConfigPort->updateConfig($userId, $mediaType, $dto->sections);

        return $this->successResponse($this->buildSectionsResponse($config, $mediaType));
    }

    #[OA\Operation\Delete(
        path: '/api/user/sidebar-config/{mediaType}',
        summary: 'Reset sidebar configuration to defaults for a media type',
        responses: [
            new OA\Response(response: '204', description: 'Configuration reset'),
            new OA\Response(
                response: '401',
                description: 'Not authenticated',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '422',
                description: 'Invalid media type',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ValidationError::class),
                ),
            ),
        ],
    )]
    #[Route('/{mediaType}', name: 'delete', methods: ['DELETE'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function reset(string $mediaType): JsonResponse
    {
        $error = $this->validateMediaType($mediaType);
        if ($error !== null) {
            return $error;
        }

        /** @var SecurityUser $user */
        $user = $this->security->getUser();
        $userId = Uuid::fromString($user->getId());

        $this->sidebarConfigPort->deleteConfig($userId, $mediaType);

        // Return fresh defaults after reset
        $config = $this->sidebarConfigPort->getConfigOrDefault($userId, $mediaType);

        return $this->successResponse($this->buildSectionsResponse($config, $mediaType));
    }

    private function validateMediaType(string $mediaType): ?JsonResponse
    {
        if (!in_array($mediaType, self::VALID_MEDIA_TYPES, true)) {
            return $this->errorResponse(
                sprintf('Invalid media type "%s". Must be one of: %s', $mediaType, implode(', ', self::VALID_MEDIA_TYPES)),
                422,
            );
        }

        return null;
    }

    /**
     * Build the MediaSidebarSchema-compatible response shape.
     * Flattened items are regrouped into sections using the item ID prefix convention.
     * @return array{mediaType: string, sections: list<array{id: string, label: string, type: string, items: list<array<string, mixed>>}>, updatedAt: string}
     */
    private function buildSectionsResponse(object $config, string $mediaType): array
    {
        $items = array_map(
            fn (object $item) => $item->toArray(),
            $config->getItems(),
        );

        return [
            'mediaType' => $mediaType,
            'sections' => $this->groupItemsIntoSections($items, $mediaType),
            'updatedAt' => $config->getState()->updatedAt->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * Group flat items into sections based on ID prefix.
     * Items like "music-home", "music-browse" → section "music-quick-jump".
     * @param array<array-key, array<string, mixed>> $items
     * @return list<array{id: string, label: string, type: string, items: list<array<string, mixed>>}>
     */
    private function groupItemsIntoSections(array $items, string $mediaType): array
    {
        // If items were saved with sections, they're already structured
        // For flat items from defaults, group by convention
        $sections = [];
        $currentSection = null;
        $currentSectionItems = [];

        foreach ($items as $item) {
            $id = $item['id'] ?? '';
            $section = $this->inferSection($id, $mediaType);

            if ($currentSection === null) {
                $currentSection = $section;
            }

            if ($section !== $currentSection) {
                if ($currentSectionItems !== []) {
                    $sections[] = $this->makeSection($currentSection, $currentSectionItems);
                }
                $currentSection = $section;
                $currentSectionItems = [];
            }

            $currentSectionItems[] = $item;
        }

        if ($currentSectionItems !== []) {
            $sections[] = $this->makeSection($currentSection, $currentSectionItems);
        }

        return $sections;
    }

    private function inferSection(string $itemId, string $mediaType): string
    {
        // Convention: item IDs like "music-home" map to sections like "music-quick-jump"
        // Use a simple mapping based on known patterns
        return match (true) {
            str_ends_with($itemId, '-home') || str_ends_with($itemId, '-browse') => "{$mediaType}-quick-jump",
            str_ends_with($itemId, '-items') || str_ends_with($itemId, '-albums') || str_ends_with($itemId, '-artists') || str_ends_with($itemId, '-songs') || str_ends_with($itemId, '-genres') || str_ends_with($itemId, '-directors') || str_ends_with($itemId, '-shows') || str_ends_with($itemId, '-networks') || str_ends_with($itemId, '-episodes') || str_ends_with($itemId, '-venues') || str_ends_with($itemId, '-authors') || str_ends_with($itemId, '-series') || str_ends_with($itemId, '-books') => "{$mediaType}-library",
            str_ends_with($itemId, '-playlists') || str_ends_with($itemId, '-favorites') || str_ends_with($itemId, '-watchlists') || str_ends_with($itemId, '-subscriptions') || str_ends_with($itemId, '-shelves') || str_ends_with($itemId, '-reading-lists') => "{$mediaType}-collections",
            default => "{$mediaType}-discover",
        };
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return array{id: string, label: string, type: string, items: list<array<string, mixed>>}
     */
    private function makeSection(string $sectionId, array $items): array
    {
        $label = $this->sectionLabel($sectionId);

        return [
            'id' => $sectionId,
            'label' => $label,
            'type' => 'navigation',
            'items' => $items,
        ];
    }

    private function sectionLabel(string $sectionId): string
    {
        return match (true) {
            str_ends_with($sectionId, '-quick-jump') => 'Quick Jump',
            str_ends_with($sectionId, '-library') => 'Library',
            str_ends_with($sectionId, '-collections') => 'Collections',
            str_ends_with($sectionId, '-discover') => 'Discover',
            default => 'Navigation',
        };
    }
}
