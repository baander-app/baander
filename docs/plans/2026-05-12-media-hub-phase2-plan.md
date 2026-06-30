# Media Hub Phase 2 — Implementation Plan

**Date:** 2026-05-12
**Supersedes:** Out-of-scope items from `docs/plans/2026-05-12-media-hub-sidebar-plan.md`
**Status:** Ready for implementation

---

## Scope

Three features deferred from Phase 1:

1. **Backend API for `/api/user/recent`** — sidebar Recent section + home page recently played cards need a dedicated endpoint
2. **Media type Home page content** — replace `MediaPlaceholder` stubs with real content pages
3. **Thumbnail loading via `useImageBlob`** — wire the existing shared hook into Recent section thumbnails

---

## Current State

### What exists

| Layer | What's there |
|-------|-------------|
| **Backend: Activity context** | `ActivityController` at `/api/activity/*` with `history`, `play`, `love`, `loved` endpoints. `ActivityPortInterface::getRecentlyPlayed()` returns `MediaActivity[]` ordered by `lastPlayedAt` DESC. Private `enrichActivities()` batch-resolves song titles, album covers, artist names via `SongPortInterface`, `AlbumPortInterface`, `ImagePortInterface`. |
| **Backend: MediaActivity model** | Has `songId`, `albumId`, `artistId`, `movieId` (all nullable Uuid), `playCount`, `love`, `lastPlayedAt`, `lastPlatform`, `lastPlayer`. Activity types: `play`, `love`. Reconstituted via `MediaActivity::reconstitute()`. |
| **Backend: MediaActivityEntity** | Doctrine entity with ManyToOne to `SongEntity`, `AlbumEntity`, `ArtistEntity`, `MovieEntity`. All nullable with `onDelete: 'SET NULL'`. |
| **Backend: ActivityResource** | `from()` returns raw fields (uuid, publicId, userId, activityType, songId, albumId, artistId, movieId, playCount, love, lastPlayedAt, lastPlatform, lastPlayer, createdAt). `fromWithDetails()` enriches with `songTitle`, `songPublicId`, `albumTitle`, `albumPublicId`, `coverImage: { url, blurhash }`, `artistName`. Both static methods. |
| **Backend: MoviePortInterface** | Exists at `src/Catalog/Application/Port/MoviePortInterface.php`. Methods: `findByPublicId`, `findByUuid`, `search`, `count`, `save`, `delete`. Wired to `MovieService`. |
| **Frontend: useImageBlob hook** | Shared hook at `ui/web/src/shared/hooks/use-image-blob.ts`. Returns `{ src: string|null, isLoading: boolean }`. Uses `AbortController`, blob URL revocation. |
| **Frontend: SidebarRecentItems** | Component at `ui/web/src/features/layout/components/SidebarRecentItems.tsx`. Exports `RecentItem` interface: `{ id, title, subtitle, timestamp, thumbnailUrl }`. Renders 32px thumbnails via raw `<img src>`. Shows "Nothing played yet" empty state. Currently receives `items={[]}`. |
| **Frontend: SidebarContent** | At `ui/web/src/features/layout/components/SidebarContent.tsx`. Renders navigation sections + `<SidebarRecentItems items={recentItems} />` at bottom. Accepts `recentItems` as optional prop (defaults to `[]`). |
| **Frontend: HomePage** | `ui/web/src/features/catalog/pages/HomePage.tsx`. Music-only. Uses `useGetActivityHistory({ limit: 10 })` for "Recently Played" section. Has an **inline** `useImageBlob` copy (not the shared hook). `RecentlyPlayedCard` uses `{ src } = useImageBlob(...)` from the inline copy. |
| **Frontend: Routes** | Media-prefixed routes exist. `/movies`, `/tv`, `/podcasts`, `/concerts`, `/ebooks` all render inline `MediaPlaceholder` (defined in routes.tsx). |
| **Frontend: Generated API client** | Orval-generated at `ui/web/src/shared/api-client/gen/endpoints/index.ts`. Produces `useGetActivityHistory` with tanstack-query integration. No `/api/user/recent` client yet. |
| **Frontend: Feature dirs** | No `features/movies`, `features/tv`, `features/podcasts`, `features/concerts`, or `features/ebooks` directories exist yet. |
| **Tests: Backend** | `tests/Functional/Controller/ActivityControllerTest.php` extends `tests/Functional/TestCase`. Uses `$this->createTestUser()`, `$this->authenticatedRequest()`, `$this->anonymousRequest()`, `$this->assertJsonResponse()`. Auth via `HTTP_X_Test_User_Id` header. |
| **Tests: Frontend** | Frontend tests use vitest + @testing-library/react. |

### Key constraints

- Activity domain already tracks movies via `movieId` on `MediaActivity`. No schema change needed.
- `enrichActivities` is a **private method** on `ActivityController`. Must be extracted to share with `UserRecentController`.
- `useImageBlob` fetches images via blob for authenticated access. Direct `<img src>` won't work for protected images — the current `SidebarRecentItems` is broken for auth-protected images.
- Controllers are plain classes with `ApiResponsesTrait` + `TranslatorTrait`. Never extend `AbstractController`.
- Port pattern: controller depends on port interfaces, not infrastructure directly.
- Services.yaml wires ports via `alias:` (e.g., `ActivityPortInterface: alias: ActivityService`).
- Generated API client (orval) means: either add OpenAPI annotations and regenerate, or write a hand-rolled hook. The endpoint won't exist in the generated client until the spec is regenerated.
- `SidebarContent` currently accepts `recentItems` as a prop from its parent (`Sidebar.tsx` passes nothing — defaults to `[]`). The hook needs to move into `SidebarContent` itself.

