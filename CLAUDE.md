# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**Bånder** is a self-hosted media server application for music and movie library management. It consists of:

- **Backend API**: Laravel 12 with PHP 8.4, powered by Octane/Swoole
- **Frontend**: React 19 + TypeScript with Vite
- **Desktop Client**: Electron-based cross-platform application
- **Transcoding Service**: Node.js service for on-the-fly media transcoding

The application uses a modular monolith architecture with advanced features like OAuth 2.0, WebAuthn authentication, intelligent metadata extraction, and recommendation algorithms.

## When to Update This Document

Update CLAUDE.md when you make changes to:

- **Architecture**: New modules, major structural patterns, routing approach
- **Development workflow**: New docker services, fundamental command changes
- **Key conventions**: State management, API client patterns, data layer
- **Testing approach**: Framework changes, test organization
- **Directory structure**: Major reorganizations

**Do NOT update for**:
- Bug fixes and small feature additions
- Configuration tweaks
- Minor dependency updates
- Transient implementation details

This document should focus on **enduring architecture** and patterns, not exhaustive implementation details. When in doubt, ask: "Will this still be relevant 6 months from now?"

## Development Commands

### Docker Environment

**IMPORTANT**: This project uses Docker directly via `docker-compose` and **NOT** Laravel Sail. Laravel Sail is not installed.

```bash
# Build and start all services
make build
make start

# Stop services
make stop

# Access application container
make ssh              # as www-data user
make ssh-root         # as root user

# View logs
make logs             # app container logs
make logs-nginx       # nginx logs
```

### Backend (Laravel/PHP)

**Use `docker exec baander-app` to run commands in the Laravel container:**

```bash
# Install dependencies
docker exec baander-app composer install

# Initial development setup
docker exec baander-app php artisan setup:dev --fresh    # Drops DB, runs migrations, seeds test users

# Database operations
docker exec baander-app php artisan migrate:fresh        # Drop and recreate all tables
docker exec baander-app php artisan migrate              # Run pending migrations
docker exec baander-app php artisan db:seed              # Seed database

# Code generation
docker exec baander-app php artisan make:migration       # Create migration
docker exec baander-app php artisan make:log-channel     # Create logging channels

# Type generation for frontend
docker exec baander-app php artisan ziggy:generate       # Generate Ziggy route definitions

# Testing
docker exec baander-app php artisan -p test                 # Run all tests
docker exec baander-app php vendor/bin/phpunit              # Run PHPUnit directly

# Development server
docker exec baander-app php artisan dev:server           # Start dev server, queue worker, and scheduler
docker exec baander-app php artisan reverb:start         # Start WebSocket server (in separate terminal)

# API Client Generation
docker exec baander-app php artisan scramble:export && yarn generate-api-client
```

### Frontend (React/TypeScript)

```bash
# Development
yarn dev                         # Start Vite dev server (https://baander.test)

# Build
yarn build                       # Production build
yarn tsc                         # Type check only

# API Client Generation
yarn generate-api-client         # Generate API client from OpenAPI spec

# Electron Desktop Client
yarn dev:electron                # Start Electron in dev mode
yarn build:electron              # Build Electron for production
yarn dist:all                    # Package Electron for all platforms
```

## Architecture

### Modular Structure

The application uses a **modular monolith** architecture. Core business logic is organized into self-contained modules in `app/Modules/`:

- **Auth**: OAuth 2.0 server, WebAuthn (Passkeys), token management
- **Metadata**: Pluggable metadata readers (ID3, FLAC, OGG), MusicBrainz/Discogs integration
- **Transcoder**: Control client for Node.js transcoding service (Unix socket communication)
- **Recommendation**: Content-based and behavior-based recommendation algorithms
- **FFmpeg**: Custom FFmpeg wrapper for media processing and HLS/DASH generation
- **Essentia**: FFI bridge to Python Essentia library for audio feature extraction
- **BlurHash**: Perceptual image hash generation for fast loading previews
- **Queue**: Job monitoring and metrics collection

