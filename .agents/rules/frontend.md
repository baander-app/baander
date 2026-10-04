# Frontend conventions

Applies to React/TypeScript work in `ui/web/`. Read `ui/DESIGN.md` for the binding
product design rules. These conventions describe implementation; existing code
that conflicts with the design contract is not automatically an exception.

## Structure and styling

Features live in `ui/web/src/features/<feature>/`; shared code lives in
`ui/web/src/shared/`. Add only directories the feature needs: components, pages,
hooks, stores, API modules, services, views, or utilities. Use `@/` for imports
across feature boundaries; local relative imports within a feature are fine.

Follow adjacent naming: feature components/pages generally use PascalCase,
hooks `use-*.ts`, stores `*-store.ts`, and handwritten API modules `*-api.ts`.
Shared UI primitives use lowercase filenames such as `button.tsx`; do not rename
them to satisfy a feature naming heuristic.

The web UI uses styled-components and the theme/token system under
`src/shared/theme/` and `ui/design-tokens/`. Reuse primitives from
`@/shared/components/ui/`, including their Radix behavior, before building a new
control. Follow their variants and CSS variables; do not introduce Tailwind
utilities or import the removed `@/shared/lib/utils` helper. Preserve the
interaction, accessibility, motion, and token requirements in `ui/DESIGN.md`.
Use `lucide-react` and `@lucide/lab` for icons.

Keep formatting consistent with `.editorconfig` and adjacent TypeScript: two-space
indentation, readable multiline callbacks and object literals, and separate logical
steps. Avoid compressed test setup and several statements on one line. Prefer named
types for repeated nested shapes. Do not introduce Prettier for this project.

## Components, effects, and stores

Type component props and state without `any`. Interfaces, type aliases, and
typed intersections with native React props are valid. Components without props
do not need a props type. Extract repeated behavior or mixed responsibilities
when useful; file length alone does not prove a design defect.

Effects must release subscriptions, listeners, timers, and owned resources.
Setup and cleanup must support StrictMode replay as well as final unmount. Keep
dependencies accurate rather than using a guard to hide lifecycle problems.
Choose memoization based on an actual expensive computation, render path, or
identity contract; it is not required for every callback or component.

Use Zustand selectors for client state. Select stable individual values, or use
`useShallow` from `zustand/react/shallow` when returning a new object/array whose
members can be compared shallowly. Type each store and keep its responsibilities
focused. When using `persist`, restrict persisted fields with `partialize`;
exclude DOM references and transient state. Authentication has its own IndexedDB
and non-exportable DPoP key lifecycle: follow that implementation rather than
moving credentials into a general localStorage store.

Store entrypoints expose typed state and named transition actions. Compose the
player's queue/playback/preferences slices in one store to retain atomic updates;
small feature stores do not need artificial slices. Keep the playback clock in
`player-time-tracker`, outside durable Zustand state. Use `withNoopGuard` inside
`persist` and `createSelectiveJSONStorage` with an explicit durable projection.
Guard external side effects before calling them, even when the state setter is
idempotent. Consumers should call actions instead of bypassing invariants with
`setState`. Test notification/render counts and storage writes for hot paths.

Wrap store creators with outermost `withStoreDebug` for optional development
tracing. Its disabled path returns the original creator unchanged. The developer
panel enables recording on reload; never send credentials or raw native objects
to a debugger. Recorded snapshots are diagnostic, not permission to replay effects.

## API access

Use generated Orval hooks/functions in `@/shared/api-client/gen/endpoints` or the
shared `AXIOS_INSTANCE` for normal backend JSON API calls. The shared client owns
authentication, DPoP, refresh, and error handling. Use TanStack Query for server
state and invalidate/refetch the affected queries after mutations. Inspect the
actual response envelope; an HTTP 201 response does not by itself determine its
shape. Regenerate the client with `yarn generate` after a relevant schema change;
do not hand-edit generated endpoints.

Native `fetch` is appropriate for the service worker's native request forwarding
and streaming interception, and for binary/static assets such as WASM. Keep those
exceptions local to their browser/worker contract and preserve origin, client,
credential, redirect, and DPoP checks. An ordinary API request or arbitrary image
URL is not exempt simply because other fetch calls exist.

## Verification

Use Yarn, as declared by `ui/web/package.json`. Relevant checks are
`yarn typecheck`, `yarn lint`, `yarn test`, and `yarn build`; the typecheck also
checks the streaming worker with its separate configuration. Vitest configuration
is in `vite.config.ts`, setup in `tests/setup.ts`, and tests live both alongside
source (including `__tests__/`) and under `tests/`. Test behavior and meaningful
failure cases rather than incidental CSS or private state.

Playwright is separate: run `tests/e2e/playwright.config.ts` with `E2E_BASE_URL`
pointing to an explicitly routed disposable application. Its legacy default is
not an approved test target. Follow AGENTS.md test-domain and network-isolation
rules. Unit/DOM tests do not replace a browser check for a
worker, media, layout, or interaction issue. Report exactly which checks ran and
which did not; existing tests and CI checks do not establish whole-app coverage.
