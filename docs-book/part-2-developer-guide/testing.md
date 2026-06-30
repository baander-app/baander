# Testing

Baander uses PHPUnit 12 with three test suites. Tests run inside the Docker container.

## Test Suites

| Suite | Directory | Scope |
|-------|-----------|-------|
| **Unit** | `tests/Unit/` | Pure domain logic, no container, no framework |
| **Functional** | `tests/Functional/` | With Symfony kernel container, database, and services |
| **Integration** | `tests/Integration/` | External service integration tests |

## Running Tests

All commands run inside the app container via `make exec`:

```bash
# Run all tests
make phpunit

# Run a single test file
make exec cmd="./vendor/bin/phpunit tests/Unit/Catalog/Domain/Model/AlbumTest.php"

# Run a specific suite
make exec cmd="./vendor/bin/phpunit --testsuite Unit"

# Run a specific controller's functional tests
make exec cmd="./vendor/bin/phpunit --filter NotificationControllerTest"

# Run a single test method
make exec cmd="./vendor/bin/phpunit --filter testCreateAlbum"

# Run with Xdebug off (faster)
make exec cmd="XDEBUG_MODE=off ./vendor/bin/phpunit"
```

## Coverage

`make phpunit` runs with HTML, Clover, and JUnit report generation:

- **HTML**: `reports/coverage/`
- **Clover**: `reports/clover.xml`
- **JUnit**: `reports/junit.xml`

## Unit Test Conventions

- **Manual object construction** — tests build domain objects directly (e.g., `Album::create(...)`) rather than using factories. Zenstruck Foundry is available but not the default convention.
- **Test file structure** mirrors `src/` — a test for `src/Catalog/Domain/Model/Album.php` lives in `tests/Unit/Catalog/Domain/Model/AlbumTest.php`.
- **No mocks in domain tests** — unit tests exercise real domain logic. Mocks are reserved for infrastructure and external dependencies.

### Example: Unit Test (Domain Logic)

Unit tests live in `tests/Unit/` and test pure domain logic with no framework or container:

```php
final class AlbumTest extends TestCase
{
    public function testCreateAlbum(): void
    {
        $album = Album::create(
            libraryId: Uuid::generate(),
            title: 'Abbey Road',
            type: 'album',
        );

        $this->assertNotNull($album->getId());
        $this->assertNotNull($album->getPublicId());
        $this->assertSame('Abbey Road', $album->getTitle());
    }

    public function testCreateAlbumWithEmptyTitleThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Album::create(
            libraryId: Uuid::generate(),
            title: '',
            type: 'album',
        );
    }
}
```

### Example: Unit Test (Application Handler)

Application handler tests mock domain interfaces (repositories, ports) but test real orchestration logic:

```php
final class StartPlaybackHandlerTest extends TestCase
{
    public function testStartsPlaybackAndDispatchesEvent(): void
    {
        $sessionPort = $this->createMock(PartySessionPortInterface::class);
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);

        $handler = new StartPlaybackHandler($sessionPort, $eventDispatcher);

        $session = /* ... create or reconstitute a PartySession ... */;

        $sessionPort->method('findByUuid')->willReturn($session);
        $sessionPort->expects($this->once())->method('startPlayback');
        $eventDispatcher->expects($this->once())->method('dispatch');

        $command = new StartPlaybackCommand(sessionId: $session->getId(), position: 0);
        ($handler)($command);
    }
}
```

## Functional Test Conventions

Functional tests live in `tests/Functional/` and extend `App\Tests\Functional\TestCase`, which provides a `KernelBrowser`, test user factories, and request helpers. Each test is wrapped in a database transaction by DAMA DoctrineTestBundle, so tests share a single connection and never pollute each other.

### Base Class: `App\Tests\Functional\TestCase`

