# Frontend Development

The web frontend lives in `ui/web/`. It is a single-page application built with React, TypeScript, and Vite, consuming the backend API via an auto-generated client.

## Package Manager

**Always use Yarn.** Never run `npm` commands in this project.

```bash
cd ui/web
yarn install
```

## Tech Stack

| Technology | Purpose |
|-----------|---------|
| React 19 | UI framework |
| TypeScript 6 | Type safety |
| Vite 8 | Build tool and dev server (HMR) |
| Tailwind CSS v4 | Utility-first CSS (`@tailwindcss/vite` plugin) |
| TanStack Query v5 | Server state management (caching, refetching, mutations) |
| Zustand | Client-side UI state (stores for player, auth, etc.) |
| Orval | API client generation from OpenAPI spec |
| Axios | HTTP client (used by generated Orval client) |
| React Router v7 | Client-side routing |
| Radix UI | Accessible headless UI primitives |
| shadcn | Pre-styled component library built on Radix UI |
| cmdk | Command palette component |
| Lucide React + @lucide/lab | Icon library |
| i18next | Internationalization |
| Sonner | Toast notifications |
| @dnd-kit | Drag-and-drop (sortable lists, playlists) |

## Project Structure

```
ui/web/
├── src/
│   ├── main.tsx                  # App entry point
│   ├── App.tsx                   # Root component, router setup
│   ├── index.css                 # Global styles, Tailwind imports
│   ├── features/                 # Feature modules (one per domain)
│   │   ├── auth/                 #   Login, registration, protected routes
│   │   ├── catalog/              #   Artists, albums, songs, genres, search
│   │   ├── player/               #   Audio/video playback, controls, equalizer
│   │   ├── playlist/             #   Playlist management
│   │   ├── admin/                #   Admin panel
│   │   ├── equalizer/            #   Audio equalizer
│   │   ├── layout/               #   App shell, sidebar, header
│   │   └── settings/             #   User settings and preferences
│   └── shared/                   # Cross-feature shared code
│       ├── api-client/           #   Generated API client + Axios instance
│       │   ├── axios-instance.ts #   Custom Axios configuration
│       │   └── gen/endpoints/    #   Orval-generated hooks and types
│       ├── components/           #   Shared UI components
│       │   ├── ui/               #   shadcn primitives (Button, Dialog, etc.)
│       │   ├── ErrorBoundary.tsx
│       │   ├── LoadingSkeleton.tsx
│       │   └── ...
│       ├── hooks/                #   Shared React hooks
│       ├── i18n/                 #   Translation files and config
│       └── lib/                  #   Shared utilities
├── tests/                        # Test files (mirrors src/ structure)
│   ├── setup.ts                  # Vitest global setup
│   ├── features/                 #   Feature-level tests
│   ├── shared/                   #   Shared component tests
│   └── perf/                     #   Performance benchmarks
├── orval.config.ts               # Orval code generation config
├── vite.config.ts                # Vite build and dev server config
├── vitest.perf.config.ts         # Separate config for perf tests
├── tsconfig.json                 # TypeScript project references
├── components.json               # shadcn component configuration
└── package.json
```

Each feature module follows the same internal structure:

```
features/<name>/
├── components/     # Feature-specific React components
├── pages/          # Route-level page components
├── hooks/          # Feature-specific React hooks
├── services/       # Business logic, workers, external integrations
└── stores/         # Zustand stores (optional, for complex UI state)
```

## Dev Server

```bash
cd ui/web && yarn dev
```

Starts Vite on `http://localhost:5174` with HMR. The `vite-plugin-symfony` plugin proxies API requests to the Symfony backend running in Docker.

## Build

```bash
cd ui/web && yarn build
```

Runs `tsc -b` (type checking) followed by `vite build`. Output goes to `public/` (served by the Symfony backend in production).

## Scripts

