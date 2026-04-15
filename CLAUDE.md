# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

Always use Context7 MCP when I need library/API documentation, code generation, setup or configuration steps without me having to explicitly ask.

## Project Overview

**Bånder** is a self-hosted media server for music/movie libraries. Stack: Laravel 12 (PHP 8.4, Octane/Swoole), React 19 + TypeScript + Vite, Electron desktop client, Node.js transcoding service. Modular monolith with OAuth 2.0, WebAuthn, metadata extraction, and recommendations.

## Development Commands

**Uses Docker directly (NOT Laravel Sail). Commands use `make exec` for consistency.**

```bash
# Docker
make build && make start         # Build and start
make stop                        # Stop
make ssh / make ssh-root         # Access container
make logs / make logs-nginx      # View logs

# Backend
make composer-install            # Install dependencies
make exec cmd="php artisan setup:dev --fresh"  # Initial setup
make exec cmd="php artisan migrate:fresh"      # Reset DB
make exec cmd="php artisan make:migration"     # Create migration
make ziggy-routes                # Generate Ziggy routes
make phpunit                     # Run tests
make exec cmd="php artisan dev:server"         # Start dev server
make exec cmd="php artisan reverb:start"       # Start WebSocket

# Frontend
yarn dev                        # Vite dev server
yarn build                      # Production build
yarn generate-api-client        # Generate from OpenAPI spec

# API Client Generation (after ANY API change)
make exec cmd="php artisan scramble:export" && yarn generate-api-client
```

## Critical Patterns

### Routing with Attributes
**CRITICAL**: Routes are NOT in `routes/api.php`. Use PHP 8 attributes on controllers:

```php
#[Middleware(['force.json'])]
#[Prefix('/libraries/{library}/songs')]
class SongController extends Controller
{
    #[Get('', 'api.songs.index', ['auth:oauth', 'scope:access-api'])]
    public function index(SongIndexRequest $request, Library $library): JsonAnonymousResourceCollection
    {
        // ...
    }
}
```

After adding routes: `make typescript-transform && make ziggy-routes`

### API Client Generation
**CRITICAL**: Frontend API client is fully auto-generated. Never use `axios` directly.

```typescript
// ✅ CORRECT
import { useAlbumsIndex } from '@/app/libs/api-client/gen/endpoints';
const { data: albums } = useAlbumsIndex({ library: 'my-library' });

// ❌ WRONG
import axios from 'axios';
axios.get('/api/albums');
```

Workflow: Backend changes → Scramble generates `api.json` → Orval generates TypeScript hooks → Type-safe React Query

### Frontend Path Aliases
```typescript
// ✅ Use aliases
import { BrowseTab } from '@/app/modules/dashboard/music/components/browse-tab';
// ❌ No relative imports
import { BrowseTab } from '../../../../dashboard/music/...';
```
`@/app/*` → `resources/app/*`, `@/docs/*` → `resources/docs/*`

## Architecture

### Backend Modules (`app/Modules/`)
- **Auth**: OAuth 2.0, WebAuthn, token management
- **Metadata**: Pluggable readers (ID3, FLAC, OGG), MusicBrainz/Discogs
- **Transcoder**: Unix socket control client for Node.js transcoding service
- **Recommendation**: Content-based and behavior-based algorithms
- **FFmpeg**: Media processing, HLS/DASH generation
- **Essentia**: FFI bridge to Python Essentia for audio features
- **BlurHash**: Perceptual image hashes
- **Queue**: Job monitoring and metrics

### State Management
- **Redux Toolkit**: Client state (player, UI, notifications)
- **TanStack Query**: Server state (auto-generated hooks)
- **Redux Persist + IndexedDB**: Persistence

### Key Traits & Patterns
- `HasNanoPublicId`: URL-safe, non-sequential public IDs (use for public-facing URLs)
- `HasLibraryAccess`: Automatic scoping to user-accessible libraries
- `HasMusicMetadata`: Common music fields and scopes
- `HasContentSimilarity`: Content-based similarity for recommendations
- **Jobs extend `BaseJob`**: Automatic monitoring, logging, metrics
- **Artists relate to Songs**, not Albums (album artists come through songs)

## Code Organization

### Backend Decision Framework
1. Controller → `app/Http/Controllers/`
2. Eloquent Model → `app/Models/`
3. Middleware → `app/Http/Middleware/`
4. Migration/seeder/factory → `database/`
5. Business logic/domain services → `app/Modules/`
6. Background job → `app/Jobs/`
7. API resource/transformer → `app/Http/Resources/`
8. Form validation → `app/Http/Requests/`

### Frontend Decision Framework
1. Page/route → `resources/app/modules/feature-name/routes/`
2. Feature-specific → `resources/app/modules/feature-name/components/`
3. Reusable → `resources/app/ui/`
4. Layout → `resources/app/layouts/`
5. Global state → `resources/app/store/`
6. Custom hook → `resources/app/hooks/`
7. API-related → Use generated hooks in `libs/api-client/gen/`
8. Utility → `resources/app/utils/`

