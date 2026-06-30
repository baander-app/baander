# Settings & Session Completeness

**Date:** 2026-05-10
**Status:** Draft
**Motivation:** Pre-onboarding completeness — ship complete settings and cross-device experience before other users see the app.

## Feature 1: Passkey List & Delete

### Problem
Users can register passkeys but cannot see which passkeys they've registered or remove unwanted ones. The backend supports deletion (`DELETE /api/auth/passkey/{publicId}`) and has a `forUser()` repository method, but there is no GET endpoint or frontend UI.

### Requirements
- **R1.** Authenticated users can view all their registered passkeys
- **R2.** Each passkey shows: name, creation date, last used date
- **R3.** Users can delete a passkey with a confirmation step
- **R4.** Credential IDs and internal data are never exposed to the frontend
- **R5.** Deleting the last passkey is allowed — the user may have other auth methods (password, TOTP)

### Backend
- Add `GET /api/auth/passkey` to `PasskeyController` returning `{ data: [ { publicId, name, createdAt, lastUsedAt } ] }`
- Reuse existing `PasskeyRepositoryInterface::forUser()` — no new repository methods needed
- Follow existing OpenAPI attribute pattern from other endpoints

### Frontend
- Update `PasskeyManagement` component to fetch and display passkey list
- Each passkey row shows name, created date, last used date, delete button
- Delete triggers confirmation dialog, then calls `DELETE /api/auth/passkey/{publicId}`
- Show empty state when no passkeys are registered
- Invalidate list after successful registration or deletion

### Success Criteria
- User registers a passkey → it appears in the list immediately
- User deletes a passkey → it disappears from the list
- User with no passkeys sees helpful empty state

---

## Feature 2: User Preferences Sync

### Problem
All user preferences (audio/eq settings, UI layout, player preferences) are stored in localStorage via Zustand persist. If a user logs in from another device or clears browser data, all settings are lost. For a multi-user server, preferences should follow the account.

### Settings to sync

| Store | Settings | Notes |
|-------|----------|-------|
| eq-store | enabled, bands, preset, compressionEnabled, masterGain, normalizationEnabled, targetLufs, visualizerMode | All 8 settings — EQ tuned for a headset should be available on any device |
| context-panel-store | mode (compact/expanded/pioneer) | UI layout preference |
| player-store | volume, shuffle, repeat, muted | Queue and currentIndex are session state, not preferences |
| (already synced) | accent color, sidebar config | Via existing UserPreference endpoints |

### Requirements
- **R1.** All settings listed above sync to the server
- **R2.** Settings load from server on page visit (server is source of truth)
- **R3.** Local changes save to server immediately (optimistic local update, background PUT)
- **R4.** First-time users (no server record) get current local defaults written to server
- **R5.** No UX change — this is a storage layer swap, the UI works identically

### Backend
- One aggregate per preference group in `UserPreference` context (following `AccentColor` pattern):
  - `AudioPreferences` — GET/PUT `/api/user/audio-preferences`
  - `PlayerPreferences` — GET/PUT `/api/user/player-preferences`
  - `LayoutPreferences` — GET/PUT `/api/user/layout-preferences`
- Each stores a JSONB payload, one row per user, unique constraint on user_id

### Frontend
- On load: fetch server preferences → populate relevant Zustand stores (overriding localStorage)
- On change: update store locally (instant) + PUT to server in background
- Keep Zustand persist as offline fallback when API is unavailable

### Success Criteria
- User sets LUFS target to -16 on device A → logs in on device B → sees -16
- User switches context panel to pioneer mode → opens tablet → pioneer mode is active
- User adjusts volume to 50% on phone → opens desktop → volume is 50%
- Offline: settings still work from localStorage, sync when back online

---

## Feature 3: Cross-Device Sessions

### Problem
Users cannot see what's playing on another device or resume a listening session elsewhere. Each device maintains its own independent queue. For a multi-user media server, the listening session should be transferable.

### Requirements
- **R1.** A user can see what's currently playing on their other devices (track, queue, position)
- **R2.** A user can transfer an active session to the current device — queue, position, and playback state resume
- **R3.** If a session is active on another device, the current device shows the active session as read-only. The user can either take over the session or start a new one.
- **R4.** Only one device actively plays at a time per user. When device B takes over, device A is notified and pauses.
- **R5.** A user can always start a fresh session (new queue) regardless of other active sessions

### Key Concepts
- **Listening session**: server-side record of a user's current queue, position, and playback state
- **Active device**: the device currently playing. At most one per user.
- **Session transfer**: moving the active session from one device to another, preserving queue and position

### Backend
- New `ListeningSession` aggregate (likely in a `Session` or `Playback` bounded context)
- REST endpoints:
  - `GET /api/session` — current user's active session (queue, position, active device info)
  - `PUT /api/session` — update session state (called periodically during playback for position sync)
  - `POST /api/session/claim` — claim the session for this device (transfer from another)
  - `POST /api/session/new` — start a fresh session, replacing the current one
- WebSocket messages for real-time session events:
  - `session.claimed` — another device took over (current device should pause)
  - `session.updated` — playback state changed on another device
- Reuse existing Swoole WebSocket infrastructure (`WebSocketConnectionRegistry`, `WebSocketPusher`)
- Device identity: derive from WebSocket connection or a client-generated device ID stored in session storage

### Frontend
- On connect: fetch `/api/session` → if active session exists, show "Resume on this device?" prompt
- "Resume" calls `POST /api/session/claim` → loads queue, position, starts playback
- "New session" calls `POST /api/session/new` → clears queue, starts fresh
- During playback: periodically PUT position/queue to `/api/session` (debounced)
- On `session.claimed` WebSocket event: pause playback, show notification "Session moved to another device"

### Success Criteria
- User starts album on desktop → opens phone → sees album playing, can resume at same position
- User transfers session to phone → desktop pauses and shows notification
- User starts new queue on phone → desktop can still see the old session ended
- Network interruption doesn't lose the session — it's recoverable from server state

---

## Scope Boundaries

### In scope
- Backend endpoints for all three features
- Frontend UI for passkey list/delete
- Frontend integration for preferences sync (all groups)
- Cross-device session management (claim, transfer, new)
- Generated API client updates (orval)

### Deferred for later
- Passkey naming UI during registration (currently defaults to "Passkey")
- Passkey last-used tracking (field exists but may not be updated on every auth)
- Conflict resolution for preferences edited on two devices simultaneously (last-write-wins)
- Migration of existing localStorage data to server on first sync
- Device naming/management UI (users can't name their devices yet)
- Session history (past listening sessions)

### Outside scope
- Adding new auth methods
- Per-device preference profiles
- Preference versioning or undo
- Multi-user session sharing (that's what the Party feature does)
- Playback sync between users (also Party feature)
