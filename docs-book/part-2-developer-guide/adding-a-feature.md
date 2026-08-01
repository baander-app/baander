# Adding a Feature

A step-by-step walkthrough for adding a new feature to an existing bounded context. The example adds a "favorite album" endpoint to the Playlist context, covering the full path from domain model to API endpoint.

See [Coding Conventions](coding-conventions.md) and [Testing](testing.md) for detailed pattern references.

## Overview

Features typically flow through four layers, from the inside out:

```
Domain  ->  Application  ->  Infrastructure  ->  Interface
(model)     (port, command)  (entity, repo)      (controller, resource)
```

Each step is described below in the order you would implement it.

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

Handlers are `final class` with `#[AsMessageHandler]` on `__invoke`. They depend on domain interfaces, not infrastructure.

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

Add a Doctrine entity in `Infrastructure/Doctrine/Entity/` for persistence. These are separate from domain models and contain ORM mapping annotations.

```php
// src/Playlist/Infrastructure/Doctrine/Entity/FavoriteAlbumEntity.php
namespace App\Playlist\Infrastructure\Doctrine\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'playlist_favorite_albums')]
#[ORM\UniqueConstraint(name: 'uniq_user_album', columns: ['user_id', 'album_id'])]
class FavoriteAlbumEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: UserEntity::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private UserEntity $user;

    #[ORM\ManyToOne(targetEntity: AlbumEntity::class)]
    #[ORM\JoinColumn(name: 'album_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private AlbumEntity $album;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $addedAt;

    public function __construct(Uuid $id, UserEntity $user, AlbumEntity $album)
    {
        $this->id = $id;
        $this->user = $user;
        $this->album = $album;
        $this->addedAt = new \DateTimeImmutable();
    }

    // getters...
}
```

**Key rules for Doctrine entities:**

- Primary keys always use UUID v7 (via the `Uuid` domain model and `UuidType` Doctrine type). Never use auto-incrementing integers.
- String columns always use `TEXT`, never `VARCHAR(n)`. Length validation belongs in the domain layer.
- JSON columns always use JSONB (via `options: ['jsonb' => true]` on `json` type).

## 7. Doctrine Repository Implementation

Implement the repository interface in `Infrastructure/Doctrine/Repository/`. Include the three private helpers: `toDomain()`, `syncToEntity()`, and `findEntityOrCreate()`.

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

Create a Doctrine migration for the new table. Migrations live in `migrations/` and are named sequentially.

```php
// migrations/Version024_CreatePlaylistFavoriteAlbums.php
namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version024_CreatePlaylistFavoriteAlbums extends AbstractMigration
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
                added_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                PRIMARY KEY(id),
                UNIQUE(user_id, album_id)
            )
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS playlist_favorite_albums');
    }
}
```

Run the migration:

```bash
make migrate
```

## 9. Resource

Resources extend `AbstractResource` and transform domain models to API responses via a static `from()` method. They live in `Interface/Resource/`.

```php
// src/Playlist/Interface/Resource/FavoriteAlbumResource.php
namespace App\Playlist\Interface\Resource;

use App\Shared\Interface\Resource\AbstractResource;
use App\Shared\Domain\Model\Uuid;

final class FavoriteAlbumResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        // $source could be a Uuid (album ID) or a domain model
        assert($source instanceof Uuid);

        return [
            'albumId' => $source->toString(),
        ];
    }

    public static function collection(array $items): array
    {
        return array_map(self::from(...), $items);
    }
}
```

## 10. Request DTO

Request DTOs use promoted properties with Symfony validation attributes and `#[OA\Schema]` for OpenAPI docs. They live in `Interface/Request/`.

```php
// src/Playlist/Interface/Request/AddFavoriteAlbumRequest.php
namespace App\Playlist\Interface\Request;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints\NotBlank;

#[OA\Schema(
    schema: 'AddFavoriteAlbumRequest',
    required: ['albumId'],
    properties: [
        new OA\Property(property: 'albumId', type: 'string', format: 'uuid', example: '550e8400-e29b-41d4-a716-446655440000'),
    ],
)]
final readonly class AddFavoriteAlbumRequest
{
    public function __construct(
        #[NotBlank(message: 'Album ID is required.')]
        public string $albumId = '',
    ) {}
}
```

## 11. Controller

Controllers live in `Interface/Controller/` and depend on port interfaces. Routes are defined via `#[Route]` attributes directly on the controller class and methods.

