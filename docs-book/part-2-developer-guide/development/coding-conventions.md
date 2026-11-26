# Coding Conventions

This page summarizes the core patterns of the Baander codebase. It is derived from the authoritative rules files in [`.agents/rules/`](../../../.agents/rules/), and [AGENTS.md](../../../AGENTS.md) points agents to the rules that apply to a change.

## Domain Models

New aggregate roots use a **state object pattern**, which keeps the fields of the creation factory and of `reconstitute()` aligned. A repository interface is good evidence that a model is an aggregate root, but not a definition: a model without one may be a child entity, a value type or a simple model. Existing aggregates that still take positional constructor arguments, such as `Playlist`, are accepted until they are deliberately migrated.

### State Object

The state object is a final, mutable class beside the aggregate root, with a public property for each persisted field. Identity and creation fields that no mutation reassigns stay `readonly`. It is an implementation detail: only the aggregate root and its repository use it.

```php
// src/Catalog/Domain/Model/AlbumState.php
final class AlbumState
{
    public function __construct(
        public readonly Uuid $id,
        public readonly PublicId $publicId,
        public string $title,
        public string $type,
        // ... all fields
        public readonly DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
    }
}
```

### Aggregate Root

The private constructor takes the state. A public static factory validates the invariants and supplies the new identity and creation data; keep a semantic name such as `register()` or `issue()` where the domain has one, since a literal `create()` is not required. `reconstitute()` rehydrates the aggregate from persistence.

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

Ordinary clients use the getters. `getState()` serves persistence; it is not an HTTP or serialized payload contract.

### Value Objects

A value object is an immutable `final readonly class`. It has a public constructor, a named factory such as `fromString()`, or both, whichever fits the type.

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
- Keep Doctrine mappings and persistence adapters out of domain models
- Add new fields to the state object when extending an aggregate root
- Use internal `Uuid` (v7 default) for persistence keys, foreign keys, and domain events; expose 21-character `PublicId` NanoIDs on public routes, relationships, cursors, and WebSocket payloads (see [CONCEPTS.md](../../../CONCEPTS.md))

## Repositories

### Interface

A repository interface lives in `Domain/Repository/`, or a feature subfolder of it. It extends `Searchable` only when the repository actually implements full-text search.

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

A new Doctrine repository lives in `Infrastructure/Doctrine/Repository/`. It typically has three private helpers; their names are conventions, not requirements for every storage adapter:

- **`toDomain(Entity)`** — converts Doctrine entity to domain model via `reconstitute()`
- **`syncToEntity(Model, Entity)`** — copies domain state to Doctrine entity
- **`findEntityOrCreate(Model)`** — finds existing entity by UUID or creates new

### Wiring

Give each new repository interface an explicit alias in `config/services.yaml`:

```yaml
App\Catalog\Domain\Repository\AlbumRepositoryInterface:
    alias: App\Catalog\Infrastructure\Doctrine\Repository\AlbumRepository
```

Always depend on the interface, never the implementation.

## CQRS

A command is a `final readonly class` that carries input data and no business logic. Its fields are either private with getters or public promoted properties; it has no setters. A handler carries `#[AsMessageHandler]` on `__invoke`.

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

Controllers dispatch commands through `MessageBusInterface::dispatch()`. The call returns the envelope, not the handler's value; read the result from the envelope's stamps.

## Port Pattern

Controllers depend on ports. The Application layer defines the port interfaces in `Port/`, controllers inject them, and Infrastructure implements them. Existing controllers that inject repositories or infrastructure classes directly are recorded debt, not a pattern to follow.

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
    alias: App\Auth\Infrastructure\Security\Totp\TotpVerifier
```

## Anti-Corruption Layer

Baander uses League OAuth2 Server only to validate access tokens through its `ResourceServer`; Auth's own handlers issue tokens for login, refresh, and the OAuth grants. The League access token and refresh token repository interfaces are aliased to internal adapters in `services.yaml`, so the library's interfaces stay out of the domain layer.

## Request DTOs and Resources

### Request DTO

A request DTO is a concrete `final readonly class` with Symfony validation attributes and `#[OA\Schema]` for the OpenAPI documentation. `#[MapRequestPayload]` takes such a class, never a bare `object`. Its validation does not replace the domain's invariant checks.

### Resource

A resource extends `AbstractResource` and maps a domain model to an API response in a static `from()` method. Never return a state object or a persistence entity as a response.

### HTTP Headers and Identity

- Prefix Baander-defined HTTP headers with `X-Baander-` (for example, `X-Baander-Client-Fingerprint`, `X-Baander-Playback-Endpoint`); keep standard protocol, proxy, and security header names.
- Resolve the signed-in user in a controller by checking that `$this->security->getUser()` implements `App\Auth\Application\Port\AuthenticatedUserIdentityInterface`; its `getId()` returns the user's UUID as a string.

## Admin Actions and Console Parity