### Routing with Attributes

**Critical**: Routes are NOT defined in `routes/api.php`. All API routes use PHP 8 attributes directly on controllers:

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

When adding new routes:
1. Add the route attribute to the controller method
2. Run `docker exec baander-app php artisan typescript:transform` and `php artisan ziggy:generate` for frontend
3. API documentation is auto-generated by Scramble from these attributes

### Job Queue System

All queued jobs extend `BaseJob` which provides monitoring, logging, and metrics:

```php
class ScanDirectoryJob extends BaseJob
{
    // Automatic queue monitoring and dedicated logger
}
```

Key job categories:
- **Library Scanning** (`app/Jobs/Library/Music/`): Batch file scanning, metadata extraction, cover art
- **Recommendations** (`app/Jobs/Recommendation/`): Background similarity calculations
- **Metadata Sync** (`app/Jobs/Library/Music/`): External API integration with rate limiting

Jobs are monitored via **Laravel Horizon** at `/-/horizon`.

### Data Layer

**Models** use several key traits:
- `HasNanoPublicId`: URL-safe, non-sequential public IDs (not UUIDs)
- `HasLibraryAccess`: Automatic scoping to user-accessible libraries
- `HasMusicMetadata`: Common music fields and scopes
- `HasContentSimilarity`: Content-based similarity for recommendations

**Important Relationships**:
- `ArtistSong` pivot table with `role` field (Primary, Featured, Producer, etc.)
- Custom `BelongsToManyThrough` relationship for complex queries
- Artists are related to Songs, not Albums (album artists come through songs)

**Model Conventions**:
- Use `public_id` (Nanoid) as the route key for public-facing URLs
- Use integer `id` for internal/foreign key relationships
- Store JSONB metadata in `*_metadata` columns (e.g., `album_metadata`)
- Store locked fields in `locked_fields` JSONB column

### Frontend State Management

- **Redux Toolkit**: Client state (music player, UI, notifications)
- **TanStack Query**: Server state with auto-generated hooks from API
- **Redux Persist**: Persistent state storage
- **IndexedDB**: TanStack Query persistence

### API Client Generation

The frontend API client is **fully auto-generated** using Orval:

1. Backend: Scramble generates `api.json` OpenAPI spec from route attributes
2. Frontend: Orval generates TypeScript client from `api.json`
3. Result: Type-safe API calls with React Query hooks

**Workflow after API changes**:
```bash
# 1. Make backend changes
# 2. Export OpenAPI spec and regenerate client
docker exec baander-app php artisan scramble:export
yarn generate-api-client
```

**IMPORTANT**: Never use `axios` directly. Always use the auto-generated React Query hooks:
```typescript
// ✅ CORRECT - Use generated hooks
import { useAlbumsIndex } from '@/app/libs/api-client/gen/endpoints';

function MyComponent() {
  const { data: albums } = useAlbumsIndex({ library: 'my-library' });
}

// ❌ WRONG - Don't use axios directly
import axios from 'axios';
axios.get('/api/albums');
```

### Transcoding Architecture

The Node.js transcoding service operates independently:
- **PHP**: Control client via Unix socket (starts/stops transcodes)
- **Node.js**: Handles FFmpeg processes and media streaming
- **Browser**: Connects directly to Node.js for media streams (not through PHP)

## Code Organization

### Backend Decision Framework

When deciding where to place new backend code:

1. **Is it a Controller?** → `app/Http/Controllers/`
2. **Is it an Eloquent Model?** → `app/Models/`
3. **Is it Middleware?** → `app/Http/Middleware/`
4. **Is it a migration/seeder/factory?** → `database/`
5. **Is it business logic/domain services?** → `app/Modules/`
6. **Is it a background job?** → `app/Jobs/`
7. **Is it an API resource/transformer?** → `app/Http/Resources/`
8. **Is it form validation?** → `app/Http/Requests/`

