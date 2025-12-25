# Repository Organization Guidelines

**Bånder** is a sophisticated self-hosted media server and player application. This document outlines the file organization standards for the entire monorepo, which consists of three main components:

1. **Laravel Backend** - PHP API server and business logic
2. **React SPA** - TypeScript/React web application
3. **Electron Desktop** - Electron wrapper for native desktop experience

## Development Environment

**IMPORTANT:** This project uses Docker directly via `docker-compose` and **NOT** Laravel Sail. Laravel Sail is not installed and will never be installed.

### Running Commands

Use `docker exec baander-app` to run commands in the Laravel container:

```bash
# Run tests
docker exec baander-app php artisan test

# Run migrations
docker exec baander-app php artisan migrate

# Clear cache
docker exec baander-app php artisan cache:clear

# Generate openapi schema
docker exec baander-app php artisan scramble:export && yarn generate-api-client
```

### Docker Services

- **baander-app** - Main Laravel/PHP application container
- Other services defined in `docker-compose.yml`

---

## Architecture Overview

```
baander/
├── app/                    # Laravel backend (PHP)
├── resources/
│   ├── app/               # React SPA (TypeScript/React)
│   └── docs/              # API documentation (TypeScript/React)
├── electron/              # Electron desktop app (TypeScript)
├── public/                # Public web root
├── routes/                # Laravel route definitions
├── config/                # Laravel configuration
├── database/              # Database migrations, seeders, factories
├── tests/                 # PHPUnit tests
├── api.json               # OpenAPI specification for API client generation
├── orval.config.cjs       # Orval configuration for API client generation
├── vite.config.mts        # Vite configuration for React SPA
├── tsconfig.json          # Root TypeScript configuration
└── composer.json          # PHP dependencies
```

---

## 1. Laravel Backend Organization

### Core Principles

**Laravel's default directory structure should be respected for framework-bound components.**

### Controllers

- **Location:** `app/Http/Controllers/`
- **Reasoning:** Laravel's standard location, used by framework components and route resolution
- **Organization:** Grouped by API version and domain
- **Examples:**
  ```php
  app/Http/Controllers/
  ├── Api/
  │   ├── Auth/
  │   │   └── OAuthController.php
  │   ├── Users/
  │   │   └── UserController.php
  │   ├── Libraries/
  │   │   ├── AlbumController.php
  │   │   ├── ArtistController.php
  │   │   ├── SongController.php
  │   │   ├── MetadataBrowseController.php
  │   │   ├── MetadataSyncController.php
  │   │   └── PlaylistController.php
  │   ├── JobController.php
  │   ├── LogsController.php
  │   └── QueueController.php
  ```

**Controller Naming Conventions:**
- Use plural nouns for resource controllers (`AlbumController`, `ArtistController`)
- Use descriptive names for functional controllers (`MetadataBrowseController`)
- Controllers should be thin - delegate business logic to Services in Modules

### Eloquent Models

- **Location:** `app/Models/`
- **Reasoning:** Laravel's standard location, conventions, and framework expectations
- **Organization:** Can be grouped by domain in subdirectories
- **Examples:**
  ```php
  app/Models/
  ├── User.php
  ├── Library.php
  ├── Album.php
  ├── Artist.php
  ├── Song.php
  ├── Playlist.php
  ├── QueueMonitor.php
  └── FailedJob.php
  ```

**Model Conventions:**
- Use `public_id` (Nanoid) as the route key for public-facing URLs
- Use integer `id` for internal/foreign key relationships
- Store JSONB metadata in `*_metadata` columns (e.g., `album_metadata`)
- Store locked fields in `locked_fields` JSONB column

### Resources (API Responses)

- **Location:** `app/Http/Resources/`
- **Reasoning:** Transform Eloquent models to API responses
- **Organization:** Group by entity type
- **Examples:**
  ```php
  app/Http/Resources/
  ├── Album/
  │   └── AlbumResource.php
  ├── Artist/
  │   └── ArtistResource.php
  ├── Song/
  │   └── SongResource.php
  └── User/
      └── UserResource.php
  ```

