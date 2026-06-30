# Plan: Cross-Context Mediator for Zustand Stores

## Problem summary

The frontend has 12 independent Zustand stores across 7 feature modules. Stores directly import and mutate each other — `radio-store` calls `usePlayerStore.getState().setIsPlaying(false)`, `settings` writes to 3 stores via `setState()`, `session` writes to `player-store`. There is no single stream of truth to observe, no causal trace, and no way to debug cross-context interactions without reading every store's source.

This mirrors a DDD backend that has bounded contexts but skipped the event/command layer — services call other services' repositories directly.

## Proposed approach

Keep all stores as separate Zustand stores. Introduce a **typed action mediator** in `shared/lib/mediator/` that serves as the single coordination point between feature contexts.

**Stores stay independent.** Player, radio, EQ, etc. remain self-contained Zustand stores with their own `create()`, `persist`, and internal actions. Components continue to use `usePlayerStore`, `useRadioStore`, etc. as they do today.

**Cross-context coordination flows through the mediator.** When radio needs to pause the player, it dispatches a `player:pause` action through the mediator — it does not import `usePlayerStore`. The player registers a handler for `player:pause` that performs the actual state change. Every cross-context interaction is visible, traceable, and hookable.

### What this gives us

- **Stores stay encapsulated** — no feature imports another feature's store
- **Single trace point** — every cross-context action flows through the mediator bus
- **Chronological action log** — dev mode maintains a full timeline of all dispatched actions with timestamps, payloads, source context, and handler results
- **Discoverable wiring** — each store's handler registration file explicitly declares what it reacts to
- **Inspectable at runtime** — UI component shows live action stream, handler map, and store snapshots for operators and developers
- **Zero change to component API** — `usePlayerStore((s) => s.isPlaying)` works exactly as before

## Scope boundaries

### In scope

- Typed action bus (`shared/lib/mediator/bus.ts`) — dispatch, register, subscribe, action log
- Typed action map (`shared/lib/mediator/actions.ts`) — action name → payload type
- Per-store handler registration — each store registers handlers for actions it should react to
- Dev tools (`shared/lib/mediator/devtools.ts`) — console helpers, action log inspection
- Debug UI component(s) — live action timeline, handler map, store state inspector; accessible to operators
- Eliminate all direct cross-store imports (the 8 mutation sites identified below)

### Out of scope