---

## Implementation Units

### Unit 1: Backend — Extract `ActivityEnrichmentService`

**Goal:** Extract the `enrichActivities` private method from `ActivityController` into a dedicated service so both `ActivityController` and the new `UserRecentController` can share it.

**Files:**
- `src/Activity/Infrastructure/ActivityEnrichmentService.php` (new)
- `src/Activity/Interface/Controller/ActivityController.php` (modify — inject and delegate)
- `config/services.yaml` (no change needed — autoconfigured)

**Rationale:** The enrichment logic (batch-resolve songs, albums, images, artists) is 50+ lines of non-trivial code. Duplicating it in a second controller is unmaintainable. Extracting to a service follows the existing port/service pattern.

**RED:**
```php
// tests/Unit/Activity/Infrastructure/ActivityEnrichmentServiceTest.php

class ActivityEnrichmentServiceTest extends TestCase
{
    public function testEnrichEmptyArrayReturnsEmptyArray(): void
    {
        $service = new ActivityEnrichmentService(
            $this->createMock(SongPortInterface::class),
            $this->createMock(AlbumPortInterface::class),
            $this->createMock(ImagePortInterface::class),
        );
        $result = $service->enrich([], 'http://localhost');
        $this->assertSame([], $result);
    }

    public function testEnrichResolvesSongTitle(): void
    {
        // Create a MediaActivity with songId
        // Mock SongPortInterface::findByUuids to return song with title
        // Assert enriched item has songTitle
    }

    public function testEnrichResolvesAlbumCoverImage(): void
    {
        // Create activity with albumId
        // Mock AlbumPortInterface::findByUuids + getArtistNamesForAlbums
        // Mock ImagePortInterface::findByUuids
        // Assert enriched item has coverImage.url with baseUrl prefix
    }

    public function testEnrichResolvesArtistNameFromAlbum(): void
    {
        // Verify artistName comes from getArtistNamesForAlbums
    }
}
```

**GREEN:**

```php
<?php
// src/Activity/Infrastructure/ActivityEnrichmentService.php

declare(strict_types=1);

namespace App\Activity\Infrastructure;

use App\Activity\Domain\Model\MediaActivity;
use App\Activity\Interface\Resource\ActivityResource;
use App\Catalog\Application\Port\AlbumPortInterface;
use App\Catalog\Application\Port\SongPortInterface;
use App\Media\Application\Port\ImagePortInterface;

final class ActivityEnrichmentService
{
    public function __construct(
        private readonly SongPortInterface $songService,
        private readonly AlbumPortInterface $albumService,
        private readonly ImagePortInterface $imageService,
    ) {}

    /**
     * @param MediaActivity[] $activities
     * @return array<int, array<string, mixed>>
     */
    public function enrich(array $activities, string $baseUrl): array
    {
        // Move the exact logic from ActivityController::enrichActivities here
        // Batch-collect songIds, albumIds
        // Batch-resolve songs, albums, images, artists
        // Return array_map using ActivityResource::fromWithDetails(...)
    }
}
```

Then modify `ActivityController` to inject and delegate:
```php
// In ActivityController constructor, add:
private readonly ActivityEnrichmentService $enrichmentService,

// Replace $this->enrichActivities(...) with:
$this->enrichmentService->enrich(...)
```

Remove the private `enrichActivities` method from `ActivityController`.

**REFACTOR:** Verify existing `ActivityControllerTest` still passes after extraction. No behavioral change.

**Verification:**
```
make phpunit ARGS='tests/Unit/Activity/Infrastructure/ActivityEnrichmentServiceTest.php'
make phpunit ARGS='tests/Functional/Controller/ActivityControllerTest.php'
```

---

### Unit 2: Backend — `/api/user/recent` endpoint

**Goal:** Add `GET /api/user/recent` that returns recently played items enriched with thumbnails, optimized for the sidebar and home page widgets.

**Files:**
- `src/Activity/Interface/Controller/UserRecentController.php` (new)
- `src/Activity/Interface/Resource/RecentItemResource.php` (new)
- `tests/Functional/Controller/UserRecentControllerTest.php` (new)

**Dependencies:** Unit 1 (uses `ActivityEnrichmentService`).

**Why separate controller:** `ActivityController` manages full activity CRUD (history log, play recording, love toggle). `/api/user/recent` is a different consumer — compact sidebar format, no pagination, media-type filtering. Separate controller avoids bloating the existing one.

**Endpoint spec:**

```
GET /api/user/recent?limit=5&mediaType=music

Response 200:
{
  "data": [
    {
      "publicId": "abc123",
      "activityType": "play",
      "songTitle": "Paranoid Android",
      "songPublicId": "def456",
      "albumTitle": "OK Computer",
      "albumPublicId": "ghi789",
      "artistName": "Radiohead",
      "coverImage": {
        "url": "http://localhost/api/images/img123/file",
        "blurhash": "LEHV6nWB2yk8pyo0adR*.7kCMdnj"
      },
      "lastPlayedAt": "2026-05-12T10:30:00+00:00",
      "playCount": 42
    }
  ]
}
```

Parameters:
- `limit` (int, 1-20, default 5)
- `mediaType` (string, optional) — `music`/`movies`/`tv`/`podcasts`/`concerts`/`ebooks`. When absent, returns all types.

**Media type filtering logic** (applied in PHP after fetching from repo):
- `music` → `activity.getSongId() !== null || activity.getAlbumId() !== null`
- `movies` → `activity.getMovieId() !== null`
- `tv`, `podcasts`, `concerts`, `ebooks` → empty array (no backend tracking for these types yet)

