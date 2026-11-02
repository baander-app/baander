# Session

Coordinates an account's playback destination, media selection, music queue and
playback position. Browser adapters execute media commands; Session records the
intent and accepts acknowledgements from the endpoint that owns each command.
Each browser tab registers and runs its own playback endpoint. Local music and
video playback require no casting companion or companion pairing.

## Domain Concepts

- **ListeningSession** — One aggregate per user, enforced by unique `user_id`.
  It holds a song or video selection, music queue, current track index, source-time
  position, desired and observed playback states, selected destination, active
  endpoint incarnation and revision.
- **Device** — A registered browser device identified by its client-generated
  `device_id` and owner. Device registration supplies a name and history; endpoint
  registration supplies the authority to execute playback commands. Forgetting a
  device removes its registration while an existing playback endpoint retains its
  own lifecycle.
- **Playback destination and incarnation** — A destination identifies a browser
  tab. Each connection registers a fresh incarnation, epoch and capability.
  Presence and capabilities describe whether that incarnation is available.
  A disconnected or expired incarnation can still have unconfirmed playback.
- **PlaybackTransfer** — A handoff with an attempt identity, source and target
  incarnations, retained cursor and desired state. It moves through `preparing`,
  `quiescing` and `starting`, then commits or enters failure or uncertainty.

## Ports

| Interface | Implementation | Purpose |
|-----------|---------------|---------|
| `ListeningSessionRepositoryInterface` | `ListeningSessionDoctrineRepository` | Persist listening-session aggregates |
| `DeviceRepositoryInterface` | `DeviceDoctrineRepository` | Persist device registrations |
| `DevicePortInterface` | `DeviceAdapter` | Register, list, rename and forget devices |
| `PlaybackCoordinationInterface` | `PlaybackCoordinator` | Apply controls, endpoint registration, transfers, acknowledgements, observations and reconciliation |
| `PlaybackStoreInterface` | `PlaybackStore` | Lock session state and persist destinations, incarnations, transfers, command intent and request identity |
| `PlaybackConnectionsInterface` | `PlaybackCommandRelay` | Bind authenticated WebSocket connections and replay snapshots and pending commands |
| `ListeningSessionInteractionInterface` in Shared | `ListeningSessionInteractionAdapter` | Connect the shared WebSocket controller to playback coordination and connection binding |

`PlaybackCoordinator` validates media through Media's
`PlaybackMediaAccessInterface` and commits state with command intent through
Shared's `TransactionPortInterface`.

## Events

| Event | Trigger | Payload |
|-------|---------|---------|
| `DeviceRegistered` | `Device::create()` | `user_id`, `device_id`, `occurred_at` |

Playback commands and complete snapshots use the coordination ports and durable
command ledger.

## Interactions

1. A browser registers its device, then registers an endpoint for its tab and
   connection. The returned capability stays in tab memory. Reconnecting creates
   a fresh incarnation; the old capability can still confirm that the old
   incarnation stopped.
2. Controls carry a request identity and expected session revision. The available
   actions are `load`, `play`, `pause`, `seek`, `stop`, `volume` and `queue`.
   Loading supplies the media selection; queue edits preserve the current media,
   position and playback state. Volume is a fraction from zero to one and requires
   destination support.
3. Session commits the control and its command intent in one transaction. An
   identical repeated request returns the original command result. The control
   response exposes that result as `requestResult`, including a pending or settled
   outcome; general snapshots omit it. Supplying `incarnationId` with its
   `X-Baander-Playback-Endpoint` capability also returns that endpoint's pending
   commands after commit. The browser executes them without waiting for the relay
   timer. Delivery identity does not change the logical request or its saved target.
4. A transfer prepares the target without playing, quiesces the source and captures
   its final cursor, then starts the target. A matching acknowledgement commits the
   active destination. Rejection before quiescence leaves the source unchanged;
   uncertain startup leaves it recoverably paused while reconciliation requests
   a target stop.
5. The destination executes commands and acknowledges their command, attempt,
   revision, incarnation and epoch. Observations report actual playback state,
   position and supported volume. They renew presence but do not commit transfers.
6. `PlaybackCommandRelay` checks bound connections once per second and sends
   changed complete snapshots or pending commands. HTTP snapshot recovery uses
   the same coordinator. A stop retains the media, queue and cursor, and remains
   unconfirmed until the destination acknowledges it.

Changing tracks on the active device is an ordinary control. The selected media
is authorized on load, play and seek. Queue entries are rechecked when the queue
changes; an unchanged queue adds no repeated access lookups. Both immediate HTTP
delivery and relay delivery use the same command identity and execution guards.

## Browser-local recovery

Eligible local adapters can recover after a reload or closed tab. Before executing,
the browser acquires an exclusive Web Lock and commits a separate recovery
credential to IndexedDB. Its lifecycle fence pauses owned media elements and
prevents delayed callbacks from restarting them. Music also fences graph output
without changing the listener's EQ or volume settings. Releasing a lock alone does
not establish that playback stopped.

`POST /api/session/recover-local` requires the authenticated owner and a credential
bound to the previous incarnation and tab. It retires that incarnation and retains
the media and queue paused when the recovered browser was the active runner. A
saved cursor is accepted only when its media, queue position and revision still
match. Recovery during a transfer preserves any unconfirmed execution on the other
endpoint. Replaying a consumed credential returns its recorded result without
changing a later transfer.

The server stores only the credential hash. This credential cannot execute
commands or authorize media delivery; the normal execution capability remains in
memory. Browsers without the required storage, locks or qualified stop behavior
retain ordinary playback with automatic recovery unavailable. External receiver
routes require their own stop confirmation.

A snapshot can also release an unavailable browser after a confirmed pause. This
requires a matching acknowledgement for its incarnation and epoch, the immediately
preceding command revision, consistent paused state, and no later or pending commands
or unfinished transfer. Retirement and release share the session transaction and
retain the media, queue and cursor. An expired presence lease alone is insufficient;
this recovery does not apply to external receivers.

For its active, available endpoint, the browser reuses presence confirmed within
the current heartbeat interval. The freshness window starts when registration or
observation begins, so a delayed response cannot extend it. Otherwise the browser
renews presence before playback. If its endpoint has been retired, it fences the
old execution and waits for a fresh registration.
The destination chooser lists available targets and reports unconfirmed previous
playback separately from unavailable device history.

Closing a video view carries the browser incarnation and video identity into the
command queue. It stops playback only if that browser still owns the same video
and no transfer is pending. Closing a remote view leaves its playback unchanged;
the explicit Stop control still targets the selected destination.