### Directory Structure
```
app/
├── Http/Controllers/Api/    # API controllers with route attributes
├── Http/Integrations/        # External API clients
├── Http/Requests/            # Form request validation
├── Http/Resources/           # API resource transformers
├── Jobs/                     # Queue jobs
├── Models/                   # Eloquent models
├── Modules/                  # Self-contained modules
└── Services/                 # Business logic

resources/app/
├── libs/api-client/gen/      # Auto-generated API client
├── modules/                  # Feature modules (auth, library-music, etc.)
├── store/                    # Redux store
└── hooks/                    # Custom React hooks
```

## Conventions

### Naming
**PHP**: Classes `PascalCase`, methods/variables `camelCase`, constants `UPPER_SNAKE_CASE`, DB columns `snake_case`, JSON keys `camelCase`
**TypeScript**: Components `PascalCase`, functions/variables `camelCase`, types `PascalCase`, files `kebab-case`

### Model Conventions
- Use `public_id` (Nanoid) for public-facing URLs
- Use integer `id` for internal/foreign key relationships
- Store JSONB metadata in `*_metadata` columns (e.g., `album_metadata`)
- Store locked fields in `locked_fields` JSONB column
- `ArtistSong` pivot has `role` field (Primary, Featured, Producer, etc.)

### Common Pitfalls
1. **Missing Route Registration**: Routes use attributes - no registration needed
2. **Stale API Client**: Always regenerate after API changes
3. **Swoole State**: Don't store state in memory between requests (use cache, or `make restart-app`)
4. **Artist-Song vs Album-Artist**: Artists relate to Songs, not Albums
5. **External API Rate Limits**: Scanner respects Discogs/MusicBrainz limits automatically

## Testing

- **Feature tests**: `tests/Feature/` - API endpoints, integration
- **Unit tests**: `tests/Unit/` - Individual components
- Use `RefreshDatabase` trait to reset DB between tests
- Use factories in `database/factories/` for test data
- Test database: `.env.testing` with separate PostgreSQL instance

## Environment

**Docker Services**: nginx (reverse proxy), baander-app (PHP 8.4 + Swoole), postgres (PostgreSQL 18 + PgROONGA), redis (Redis Stack)

**Key Config**: `config/scanner.php`, `config/octane.php`, `config/horizon.php`, `config/recommendation.php`, `orval.config.cjs`

**First-time Setup**: `cp .env.example .env && make build && make start && make composer-install && make exec cmd="php artisan setup:dev --fresh" && yarn install`

**Dev URLs**: https://baander.test, API docs at `/api/docs`, Horizon at `/-/horizon`

## Troubleshooting

- **"php cli asks for PEM passphrase"**: Check `config/oauth.php` credentials or regenerate keys
- **API client out of date / TypeScript errors**: `make exec cmd="php artisan scramble:export" && yarn generate-api-client`
- **Swoole state issues / Changes not reflected**: `make restart-app` (Octane caches in memory)
- **Routes return 404**: Check route attributes, run `make typescript-transform && make ziggy-routes`, verify middleware/scopes
- **Tests fail with DB errors**: Check `.env.testing` exists, run `make exec cmd="php artisan migrate:fresh --env=test"`

## Additional Documentation

- `README.md`: Project overview
- `docs/dev_workflow.md`: Detailed workflow
- `docs/dev_users.md`: Test user credentials after setup