**RED:**
```php
<?php
// tests/Functional/Controller/UserRecentControllerTest.php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Shared\Domain\Model\Uuid;
use App\Tests\Functional\TestCase;

final class UserRecentControllerTest extends TestCase
{
    public function testRecentRequiresAuthentication(): void
    {
        $response = $this->anonymousRequest('GET', '/api/user/recent');
        $this->assertJsonResponse($response, 401);
    }

    public function testRecentReturnsEmptyArrayWhenNoActivity(): void
    {
        $user = $this->createTestUser();
        $response = $this->authenticatedRequest('GET', '/api/user/recent', $user);
        $data = $this->assertJsonResponse($response, 200);
        $this->assertSame([], $data['data']);
    }

    public function testRecentReturnsEnrichedItems(): void
    {
        $user = $this->createTestUser();

        // Record a play first
        $this->authenticatedRequest('POST', '/api/activity/play', $user, [
            'songId' => Uuid::generate()->toString(),
        ]);

        $response = $this->authenticatedRequest('GET', '/api/user/recent', $user);
        $data = $this->assertJsonResponse($response, 200);

        // Should have at least one item with enriched fields
        $this->assertNotEmpty($data['data']);
        $item = $data['data'][0];
        $this->assertArrayHasKey('publicId', $item);
        $this->assertArrayHasKey('activityType', $item);
        $this->assertArrayHasKey('lastPlayedAt', $item);
        $this->assertArrayHasKey('playCount', $item);
    }

    public function testRecentRespectsLimit(): void
    {
        $user = $this->createTestUser();
        $songId = Uuid::generate()->toString();

        // Record 3 plays
        for ($i = 0; $i < 3; $i++) {
            $this->authenticatedRequest('POST', '/api/activity/play', $user, [
                'songId' => $songId,
            ]);
        }

        $response = $this->authenticatedRequest('GET', '/api/user/recent?limit=1', $user);
        $data = $this->assertJsonResponse($response, 200);
        // Same song played 3 times = 1 activity with playCount=3
        $this->assertCount(1, $data['data']);
    }

    public function testRecentDefaultsTo5Limit(): void
    {
        $user = $this->createTestUser();

        $response = $this->authenticatedRequest('GET', '/api/user/recent', $user);
        $data = $this->assertJsonResponse($response, 200);
        // Even with no items, verify the endpoint accepts default limit
        $this->assertIsArray($data['data']);
    }

    public function testRecentCapsLimitAt20(): void
    {
        $user = $this->createTestUser();
        $response = $this->authenticatedRequest('GET', '/api/user/recent?limit=100', $user);
        // Should not error — capped to 20
        $this->assertJsonResponse($response, 200);
    }
}
```

**GREEN:**

```php
<?php
// src/Activity/Interface/Controller/UserRecentController.php

declare(strict_types=1);

namespace App\Activity\Interface\Controller;

use App\Activity\Application\Port\ActivityPortInterface;
use App\Activity\Domain\Model\MediaActivity;
use App\Activity\Infrastructure\ActivityEnrichmentService;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\Shared\Interface\Controller\TranslatorTrait;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[OA\Tag(name: 'User', description: 'User-specific data endpoints')]
#[Route('/api/user', name: 'user_')]
final class UserRecentController
{
    use ApiResponsesTrait;
    use TranslatorTrait;

    public function __construct(
        private readonly Security $security,
        private readonly ActivityPortInterface $activityService,
        private readonly ActivityEnrichmentService $enrichmentService,
    ) {
    }

    #[OA\Get(
        path: '/api/user/recent',
        summary: 'Get recently played items',
        parameters: [
            new OA\Parameter(name: 'limit', description: 'Max items (1-20)', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 5, minimum: 1, maximum: 20)),
            new OA\Parameter(name: 'mediaType', description: 'Filter by media type', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['music', 'movies', 'tv', 'podcasts', 'concerts', 'ebooks'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Success', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: new Model(type: RecentItemResource::class)))],
            )),
            new OA\Response(response: 401, description: 'Not authenticated'),
        ],
    )]
    #[Route('/recent', name: 'recent', methods: ['GET'])]
    public function recent(Request $request): JsonResponse
    {
        $user = $this->security->getUser();
        if ($user === null) {
            return $this->unauthorized();
        }

        $limit = min(20, max(1, (int) $request->query->get('limit', 5)));
        $mediaType = $request->query->getAlnum('mediaType');

        // Fetch more than needed if filtering, to ensure enough results
        $fetchLimit = $mediaType !== '' ? 100 : $limit;
        $activities = $this->activityService->getRecentlyPlayed(
            Uuid::fromString($user->getId()),
            $fetchLimit,
        );

        if ($mediaType !== '') {
            $activities = $this->filterByMediaType($activities, $mediaType);
            $activities = array_slice($activities, 0, $limit);
        }

        $baseUrl = $request->getSchemeAndHttpHost();
        $enriched = $this->enrichmentService->enrich($activities, $baseUrl);

        return $this->successResponse($enriched);
    }

    /**
     * @param MediaActivity[] $activities
     * @return MediaActivity[]
     */
    private function filterByMediaType(array $activities, string $mediaType): array
    {
        return array_values(array_filter($activities, function (MediaActivity $activity) use ($mediaType): bool {
            return match ($mediaType) {
                'music' => $activity->getSongId() !== null || $activity->getAlbumId() !== null,
                'movies' => $activity->getMovieId() !== null,
                default => false, // tv, podcasts, concerts, ebooks not tracked yet
            };
        }));
    }
}
```

