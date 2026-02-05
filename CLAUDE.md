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