```php
// src/Playlist/Interface/Controller/FavoriteAlbumController.php
namespace App\Playlist\Interface\Controller;

use App\Playlist\Application\Port\FavoriteAlbumPortInterface;
use App\Playlist\Interface\Request\AddFavoriteAlbumRequest;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\Shared\Domain\Model\Uuid;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Bundle\SecurityBundle\Security;

#[OA\Tag(name: 'Favorites')]
#[Route('/api/favorites/albums', name: 'favorite_album_')]
final class FavoriteAlbumController extends AbstractController
{
    use ApiResponsesTrait;

    public function __construct(
        private readonly Security $security,
        private readonly FavoriteAlbumPortInterface $favoriteAlbumService,
    ) {}

    #[OA\Post(
        path: '/api/favorites/albums',
        summary: 'Add an album to favorites',
        requestBody: new OA\RequestBody(ref: '#/components/schemas/AddFavoriteAlbumRequest'),
        responses: [
            new OA\Response(response: '201', description: 'Added to favorites'),
            new OA\Response(response: '401', description: 'Not authenticated'),
            new OA\Response(response: '422', description: 'Validation error'),
        ],
    )]
    #[Route('', name: 'store', methods: ['POST'])]
    public function store(#[MapRequestPayload] AddFavoriteAlbumRequest $payload): JsonResponse
    {
        $user = $this->security->getUser();
        if ($user === null) {
            return $this->unauthorized();
        }

        $userId = Uuid::fromString($user->getId());
        $albumId = Uuid::fromString($payload->albumId);

        $this->favoriteAlbumService->addFavorite($userId, $albumId);

        return $this->created(['albumId' => $albumId->toString()]);
    }
}
```

No separate route configuration file is needed. The `#[Route]` attributes on the class and methods define the routing.

## 12. Tests

### Unit Test (Domain Logic)

Unit tests live in `tests/Unit/<Context>/` and test domain behavior with no container or framework dependencies. Construct objects manually -- the project does not use Zenstruck Foundry by convention.

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
        $albumId = Uuid::v4();
        $before = new \DateTimeImmutable();

        $favorite = new FavoriteAlbum($albumId, new \DateTimeImmutable());

        $this->assertTrue($favorite->albumId->equals($albumId));
        $this->assertGreaterThanOrEqual($before, $favorite->addedAt);
    }
}
```

### Unit Test (Resource)

Test that the resource correctly transforms domain models to API responses.

```php
// tests/Unit/Playlist/Interface/Resource/FavoriteAlbumResourceTest.php
namespace App\Tests\Unit\Playlist\Interface\Resource;

use App\Playlist\Interface\Resource\FavoriteAlbumResource;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;

final class FavoriteAlbumResourceTest extends TestCase
{
    public function testFromTransformsAlbumIdToArray(): void
    {
        $albumId = Uuid::v4();

        $result = FavoriteAlbumResource::from($albumId);

        $this->assertSame($albumId->toString(), $result['albumId']);
    }
}
```

### Functional Test (API Endpoint)

Functional tests live in `tests/Functional/` and use the kernel container. Extend the base `TestCase` which provides `authenticatedRequest()`, `anonymousRequest()`, `createTestUser()`, and `assertJsonResponse()` helpers.

```php
// tests/Functional/Controller/FavoriteAlbumControllerTest.php
namespace App\Tests\Functional\Controller;

use App\Tests\Functional\TestCase;

final class FavoriteAlbumControllerTest extends TestCase
{
    public function testAddFavoriteRequiresAuth(): void
    {
        $response = $this->anonymousRequest('POST', '/api/favorites/albums', [
            'albumId' => (string) \App\Shared\Domain\Model\Uuid::v4(),
        ]);

        $this->assertJsonResponse($response, 401);
    }

    public function testAddFavoriteReturns201(): void
    {
        $user = $this->createTestUser();

        $response = $this->authenticatedRequest('POST', '/api/favorites/albums', $user, [
            'albumId' => (string) \App\Shared\Domain\Model\Uuid::v4(),
        ]);

        $this->assertSame(201, $response->getStatusCode());
    }
}
```

Run the tests:

```bash
make phpunit
# or a specific file:
make exec cmd="./vendor/bin/phpunit tests/Unit/Playlist/Domain/Model/FavoriteAlbumTest.php"
```

## Creating a New Bounded Context

When a feature does not fit into any existing context, create a new one. The context directory follows the four-layer structure:

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
    ├── Controller/
    ├── Request/
    └── Resource/
```

Start with the domain model and repository interface, then build outward. Register any new repository interfaces and port interfaces as aliases in `config/services.yaml`.

## Adding a Console Command

Console commands live in `Interface/Console/` within a bounded context. Use Symfony's `#[AsCommand]` attribute.

```php
// src/Playlist/Interface/Console/SyncSmartPlaylistsCommand.php
namespace App\Playlist\Interface\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'playlist:sync-smart', description: 'Sync all smart playlists')]
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
- [ ] Repository implementation with `toDomain()`, `syncToEntity()`, `findEntityOrCreate()`
- [ ] Service alias wired in `config/services.yaml`
- [ ] Doctrine migration
- [ ] Resource extending `AbstractResource` in `Interface/Resource/`
- [ ] Request DTO with validation and OpenAPI attributes in `Interface/Request/`
- [ ] Controller depending on ports, using `#[Route]` attributes in `Interface/Controller/`
- [ ] Unit tests for domain logic and resource transformations
- [ ] Functional test for the API endpoint