**RecentItemResource** — a sidebar-optimized subset of `ActivityResource::fromWithDetails()`. Omits `uuid`, `userId`, `createdAt`, `updatedAt`, `lastPlatform`, `lastPlayer`:

```php
<?php
// src/Activity/Interface/Resource/RecentItemResource.php

declare(strict_types=1);

namespace App\Activity\Interface\Resource;

use App\Shared\Interface\Resource\AbstractResource;

final class RecentItemResource extends AbstractResource
{
    /**
     * Sidebar-optimized recent item shape.
     * Accepts the enriched array from ActivityEnrichmentService.
     *
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    public static function from(mixed $source): array
    {
        return [
            'publicId' => $source['publicId'],
            'activityType' => $source['activityType'],
            'songTitle' => $source['songTitle'] ?? null,
            'songPublicId' => $source['songPublicId'] ?? null,
            'albumTitle' => $source['albumTitle'] ?? null,
            'albumPublicId' => $source['albumPublicId'] ?? null,
            'artistName' => $source['artistName'] ?? null,
            'coverImage' => $source['coverImage'] ?? null,
            'lastPlayedAt' => $source['lastPlayedAt'] ?? null,
            'playCount' => $source['playCount'] ?? 0,
        ];
    }
}
```

Wait — the enrichment service already returns the full `ActivityResource::fromWithDetails()` shape. The `RecentItemResource` can just pick the fields it wants. But actually, the enrichment service returns the full array already. So `UserRecentController` can either:
1. Return the enriched array as-is (simplest, the frontend picks what it needs)
2. Map through `RecentItemResource::from()` to strip fields (cleaner API contract)

Option 2 is correct for API design. The endpoint should not leak internal fields like `uuid` and `userId`.

**Controller change:**
```php
$enriched = $this->enrichmentService->enrich($activities, $baseUrl);
$mapped = array_map(
    fn(array $item) => RecentItemResource::from($item),
    $enriched
);
return $this->successResponse($mapped);
```

**REFACTOR:** Clean up. Verify all existing tests pass.

**Verification:**
```
make phpunit ARGS='tests/Functional/Controller/UserRecentControllerTest.php'
make phpunit ARGS='tests/Functional/Controller/ActivityControllerTest.php'
```

---

### Unit 3: Frontend — `useRecentItems` hook

**Goal:** Create a React hook that fetches from `/api/user/recent`, maps the response to `RecentItem[]`, and handles loading/error states.

**Files:**
- `ui/web/src/features/layout/hooks/use-recent-items.ts` (new)
- `ui/web/tests/features/layout/hooks/use-recent-items.test.ts` (new)

**Design decision: hand-rolled vs generated.**

The generated client (orval) produces tanstack-query hooks with full type safety. But the generated client requires running `yarn generate-api` after adding OpenAPI annotations. For a single new endpoint, a hand-rolled hook is faster and follows the same pattern as `getStations()` / `getStarredStations()` in the radio feature (which are also hand-rolled).

If the endpoint is later added to the OpenAPI spec and regenerated, this hook can be replaced by the generated one. The interface (`RecentItem[]`) stays the same.

**RED:**
```typescript
// ui/web/tests/features/layout/hooks/use-recent-items.test.ts
import { renderHook, waitFor } from '@testing-library/react'
import { vi, describe, it, expect, beforeEach } from 'vitest'
import { useRecentItems } from '@/features/layout/hooks/use-recent-items'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'

vi.mock('@/shared/api-client/axios-instance', () => ({
  AXIOS_INSTANCE: { get: vi.fn() },
}))

describe('useRecentItems', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('fetches from /api/user/recent with default limit', async () => {
    const mockGet = vi.mocked(AXIOS_INSTANCE.get)
    mockGet.mockResolvedValueOnce({
      data: { data: [makeApiItem({ publicId: 'r1', songTitle: 'Test Song' })] },
    })

    const { result } = renderHook(() => useRecentItems())

    await waitFor(() => expect(result.current.isLoading).toBe(false))

    expect(mockGet).toHaveBeenCalledWith('/api/user/recent?limit=5')
    expect(result.current.items).toHaveLength(1)
    expect(result.current.items[0].id).toBe('r1')
    expect(result.current.items[0].title).toBe('Test Song')
  })

  it('passes mediaType as query parameter', async () => {
    const mockGet = vi.mocked(AXIOS_INSTANCE.get)
    mockGet.mockResolvedValueOnce({ data: { data: [] } })

    renderHook(() => useRecentItems({ mediaType: 'movies' }))

    await waitFor(() => expect(mockGet).toHaveBeenCalled())
    expect(mockGet).toHaveBeenCalledWith('/api/user/recent?limit=5&mediaType=movies')
  })

  it('maps API response to RecentItem shape', async () => {
    const mockGet = vi.mocked(AXIOS_INSTANCE.get)
    const apiItem = makeApiItem({
      publicId: 'abc',
      songTitle: 'Paranoid Android',
      artistName: 'Radiohead',
      coverImage: { url: '/api/images/x/file', blurhash: 'abc' },
      lastPlayedAt: new Date().toISOString(),
    })
    mockGet.mockResolvedValueOnce({ data: { data: [apiItem] } })

    const { result } = renderHook(() => useRecentItems())

    await waitFor(() => expect(result.current.items).toHaveLength(1))
    const item = result.current.items[0]
    expect(item).toEqual({
      id: 'abc',
      title: 'Paranoid Android',
      subtitle: 'Radiohead',
      timestamp: expect.any(String), // relative time
      thumbnailUrl: '/api/images/x/file',
    })
  })

  it('returns empty array on API error', async () => {
    const mockGet = vi.mocked(AXIOS_INSTANCE.get)
    mockGet.mockRejectedValueOnce(new Error('Network error'))

    const { result } = renderHook(() => useRecentItems())

    await waitFor(() => expect(result.current.isLoading).toBe(false))
    expect(result.current.items).toEqual([])
  })

  it('falls back title to albumTitle when songTitle is null', async () => {
    const mockGet = vi.mocked(AXIOS_INSTANCE.get)
    const apiItem = makeApiItem({ songTitle: null, albumTitle: 'OK Computer' })
    mockGet.mockResolvedValueOnce({ data: { data: [apiItem] } })

    const { result } = renderHook(() => useRecentItems())

    await waitFor(() => expect(result.current.items).toHaveLength(1))
    expect(result.current.items[0].title).toBe('OK Computer')
  })

  it('refetches when mediaType changes', async () => {
    const mockGet = vi.mocked(AXIOS_INSTANCE.get)
    mockGet.mockResolvedValue({ data: { data: [] } })

    const { rerender } = renderHook(
      (props) => useRecentItems(props),
      { initialProps: { mediaType: 'music' } }
    )
    rerender({ mediaType: 'movies' })

    await waitFor(() => expect(mockGet).toHaveBeenCalledTimes(2))
    expect(mockGet).toHaveBeenLastCalledWith('/api/user/recent?limit=5&mediaType=movies')
  })
})

function makeApiItem(overrides: Record<string, any> = {}) {
  return {
    publicId: 'test-id',
    activityType: 'play',
    songTitle: 'Default Song',
    songPublicId: 'song-1',
    albumTitle: 'Default Album',
    albumPublicId: 'album-1',
    artistName: 'Default Artist',
    coverImage: null,
    lastPlayedAt: new Date().toISOString(),
    playCount: 1,
    ...overrides,
  }
}
```