- Changing component selectors or store hooks — `usePlayerStore` stays `usePlayerStore`
- Changing store internals — stores keep their own `create()`, `persist`, `partialize`
- Auth store (already uses custom IndexedDB, doesn't import other stores)
- Adding devtools middleware to stores (orthogonal to this plan)
- Backend changes

## Architecture

### Mediator structure

```
shared/lib/mediator/
├── bus.ts          — ActionBus class: dispatch, register, subscribe, log
├── actions.ts      — Typed action map (action name → payload type)
├── handlers.ts     — registerAllHandlers() — wires up per-store handler registrations
├── devtools.ts     — Dev-mode helpers: getActionLog, inspectHandlers, getStoreSnapshot
└── types.ts        — Shared types for the mediator system

features/<name>/stores/
└── <name>-handlers.ts   — Per-store: registers handlers for actions this store reacts to

shared/components/
└── mediator-dev-panel/  — Debug UI components
    ├── MediatorDevPanel.tsx      — Main panel container
    ├── ActionTimeline.tsx        — Chronological action log with filtering
    ├── HandlerMap.tsx            — Shows which store handles which actions
    └── StoreInspector.tsx        — Live state snapshot of any store
```

### Action flow

```
Radio component clicks "start station"
  → radio-store.startStation() called
  → radio-store dispatches mediator action: { type: 'player:pause', source: 'radio', payload: {} }
  → mediator looks up registered handlers for 'player:pause'
  → player's handler runs: usePlayerStore.getState().setIsPlaying(false)
  → action logged with timestamp, source, result
  → debug UI updates in real-time
```

### Action map

```ts
// actions.ts
export interface ActionMap {
  // Player coordination
  'player:pause': { reason?: string }
  'player:play': { track?: Track }
  'player:queue-add': { track: Track }
  'player:queue-insert': { tracks: Track[] }
  'player:state-restore': { queue: Track[]; currentIndex: number; currentTime: number }

  // Radio coordination
  'radio:started': { station: RadioStation }
  'radio:stopped': {}

  // Settings coordination
  'settings:apply-eq': { bands: number[]; enabled: boolean; /* ... */ }
  'settings:apply-player': { volume: number; shuffle: boolean; repeat: RepeatMode }
  'settings:apply-layout': { contextPanelMode: ContextPanelMode }
  'settings:preferences-loaded': {}

  // Catalog coordination
  'catalog:play-track': { track: Track; queue?: Track[] }
}
```

### Bus API

```ts
// bus.ts
class ActionBus {
  // Register a handler for an action type. Returns unsubscribe function.
  on<K extends keyof ActionMap>(action: K, handler: Handler<ActionMap[K]>): () => void

  // Dispatch an action. All registered handlers run. Action is logged.
  dispatch<K extends keyof ActionMap>(action: K, payload: ActionMap[K], source: string): void

  // Subscribe to all dispatched actions (for dev tools, logging middleware).
  subscribe(listener: ActionListener): () => void

  // Dev-mode: retrieve chronological action log.
  getActionLog(): ActionLogEntry[]

  // Dev-mode: inspect registered handlers.
  getHandlerMap(): Record<string, string[]>

  // Dev-mode: clear action log.
  clearLog(): void
}
```

### Handler registration example

```ts
// features/player/stores/player-handlers.ts
import { mediator } from '@/shared/lib/mediator/bus'
import { usePlayerStore } from './player-store'

export function registerPlayerHandlers() {
  mediator.on('player:pause', (payload) => {
    const state = usePlayerStore.getState()
    if (state.isPlaying) {
      state.setIsPlaying(false)
    }
  })

  mediator.on('player:state-restore', (payload) => {
    usePlayerStore.setState({
      queue: payload.queue,
      currentIndex: payload.currentIndex,
      currentTime: payload.currentTime,
    })
  })

  mediator.on('radio:started', () => {
    const state = usePlayerStore.getState()
    if (state.isPlaying) {
      state.setIsPlaying(false)
    }
  })
}
```

### Debug UI

A collapsible panel (toggled via keyboard shortcut or admin menu) that shows:

1. **Action Timeline** — chronological list of all dispatched actions. Each entry shows:
   - Timestamp
   - Action type
   - Source context
   - Payload (collapsible JSON)
   - Handler execution time
   - Any errors from handlers

   Filterable by source context, action type prefix, or time range.

2. **Handler Map** — shows which actions each context handles. Useful for understanding the dependency graph at a glance.

3. **Store Inspector** — dropdown to select any store, shows current state as expandable JSON. Auto-refreshes on state changes.

This panel is available in all environments, not just dev mode. Operators use it to diagnose issues in staging/production.

## Cross-store mutations to eliminate

These are the 8 sites where stores directly import and mutate each other. Each becomes a mediator action dispatch:

| # | Source | Current call | Action dispatched |
|---|--------|-------------|-------------------|
| 1 | `radio-store.startStation()` | `usePlayerStore.getState().setIsPlaying(false)` | `dispatch('player:pause', { reason: 'radio-started' }, 'radio')` |
| 2 | `session/use-session` | `usePlayerStore.setState({...})` | `dispatch('player:state-restore', { queue, currentIndex, currentTime }, 'session')` |
| 3 | `settings/use-audio-preferences` | `useEqStore.setState({...})` | `dispatch('settings:apply-eq', payload, 'settings')` |
| 4 | `settings/use-player-preferences` | `usePlayerStore.setState({...})` | `dispatch('settings:apply-player', payload, 'settings')` |
| 5 | `settings/use-layout-preferences` | `useContextPanelStore.setState({...})` | `dispatch('settings:apply-layout', payload, 'settings')` |
| 6 | `settings/use-preference-bootstrap` | subscribes to + reads eq, player, context-panel stores | `dispatch('settings:preferences-loaded', {}, 'settings')` |
| 7 | `catalog/SongContextMenu` | `usePlayerStore.getState().addToQueue(track)` | `dispatch('player:queue-add', { track }, 'catalog')` |
| 8 | `radio/use-radio-audio` | reads `usePlayerStore.getState().volume/muted`, subscribes to player store | Player volume changes dispatch `player:volume-changed` — radio subscribes |

After migration, no feature store imports another feature's store.

### Reads that stay direct

Components reading state from stores (e.g., `NowPlayingBar` reading `playerStore.currentTrack`) are direct reads and stay as-is. The mediator handles cross-context **mutations and coordination**, not data flow to the UI layer.

## Implementation units

### Unit 1: Mediator bus + action types

**Goal:** Create the core `ActionBus` class, typed action map, and singleton instance. No store integration yet — just the bus itself.

**Files:**
- Create: `ui/web/src/shared/lib/mediator/types.ts`
- Create: `ui/web/src/shared/lib/mediator/actions.ts`
- Create: `ui/web/src/shared/lib/mediator/bus.ts`
- Create: `ui/web/src/shared/lib/mediator/__tests__/bus.test.ts`

**Test scenarios:**
- `dispatch` calls all registered handlers for an action type
- `dispatch` does not call handlers for other action types
- `on` returns unsubscribe function that removes the handler
- `subscribe` listener receives all dispatched actions
- Action log records timestamp, type, source, payload for each dispatch
- `getHandlerMap` returns registered action → handler names
- Handler errors are caught and logged, do not break other handlers
- Multiple handlers for same action type all execute
- `clearLog` empties the action log

**Verification:** `yarn vitest run shared/lib/mediator`

**Dependencies:** None

### Unit 2: Store handler registrations + handler wiring

**Goal:** Create per-store handler files and the central `registerAllHandlers()` function. Stores register handlers for actions they should react to. Wire up in app initialization.

**Files:**
- Create: `ui/web/src/shared/lib/mediator/handlers.ts`
- Create: `ui/web/src/features/player/stores/player-handlers.ts`
- Create: `ui/web/src/features/radio/stores/radio-handlers.ts`
- Create: `ui/web/src/features/equalizer/stores/eq-handlers.ts`
- Create: `ui/web/src/features/layout/stores/context-panel-handlers.ts`
- Create: `ui/web/src/features/catalog/stores/catalog-handlers.ts`
- Create: `ui/web/src/features/session/session-handlers.ts` (or wherever session lives)
- Modify: `ui/web/src/main.tsx` or `App.tsx` — call `registerAllHandlers()` on startup
- Create: `ui/web/src/shared/lib/mediator/__tests__/handlers.test.ts`

**Test scenarios:**
- `registerAllHandlers()` registers expected handlers for each action type
- Player handler for `player:pause` pauses playback
- Player handler for `radio:started` pauses playback
- Radio handler for `player:play` stops radio
- EQ handler for `settings:apply-eq` updates EQ state
- Dispatching an action with no registered handlers logs a warning in dev mode
- Handler registration is idempotent (calling twice doesn't double-register)

**Verification:** `yarn vitest run shared/lib/mediator`

**Dependencies:** Unit 1

### Unit 3: Eliminate cross-store imports

**Goal:** Replace all 8 direct cross-store mutation sites with mediator dispatches. Each store that currently reaches into another store instead dispatches an action.

**Files:**
- Modify: `ui/web/src/features/radio/stores/radio-store.ts` — replace `usePlayerStore.getState()` with `dispatch('player:pause', ...)`
- Modify: `ui/web/src/features/session/hooks/use-session.ts` — replace `usePlayerStore.setState()` with `dispatch('player:state-restore', ...)`
- Modify: `ui/web/src/features/settings/hooks/use-audio-preferences.ts` — replace `useEqStore.setState()` with `dispatch('settings:apply-eq', ...)`
- Modify: `ui/web/src/features/settings/hooks/use-player-preferences.ts` — replace `usePlayerStore.setState()` with `dispatch('settings:apply-player', ...)`
- Modify: `ui/web/src/features/settings/hooks/use-layout-preferences.ts` — replace `useContextPanelStore.setState()` with `dispatch('settings:apply-layout', ...)`
- Modify: `ui/web/src/features/settings/hooks/use-preference-bootstrap.tsx` — replace direct store subscriptions with mediator subscription or simplify
- Modify: `ui/web/src/features/catalog/components/menus/SongContextMenu.tsx` — replace `usePlayerStore.getState().addToQueue()` with `dispatch('player:queue-add', ...)`
- Modify: `ui/web/src/features/radio/hooks/use-radio-audio.ts` — replace `usePlayerStore.getState().volume/muted` reads and subscriptions with mediator pattern

**Test scenarios:**
- `radio-store.startStation()` dispatches `player:pause` and does not import `usePlayerStore`
- `session` hook dispatches `player:state-restore` and does not import `usePlayerStore`
- `settings` hooks dispatch actions instead of calling `setState` on foreign stores
- `SongContextMenu` dispatches `player:queue-add` instead of calling `usePlayerStore.getState().addToQueue()`
- No feature store file imports another feature's store (verified by grep)

**Verification:**
- `yarn vitest run`
- `grep -rn "from '@/features/" ui/web/src/features --include="*.ts" --include="*.tsx" | grep -v "__tests__"` — verify no cross-store imports remain in store/hook files (component reads are fine)

**Dependencies:** Unit 2

### Unit 4: Dev tools + action log

**Goal:** Create dev helpers for inspecting the mediator state from the browser console and programmatically.

**Files:**
- Create: `ui/web/src/shared/lib/mediator/devtools.ts`
- Create: `ui/web/src/shared/lib/mediator/__tests__/devtools.test.ts`

**Test scenarios:**
- `getActionLog()` returns chronological list of all dispatched actions
- `inspectHandlers()` returns map of action types to registered handler descriptions
- `getStoreSnapshot(storeName)` returns current state of a named store
- Action log entries have correct timestamps, sources, and payloads
- Log can be filtered by action type prefix and source context
- `clearLog()` empties the log
- Exposed on `window.__MEDIATOR__` in dev mode for console access

**Verification:** `yarn vitest run shared/lib/mediator`

**Dependencies:** Unit 1

### Unit 5: Debug UI components

**Goal:** Build React components that render the mediator's action timeline, handler map, and store inspector. These are part of the app, accessible to operators.

**Files:**
- Create: `ui/web/src/shared/components/mediator-dev-panel/MediatorDevPanel.tsx` — main container, toggle open/close
- Create: `ui/web/src/shared/components/mediator-dev-panel/ActionTimeline.tsx` — scrollable action log with filters
- Create: `ui/web/src/shared/components/mediator-dev-panel/HandlerMap.tsx` — visual map of action → handler relationships
- Create: `ui/web/src/shared/components/mediator-dev-panel/StoreInspector.tsx` — dropdown + JSON tree for any store
- Create: `ui/web/src/shared/components/mediator-dev-panel/index.ts`
- Modify: `ui/web/src/features/layout/components/AppShell.tsx` — mount `MediatorDevPanel` (hidden by default, toggled via keyboard shortcut or admin settings)

**Test scenarios:**
- Panel renders with action timeline, handler map, and store inspector tabs
- Action timeline updates in real-time as actions are dispatched
- Timeline can be filtered by source context and action type
- Handler map shows all registered actions and their handler stores
- Store inspector displays current state of selected store
- Panel can be toggled open/closed
- Does not render in production unless operator has admin role (configurable)

**Verification:** `yarn vitest run shared/components/mediator-dev-panel`

**Dependencies:** Units 1, 4

### Unit 6: Integration test + cleanup

**Goal:** End-to-end test that dispatches actions through the mediator and verifies the correct store state changes occur. Clean up any remaining cross-store imports. Verify the full system works.

**Files:**
- Create: `ui/web/src/shared/lib/mediator/__tests__/integration.test.ts`
- Verify all files

**Test scenarios:**
- Dispatch `player:pause` → player store's `isPlaying` becomes false
- Dispatch `radio:started` → player store pauses, radio store has active station
- Dispatch `settings:apply-eq` → EQ store updates bands
- Dispatch `player:state-restore` → player store has restored queue
- Full action log captures the sequence
- No runtime errors

**Verification:**
- `yarn vitest run`
- `yarn tsc --noEmit`
- `yarn vite build`

**Dependencies:** Units 3, 4, 5

## Verification strategy

1. **Unit tests**: Bus, handlers, devtools each have comprehensive test suites
2. **Integration tests**: Full dispatch → handler → store mutation flow
3. **Import audit**: `grep` confirms no cross-store imports in store/hook files
4. **Type check**: `yarn tsc --noEmit` passes
5. **Build check**: `yarn vite build` succeeds
6. **Manual smoke test**: Load app, play a song, switch to radio, verify EQ settings persist, open debug panel, see action timeline
7. **Console access**: `window.__MEDIATOR__` available in dev tools for ad-hoc inspection

## Risks

- **Handler ordering**: If two handlers for the same action have ordering dependencies, there's no guaranteed execution order. Mitigate by documenting this and keeping handlers independent.
- **Circular dispatches**: A handler could dispatch another action that triggers a handler that dispatches back. The bus should detect and warn about recursion depth. Add a max-depth guard.
- **Performance**: The action log grows unbounded. Add a configurable max log size (default 500 entries) with oldest-first eviction.
- **Missing handler registration**: If `registerAllHandlers()` isn't called, dispatched actions silently do nothing. The bus should log a warning in dev mode when an action has no handlers.