| Command | Description |
|---------|-------------|
| `yarn dev` | Start Vite dev server with HMR |
| `yarn build` | Type check and production build |
| `yarn typecheck` | Run TypeScript compiler only (`tsc -b`) |
| `yarn lint` | Run ESLint |
| `yarn preview` | Preview the production build locally |
| `yarn test` | Run all tests once |
| `yarn test:watch` | Run tests in watch mode |
| `yarn test:coverage` | Run tests with coverage report |
| `yarn test:perf` | Run performance benchmarks |
| `yarn generate` | Regenerate the API client from OpenAPI spec |

## API Client Generation

Orval reads the backend's OpenAPI spec (`openapi.json`) and generates typed React Query hooks and TypeScript types.

```bash
cd ui/web && yarn generate
```

Configuration is in `orval.config.ts`:

- **Input**: `openapi.json` at the project root (exported by the backend via Nelmio ApiDoc)
- **Output**: `src/shared/api-client/gen/endpoints/index.ts`
- **Client mode**: `react-query` (generates TanStack Query hooks)
- **HTTP layer**: Uses a custom Axios instance from `src/shared/api-client/axios-instance.ts`

Regenerate the client after any backend API changes (new endpoints, modified request/response schemas, etc.). The generated file is committed to version control.

## State Management

| Concern | Tool | When to use |
|---------|------|-------------|
| Server state (API data) | TanStack Query | Fetching, caching, mutating backend data |
| Client UI state | Zustand | Complex cross-component state (auth, player) |
| Local component state | React `useState`/`useReducer` | Component-scoped state |

### TanStack Query

The generated Orval hooks wrap TanStack Query. Use them directly in components:

```tsx
import { useGetArtists } from '@/shared/api-client/gen/endpoints';

function ArtistsList() {
  const { data, isLoading } = useGetArtists({ page: 1 });
  // ...
}
```

### Zustand Stores

Feature stores live in `features/<name>/stores/`. The auth store (`features/auth/stores/auth-store.ts`) is the primary example. Create a store when state is shared across many components or needs to persist beyond component lifecycle.

## Icons

Use **only** `lucide-react` and `@lucide/lab`. No other icon libraries are permitted.

```tsx
import { Play, Pause, Heart } from 'lucide-react';
import { IconName } from '@lucide/lab';
```

## Path Aliases

Configured in `vite.config.ts` and `tsconfig.json`:

| Alias | Resolves to |
|-------|-------------|
| `@/` | `src/` |
| `@/shared` | `src/shared/` |

## Testing

Vitest with jsdom environment. Test files live in `ui/web/tests/` and mirror the `src/` structure.

```bash
cd ui/web && yarn test           # Run all tests once
cd ui/web && yarn test:watch     # Watch mode
cd ui/web && yarn test:coverage  # With coverage report
```

### Testing Stack

| Library | Purpose |
|---------|---------|
| Vitest | Test runner |
| jsdom | Browser environment simulation |
| @testing-library/react | Component rendering and interaction |
| @testing-library/user-event | Simulating user input |
| @testing-library/jest-dom | DOM assertion matchers |
| axios-mock-adapter | Mocking API requests |

### Setup

Global test setup is in `tests/setup.ts`. This file runs before every test suite and configures matchers and mocks.

## Component Library

The project uses **shadcn** (managed via the `shadcn` CLI) for pre-built UI components. These live in `src/shared/components/ui/` and include:

Button, Card, Dialog, Sheet, Dropdown Menu, Context Menu, Command, Input, Textarea, Select, Slider, Tabs, Toggle, Tooltip, Scroll Area, Skeleton, Separator, Badge, Table, Toast (Sonner), and custom components like `DndSortable`, `FilterBar`, and `SortSelect`.

Components are configured in `components.json`.

## Backend Integration

For details on how the frontend integrates with the backend (API proxying, authentication flow, WebSocket, SSE), see [CLAUDE.md](../../CLAUDE.md).