**GREEN:**

```typescript
// ui/web/src/features/layout/hooks/use-recent-items.ts
import { useEffect, useState, useRef } from 'react'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import type { RecentItem } from '@/features/layout/components/SidebarRecentItems'

export interface UseRecentItemsOptions {
  limit?: number
  mediaType?: string
}

export interface UseRecentItemsResult {
  items: RecentItem[]
  isLoading: boolean
}

export function useRecentItems(options: UseRecentItemsOptions = {}): UseRecentItemsResult {
  const { limit = 5, mediaType } = options
  const [items, setItems] = useState<RecentItem[]>([])
  const [isLoading, setIsLoading] = useState(false)
  const seqRef = useRef(0)

  useEffect(() => {
    const seq = ++seqRef.current
    const params = new URLSearchParams()
    params.set('limit', String(limit))
    if (mediaType) params.set('mediaType', mediaType)

    setIsLoading(true)
    AXIOS_INSTANCE.get(`/api/user/recent?${params}`)
      .then((res) => {
        if (seq !== seqRef.current) return // stale
        const mapped: RecentItem[] = (res.data?.data ?? []).map(mapToRecentItem)
        setItems(mapped)
      })
      .catch(() => {
        if (seq !== seqRef.current) return
        setItems([])
      })
      .finally(() => {
        if (seq !== seqRef.current) return
        setIsLoading(false)
      })
  }, [limit, mediaType])

  return { items, isLoading }
}

function mapToRecentItem(apiItem: Record<string, any>): RecentItem {
  return {
    id: apiItem.publicId,
    title: apiItem.songTitle ?? apiItem.albumTitle ?? 'Unknown',
    subtitle: apiItem.artistName ?? '',
    timestamp: formatRelativeTime(apiItem.lastPlayedAt),
    thumbnailUrl: apiItem.coverImage?.url ?? '',
  }
}

function formatRelativeTime(isoDate: string | null | undefined): string {
  if (!isoDate) return ''
  const now = Date.now()
  const then = new Date(isoDate).getTime()
  if (isNaN(then)) return ''
  const diffMs = now - then
  if (diffMs < 0) return 'just now'
  const diffMin = Math.floor(diffMs / 60_000)
  if (diffMin < 1) return 'just now'
  if (diffMin < 60) return `${diffMin}m ago`
  const diffHr = Math.floor(diffMin / 60)
  if (diffHr < 24) return `${diffHr}h ago`
  const diffDay = Math.floor(diffHr / 24)
  if (diffDay < 7) return `${diffDay}d ago`
  return new Date(isoDate).toLocaleDateString()
}
```

The `seqRef` pattern prevents stale responses from overwriting newer data when `mediaType` changes rapidly.

**Wire into `SidebarContent`:**

Currently `SidebarContent` accepts `recentItems` as a prop. Change it to call the hook internally:

```typescript
// SidebarContent.tsx — BEFORE:
export function SidebarContent({ onItemClick, recentItems = [] }: SidebarContentProps) {

// AFTER:
import { useRecentItems } from '../hooks/use-recent-items'

export function SidebarContent({ onItemClick }: Omit<SidebarContentProps, 'recentItems'>) {
  const activeMedia = useMediaModeStore((s) => s.activeMedia)
  const schema = useSidebarStore((s) => s.schemas[activeMedia])
  const { items: recentItems } = useRecentItems({ limit: 5, mediaType: activeMedia })
  // ... rest unchanged
```

Update `Sidebar.tsx` to remove `recentItems` prop from `<SidebarContent>`.

**REFACTOR:** Verify the sidebar shows recent items for the active media type. Test switching media types and confirming the list updates.

**Verification:** `cd ui/web && yarn vitest run tests/features/layout/hooks/use-recent-items.test.ts`

---

