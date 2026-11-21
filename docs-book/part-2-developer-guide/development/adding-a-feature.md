# Adding a Feature

This walkthrough adds a new feature to an existing bounded context. The example adds a "favorite album" endpoint to the Playlist context and follows it from the domain model to the API endpoint.

See [Coding Conventions](coding-conventions.md) and [Testing](../testing/README.md) for detailed pattern references.

## Overview

Features typically flow through four layers, from the inside out:

```
Domain  ->  Application  ->  Infrastructure  ->  Interface
(model)     (port, command)  (entity, repo)      (controller, resource)
```

The steps below follow the order of implementation.

## 1. Domain Model

If the feature introduces new business rules or state, add a domain model. For aggregate roots, use the state object pattern; for simple types, use a value object.

For a "favorite album" feature, a value object is sufficient -- it captures the relationship between a user and an album with no independent lifecycle:

```php
// src/Playlist/Domain/Model/FavoriteAlbum.php
namespace App\Playlist\Domain\Model;

use App\Shared\Domain\Model\Uuid;

final readonly class FavoriteAlbum
{
    public function __construct(
        public Uuid $albumId,
        public \DateTimeImmutable $addedAt,
    ) {}
}
```

If this were a new aggregate root instead (with its own table and repository), you would create a state object and aggregate root following the pattern in [Coding Conventions](coding-conventions.md#domain-models).

## 2. Repository Interface

If the feature needs persistence, add methods to the existing repository interface or create a new one in `Domain/Repository/`.

```php
// src/Playlist/Domain/Repository/FavoriteAlbumRepositoryInterface.php
namespace App\Playlist\Domain\Repository;

use App\Shared\Domain\Model\Uuid;

interface FavoriteAlbumRepositoryInterface
{
    public function addFavorite(Uuid $userId, Uuid $albumId): void;
    public function removeFavorite(Uuid $userId, Uuid $albumId): void;
    public function isFavorite(Uuid $userId, Uuid $albumId): bool;
    /** @return Uuid[] */
    public function findFavoriteAlbumIds(Uuid $userId): array;
}
```

## 3. Application Port

Ports define use-case contracts in `Application/Port/`. Controllers depend on these interfaces; infrastructure implements them.

```php
// src/Playlist/Application/Port/FavoriteAlbumPortInterface.php
namespace App\Playlist\Application\Port;

use App\Shared\Domain\Model\Uuid;

interface FavoriteAlbumPortInterface
{
    public function addFavorite(Uuid $userId, Uuid $albumId): void;
    public function removeFavorite(Uuid $userId, Uuid $albumId): void;
    public function isFavorite(Uuid $userId, Uuid $albumId): bool;
    /** @return Uuid[] */
    public function listFavoriteAlbumIds(Uuid $userId): array;
}
```

## 4. Command DTO

Commands are `final readonly class` data carriers in `Application/Command/`. Name them after the use case.

```php
// src/Playlist/Application/Command/AddFavoriteAlbumCommand.php
namespace App\Playlist\Application\Command;

use App\Shared\Domain\Model\Uuid;

final readonly class AddFavoriteAlbumCommand
{
    public function __construct(
        private Uuid $userId,
        private Uuid $albumId,
    ) {}

    public function getUserId(): Uuid { return $this->userId; }
    public function getAlbumId(): Uuid { return $this->albumId; }
}
```

## 5. Command Handler

A handler is a `final class` with `#[AsMessageHandler]` on `__invoke`. It depends on repository and port interfaces, not on infrastructure implementations.

```php
// src/Playlist/Application/CommandHandler/AddFavoriteAlbumHandler.php
namespace App\Playlist\Application\CommandHandler;

use App\Playlist\Application\Command\AddFavoriteAlbumCommand;
use App\Playlist\Domain\Repository\FavoriteAlbumRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class AddFavoriteAlbumHandler
{
    public function __construct(
        private readonly FavoriteAlbumRepositoryInterface $favoriteAlbumRepository,
    ) {}

    #[AsMessageHandler]
    public function __invoke(AddFavoriteAlbumCommand $command): void
    {
        $this->favoriteAlbumRepository->addFavorite(
            $command->getUserId(),
            $command->getAlbumId(),
        );
    }
}
```

## 6. Doctrine Entity

Add a Doctrine entity in `Infrastructure/Doctrine/Entity/` for persistence. Entities are separate from domain models and carry the ORM mapping attributes. Store an identifier from another bounded context as a scalar `Uuid` column, not as a Doctrine `#[ORM\ManyToOne]` association to that context's entity, and declare the cross-context foreign key in the context's `*ForeignKeys` service, which implements `ForeignKeyDeclarationProviderInterface` (such as `FavoritesForeignKeys` or `NotificationForeignKeys`).

```php
// src/Playlist/Infrastructure/Doctrine/Entity/FavoriteAlbumEntity.php
namespace App\Playlist\Infrastructure\Doctrine\Entity;

use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'playlist_favorite_albums')]
#[ORM\UniqueConstraint(name: 'uniq_playlist_favorite_albums_user_id_album_id', columns: ['user_id', 'album_id'])]
#[ORM\Index(name: 'idx_playlist_favorite_albums_album_id', columns: ['album_id'])]
class FavoriteAlbumEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\Column(name: 'user_id', type: 'uuid')]
    private Uuid $userId;

    #[ORM\Column(name: 'album_id', type: 'uuid')]
    private Uuid $albumId;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $addedAt;

    public function __construct(Uuid $id, Uuid $userId, Uuid $albumId)
    {
        $this->id = $id;
        $this->userId = $userId;
        $this->albumId = $albumId;
        $this->addedAt = new \DateTimeImmutable();
    }

    // getters...
}
```

**Key rules for Doctrine entities:**

- Primary keys use UUID v7 (via the `Uuid` domain model and `UuidType` Doctrine type). A new internal integer key needs a documented purpose and an identity column, not a serial type.
- String columns use `TEXT` rather than `VARCHAR(n)` unless the length is a real contract. Length validation belongs in the domain layer.
- JSON columns always use JSONB (via `options: ['jsonb' => true]` on `json` type).
- When an entity's primary key is a to-one association (`#[ORM\Id]` on a `ManyToOne` or `OneToOne`), its repository reads and writes the table with DBAL statements, not the unit of work, and the entity stays as schema mapping only: once Doctrine has loaded such an entity, the strict `uuid` type rejects the key it binds on update. See `docs/solutions/runtime-errors/doctrine-cannot-update-a-loaded-entity-keyed-by-its-association-under-the-strict-uuid-type.md`.

## 7. Doctrine Repository Implementation

Implement the repository interface in `Infrastructure/Doctrine/Repository/`. A repository that maps an aggregate typically has three private helpers, `toDomain()`, `syncToEntity()`, and `findEntityOrCreate()`; the names are conventions, not requirements.

```php
// src/Playlist/Infrastructure/Doctrine/Repository/FavoriteAlbumRepository.php
namespace App\Playlist\Infrastructure\Doctrine\Repository;

use App\Playlist\Domain\Repository\FavoriteAlbumRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use App\Shared\Domain\Model\Uuid;

final class FavoriteAlbumRepository implements FavoriteAlbumRepositoryInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function addFavorite(Uuid $userId, Uuid $albumId): void
    {
        // Look up related entities, create and persist FavoriteAlbumEntity
        // ...
    }

    public function removeFavorite(Uuid $userId, Uuid $albumId): void { /* ... */ }
    public function isFavorite(Uuid $userId, Uuid $albumId): bool { /* ... */ }
    public function findFavoriteAlbumIds(Uuid $userId): array { /* ... */ }
}
```

Wire the interface in `config/services.yaml`:

```yaml
App\Playlist\Domain\Repository\FavoriteAlbumRepositoryInterface:
    alias: App\Playlist\Infrastructure\Doctrine\Repository\FavoriteAlbumRepository
```

Wire the port to its infrastructure implementation:

```yaml
App\Playlist\Application\Port\FavoriteAlbumPortInterface:
    alias: App\Playlist\Infrastructure\FavoriteAlbumService
```

## 8. Migration

Create a Doctrine migration for the new table. Migrations live in `migrations/` and are named `VersionYYYYMMDDHHMMSS`. Name every index and constraint as [Database Naming](database-naming.md) describes, with the same names as the entity mapping.

```php
// migrations/Version20261018000000.php
namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261018000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create playlist_favorite_albums table for album favoriting.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE playlist_favorite_albums (
                id UUID NOT NULL,
                user_id UUID NOT NULL,
                album_id UUID NOT NULL,
                added_at TIMESTAMPTZ NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT uniq_playlist_favorite_albums_user_id_album_id UNIQUE (user_id, album_id),
                CONSTRAINT fk_playlist_favorite_albums_user_id FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
                CONSTRAINT fk_playlist_favorite_albums_album_id FOREIGN KEY (album_id) REFERENCES albums (id) ON DELETE CASCADE
            )
        SQL);
        // The unique constraint leads with user_id and serves its cascade; album_id needs its own index.
        $this->addSql('CREATE INDEX idx_playlist_favorite_albums_album_id ON playlist_favorite_albums (album_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS playlist_favorite_albums');
    }
}
```

`added_at` records an instant, so the migration declares it `TIMESTAMPTZ` without a precision: every instant column in Baander is `timestamptz` at PostgreSQL's native microsecond precision. The entity maps the column as `datetime_immutable`. Baander replaces that Doctrine type with `InstantType` (see [Shared Kernel](../contexts/shared.md#doctrine)), which declares `TIMESTAMPTZ` and writes and reads the instant in UTC with its microseconds. The `mapping_types` entry in `config/packages/doctrine.yaml` reads every `TIMESTAMPTZ` column back as `datetime_immutable`, so schema comparison finds no difference. Schema comparison cannot see a precision such as `TIMESTAMPTZ(0)`; `InstantColumnConventionTest` fails on one.

Run the migration:

```bash
make migrate
```

## 9. Resource

A resource extends `AbstractResource` and maps a domain model to an API response in a static `from()` method. Resources live in `Interface/Resource/` and expose 21-character `PublicId` values rather than internal `Uuid` keys.

```php
// src/Playlist/Interface/Resource/FavoriteAlbumResource.php
namespace App\Playlist\Interface\Resource;

use App\Shared\Interface\Resource\AbstractResource;
use App\Shared\Domain\Model\PublicId;
use OpenApi\Spec as OA;

#[OA\Schema(
    type: 'object',
    properties: [
        'albumPublicId' => new OA\Schema(type: 'string'),
    ],
    required: ['albumPublicId'],
    component: 'FavoriteAlbumResource',
)]
final class FavoriteAlbumResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        assert($source instanceof PublicId);

        return [
            'albumPublicId' => $source->toString(),
        ];
    }
}
```

`AbstractResource` also supplies `collection()`, which maps a list of items through `from()`. It is final, so a resource does not redeclare it.

## 10. Request DTO

A request DTO uses promoted properties with Symfony validation attributes and a
native `OpenApi\Spec\Schema` declaration. Request DTOs live in `Interface/Request/`
and accept public IDs at public transport boundaries. The schema explicitly
records fields, required values, defaults, nullability and constraints; validation
attributes do not generate this metadata. Use keyed Schema values in `properties`
and a stable `component:` name for reusable schemas.

```php
// src/Playlist/Interface/Request/AddFavoriteAlbumRequest.php
namespace App\Playlist\Interface\Request;

use OpenApi\Spec as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(
    type: 'object',
    properties: [
        'albumPublicId' => new OA\Schema(
            type: 'string',
            example: 'V1StGXR8_Z5jdHi6B-myT',
            default: '',
        ),
    ],
    required: ['albumPublicId'],
    component: 'AddFavoriteAlbumRequest',
)]
final readonly class AddFavoriteAlbumRequest
{
    public function __construct(
        #[Assert\NotBlank(message: 'Album public ID is required.')]
        #[Assert\Regex(
            pattern: '/^[A-Za-z0-9_-]{21}$/D',
            message: 'Album public ID must be a 21-character NanoID.',
        )]
        public string $albumPublicId = '',
    ) {}
}
```

## 11. Controller

Controllers live in `Interface/Controller/` and depend on port interfaces. `#[Route]` attributes on the controller class and its methods define the routes. To resolve the authenticated user, check that the Symfony `Security` user implements `AuthenticatedUserIdentityInterface` and read its UUID with `getId()`.

```php
// src/Playlist/Interface/Controller/FavoriteAlbumController.php
namespace App\Playlist\Interface\Controller;

use App\Auth\Application\Port\AuthenticatedUserIdentityInterface;
use App\Catalog\Application\Port\AlbumPortInterface;
use App\Playlist\Application\Port\FavoriteAlbumPortInterface;
use App\Playlist\Interface\Request\AddFavoriteAlbumRequest;
use App\Playlist\Interface\Resource\FavoriteAlbumResource;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use OpenApi\Spec as OA;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

#[OA\Tag(name: 'Favorites', description: 'User favorites endpoints')]
#[Route('/api/favorites/albums', name: 'favorite_album_')]
final class FavoriteAlbumController
{
    use ApiResponsesTrait;

    public function __construct(
        private readonly Security $security,
        private readonly AlbumPortInterface $albumPort,
        private readonly FavoriteAlbumPortInterface $favoriteAlbumService,
    ) {}

    #[OA\Operation\Post(
        path: '/api/favorites/albums',
        summary: 'Add an album to favorites',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType\Json(ref: AddFavoriteAlbumRequest::class),
        ),
        responses: [
            new OA\Response(
                response: '201',
                description: 'Added to favorites',
                content: new OA\MediaType\Json(ref: FavoriteAlbumResource::class),
            ),
            new OA\Response(
                response: '401',
                description: 'Not authenticated',
            ),
            new OA\Response(
                response: '404',
                description: 'Album not found',
            ),
            new OA\Response(
                response: '422',
                description: 'Validation error',
            ),
        ],
    )]
    #[Route('', name: 'store', methods: ['POST'])]
    public function store(#[MapRequestPayload] AddFavoriteAlbumRequest $payload): JsonResponse
    {
        $user = $this->security->getUser();
        if (!$user instanceof AuthenticatedUserIdentityInterface) {
            return $this->unauthorized();
        }
        $userId = Uuid::fromString($user->getId());

        $albumPublicId = PublicId::fromString($payload->albumPublicId);
        $album = $this->albumPort->findByPublicId($albumPublicId);
        if ($album === null) {
            return $this->notFound('Album not found.');
        }

        $this->favoriteAlbumService->addFavorite($userId, $album->getId());

        return $this->created(['albumPublicId' => $albumPublicId->toString()]);
    }
}
```

No separate route configuration file is needed. Native class references connect
the request body and response content to their component schemas. The exporter
reads those declarations with the Symfony routes through the Shared application
`OpenApiExporterInterface`; its native implementation owns compilation. See
[API Reference](api-reference.md#openapi-specification) for export and client
generation commands. Keep named arguments in the actual Spec constructor order.

## 12. Tests

### Unit Test (Domain Logic)

Unit tests live in `tests/Unit/<Context>/` and test domain behavior without the container or framework. Construct objects manually -- by convention, the project's tests do not use Zenstruck Foundry.

```php
// tests/Unit/Playlist/Domain/Model/FavoriteAlbumTest.php
namespace App\Tests\Unit\Playlist\Domain\Model;

use App\Playlist\Domain\Model\FavoriteAlbum;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;

final class FavoriteAlbumTest extends TestCase
{
    public function testCreatesWithAlbumIdAndTimestamp(): void
    {
        $albumId = new Uuid();
        $before = new \DateTimeImmutable();

        $favorite = new FavoriteAlbum($albumId, new \DateTimeImmutable());

        $this->assertTrue($favorite->albumId->equals($albumId));
        $this->assertGreaterThanOrEqual($before, $favorite->addedAt);
    }
}
```

### Unit Test (Resource)

Test that the resource maps the domain value to the expected API response.

```php
// tests/Unit/Playlist/Interface/Resource/FavoriteAlbumResourceTest.php
namespace App\Tests\Unit\Playlist\Interface\Resource;

use App\Playlist\Interface\Resource\FavoriteAlbumResource;
use App\Shared\Domain\Model\PublicId;
use PHPUnit\Framework\TestCase;

final class FavoriteAlbumResourceTest extends TestCase
{
    public function testFromTransformsAlbumPublicIdToArray(): void
    {
        $albumPublicId = new PublicId();

        $result = FavoriteAlbumResource::from($albumPublicId);

        $this->assertSame($albumPublicId->toString(), $result['albumPublicId']);
    }
}
```

### Functional Test (API Endpoint)

Functional tests live in `tests/Functional/` and use the kernel container. They extend the base `TestCase`, which provides the `authenticatedRequest()`, `anonymousRequest()`, `createTestUser()`, and `assertJsonResponse()` helpers.

```php
// tests/Functional/Controller/FavoriteAlbumControllerTest.php
namespace App\Tests\Functional\Controller;

use App\Shared\Domain\Model\PublicId;
use App\Tests\Functional\TestCase;

final class FavoriteAlbumControllerTest extends TestCase
{
    public function testAddFavoriteRequiresAuth(): void
    {
        $response = $this->anonymousRequest('POST', '/api/favorites/albums', [
            'albumPublicId' => (string) new PublicId(),
        ]);

        $this->assertJsonResponse($response, 401);
    }

    public function testAddFavoriteForUnknownAlbumReturns404(): void
    {
        $user = $this->createTestUser();

        $response = $this->authenticatedRequest('POST', '/api/favorites/albums', $user, [
            'albumPublicId' => (string) new PublicId(),
        ]);

        $this->assertSame(404, $response->getStatusCode());
    }
}
```

Run the tests:

```bash
make phpunit
# or a specific file inside the preview container:
make exec cmd="./vendor/bin/phpunit tests/Unit/Playlist/Domain/Model/FavoriteAlbumTest.php"
```

## Creating a New Bounded Context

When a feature fits no existing context, create a new one. The context directory follows the four-layer structure:

```
src/<Context>/
├── Domain/
│   ├── Model/
│   ├── Repository/
│   └── Event/
├── Application/
│   ├── Port/
│   ├── Command/
│   └── CommandHandler/
├── Infrastructure/
│   ├── Doctrine/
│   │   ├── Entity/
│   │   └── Repository/
│   └── <Context>Service.php
└── Interface/
    ├── Console/
    ├── Controller/
    ├── Request/
    └── Resource/
```

Start with the domain model and repository interface, then build outward. Register any new repository interfaces and port interfaces as aliases in `config/services.yaml`.

## Adding a Console Command

Console commands live in a bounded context's `Interface/Console/` and are named `app:<noun>[:<sub>]:<verb>` with a singular noun. Admin-facing commands use `AdminCommandSupport`, and admin controller routes name their CLI counterpart with `#[CliCounterpart('app:...')]`. Every console command also needs a reference page in `docs-book/part-1-operator-guide/commands/` and an entry in `docs-book/part-1-operator-guide/commands/README.md`; `CommandDocsCoverageTest` enforces both.

```php
// src/Playlist/Interface/Console/SyncSmartPlaylistsCommand.php
namespace App\Playlist\Interface\Console;

use App\Playlist\Application\Port\PlaylistPortInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:playlist:sync-smart', description: 'Sync all smart playlists')]
final class SyncSmartPlaylistsCommand extends Command
{
    public function __construct(
        private readonly PlaylistPortInterface $playlistService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // ... implementation
        return Command::SUCCESS;
    }
}
```

## Checklist

When adding a feature, verify each item:

- [ ] Domain model with proper validation and immutability
- [ ] Repository interface in `Domain/Repository/` (if persistence needed)
- [ ] Application port in `Application/Port/`
- [ ] Command DTO as `final readonly class` in `Application/Command/`
- [ ] Handler with `#[AsMessageHandler]` in `Application/CommandHandler/`
- [ ] Doctrine entity with UUID primary key, TEXT strings, JSONB for JSON
- [ ] Repository implementation with `toDomain()`, `syncToEntity()`, `findEntityOrCreate()` where they apply
- [ ] Service alias wired in `config/services.yaml`
- [ ] Doctrine migration
- [ ] Resource extending `AbstractResource` in `Interface/Resource/`
- [ ] Request DTO with validation and explicit native Spec schema metadata in `Interface/Request/`
- [ ] Controller depending on ports, using `#[Route]` attributes in `Interface/Controller/`
- [ ] Unit tests for domain logic and resource transformations
- [ ] Functional test for the API endpoint