Every action on the admin pages has a console command counterpart, and CLI execution runs with full authority (`Actor::CLI` and unrestricted library read scope). See [`.agents/rules/admin-cli-parity.md`](../../../.agents/rules/admin-cli-parity.md) for the complete specification.

- **Shared use case:** A controller method and its CLI command dispatch the same Application command, query, or port. Business logic lives in Application, never in the controller or command alone.
- **Route attributes:** Mark every admin-guarded route (and every non-admin route called by an admin page) with `#[CliCounterpart('app:noun:verb')]` or `#[CliParityExemption(...)]`. `AdminCliParityTest` verifies these markings and checks that every counterpart has a documentation page under `docs-book/part-1-operator-guide/commands/`.
- **Command naming and helpers:** Name commands `app:<noun>[:<sub>]:<verb>` with a singular noun and build them on `App\Shared\Interface\Console\AdminCommandSupport`.
- **Shared exceptions:** Throw `NotFoundException` (`404` / exit `1`), `ConflictException` (`409` / exit `1`), `InvalidInputException` (`422` / exit `2`), or `ServiceUnavailableException` (`503` / exit `1`) from handlers instead of `RuntimeException`, and let the HTTP exception subscriber and `AdminCommandSupport::dispatch()` map them consistently.

## Database Conventions

- **Primary keys**: Generate aggregate identifiers as UUID v7 through the `Uuid` domain model. Token IDs, public IDs, imported identifiers and internal sequence keys keep their own contracts; a new internal integer key needs a documented purpose and an identity column
- **String columns**: Prefer `TEXT` to `VARCHAR(n)`; keep a bounded string only where the length is a real contract, and otherwise validate length in the application layer
- **Timestamps**: Always `TIMESTAMPTZ` without explicit precision (`datetime_immutable` mapped via `InstantType`)
- **JSON columns**: Always `JSONB`
- **Foreign keys and indexes**: Index every foreign key column and follow [Database Naming](database-naming.md)

## Pipe Operator

Two project rules make PHPStan report what PhpStorm 2026.3's pipe-operator inspection reports, so `make phpstan`, `composer analyse:tooling`, `make ci` and CI fail on the code the IDE highlights:

- `baander.pipeOperator.nestedCalls` reports a chain of three to ten nested calls, such as `array_values(array_unique(array_column($rows, 'k')))` or `$this->assertSame(1, json_decode(file_get_contents($path), true))`. A two-level call such as `bin2hex(random_bytes(4))` is not reported.
- `baander.pipeOperator.assignmentChain` reports three to ten consecutive assignments to one variable, each a call that takes the variable as an argument.

Fix a finding in this order:

1. Turn every single-argument level into a pipe stage with a first-class callable: `array_column($rows, 'k') |> array_unique(...) |> array_values(...)`.
2. Keep a two-level head nested when its outer call takes several arguments, and pipe the single-argument levels after it: `implode(', ', array_column($rows, 'k')) |> $escape(...)`.
3. When a chain still has three levels, hoist values into locals before the statement, from the innermost outward, and name each for what it holds: `$json = file_get_contents($path);` followed by `$this->assertSame(1, json_decode($json, true));`.
4. Do not write a one-stage pipe in an argument position only to break the chain.
5. When a local would change whether or when a value is computed, use a named private helper or a closure with a statement body. A typed `static fn` pipe step is the last resort.

A hoisted local must run exactly once, unconditionally, and before any other call or write in the statement, so the statement computes the same values in the same order. It cannot come out of a loop condition, an `elseif` condition, an arrow function, a ternary branch, the right side of `&&`, `||` or `??`, or a `match` arm. A pipe inside concatenation, under `!`, a cast or array access needs parentheses, because `.` binds tighter than `|>`.

Do not apply PhpStorm's quick fix to a chain with multi-argument steps; fix it in the order above. The quick fix wraps each such step in an untyped, non-static `fn($x) => …`, swallows an enclosing assertion into the pipe, and can drop a by-reference write such as the first argument of `array_push()`. A `/** */` docblock between assignments hides a chain from both tools without changing the code.

Production runs with `zend.assertions=-1`, which removes an `assert()` call and its operand. A pipe or a local evaluates the operand there, so hoist an `assert` chain only when its operand is cheap and pure; otherwise replace the assertion with an explicit check that throws.

After a PhpStorm or PHPStan upgrade, check that the two still agree. Run `inspect.sh` from the PhpStorm installation with a profile that enables only `PhpPipeOperatorCanBeUsedInspection`, and compare its findings with PHPStan's JSON output filtered by the two identifiers. Give each headless run a fresh system directory, because a reused one serves file contents cached by an earlier run. Keep `node` and `npm` off `PATH` for the run, because PhpStorm's Node.js project configurator otherwise runs `npm install` in every `package.json` of the project, `ui/rn` included.

## See Also

- [Glossary](../architecture/glossary.md) — definitions of DDD terms used on this page
- [CQRS and Messaging](cqrs-and-messaging.md) — how commands and handlers work in practice
- [Search](search.md) — how to make a context searchable with PGroonga
