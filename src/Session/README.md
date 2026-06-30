# Session

Tracks a user's active listening session: which device holds the session, the current playback queue, track position, and playback state.

## Domain Concepts

- **ListeningSession** -- Aggregate root. One per user (enforced by unique `user_id`). Holds the playback queue (array of track references), current track index, playback position in seconds, and a playback state (`playing`, `paused`, `stopped`).
- **Device** -- A physical or logical device that can claim a session. Tracked by `device_id` (client-generated UUID) and `user_id`. A user can have multiple registered devices but only one can hold the active session at a time.

## Ports

| Interface | Implementation | Purpose |
|-----------|---------------|---------|
| `ListeningSessionRepositoryInterface` | `ListeningSessionDoctrineRepository` | Persistence of ListeningSession aggregates |

## Events

| Event | Trigger | Payload |
|-------|---------|---------|
| `SessionCreated` | `ListeningSession::create()` | userId, queue |
| `SessionClaimed` | `ListeningSession::claim()` | userId, deviceId |
| `SessionUpdated` | `ListeningSession::updatePlayback()` | userId, deviceId, queue |

## Interactions

1. A client creates a session when playback begins.
2. The client claims the session for its device via `claim(deviceId)`. If the same device already holds the session, this is a no-op.
3. During playback, the client sends periodic `updatePlayback()` calls with the current queue, track index, position, and state.
4. When playback ends, `end()` sets the state to `stopped`.
5. `markUsed()` updates the `lastUsedAt` timestamp for session liveness tracking.