**Resource Conventions:**
- Always include `publicId` field (mapped from `public_id`)
- Include `lockedFields` for entities that support field locking
- Use camelCase for JSON keys (Laravel handles this automatically)

### Requests (Form Validation)

- **Location:** `app/Http/Requests/`
- **Reasoning:** Encapsulate validation logic
- **Examples:**
  ```php
  app/Http/Requests/
  ├── AlbumUpdateRequest.php
  ├── ArtistUpdateRequest.php
  └── SongUpdateRequest.php
  ```

### Middleware

- **Location:** `app/Http/Middleware/`
- **Reasoning:** Laravel's standard location, registered in Kernel.php
- **Examples:**
  ```php
  app/Http/Middleware/
  ├── ValidateOAuthToken.php
  ├── CheckOAuthScopes.php
  └── MetadataRateLimiter.php
  ```

### Jobs (Async Processing)

- **Location:** `app/Jobs/`
- **Reasoning:** Queue-based background processing
- **Organization:** Grouped by domain
- **Examples:**
  ```php
  app/Jobs/
  ├── Library/
  │   └── Music/
  │       ├── ScanDirectoryJob.php
  │       ├── SyncAlbumJob.php
  │       ├── SyncArtistJob.php
  │       └── SyncSongMetadataJob.php
  └── Middleware/
      └── MetadataRateLimiter.php
  ```

**Job Conventions:**
- Implement `ShouldBeUnique` for jobs that should not overlap
- Use `Middleware` directory for job middleware (e.g., rate limiting)
- Jobs should dispatch other jobs for complex workflows

### Database Components

- **Migrations:** `database/migrations/`
- **Seeders:** `database/seeders/`
- **Factories:** `database/factories/`
- **Reasoning:** Laravel's standard database structure

---

## 2. Laravel Modules (`app/Modules/`)

The `app/Modules/` directory is for **domain logic, services, and business rules** that are not framework-bound components.

### When to Use Modules

**Business Logic & Services:**
- Service classes
- Domain services
- Business rules
- Complex algorithms

**Guards & Authentication:**
- Custom auth guards
- Authentication services
- Authorization logic

**Integrations:**
- Third-party API clients
- External service integrations
- Metadata providers (MusicBrainz, Discogs)

### Module Structure

```php
app/Modules/
├── Auth/                  # Authentication domain
│   └── OAuth/
│       ├── Contracts/
│       ├── Entities/
│       ├── Repositories/
│       ├── Guards/
│       └── Services/
├── Metadata/              # Metadata management
│   ├── Matching/
│   │   └── Validators/
│   ├── MediaMeta/
│   ├── Processing/
│   ├── Providers/
│   │   ├── MusicBrainz/
│   │   └── Discogs/
│   ├── Search/
│   ├── MetadataJobDispatcher.php
│   ├── MetadataSyncService.php
│   └── LocalMetadataService.php
├── FFmpeg/                # FFmpeg wrapper
├── Essentia/              # Audio analysis
├── BlurHash/              # Image blurhash generation
├── Transcoder/            # Audio transcoding
├── Recommendation/        # Music recommendations
└── Queue/                 # Queue management
```

### Module Examples

**Metadata Module:**
```php
app/Modules/Metadata/
├── Matching/
│   └── Validators/
│       └── ArtistQualityValidator.php
├── Providers/
│   ├── MusicBrainz/
│   │   ├── MusicBrainzClient.php
│   │   └── Filters/
│   │       └── ReleaseFilter.php
│   └── Discogs/
│       ├── DiscogsClient.php
│       └── Filters/
│           ├── BaseFilter.php
│           └── ReleaseFilter.php
├── Processing/
│   └── MetadataProcessor.php
└── MetadataSyncService.php
```

**Integration Patterns:**
- Use Filters/Query builders for API integrations
- Extend `BaseFilter` for consistent pagination
- Implement quality validators for metadata matching
- Use rate limiting middleware for external API calls