### Unit 4: Frontend — Thumbnail loading via `useImageBlob` in Recent section

**Goal:** Replace raw `<img src={item.thumbnailUrl}>` in `SidebarRecentItems` with `useImageBlob` for authenticated image loading.

**Files:**
- `ui/web/src/features/layout/components/SidebarRecentItems.tsx` (modify)
- `ui/web/tests/features/layout/components/sidebar-recent-items.test.tsx` (extend)

**Problem:** Images at `/api/images/{publicId}/file` require DPoP auth headers. Raw `<img>` tags can't send them. The shared `useImageBlob` fetches via AXIOS_INSTANCE and creates blob URLs.

**Approach:** Extract a `RecentItemThumbnail` sub-component since `useImageBlob` is a per-URL hook and each item needs its own invocation.

**RED:**
```typescript
// sidebar-recent-items.test.tsx additions
describe('RecentItemThumbnail', () => {
  it('shows loading pulse while fetching', () => {
    vi.mocked(useImageBlob).mockReturnValue({ src: null, isLoading: true })
    render(<RecentItemThumbnail url="/api/images/x/file" />)
    expect(document.querySelector('.animate-pulse')).toBeInTheDocument()
  })

  it('renders img with blob URL when loaded', () => {
    vi.mocked(useImageBlob).mockReturnValue({ src: 'blob:http://localhost/abc', isLoading: false })
    render(<RecentItemThumbnail url="/api/images/x/file" />)
    const img = screen.getByRole('img')
    expect(img).toHaveAttribute('src', 'blob:http://localhost/abc')
  })

  it('renders placeholder when no URL provided', () => {
    vi.mocked(useImageBlob).mockReturnValue({ src: null, isLoading: false })
    render(<RecentItemThumbnail url="" />)
    expect(screen.queryByRole('img')).not.toBeInTheDocument()
    // SVG placeholder present
    expect(document.querySelector('svg')).toBeInTheDocument()
  })

  it('renders placeholder on load failure', () => {
    vi.mocked(useImageBlob).mockReturnValue({ src: null, isLoading: false })
    render(<RecentItemThumbnail url="/api/images/broken/file" />)
    expect(screen.queryByRole('img')).not.toBeInTheDocument()
  })
})
```

**GREEN:**

```typescript
// Inside SidebarRecentItems.tsx — add import and sub-component

import { useImageBlob } from '@/shared/hooks/use-image-blob'

function RecentItemThumbnail({ url }: { url: string }) {
  const { src, isLoading } = useImageBlob(url || null)

  if (isLoading) {
    return <div className="size-8 shrink-0 animate-pulse rounded-md bg-secondary" />
  }

  if (!src) {
    return (
      <div className="flex size-8 shrink-0 items-center justify-center rounded-md bg-secondary">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" className="text-muted-foreground/20">
          <circle cx="12" cy="12" r="10" />
          <circle cx="12" cy="12" r="3" />
        </svg>
      </div>
    )
  }

  return (
    <img
      src={src}
      alt=""
      width={32}
      height={32}
      loading="lazy"
      className="size-8 shrink-0 rounded-md object-cover"
    />
  )
}
```

Then in the item rendering, replace:
```typescript
// BEFORE:
<img src={item.thumbnailUrl} alt="" width={32} height={32} loading="lazy" className="..." />

// AFTER:
<RecentItemThumbnail url={item.thumbnailUrl} />
```

**REFACTOR:** The circle-in-circle SVG placeholder appears in `HomePage.tsx` (AlbumCard, RecentlyPlayedCard, PlaylistCard), `TimelineYear.tsx`, and now here. Extracting to a shared component is worth doing but not blocking — note as tech debt.

**Verification:** `cd ui/web && yarn vitest run tests/features/layout/components/sidebar-recent-items.test.tsx`

---

### Unit 5: Frontend — Shared `MediaTypeHomePage` component

**Goal:** Create a reusable home page component that all 5 non-music media types share. Eliminates 5 nearly-identical page implementations.

**Files:**
- `ui/web/src/shared/components/media-type-home-page.tsx` (new)
- `ui/web/src/shared/components/media-type-home-page-card.tsx` (new — card variant for non-music)
- `ui/web/tests/shared/components/media-type-home-page.test.tsx` (new)

**Dependencies:** Unit 3 (uses `useRecentItems`), Unit 4 (uses `useImageBlob` in cards).

**Layout:**

```
MediaTypeHomePage
  <div className="flex-1 overflow-y-auto">
    <div className="mx-auto max-w-6xl px-6 py-6">
      <h1>{title}</h1>
      <p>{subtitle}</p>

      {isLoading ? <Skeleton /> :
        items.length === 0 ? <EmptyState icon={icon} text={emptyText} /> :
          <DashboardSection title={recentLabel}>
            <HorizontalScrollRow>
              {items.map(item => <MediaTypeHomePageCard key={item.id} item={item} />)}
            </HorizontalScrollRow>
          </DashboardSection>
      }
    </div>
  </div>
```

**Card component:**

Each media type card is a 2:3 poster aspect ratio (movies) or 1:1 album ratio (music) with `useImageBlob`. For now, use a consistent 2:3 poster format for all non-music types:

```typescript
// media-type-home-page-card.tsx
import { useImageBlob } from '@/shared/hooks/use-image-blob'
import type { RecentItem } from '@/features/layout/components/SidebarRecentItems'

export function MediaTypeHomePageCard({ item }: { item: RecentItem }) {
  const { src, isLoading } = useImageBlob(item.thumbnailUrl || null)

  return (
    <div className="w-28 shrink-0 overflow-hidden rounded-lg bg-card">
      <div className="aspect-[2/3] bg-secondary">
        {isLoading ? (
          <div className="h-full w-full animate-pulse bg-secondary" />
        ) : src ? (
          <img src={src} alt={item.title} className="h-full w-full object-cover" loading="lazy" />
        ) : (
          <div className="flex h-full w-full items-center justify-center">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" className="text-muted-foreground/20">
              <rect x="2" y="2" width="20" height="20" rx="2" />
            </svg>
          </div>
        )}
      </div>
      <div className="px-1.5 py-1.5">
        <p className="truncate text-xs font-medium text-foreground">{item.title}</p>
        {item.subtitle && (
          <p className="truncate text-[11px] text-muted-foreground">{item.subtitle}</p>
        )}
      </div>
    </div>
  )
}
```

**RED:**
```typescript
describe('MediaTypeHomePage', () => {
  it('renders title and subtitle', () => {
    render(<MediaTypeHomePage title="Movies" subtitle="Your collection" mediaType="movies" recentLabel="Recently Watched" emptyText="No movies" icon={<div data-testid="icon" />} />)
    expect(screen.getByRole('heading', { name: 'Movies' })).toBeVisible()
    expect(screen.getByText('Your collection')).toBeVisible()
  })

  it('renders empty state when no items', () => {
    // Mock useRecentItems to return []
    render(<MediaTypeHomePage ... />)
    expect(screen.getByText('No movies')).toBeVisible()
  })

  it('renders recent items section when data exists', async () => {
    // Mock useRecentItems to return items
    render(<MediaTypeHomePage ... recentLabel="Recently Watched" />)
    expect(screen.getByText('Recently Watched')).toBeVisible()
  })

  it('shows loading skeleton while fetching', () => {
    // Mock useRecentItems to return { items: [], isLoading: true }
    render(<MediaTypeHomePage ... />)
    expect(document.querySelector('.animate-pulse')).toBeInTheDocument()
  })
})
```

**GREEN:** Implement `MediaTypeHomePage` with the layout above. Props interface:

```typescript
interface MediaTypeHomePageProps {
  title: string
  subtitle: string
  mediaType: string
  recentLabel: string      // "Recently Watched", "Continue Watching", etc.
  emptyText: string        // "No movies yet", "No shows watched yet", etc.
  icon: React.ReactNode    // Empty state icon
}
```

**Verification:** `cd ui/web && yarn vitest run tests/shared/components/media-type-home-page.test.tsx`

---

### Unit 6: Frontend — Media type Home pages + route wiring

**Goal:** Create 5 thin page components using `MediaTypeHomePage`. Wire them into routes. Remove `MediaPlaceholder`.

**Files:**
- `ui/web/src/features/movies/pages/MoviesHomePage.tsx` (new)
- `ui/web/src/features/tv/pages/TVHomePage.tsx` (new)
- `ui/web/src/features/podcasts/pages/PodcastsHomePage.tsx` (new)
- `ui/web/src/features/concerts/pages/ConcertsHomePage.tsx` (new)
- `ui/web/src/features/ebooks/pages/EbooksHomePage.tsx` (new)
- `ui/web/src/features/layout/routes.tsx` (modify — replace MediaPlaceholder imports)
- `ui/web/tests/features/movies/pages/movies-home-page.test.tsx` (new, thin)
- `ui/web/tests/features/tv/pages/tv-home-page.test.tsx` (new, thin)
- etc.

**Dependencies:** Unit 5 (uses `MediaTypeHomePage`).

Each page is a thin wrapper:

```typescript
// MoviesHomePage.tsx
import { MediaTypeHomePage } from '@/shared/components/media-type-home-page'
import { Film } from 'lucide-react'

export function MoviesHomePage() {
  return (
    <MediaTypeHomePage
      title="Movies"
      subtitle="Your movie collection"
      mediaType="movies"
      recentLabel="Recently Watched"
      emptyText="No movies watched yet"
      icon={<Film size={48} strokeWidth={1} className="text-muted-foreground/30" />}
    />
  )
}
```

| Page | mediaType | recentLabel | emptyText | Icon |
|------|-----------|-------------|-----------|------|
| MoviesHomePage | `movies` | "Recently Watched" | "No movies watched yet" | `Film` |
| TVHomePage | `tv` | "Continue Watching" | "No shows watched yet" | `Tv` |
| PodcastsHomePage | `podcasts` | "Recently Played" | "No podcasts played yet" | `Podcast` |
| ConcertsHomePage | `concerts` | "Recently Watched" | "No concerts watched yet" | `Music` |
| EbooksHomePage | `ebooks` | "Recently Read" | "No books read yet" | `BookOpen` |

**Route changes in `routes.tsx`:**

```typescript
// BEFORE:
import { HomePage } from '../catalog/pages/HomePage'
// ...
{ path: '/movies', element: <MediaPlaceholder title="Movies" /> },
{ path: '/tv', element: <MediaPlaceholder title="TV Shows" /> },
{ path: '/podcasts', element: <MediaPlaceholder title="Podcasts" /> },
{ path: '/concerts', element: <MediaPlaceholder title="Concerts" /> },
{ path: '/ebooks', element: <MediaPlaceholder title="Ebooks" /> },

// AFTER:
import { MoviesHomePage } from '../movies/pages/MoviesHomePage'
import { TVHomePage } from '../tv/pages/TVHomePage'
import { PodcastsHomePage } from '../podcasts/pages/PodcastsHomePage'
import { ConcertsHomePage } from '../concerts/pages/ConcertsHomePage'
import { EbooksHomePage } from '../ebooks/pages/EbooksHomePage'
// ...
{ path: '/movies', element: <MoviesHomePage /> },
{ path: '/movies/browse', element: <MediaPlaceholder title="Movie Browser" /> },
{ path: '/movies/:publicId', element: <MediaPlaceholder title="Movie Detail" /> },
{ path: '/tv', element: <TVHomePage /> },
{ path: '/tv/browse', element: <MediaPlaceholder title="TV Browser" /> },
{ path: '/podcasts', element: <PodcastsHomePage /> },
{ path: '/concerts', element: <ConcertsHomePage /> },
{ path: '/ebooks', element: <EbooksHomePage /> },
```