### Frontend Decision Framework

When deciding where to place new frontend code:

1. **Is it a page/route?** → `resources/app/modules/feature-name/routes/`
2. **Is it feature-specific?** → `resources/app/modules/feature-name/components/`
3. **Is it reusable across features?** → `resources/app/ui/`
4. **Is it a layout?** → `resources/app/layouts/`
5. **Is it global state?** → `resources/app/store/`
6. **Is it a custom hook?** → `resources/app/hooks/`
7. **Is it API-related?** → Use generated hooks in `libs/api-client/gen/`
8. **Is it a utility?** → `resources/app/utils/`

### Directory Structure

```
app/
├── Actions/              # Single-action classes
├── Auth/                 # Authentication logic
├── Console/              # Artisan commands
├── Events/               # Event definitions
├── Exceptions/           # Custom exceptions
├── Extensions/           # Extended framework classes
├── Format/               # Formatting utilities (Bytes, Duration, LocaleString)
├── Http/
│   ├── Controllers/Api/  # API controllers with route attributes
│   ├── Integrations/     # External API clients (Discogs, MusicBrainz, etc.)
│   ├── Middleware/       # HTTP middleware
│   ├── Requests/         # Form request validation
│   └── Resources/        # API resource transformers
├── Jobs/                 # Queue jobs
├── Listeners/            # Event listeners
├── Mail/                 # Email templates
├── Models/               # Eloquent models
├── Modules/              # Self-contained modules (Auth, Metadata, Transcoder, etc.)
├── Observers/            # Model observers
├── Octane/               # Octane-specific code
├── Policies/             # Authorization policies
├── Providers/            # Service providers
├── Repositories/         # Data access layer
└── Services/             # Business logic services

resources/app/
├── components/           # Reusable UI components
├── libs/                 # Third-party library integrations
│   └── api-client/       # Auto-generated API client
│       ├── gen/          # Generated endpoints and models
│       └── axios-instance.ts
├── modules/              # Feature modules
│   ├── auth/             # Authentication flows
│   ├── library-music/    # Music library browsing and management
│   ├── library-music-player/  # Audio player with queue management
│   ├── dashboard/        # Dashboard pages
│   └── user-settings/    # User preferences
├── store/                # Redux store
├── hooks/                # Custom React hooks
└── utils/                # Utility functions
```

## Key Development Patterns

### Adding New API Endpoints

1. Create Form Request class in `app/Http/Requests/` for validation
2. Create Resource class in `app/Http/Resources/` for response transformation
3. Add controller method with route attributes
4. Run `docker exec baander-app php artisan typescript:transform` and `php artisan ziggy:generate`
5. Regenerate API client: `docker exec baander-app php artisan scramble:export && yarn generate-api-client`

### Working with Metadata

The `MetadataReader` facade provides format-agnostic access:
```php
use App\Modules\Metadata\Facades\MetadataReader;

$metadata = MetadataReader::read($filePath);
```

Metadata readers are pluggable - add new formats in `app/Modules/Metadata/Readers/`.

### Library Scanning

Scanner configuration in `config/scanner.php`:
- Batch sizes for file processing
- Rate limiting for external APIs
- Delimiter detection rules for multi-value fields
- Unknown entity handling (localized "Unknown Artist"/"Unknown Album")

### Testing

- **PHPUnit**: Feature and Unit tests in `tests/`
- **Test Database**: Separate PostgreSQL instance configured in `.env.testing`
- **Factories**: Database factories in `database/factories/`
- **Seeders**: Database seeders in `database/seeders/`

### Naming Conventions