---

## 3. React SPA Organization (`resources/app/`)

### Core Principles

- **Feature-based modules** - Group code by feature/domain
- **Shared UI components** - Reusable components in `ui/`
- **Type-safe API client** - Auto-generated from OpenAPI spec
- **Path aliases** - Use `@/app/*` for imports

### Directory Structure

```
resources/app/
├── index.tsx                      # Application entry point
├── App.tsx                        # Root app component
├── bootstrap.ts                   # App initialization
├── modules/                       # Feature modules
│   ├── auth/                      # Authentication flows
│   ├── dashboard/                 # Dashboard pages
│   │   ├── music/
│   │   │   └── components/
│   │   │       ├── browse-tab/    # Metadata browser
│   │   │       ├── search-form.tsx
│   │   │       └── diff-confirmation.tsx
│   │   ├── libraries/
│   │   ├── logs/
│   │   ├── queue-monitor/
│   │   └── users/
│   ├── library-music/             # Music library views
│   │   ├── routes/
│   │   ├── components/
│   │   │   ├── album-editor/
│   │   │   ├── artist-editor/
│   │   │   ├── song-editor/
│   │   │   └── artwork/
│   │   └── routes.tsx
│   ├── library-music-player/      # Audio player
│   ├── library-music-playlists/   # Playlist management
│   ├── user-settings/             # User settings
│   └── notifications/             # Notification system
├── components/                    # Shared components
├── layouts/                       # Layout components
│   ├── bare-layout/
│   ├── dashboard-layout/
│   └── root-layout/
├── routes/                        # Route configuration
│   ├── index.tsx
│   ├── protected.tsx
│   └── public.tsx
├── store/                         # Redux store
│   ├── index.ts
│   ├── music/
│   ├── audio/
│   ├── notifications/
│   └── middleware/
├── hooks/                         # Custom React hooks
├── services/                      # Business logic services
│   └── auth/
├── libs/                          # External libraries
│   ├── api-client/
│   │   ├── gen/                   # Auto-generated from Orval
│   │   │   ├── endpoints/         # React Query hooks
│   │   │   └── models/            # TypeScript interfaces
│   │   ├── axios-instance.ts      # Axios configuration
│   │   └── interceptors/          # Request/response interceptors
│   ├── blurhash/
│   └── lyrics/
├── providers/                     # React context providers
├── ui/                            # Shared UI components
│   ├── buttons/
│   ├── alerts/
│   ├── forms/
│   ├── lyrics-viewer/
│   └── utilities/
├── models/                        # Frontend data models
├── utils/                         # Utility functions
└── tsconfig.app.json              # TypeScript config
```

### Feature Module Structure

Each feature module should follow this structure:

```
resources/app/modules/feature-name/
├── routes/                        # Page components
│   ├── _routes.tsx                # Route definitions
│   ├── overview.tsx               # Main page
│   └── detail.tsx                 # Detail page
├── components/                    # Feature-specific components
│   ├── feature-list/
│   ├── feature-item/
│   └── feature-editor/
├── hooks/                         # Feature-specific hooks
└── [feature-name].module.scss     # Feature styles
```

### Component Organization

**Editor Components:**
- Should include form fields and lock mode toggles
- Should integrate browse/sync metadata buttons
- Should use React Hook Form for form state
- Should dispatch Redux notifications for success/error

**Browse/Sync Integration:**
```typescript
// Pattern for editor components
function EntityEditor({ entity, onSubmit, onCancel, onSync, onMetadataApplied }) {
  const [showBrowseDialog, setShowBrowseDialog] = useState(false);

  return (
    <Box>
      {/* Browse and Sync buttons */}
      <Flex gap="3">
        <Button onClick={() => setShowBrowseDialog(true)}>
          Browse Metadata
        </Button>
        {onSync && <Button onClick={onSync}>Sync Metadata</Button>}
      </Flex>

      {/* Browse Dialog */}
      <Dialog.Root open={showBrowseDialog}>
        <BrowseTab
          entityType="album"
          entityId={entity.publicId}
          entityName={entity.title}
          onMetadataApplied={() => {
            setShowBrowseDialog(false);
            onMetadataApplied?.();
          }}
        />
      </Dialog.Root>
    </Box>
  );
}
```

