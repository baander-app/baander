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
- **Disclosed command** — A command that was included in a snapshot built for its
  own incarnation. That snapshot is the only way a command leaves the server, and
  `playback_commands.disclosed_at` records the first time it happened, in the same
  transaction. A command without the mark cannot have been executed.

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

## Persistence

Every table of the context has a Doctrine entity under
`Infrastructure/Doctrine/Entity/`: `ListeningSessionEntity`, `DeviceEntity` and the
four playback coordination entities `PlaybackDestinationEntity`,
`PlaybackDestinationIncarnationEntity`, `PlaybackTransferEntity` and
`PlaybackCommandEntity`. The four are `readOnly` and hold every reference to another
table as a scalar `Uuid`. Each of their foreign keys references `(id, user_id)`,
which an ORM association cannot target, so `PlaybackSessionSchemaListener` declares
the eight composite keys and `SessionForeignKeys` the owner keys to `users`.
Migrations create the tables and their check constraints; schema comparison checks
everything else against the mappings.

`PlaybackStore` runs under the account's advisory lock and the session row lock.
Its statements take three forms:

- **Insert.** A new row is persisted, flushed and detached in the method that
  creates it. A later read of the same operation therefore finds the row, and no
  managed entity outlives the call.
- **Update.** One DQL `UPDATE` whose predicate is the guard. No entity is loaded.
- **Read.** DQL hydrated to plain rows keyed by column name, never to managed
  entities. The coordinator reads an endpoint before it holds the lock, and the
  relay serves every socket of a worker through one entity manager; a managed copy
  would go stale behind both.

The database clock stamps every stored instant except a recovery credential's
expiry, which the PHP clock sets thirty days ahead. An update stamps in its
statement. An insert takes the transaction's `CURRENT_TIMESTAMP`, read with a row
the method needs anyway.

Five statements stay SQL:

| Statement | Reason |
|-----------|--------|
| The account advisory lock in `lockSession` | DQL has no advisory lock, and this lock serializes the first insert of a session row |
| The insert of a missing session row, `ON CONFLICT (user_id) DO NOTHING` | It absorbs a row created outside the account lock, where a unit-of-work insert would fail and close the entity manager. It runs only when the locked read finds no row |
| `retireConfirmedPausedBrowser` | The proof is one predicate over the session's whole command ledger, with `UPDATE … FROM … RETURNING`, a JSON operator and the database clock |
| `retireConfirmedStoppedBrowser` | The same statement shape, so the two proofs stay in one form |
| The recovery expiry check in `localRecovery` | It compares against `clock_timestamp()`, the clock as it stands after the transaction waited for its locks. DQL offers only the transaction's start time |

`PlaybackSnapshotCostTest` holds the number of statements a relayed snapshot and a
control may send, because the relay builds a snapshot once per second for every
bound socket.

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
   destination support. A load that changes the media and names no position starts
   at zero: the session has one cursor and no resume position per media.
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
   a target stop. A transfer that runs out of time is uncertain only when its start
   was disclosed to the target. Otherwise it fails: a target that was never shown a
   start cannot be playing.
   A failed transfer sends its target nothing further. The browser drops a player
   it only prepared once it is neither the runner nor the target of a transfer, so
   the preparation does not keep its stream alive.
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

For its active, available endpoint, the browser reuses presence confirmed within
the current heartbeat interval. The freshness window starts when registration or
observation begins, so a delayed response cannot extend it. Otherwise the browser
renews presence before playback. If its endpoint has been retired, it fences the
old execution and waits for a fresh registration.
The destination chooser lists available targets and reports unconfirmed previous
playback separately from unavailable device history.

Closing a video view carries the browser incarnation and video identity into the
command queue. It stops playback only if that browser still owns the same video
and no transfer is pending. During an uncertain transfer the browser silences its
own video and the view closes without a control; the session stays fenced for
every tab until the transfer settles. Closing a remote view leaves its playback
unchanged; the explicit Stop control still targets the selected destination.

## Unanswered commands and gone runners

A control that expires unanswered leaves the session unknown, with a stop owed to
the runner, whenever the runner may have acted on it. It settles without a stop
only when the control cannot have changed anything. That requires a browser
runner that the session and the runner's own last report both hold stopped or
paused, and a control that was never disclosed or is itself a stop or a pause.
The command then closes with the outcome `never_effective` and the session wants
the confirmed state again. Each such command records the session revision its
settlement left, in `playback_commands.settled_revision`.

Two more kinds of unanswered control close as `never_effective`, and neither
changes what the session wants. A control that a stop replaces closes that way
when it was never disclosed to its browser runner, or when it is a stop or a
pause on a runner confirmed stopped or paused. A volume change or queue edit that
expires closes that way when it was never disclosed; one the runner was shown may
have been applied and closes as superseded.

A disclosed `play`, `load` or `seek` that expires may have run. The session turns
unknown and the runner's own report is marked unknown too, so neither the stop
that follows nor any later stop can settle; only the runner's answer ends it. A
stop that supersedes a disclosed pending `play`, `load` or `seek` marks the runner
the same way; a superseded pause, stop, volume change or queue edit cannot have
started anything and leaves a confirmed runner confirmed.
An expired presence lease is no part of either proof: a tab that was shown a play
and then cut off keeps playing.

A browser runner that is unavailable can be released after a confirmed pause or
a confirmed stop. The paused release requires the runner's acknowledgement of a
pause, a quiesce or a stop, or of a start whose command wanted the paused state,
for its incarnation and epoch, at the immediately preceding command revision, with
consistent paused state and no later or pending commands or unfinished transfer.
A start that wanted playback is no such acknowledgement. Commands that closed
as `never_effective` do not count as later commands, and after them the session
must stand at the revision the last one recorded instead of the one the
acknowledgement left; a superseded command still counts. The stopped release
requires the runner's own stop report, consistent stopped state, and no pending
command or unfinished transfer. Retirement and release share the session
transaction and retain the media, queue and cursor. An expired presence lease
alone is insufficient; this recovery does not apply to external receivers.

A runner's observation that it is paused is no such acknowledgement. While the
session wants playback the runner may resume without another command, so a
paused report made in that state is not its last word, and a runner that goes
away after it stays held. This includes the state a stop leaves when it expires
unanswered on such a runner: the session then reads paused, without an
acknowledgement behind it. A browser that stops playing for good, as at the end
of its media, has to ask for the pause or stop through the session and
acknowledge it.

The release check runs wherever its proof can first hold: on a snapshot, on an
endpoint registration in the transaction that retires the tab's previous
incarnation, and on a control or a transfer after its revision and transfer
guards. A reloaded tab therefore sees its own paused or stopped runner released in
the answer to its registration, and a transfer that follows a lapse starts its
target without a source.