**Backend (PHP):**
- Classes: `PascalCase` (`AlbumController`, `MetadataSyncService`)
- Methods: `camelCase` (`getAlbum`, `syncMetadata`)
- Variables: `camelCase` (`$albumId`, `$metadata`)
- Constants: `UPPER_SNAKE_CASE` (`MAX_RETRIES`)
- Database columns: `snake_case` (`public_id`, `locked_fields`)
- JSON API keys: `camelCase` (`publicId`, `lockedFields`)

**Frontend (TypeScript/React):**
- Components: `PascalCase` (`AlbumEditor`, `BrowseTab`)
- Functions/variables: `camelCase` (`useAlbums`, `handleClick`)
- Types/interfaces: `PascalCase` (`AlbumResource`, `BrowseTabProps`)
- Files: `kebab-case` (`album-editor.tsx`, `browse-tab.module.scss`)
- SCSS classes: `camelCase` (`.albumEditor`, `.container`)

### Path Aliases

**Frontend TypeScript Configuration:**
- `@/app/*` → `resources/app/*`
- `@/docs/*` → `resources/docs/*`

```typescript
// ✅ CORRECT
import { Button } from '@radix-ui/themes';
import { BrowseTab } from '@/app/modules/dashboard/music/components/browse-tab';
import { useAlbumsIndex } from '@/app/libs/api-client/gen/endpoints';

// ❌ WRONG - No relative imports for feature code
import { BrowseTab } from '../../../../dashboard/music/components/browse-tab';
```

## Environment & Configuration

### Docker Services

- **nginx**: Reverse proxy (ports 80/443)
- **baander-app**: PHP 8.4 with Swoole (Laravel Octane)
- **postgres**: PostgreSQL 18 with PgROONGA extension
- **redis**: Redis Stack for cache/queue

### Key Configuration Files

- `config/scanner.php`: Music scanning behavior, rate limits
- `config/octane.php`: Swoole server configuration
- `config/horizon.php`: Queue monitoring
- `config/recommendation.php`: Algorithm settings
- `orval.config.cjs`: Frontend API client generation

### Development URLs

- Application: `https://baander.test`
- API Documentation: `https://baander.test/api/docs`
- Horizon (Queue): `https://baander.test/-/horizon`
- OpenAPI Spec: `https://baander.test/api/docs.json`

## Important Notes

### Performance

- **Swoole**: Keep workers stateless - no state should be stored in memory
- **Connection Pooling**: Transcoder connections are pooled for reuse
- **Batch Operations**: Scanner processes files in batches (default: 50)
- **Lazy Collections**: Use for memory-intensive file operations

### Security

- **OAuth Scopes**: Enforce with `['scope:access-api']` middleware
- **Library Access**: Always scope queries with `HasLibraryAccess` trait
- **CORS**: Configured per route
- **Rate Limiting**: Applied to token endpoints and external APIs

### Common Pitfalls

1. **Missing Route Registration**: Routes use attributes - no registration needed in routes file
2. **Stale API Client**: Always regenerate after API changes
3. **Swoole State**: Don't store state in memory between requests (except via cache)
4. **External API Rate Limits**: Scanner respects Discogs/MusicBrainz rate limits automatically
5. **Artist-Song vs Album-Artist**: Artists are related to Songs, not Albums directly
6. **php cli asks for PEM passphrase**: Either missing/incorrect password for oauth config or improperly generated private keypair

## Database

- **PostgreSQL 18** with PgROONGA for full-text search
- **Migrations**: `database/migrations/`
- **Model Factories**: `database/factories/`
- **Seeders**: `database/seeders/`

## Test Users

See `docs/dev_users.md` for available test user credentials after running `php artisan setup:dev`.

## Additional Documentation

- `README.md`: Project overview and screenshots
- `docs/dev_workflow.md`: Detailed development workflow
- `docs/dev_artisan_commands.md`: Development-specific artisan commands
- `docs/dev_docker_services.md`: Docker service details
- `docs/xdebug.md`: XDebug configuration for debugging
- `docs/phpstorm.md`: IDE setup recommendations