### API Client Usage

**Never use `axios` directly.** Always use the auto-generated React Query hooks:

```typescript
// ✅ CORRECT - Use generated hooks
import { useAlbumsIndex, useMetadataSync } from '@/app/libs/api-client/gen/endpoints';

function MyComponent() {
  const { data: albums } = useAlbumsIndex({ library: 'my-library' });
  const syncMutation = useMetadataSync({ mutation: { onSuccess: () => {} } });
}

// ❌ WRONG - Don't use axios directly
import axios from 'axios';
axios.get('/api/albums');
```

**API Client Generation:**
- Generated from OpenAPI spec (`api.json`)
- Located in `resources/app/libs/api-client/gen/`

**Regeneration Workflow:**
1. Export OpenAPI spec from Laravel: `docker exec baander-app php artisan scramble:export`
2. Generate React Query hooks: `yarn run generate-api-client`
3. Commit generated files

**Important:** Always regenerate after backend API changes to keep frontend types in sync.

### TypeScript Configuration

**Path Aliases:**
- `@/app/*` → `resources/app/*`
- `@/docs/*` → `resources/docs/*`

**Import Examples:**
```typescript
// ✅ CORRECT
import { Button } from '@radix-ui/themes';
import { BrowseTab } from '@/app/modules/dashboard/music/components/browse-tab';
import { useAlbumsIndex } from '@/app/libs/api-client/gen/endpoints';

// ❌ WRONG - No relative imports for feature code
import { BrowseTab } from '../../../../dashboard/music/components/browse-tab';
```

### Styling

**Use SCSS modules** for component styles:

```typescript
// component.tsx
import styles from './component.module.scss';

export function Component() {
  return <div className={styles.container}>...</div>;
}
```

**Global styles** go in `index.css` and `reset.css`.

---

## 4. Electron Desktop App (`electron/`)

### Directory Structure

```
electron/
├── src/
│   ├── main/               # Main process code
│   │   ├── index.ts        # Main entry point
│   │   ├── ipc/            # IPC handlers
│   │   └── menu.ts         # Application menu
│   ├── preload/            # Preload scripts
│   │   └── index.ts
│   ├── renderer/           # Renderer process utilities
│   ├── services/           # Electron-specific services
│   ├── shared/             # Shared utilities
│   └── typings/            # TypeScript definitions
├── config/
│   └── vite.config.mts     # Vite config for Electron
└── tsconfig.base.json      # Shared TypeScript config
```

### Electron Architecture

- **Main Process** (`main/`) - Node.js environment, manages OS integration
- **Preload Scripts** (`preload/`) - Secure bridge between main and renderer
- **Renderer** - The React SPA runs in this process
- **IPC** - Communication between main and renderer processes

---

## 5. Build Tooling & Development

### Vite Configuration

**React SPA** (`vite.config.mts`):
- Entry points: `resources/app/index.tsx`, `resources/docs/index.tsx`
- Dev server port: `3000`
- Path aliases: `@/app`, `@/docs`
- Plugins: React, Laravel translations, Icons, SVG optimization

**Electron** (`electron/config/vite.config.mts`):
- Builds Electron main and preload processes
- Uses Vite's Electron plugin

### Orval API Client Generation

**Configuration** (`orval.config.cjs`):
- Input: `api.json` (OpenAPI spec)
- Output: `resources/app/libs/api-client/gen/`
- Client: React Query (TanStack Query)
- Features:
  - Auto-generated `useQuery`, `useInfiniteQuery`, `useMutation` hooks
  - Automatic pagination support
  - Custom mutator for axios configuration