===

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.5.2
- laravel/framework (LARAVEL) - v12
- laravel/horizon (HORIZON) - v5
- laravel/octane (OCTANE) - v2
- laravel/prompts (PROMPTS) - v0
- tightenco/ziggy (ZIGGY) - v2
- laravel/mcp (MCP) - v0
- phpunit/phpunit (PHPUNIT) - v12

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `yarn run build`, `yarn run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

- Laravel Boost is an MCP server that comes with powerful tools designed specifically for this application. Use them.

## Artisan

- Use the `list-artisan-commands` tool when you need to call an Artisan command to double-check the available parameters.

## URLs

- Whenever you share a project URL with the user, you should use the `get-absolute-url` tool to ensure you're using the correct scheme, domain/IP, and port.

## Tinker / Debugging

- You should use the `tinker` tool when you need to execute PHP to debug code or query Eloquent models directly.
- Use the `database-query` tool when you only need to read from the database.

## Reading Browser Logs With the `browser-logs` Tool

- You can read browser logs, errors, and exceptions using the `browser-logs` tool from Boost.
- Only recent browser logs will be useful - ignore old logs.

## Searching Documentation (Critically Important)

- Boost comes with a powerful `search-docs` tool you should use before trying other approaches when working with Laravel or Laravel ecosystem packages. This tool automatically passes a list of installed packages and their versions to the remote Boost API, so it returns only version-specific documentation for the user's circumstance. You should pass an array of packages to filter on if you know you need docs for particular packages.
- Search the documentation before making code changes to ensure we are taking the correct approach.
- Use multiple, broad, simple, topic-based queries at once. For example: `['rate limiting', 'routing rate limiting', 'routing']`. The most relevant results will be returned first.
- Do not add package names to queries; package information is already shared. For example, use `test resource table`, not `filament 4 test resource table`.

### Available Search Syntax

1. Simple Word Searches with auto-stemming - query=authentication - finds 'authenticate' and 'auth'.
2. Multiple Words (AND Logic) - query=rate limit - finds knowledge containing both "rate" AND "limit".
3. Quoted Phrases (Exact Position) - query="infinite scroll" - words must be adjacent and in that order.
4. Mixed Queries - query=middleware "rate limit" - "middleware" AND exact phrase "rate limit".
5. Multiple Queries - queries=["authentication", "middleware"] - ANY of these terms.

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.

## Constructors

- Use PHP 8 constructor property promotion in `__construct()`.
    - <code-snippet>public function __construct(public GitHub $github) { }</code-snippet>
- Do not allow empty `__construct()` methods with zero parameters unless the constructor is private.

## Type Declarations

- Always use explicit return type declarations for methods and functions.
- Use appropriate PHP type hints for method parameters.

<code-snippet name="Explicit Return Types and Method Params" lang="php">
protected function isAccessible(User $user, ?string $path = null): bool
{
    ...
}
</code-snippet>

## Enums

- That being said, keys in an Enum should follow existing application Enum conventions.

## Comments

- Prefer PHPDoc blocks over inline comments. Never use comments within the code itself unless the logic is exceptionally complex.

## PHPDoc Blocks

- Add useful array shape type definitions when appropriate.

=== tests rules ===

# Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `php artisan test --compact` with a specific filename or filter.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using the `list-artisan-commands` tool.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

## Database

- Always use proper Eloquent relationship methods with return type hints. Prefer relationship methods over raw queries or manual joins.
- Use Eloquent models and relationships before suggesting raw database queries.
- Avoid `DB::`; prefer `Model::query()`. Generate code that leverages Laravel's ORM capabilities rather than bypassing them.
- Generate code that prevents N+1 query problems by using eager loading.
- Use Laravel's query builder for very complex database operations.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `list-artisan-commands` to check the available options to `php artisan make:model`.

### APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## Controllers & Validation

- Always create Form Request classes for validation rather than inline validation in controllers. Include both validation rules and custom error messages.
- Check sibling Form Requests to see if the application uses array or string based validation rules.

## Authentication & Authorization

- Use Laravel's built-in authentication and authorization features (gates, policies, Sanctum, etc.).

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Queues

- Use queued jobs for time-consuming operations with the `ShouldQueue` interface.

## Configuration

- Use environment variables only in configuration files - never use the `env()` function directly outside of config files. Always use `config('app.name')`, not `env('APP_NAME')`.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `yarn run build` or ask the user to run `yarn run dev` or `composer run dev`.

=== laravel/v12 rules ===

# Laravel 12

- CRITICAL: ALWAYS use `search-docs` tool for version-specific Laravel documentation and updated code examples.
- This project upgraded from Laravel 10 without migrating to the new streamlined Laravel file structure.
- This is perfectly fine and recommended by Laravel. Follow the existing structure from Laravel 10. We do not need to migrate to the new Laravel structure unless the user explicitly requests it.

## Laravel 10 Structure

- Middleware typically lives in `app/Http/Middleware/` and service providers in `app/Providers/`.
- There is no `bootstrap/app.php` application configuration in a Laravel 10 structure:
    - Middleware registration happens in `app/Http/Kernel.php`
    - Exception handling is in `app/Exceptions/Handler.php`
    - Console commands and schedule register in `app/Console/Kernel.php`
    - Rate limits likely exist in `RouteServiceProvider` or `app/Http/Kernel.php`

## Database

- When modifying a column, the migration must include all of the attributes that were previously defined on the column. Otherwise, they will be dropped and lost.
- Laravel 12 allows limiting eagerly loaded records natively, without external packages: `$query->latest()->limit(10);`.

### Models

- Casts can and likely should be set in a `casts()` method on a model rather than the `$casts` property. Follow existing conventions from other models.

=== phpunit/core rules ===

# PHPUnit

- This application uses PHPUnit for testing. All tests must be written as PHPUnit classes. Use `php artisan make:test --phpunit {name}` to create a new test.
- If you see a test using "Pest", convert it to PHPUnit.
- Every time a test has been updated, run that singular test.
- When the tests relating to your feature are passing, ask the user if they would like to also run the entire test suite to make sure everything is still passing.
- Tests should cover all happy paths, failure paths, and edge cases.
- You must not remove any tests or test files from the tests directory without approval. These are not temporary or helper files; these are core to the application.

## Running Tests

- Run the minimal number of tests, using an appropriate filter, before finalizing.
- To run all tests: `php artisan test --compact`.
- To run all tests in a file: `php artisan test --compact tests/Feature/ExampleTest.php`.
- To filter on a particular test name: `php artisan test --compact --filter=testName` (recommended after making a change to a related file).
</laravel-boost-guidelines>