| Method | Purpose |
|--------|---------|
| `createTestUser(?string $email, string $name, string $password)` | Creates a `ROLE_USER` user with a random email |
| `createAdminUser()` | Creates a `ROLE_ADMIN` user |
| `createSuperAdminUser()` | Creates a `ROLE_SUPER_ADMIN` user |
| `authenticatedRequest(string $method, string $uri, User $user, array $content)` | Sends an HTTP request as the given user. Sets `X-Test-User-Id` header, which the `TestAuthenticator` picks up to authenticate without a real JWT. |
| `anonymousRequest(string $method, string $uri, array $content)` | Sends an unauthenticated HTTP request |
| `assertJsonResponse(Response $response, int $expectedStatus, ?string $expectedKey)` | Asserts status code + valid JSON. If `$expectedKey` is set, asserts the key exists in the decoded body. Returns the decoded array. |

### Authentication in Tests

Tests do not use real JWT tokens or `loginUser()`. Instead, the `TestAuthenticator` (registered in `config/packages/test/security.yaml`) reads the `X-Test-User-Id` header and returns a `SecurityUser` for the corresponding user ID. This is set automatically by `authenticatedRequest()`.

```php
// Anonymous request — no auth headers
$response = $this->anonymousRequest('GET', '/api/genres/');
$this->assertJsonResponse($response, 401);

// Authenticated as a regular user
$user = $this->createTestUser();
$response = $this->authenticatedRequest('GET', '/api/genres/', $user);
$this->assertJsonResponse($response, 200);

// Authenticated as admin
$admin = $this->createAdminUser();
$response = $this->authenticatedRequest('POST', '/api/genres/', $admin, [
    'name' => 'Rock',
    'slug' => 'rock',
]);
$this->assertSame(201, $response->getStatusCode());
```

### Response Shape Gotchas

Controllers use two different response shapes — know which one your test expects:

| Method | Shape | When to use |
|--------|-------|-------------|
| `successResponse($data)` | `{"data": {...}}` | Most GET, PATCH, PUT responses |
| `created($data)` | `{...}` (flat, no wrapper) | POST 201 responses — the data is the top-level object |
| `paginatedResponse($p)` | `{"data": [...], "meta": {...}}` | List endpoints with pagination |
| `errorResponse($msg, $status)` | `{"error": $msg, "status": $status}` | Error responses |

When asserting on a `created()` response, decode the body directly instead of passing `'data'` as the expected key:

```php
// created() — flat response
$response = $this->authenticatedRequest('POST', '/api/favorites/', $user, [...]);
$this->assertSame(201, $response->getStatusCode());
$data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
$this->assertSame('song', $data['entityType']);

// successResponse() — wrapped in 'data'
$data = $this->assertJsonResponse(
    $this->authenticatedRequest('GET', '/api/favorites/', $user),
    200,
    'data',
);
$this->assertSame('song', $data['data'][0]['entityType']);
```

### PostgreSQL jsonb Float Round-Trip

PostgreSQL `jsonb` stores numbers without type annotations. A whole-number float like `5.0` survives the PHP → PostgreSQL → PHP round-trip as integer `5`, which fails a `assertSame(5.0, ...)` assertion. Always use non-whole-number floats in test payloads:

```php
// Bad — survives round-trip as int 5
['volume' => 5.0]

// Good — survives round-trip as float 5.5
['volume' => 5.5]
```

### DAMA Transaction Isolation

Each test is wrapped in a transaction that is rolled back on teardown. This means:

- **No cleanup needed** — inserted rows vanish automatically.
- **Single connection** — all tests share one DB connection within a test run.
- **`$client->disableReboot()`** — the base class calls this to prevent the kernel from rebooting between requests, which would break the transaction.

### Example: Functional Test (Full Pattern)

```php
final class FavoritesControllerTest extends TestCase
{
    public function testIndexRequiresAuthentication(): void
    {
        $response = $this->anonymousRequest('GET', '/api/favorites/');
        $this->assertJsonResponse($response, 401);
    }

    public function testIndexReturnsAddedFavorites(): void
    {
        $user = $this->createTestUser();
        $this->addFavorite($user, 'song', 'song-abc123');

        $data = $this->assertJsonResponse(
            $this->authenticatedRequest('GET', '/api/favorites/', $user),
            200,
        );

        $this->assertCount(1, $data['data']);
        $this->assertSame(1, $data['meta']['total']);
    }

    public function testAddReturns201(): void
    {
        $user = $this->createTestUser();

        $response = $this->authenticatedRequest('POST', '/api/favorites/', $user, [
            'entityType' => 'song',
            'entityPublicId' => 'song-create',
        ]);

        $this->assertSame(201, $response->getStatusCode());
        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('song', $data['entityType']);
    }

    private function addFavorite($user, string $entityType, string $entityPublicId)
    {
        return $this->authenticatedRequest('POST', '/api/favorites/', $user, [
            'entityType' => $entityType,
            'entityPublicId' => $entityPublicId,
        ]);
    }
}
```