**Regeneration Workflow:**
1. Export OpenAPI spec from Laravel: `docker exec -it baander-app php artisan scramble:export`
2. Generate React Query hooks: `yarn run generate-api-client`
3. Commit generated files

**Important:** Always regenerate after backend API changes to keep frontend types in sync.

### Development Scripts

```bash
# React SPA development
yarn dev                    # Start Vite dev server on port 3000

# API client generation
docker exec -it baander-app php artisan scramble:export  # Export OpenAPI spec
yarn run generate-api-client                           # Generate React Query hooks

# TypeScript checking
yarn tsc                    # Type check without emitting files

# Building
yarn build                  # Build React SPA for production

# Electron development
yarn dev:electron           # Start Electron in development mode
yarn build:electron         # Build Electron for production
yarn dist:electron          # Build Electron distributables
```

---

## 6. Code Organization Principles

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

---

## 7. Testing

### Backend Testing

- **Location:** `tests/`
- **Framework:** PHPUnit
- **Organization:** Mirror the `app/` directory structure
- **Examples:**
  ```
  tests/
  ├── Unit/
  │   ├── Models/
  │   └── Services/
  ├── Feature/
  │   ├── Auth/
  │   └── Libraries/
  └── TestCase.php
  ```

### Frontend Testing

Frontend tests should be organized similarly to the source code structure.

---

## 8. Common Patterns

### Public ID Usage

**Backend:**
```php
// Model
class Album extends Model
{
    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}

// Controller
public function show(string $library, Album $album): JsonResponse
{
    // $album is resolved by public_id automatically
    return AlbumResource::make($album);
}
```

**Frontend:**
```typescript
// Always use publicId for API calls
const { data } = useAlbumsShow({ library: 'my-lib', album: 'abc123' });

// Never use internal id
```

### Field Locking Pattern

**Backend:**
```php
// Store locked fields as JSONB
$album->locked_fields = ['title', 'artist'];
$album->save();
```

**Frontend:**
```typescript
// Check if field is locked
const isFieldLocked = (field: string) => {
  return entity.lockedFields?.includes(field) ?? false;
};

// Render lock icon
<LockIcon isOpen={!isFieldLocked('title')} />
```

### Infinite Queries Pattern

```typescript
// Use auto-generated infinite query hooks
const query = useMetadataBrowseAlbumsInfinite(
  { q: 'search term' },
  {
    query: {
      initialPageParam: 1,
      getNextPageParam: (lastPage) => {
        // Handled automatically by Orval config
      }
    }
  }
);

// Render with virtual scrolling
<Virtuoso
  data={query.data?.pages.flatMap(p => p.data) ?? []}
  endReached={() => query.hasNextPage && query.fetchNextPage()}
/>
```

---

## 9. Refactoring Guidelines

### Backend Refactoring

When consolidating scattered backend code:

- **DO move** services, guards, commands to appropriate Modules
- **DON'T move** Controllers, Models, Middleware, or database files
- **ALWAYS update** namespace references throughout the codebase
- **UPDATE service providers** when moving classes
- **TEST thoroughly** after namespace changes

### Frontend Refactoring

When restructuring frontend code:

- **DO** use feature-based organization in `modules/`
- **DO** extract reusable components to `ui/`
- **DO** use path aliases (`@/app/*`) for imports
- **DON'T** use deeply nested relative imports
- **ALWAYS** regenerate API client after API changes:
  1. `docker exec -it baander-app php artisan scramble:export`
  2. `yarn run generate-api-client`
- **UPDATE** all imports when moving files

---

## Summary

The Bånder repository follows these core principles:

1. **Respect framework conventions** (Laravel, React, Electron)
2. **Feature-based organization** for frontend modules
3. **Domain-driven modules** for backend business logic
4. **Type-safe API client** generation from OpenAPI
5. **Path aliases** for clean imports
6. **Public IDs** for external-facing URLs
7. **Field locking** for metadata control
8. **Job queues** for async processing

Following these guidelines ensures consistent, maintainable code across the entire monorepo.