Remove the `MediaPlaceholder` function from `routes.tsx` only if no remaining routes use it (browse + detail pages still use it — keep it).

**RED:** One test per page:
```typescript
describe('MoviesHomePage', () => {
  it('renders via MediaTypeHomePage with correct props', () => {
    renderWithRouter(<MoviesHomePage />)
    expect(screen.getByRole('heading', { name: 'Movies' })).toBeVisible()
    expect(screen.getByText('Your movie collection')).toBeVisible()
  })
})
```

**Verification:** `cd ui/web && yarn vitest run tests/features/movies tests/features/tv tests/features/podcasts tests/features/concerts tests/features/ebooks`

---

### Unit 7: Frontend — HomePage cleanup (remove inline `useImageBlob`)

**Goal:** Remove the inline `useImageBlob` function from `HomePage.tsx` and use the shared hook import. Update callers to destructure the new return shape.

**Files:**
- `ui/web/src/features/catalog/pages/HomePage.tsx` (modify)

**What changes:**

The inline version:
```typescript
function useImageBlob(url: string | null) {
  const [src, setSrc] = useState<string | null>(null)
  // ... returns string | null
}
```

The shared version:
```typescript
export function useImageBlob(imageUrl?: string | null): UseImageBlobResult {
  // returns { src: string | null, isLoading: boolean }
}
```

Changes:
1. Remove the inline `useImageBlob` function (~15 lines)
2. Add `import { useImageBlob } from '@/shared/hooks/use-image-blob'`
3. Update all callers from `const src = useImageBlob(url)` to `const { src } = useImageBlob(url)`:
   - `AlbumCard` — `const src = useImageBlob(album.coverImage?.url ?? null)` → `const { src } = useImageBlob(album.coverImage?.url ?? null)`
   - `RecentlyPlayedCard` — same pattern
   - `PlaylistCard` — same pattern (if it uses the hook)

This is a refactor-only change. No behavioral difference. The `isLoading` field is available but unused in these cards — they just show the placeholder when `src` is null.

**Verification:** `cd ui/web && yarn vitest run tests/features/catalog/pages/`

---

## Execution Order & Parallelization

```
Unit 1: Extract ActivityEnrichmentService    (backend, standalone)
  │
  └─→ Unit 2: UserRecentController           (depends on Unit 1)
        │
        └─→ Unit 3: useRecentItems hook       (depends on Unit 2 endpoint)
              │
              ├─→ Unit 4: Thumbnail loading    (depends on Unit 3 for real URLs)
              │     │
              │     └─→ Unit 5: MediaTypeHomePage shared component
              │           │
              │           └─→ Unit 6: 5 Home pages + route wiring
              │
  Unit 7: HomePage cleanup                    (independent, run any time)
```

**Parallel opportunities:**
- Unit 1 (backend) and Unit 7 (refactor) run simultaneously
- Unit 3 + Unit 7 can run in parallel (both independent of each other)
- Unit 4 and Unit 5 can run in parallel after Unit 3
- Unit 6 runs after Unit 5

**Critical path:** Unit 1 → 2 → 3 → 4 → 5 → 6

---

## Risk Assessment

| Risk | Impact | Mitigation |
|------|--------|-----------|
| `getRecentlyPlayed` doesn't support media-type filtering | Media type home pages show mixed results | Filter in PHP via `filterByMediaType()`. No repository change. Fetch 100, filter, slice to limit. |
| `enrichActivities` extraction breaks ActivityController | History endpoint breaks | Unit test on extraction + run existing ActivityControllerTest to verify. |
| Movie/TV/Podcast/Concert/Ebook activity types not tracked | Home pages show empty for those types | `filterByMediaType` returns `false` for untracked types → empty array → empty state renders. Correct behavior. |
| `useImageBlob` creates N blob URLs for N thumbnails | Memory pressure | 5 sidebar thumbnails + 10 home page cards = 15 blob URLs max. Blob URLs revoked on unmount. Negligible. |
| Genre chips are hardcoded | Not data-driven | Placeholder until genre ports exist. |
| Generated API client won't include new endpoint | Type mismatch with hand-rolled hook | The hand-rolled hook uses the same `AXIOS_INSTANCE`. When regenerated, the generated hook can replace it with the same `RecentItem` interface. |
| `SidebarContent` hook change causes re-renders | Performance | `useRecentItems` only fetches when `mediaType` changes. Zustand selector for `activeMedia` is stable. |

---

## Out of Scope

- **Movie/TV/Podcast catalog CRUD** — adding non-music media to the library is a separate feature
- **Genre ports for non-music types** — hardcoded chips
- **Non-music detail pages** (movie detail, show detail) — still placeholder
- **Real-time recent updates** (SSE/WebSocket) — refetch on mount + media switch is sufficient
- **Orval regeneration** for `/api/user/recent` — hand-rolled hook now; regenerate later
- **Shared placeholder SVG component** — tech debt noted; not blocking
- **Activity tracking for podcasts/concerts/ebooks** — separate backend effort