## Functional Test Coverage

The functional suite covers these controllers (tests live in `tests/Functional/Controller/`):

| Context | Controller | Tests | Notes |
|---------|-----------|-------|-------|
| Session | DeviceController | 19 | Device registration + rename |
| Notification | PushSubscriptionController | 15 | Subscribe/unsubscribe/remove-all |
| Notification | NotificationController | 19 | List, markRead, markAllAsRead, delete |
| Notification | WebhookController | 13 | Admin-gated CRUD |
| UserPreference | AccentColorController | 7 | GET + PUT |
| UserPreference | AudioPreferencesController | 14 | Versioned save, history, rollback |
| UserPreference | LayoutPreferencesController | 13 | Strict 2-field payload |
| UserPreference | PlayerPreferencesController | 13 | Strict 9-field payload |
| UserPreference | EqDeviceProfileController | 20 | CRUD + activate, pins ownership gaps |
| UserPreference | SidebarConfigController | 10 | Per-media-type config |
| UserPreference | ThemeMoodController | 7 | GET + PUT |
| Catalog | GenreController | 16 | CRUD, ROLE_ADMIN on write |
| Favorites | FavoritesController | 13 | Add/list/remove, type filter |
| Shared | HealthCheckController | 5 | /health, /ready, /live probes |

## Bugs Found by Functional Tests

The functional suite has surfaced production bugs that were fixed:

- **Push subscribe `Assert\Choice`** — `Assert\Choice([...])` used positional args; Symfony 7 requires `Assert\Choice(choices: [...])`. Subscribe was always 500.
- **Notification `findByPublicId`** — passed a raw string to a `PublicIdType` Doctrine column, which rejects non-PublicId values. Mark-read and delete were always 500.
- **Notification `save()`** — never persisted the domain model's `publicId`, so the entity got a divergent listener-generated ID.
- **Notification `markAllAsRead`** — used `->set('e.isRead', true)`, which PHP coerced to string `"1"`, causing a PostgreSQL boolean type mismatch. Always 500.
- **Notification `markRead` controller** — returned the stale domain model (isRead:false) after marking read.
- **GenreController missing authorization** — `update()` and `destroy()` lacked `#[IsGranted('ROLE_ADMIN')]`. Any authenticated user could modify or delete genres.
- **Missing `user_theme_moods` migration** — the ThemeMood entity had no database table.

### Design Gaps Pinned by Tests (Not Yet Fixed)

Some tests document known design-level gaps by asserting the *current* behavior rather than the ideal:

- **Ownership checks missing** — Notification markRead/delete and EqDeviceProfile show/update/delete have no `userId` filter; any authenticated user can access other users' resources by ID.
- **Not-found returns 500, not 404** — EqDeviceProfile's port throws `InvalidArgumentException` for not-found, which the ExceptionSubscriber maps to HTTP 500 instead of 404.
- **Dead-code optimistic locking** — AudioPreferences, LayoutPreferences, and PlayerPreferences controllers catch `RuntimeException` for version conflicts, but `saveForUser()` never throws it. The 409 response path is unreachable.
- **Favorites missing Choice constraint** — `AddFavoriteRequest.entityType` has `NotBlank` but no `Choice`, so invalid types pass DTO validation and crash in `FavoriteType::from()` with a 500.

## Static Analysis

PHPStan runs alongside tests:

```bash
# Run PHPStan
make phpstan

# Generate a baseline for existing errors
make phpstan-baseline
```

## Frontend Testing

The web frontend uses Vitest:

```bash
cd ui/web
yarn test              # Run tests
yarn test:watch        # Watch mode
yarn test:coverage     # With coverage
```

See the [Frontend Development](frontend-development.md) page for more details.
