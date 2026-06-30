# Coding Conventions

This page documents the core patterns used across the Baander codebase. These conventions are derived from the authoritative rules files in `.claude/rules/ddd-*.md`.

## Domain Models

Aggregate roots (entities with a repository interface) use a **state object pattern** to keep the constructor, `create()`, and `reconstitute()` signatures in sync.

### State Object

A mutable class alongside the aggregate root holding all fields as public properties. It's an implementation detail — only the aggregate root and its repository use it.

```php
// src/Catalog/Domain/Model/AlbumState.php
final class AlbumState
{
    public function __construct(
        public Uuid $id,
        public PublicId $publicId,
        public string $title,
        public string $type,
        // ... all fields
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
    }
}
```

### Aggregate Root

Private constructor takes the state. `create()` builds new entities with auto-generated IDs. `reconstitute()` rehydrates from persistence.

```php
final class Album
{
    private function __construct(private AlbumState $state) {}

    public static function create(
        Uuid $libraryId,
        string $title,
        string $type,
    ): self {
        return new self(new AlbumState(
            id: new Uuid(),
            publicId: new PublicId(),
            libraryId: $libraryId,
            title: $title,
            type: $type,
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
        ));
    }

    public static function reconstitute(AlbumState $state): self
    {
        return new self($state);
    }

    public function getTitle(): string { return $this->state->title; }
    public function getState(): AlbumState { return $this->state; }
}
```

### Value Objects

`final readonly class` with a public constructor and a `fromString()` factory. Immutable.

```php
final readonly class Uuid implements Stringable, JsonSerializable
{
    public function __construct(?string $value = null)
    {
        $this->value = $value ?? SymfonyUuid::v7()->toRfc4122();
        // validation...
    }

    public static function fromString(string $value): self
    {
        return new self($value);
    }
}
```

### Key Rules

- Aggregate roots are `final class` (not `readonly` — they hold mutable state)
- Value objects are `final readonly class`
- Never put Doctrine annotations in domain models
- Add new fields to the state object when extending an aggregate root

## Repositories

### Interface

Lives in `Domain/Repository/`. Extends `Searchable` when the entity supports full-text search.

```php
interface AlbumRepositoryInterface extends Searchable
{
    public function save(Album $album): void;
    public function findByUuid(Uuid $uuid): ?Album;
    public function findByPublicId(PublicId $publicId): ?Album;
    public function delete(Album $album): void;
}
```

### Implementation

Lives in `Infrastructure/Doctrine/Repository/`. Three private helpers:

- **`toDomain(Entity)`** — converts Doctrine entity to domain model via `reconstitute()`
- **`syncToEntity(Model, Entity)`** — copies domain state to Doctrine entity
- **`findEntityOrCreate(Model)`** — finds existing entity by UUID or creates new

### Wiring

Every repository interface gets an alias in `config/services.yaml`:

```yaml
App\Catalog\Domain\Repository\AlbumRepositoryInterface:
    alias: App\Catalog\Infrastructure\Doctrine\Repository\AlbumRepository
```

Always depend on the interface, never the implementation.

## CQRS

Commands are `final readonly class` data carriers. Handlers use `#[AsMessageHandler]` on `__invoke`.

### Command

```php
final readonly class CreatePlaylistCommand
{
    public function __construct(
        private string $name,
        private Uuid $userId,
        private ?string $description = null,
    ) {}

    public function getName(): string { return $this->name; }
    public function getUserId(): Uuid { return $this->userId; }
}
```

### Handler

```php
final class CreatePlaylistHandler
{
    public function __construct(
        private readonly PlaylistRepositoryInterface $playlistRepository,
    ) {}

    #[AsMessageHandler]
    public function __invoke(CreatePlaylistCommand $command): Playlist
    {
        $playlist = Playlist::create($command->getName(), $command->getUserId());
        $this->playlistRepository->save($playlist);
        return $playlist;
    }
}
```

Dispatch from controllers via `MessageBusInterface::dispatch()`.

## Port Pattern

The canonical pattern for controller dependencies. Application layer defines `Port/` interfaces. Controllers inject ports. Infrastructure implements them.

### Port Interface

```php
namespace App\Auth\Application\Port;

interface TotpVerifierInterface
{
    public function generateSecret(): string;
    public function verifyCode(string $secret, string $code, int $window = 1): bool;
}
```

### Controller Usage

```php
public function __construct(
    private readonly TotpVerifierInterface $totpVerifier,
) {}
```

### Wiring

```yaml
App\Auth\Application\Port\TotpVerifierInterface:
    alias: App\Auth\Infrastructure\Security\TotpVerifier
```

## Anti-Corruption Layer

League OAuth2 Server interfaces are aliased to internal adapter implementations in `services.yaml`. This prevents the library's interfaces from leaking into the domain layer.

## Request DTOs and Resources

### Request DTO

`final readonly class` with Symfony validation attributes and `#[OA\Schema]` for OpenAPI docs.

### Resource

Extends `AbstractResource` with a static `from()` method that transforms domain models to API responses.

## Database Conventions

- **Primary keys**: Always UUID v7 via the `Uuid` domain model
- **String columns**: Always `TEXT`, never `VARCHAR(n)` — length validation belongs in the application layer
- **JSON columns**: Always `JSONB`

## See Also

- [Glossary](glossary.md) — definitions of DDD terms used on this page
- [CQRS and Messaging](cqrs-and-messaging.md) — how commands and handlers work in practice
- [Search](search.md) — how to make a context searchable with PGroonga
