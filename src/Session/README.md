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
- **Page identity** — A random UUID that a browser document creates once, keeps
  in memory and sends as `pageId` with every registration. It is never stored, so
  a reload, a duplicated tab or a restored browser session gets a new one, while a
  page restored from the back/forward cache keeps its own. The incarnation row
  holds it in `page_id`; no snapshot carries it. With the tab, it tells a new page
  from a live page that registers again (see
  [Pages that register again](#pages-that-register-again)).
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

`playback_destination_incarnations.page_id`, added by `Version20261111180000`,
holds the page identity of the registration that created the row. It is nullable
and has no index, constraint or default. Only a registration compares it, on the
rows of the incarnations the session names; a row without one names no page.

Three guarded updates serve the releases and the succession described below.
`retireIncarnation` ends a registration without renewing its presence and keeps
the first retirement time; it records `stopped` and a cursor only when the caller
passes them, and changes nothing on a row that is already retired and `stopped`.
`succeedIncarnation` addresses an incarnation's unanswered commands to its
successor and the successor's epoch, keeping their disclosure mark, and
names the successor in each role the earlier incarnation holds in an open
transfer. `reportCursor` records a cursor and renews presence, and leaves the
reported playback state alone.

Five statements stay SQL:

| Statement | Reason |
|-----------|--------|
| The account advisory lock in `lockSession` | DQL has no advisory lock, and this lock serializes the first insert of a session row |
| The insert of a missing session row, `ON CONFLICT (user_id) DO NOTHING` | It absorbs a row created outside the account lock, where a unit-of-work insert would fail and close the entity manager. It runs only when the locked read finds no row |
| `retireConfirmedPausedBrowser` | The proof is one predicate over the session's whole command ledger, with `UPDATE … FROM … RETURNING`, a JSON operator and the database clock. It also derives the revision that later queue edits and volume changes left |
| `retireConfirmedStoppedBrowser` | The same statement shape, so the two proofs stay in one form |
| The recovery expiry check in `localRecovery` | It compares against `clock_timestamp()`, the clock as it stands after the transaction waited for its locks. DQL offers only the transaction's start time |

`PlaybackSnapshotCostTest` holds the number of statements a relayed snapshot and a
control may send, because the relay builds a snapshot once per second for every
bound socket. The ceilings are 6 for a snapshot to an unbound socket while the
runner plays, 9 for a bound socket while the runner rests, 10 for the snapshot that
first shows a command to its runner, and 21 for a control that issues one command.

## Events

| Event | Trigger | Payload |
|-------|---------|---------|
| `DeviceRegistered` | `Device::create()` | `user_id`, `device_id`, `occurred_at` |

Playback commands and complete snapshots use the coordination ports and durable
command ledger.

## Interactions

1. A browser registers its device, then registers an endpoint for its tab and
   connection, naming its page. The returned capability stays in tab memory.
   Reconnecting creates a fresh incarnation, and the old capability stays valid
   for the old one. A page whose socket closed stops its players at the close.
   Unless one of them still plays, it then sends a
   [close report](#close-report) for its earlier incarnation with the old
   capability before it registers again. A page whose socket was replaced without
   a close keeps playing and registers again as the same page.
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
   A failed transfer sends its target nothing further. The browser lets go of a
   player it only prepared once the session names the page neither as the runner
   nor in a transfer, so the preparation does not keep its stream alive. In the
   same case it silences a player that is still playing, once, without
   acknowledging anything: another tab has taken the session over. A paused or
   stopped player is left alone, because the session may have released a paused
   tab that is still open, and a stop would close its movie.
5. The destination executes commands and acknowledges their command, attempt,
   revision, incarnation and epoch. Observations report actual playback state,
   position and supported volume. They renew presence but do not commit transfers.
6. `PlaybackCommandRelay` checks bound connections once per second and sends
   changed complete snapshots or pending commands. HTTP snapshot recovery uses
   the same coordinator; the web client no longer reads `GET /api/session/snapshot`,
   because the socket and the answer to each of its session requests carry every
   snapshot it uses. A stop retains the media, queue and cursor, and remains
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
not establish that playback stopped. The release by a registration from another
page of the same tab still rests on the lock: the web client claims a tab identity
only while it holds that tab's lock, and a page gives the lock up only by ending or
by entering the back/forward cache, after it has fenced its players at `pagehide`.
A second page of the tab therefore registers only after the first has gone (see
[Pages that register again](#pages-that-register-again)).

A page recovers its own tab's record only when its players are silent. A page whose
socket was replaced without a close, or whose player still plays after a
disconnect, keeps its own record, because a recovery would tell the server that a
playing tab had stopped. Records of other tabs are recovered as before.

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
that follows nor any later stop can settle. A stop that supersedes a disclosed
pending `play`, `load` or `seek` marks the runner the same way; a superseded
pause, stop, volume change or queue edit cannot have started anything and leaves
a confirmed runner confirmed.
An expired presence lease is no part of either proof: a tab that was shown a play
and then cut off keeps playing. None of what ends the unknown state is a timer:
the runner's own answer, a [local recovery](#browser-local-recovery) of its
incarnation, the [close report](#close-report) of its page, a registration of its
tab [from another page](#pages-that-register-again), or the listener's
[override](#play-here-anyway).

A browser runner that is unavailable can be released after a confirmed pause or
a confirmed stop. The paused release requires the runner's acknowledgement of a
pause, a quiesce or a stop, or of a start whose command wanted the paused state,
for its incarnation and epoch, with consistent paused state, no later command that
counts, no pending command and no unfinished transfer. A start that wanted
playback is no such acknowledgement. The acknowledgement must be the last thing
that could have moved playback, so the session must stand at the revision after
the acknowledged command's. Two kinds of later command do not count, and each
moves that expected revision:

- A command that closed as `never_effective` cannot have changed the runner.
  After one, the session must stand at the revision the last such settlement
  recorded.
- A queue edit or a volume change cannot start or move playback, whether the
  runner applied it, rejected it or let it expire. Each leaves the session exactly
  one revision past its own once it is answered or expires, so the session must
  stand one past the highest revision among them.

When both kinds follow the acknowledgement, the session must stand at the later of
the two revisions. Any other movement fails the comparison and keeps the runner,
and every other later command counts, a superseded one included. A queue edit or
volume change that is still unanswered is pending: it holds the release until it
is answered or expires, within the 20-second command lifetime. The stopped release requires the runner's own stop
report, consistent stopped state, and no pending command or unfinished transfer.
It has no later-command clause, so a queue edit or volume change after the stop
never held it. The web video player keeps its stop report true through a volume
change: on a stopped player a `volume` command keeps the value for the next video
it opens and answers with the stopped observation, without opening the movie
again.
Retirement and release share the session transaction and retain
the media, queue and cursor. An expired presence lease alone is insufficient; this
recovery does not apply to external receivers.

A queue edit's answer, applied or rejected, records the cursor it reports, renews
the runner's presence and advances the revision once. It says nothing about
playback, so the session's observed state and the runner's own last report stand:
a rejected queue edit does not turn a confirmed paused session unknown, and an
applied one does not make an unknown session look confirmed.

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
incarnation, on a control or a transfer after its revision and transfer guards,
and on a close report and an override. A reloaded tab therefore sees its own
paused or stopped runner released in the answer to its registration, and a
transfer that follows a lapse starts its target without a source.

A failed transfer does not hand the session back to a runner already recorded
gone. When a transfer fails, by expiry, by its target's answer or because its
target went away, and the runner it would fall back to is retired with its own
last report `stopped`, the session is released from that runner instead, and an
unknown state settles as paused.

### Releasing an incarnation that has gone

Local recovery, a registration from another page, a close report and an override
release the session from one incarnation through the same routine. The
incarnation's unanswered commands are superseded. When the incarnation is the
runner, or the source of a transfer whose target was not yet asked to start, the
session is left paused without a runner. When it is a transfer's target, the
transfer fails, and the source keeps the session unless the source is itself
retired with its own last report `stopped`. When it is the source of a transfer
whose target was asked to start, nothing changes and the transfer goes on. The
callers differ in their authority, their cursor and what they record about the
incarnation:

| Caller | Authority | Cursor | Incarnation afterwards |
|--------|-----------|--------|------------------------|
| Local recovery | Account and recovery credential | The checkpoint, when its media, queue position and revision match the session | Retired and `stopped`, recovery result stored |
| Registration from another page | Account, tab and a different page identity | The last recorded | Retired by the registration and `stopped` |
| Close report | That incarnation's capability | The reported checkpoint, on the same match | Retired and `stopped` at the reported position |
| Override | The asking tab's capability; nothing proves the destination silent | The last recorded | Retired, observed state unchanged, capability kept |

### Pages that register again

A registration names its tab (device and endpoint) and, when the client sends one,
its page. When the session still names an earlier incarnation of the same tab, as
its runner or as the source or target of its open transfer, the two page
identities decide what the registration does. Inside the registration's
transaction the order is fixed: the transfer and control expiries, `register()`,
the succession, the existing proofs, then the release.

- **Another page.** A tab holds one live document at a time (see
  [Browser-local recovery](#browser-local-recovery)), so a different page identity
  proves the earlier page gone, whatever the session last knew about it, a runner
  confirmed playing and a transfer participant included. The session is released
  from that incarnation as the table above states, and the incarnation is recorded
  as stopped. The answer to the registration already shows the result, so the
  reloaded page's first Play is not refused. The existing proofs run first: a
  confirmed stopped runner with nothing outstanding still ends stopped.
- **The same page.** One live document registered again, as after a socket
  replaced without a close. The new incarnation takes the earlier one's place as
  the runner and in the open transfer, and the earlier incarnation's unanswered
  commands move to the new incarnation and epoch with their identity, attempt,
  revision and disclosure mark. Nothing is released and the revision does not
  change, because an answer is accepted only at its command's own revision.
  Answered commands stay with the earlier incarnation, so its pause or stop proves
  nothing for the new one, and an answer that still names the earlier incarnation
  is refused as stale. An answered quiesce is the exception: a transfer attempt
  asks only its source to quiesce, so the answer counts for whichever incarnation
  of that page now holds the source's role.
- **No page identity**, on the registration or on the earlier incarnation, proves
  nothing. Only the existing proofs apply.

A registration of another tab releases nothing beyond what the existing proofs
release.

The shared live transport replaces a socket without closing it after 15 seconds
without a live message. A web page whose endpoint is still bound when the new
socket opens keeps its players playing. It fences nothing, posts neither a local
recovery nor a close report for its own incarnation, and registers again with its
page identity. On this path a
registration that fails before it is sent, or is refused, is sent again after one
second, and one still unanswered is sent again after 15 seconds, for as long as
the socket stays open; `retry()` sends one whenever the current socket carries no
answered registration. A page whose new socket has carried no answered
registration 20 seconds after it opened, the command lifetime, can no longer be
commanded. It then stops its players and takes the path of a closed socket:
it reports its earlier incarnation closed and registers again. A command delivered
again under the new incarnation is answered from the effect the runner already had
for that command identity, with the incarnation and epoch of the delivery it
answers. A socket close still stops the page's players.

### Close report

`POST /api/session/close` is an endpoint's own report that it is going away and its
players have stopped. It takes `incarnationId` and a checkpoint of the shape local
recovery takes, and it requires that incarnation's `X-Baander-Playback-Endpoint`
capability, checked before the session lock. It carries no expected revision and
no request identity, because a page that is going away cannot retry. In one
transaction, after the expiries, the incarnation is retired and recorded `stopped`
at the reported position without renewing its presence, the existing proofs run,
and the release routine runs for whatever still names the incarnation. Its
destination is unavailable at once, so the next tab does not wait for a lapse. The
answer is the snapshot, without commands. A report for an incarnation the session
does not name, whether it never was named or was succeeded, changes only that
incarnation's row and gets the same answer. A repeated report changes nothing,
whatever cursor it carries. A close report and a local recovery for the same incarnation
release the session once: whichever comes second changes nothing, and a local
recovery still answers `recovered`.

A page can register again as itself after it prepared its report, and the report
can then reach the server after a successor has taken the page's place at the same
revision. While the session still stands at the revision of the report's
checkpoint, nothing has run since the page stopped, and the report speaks for the
successor too: the successor is retired and recorded `stopped` at the reported
position, and the session is released from it as from the reporting incarnation.
A successor that acted since has advanced the revision and keeps its place.

The web client sends the report on two occasions:

- **At `pagehide`.** Every API request carries a DPoP proof, and signing one is
  asynchronous, which a `pagehide` handler cannot wait for. The page therefore
  keeps a request prepared ahead: the URL, the authorization, the proof
  and the capability header of one `POST /api/session/close`. It prepares one after
  each registration answer and each successful presence observation (every ten
  seconds), and again when the access token changes, so the proof stays inside the
  60 seconds the server accepts; a page whose timers the browser throttled can
  hold an older proof, which the server refuses. The handler adds the runner's
  cursor, sends the request once with `fetch` and `keepalive`, then fences the
  players. The request bypasses the API client: it gets no nonce retry or token
  refresh, and nothing reads its answer. A tab that is only hidden sends nothing,
  because it keeps playing music by design and `visibilitychange` cannot tell
  hiding from leaving.
- **After a disconnect.** Before it registers again, a page whose socket closed
  posts the report for its earlier incarnation with the old capability and the
  cursor it held when it stopped. This replaces the snapshot, `observe` and
  acknowledgement steps it used to send. When one of its players still plays, it
  posts nothing, and its registration succeeds the earlier incarnation. A failed
  report is logged and does not stop the registration. A page that comes back as
  the runner while it holds no player reports `stopped` at once and in every later
  presence observation, and the server's branch for a runner's own stop report
  releases the session.

A page restored from the back/forward cache reports again through the second path
before it registers with its page identity; once either report was accepted, the
registration finds nothing to succeed. A crash, a killed browser, a discarded
mobile page and a network that failed first send no report. The next tab is then
refused and continues through [Play here anyway](#play-here-anyway) once the
registration has lapsed, 30 seconds after the last presence report.

### Play here anyway

`POST /api/session/override` ends the hold of a browser destination that is no
longer registered, on the listener's word: the session's runner when no transfer is
open, or the target of an uncertain transfer. Nothing proves that destination
silent, so the guard is the one fact the server has: while the destination still
reports, the server can reach it and cannot tell a crashed tab from a playing one.
The request takes `requestId`, `expectedRevision` and the asking tab's
`incarnationId`, with that tab's capability. The guards run in this order, and a
refusal changes nothing:

1. The capability, checked before the session lock:
   `An endpoint capability is required.` or `Endpoint authority is invalid.`
2. The request identity. One already used for another payload is refused with
   `The playback request identity has already been used for another payload.`
   A repeat of the same request returns its recorded result.
3. The revision: `Playback revision conflict.`
4. The asking tab must be registered: `Connect this browser before playing here.`
5. The existing proofs run. A runner they release in this request was held when
   the request arrived, so the override succeeds for it.
6. A transfer open in another state than uncertain:
   `Playback controls wait for transfer reconciliation.`
7. Nothing held, or a destination of another kind than a browser:
   `No playback is waiting to be overridden.`
8. A destination that is still registered:
   `The destination is still connected. Stop playback there.`

An accepted override runs the release routine for the destination. A failed
transfer's source keeps the session while it is registered; a source that is not
registered either is released in the same request. Each overridden incarnation is
retired, so a late presence report cannot make it available again. It keeps its
capability, so its returning page can still read its commands and send its reports,
and it is not recorded as stopped, because nothing proved it silent. The override
is one row in the command ledger: a `stop` addressed to the overridden incarnation
that carries the override's `requestId` as both its command identity and its
request identity, stored already settled with the outcome `overridden`. The row
gives the request its replay
and its `requestResult`, and no proof counts it as an acknowledgement. The request
digest leaves out `incarnationId`, so the same request sent after the asking tab
registered again is still a replay.

The web client offers the override as "Play here anyway" in the playback
destination menu while no transfer is open and the session's runner is unavailable
or missing from the destination list, or while a transfer is uncertain and its
target is unavailable or missing. The item is disabled while the tab is
disconnected, busy or recovering. The status line above it names the destination
and the consequence: "`<name>` is unavailable and has not confirmed that it
stopped. Play here anyway ends its hold; if it is still playing, it continues until
it reconnects." For an uncertain transfer, the existing transfer status gains "It
is unavailable." and the same consequence. A tab refused for unconfirmed playback
reads "Previous playback is unconfirmed. Stop it in its original browser, or
choose Play here anyway in the playback destination menu." Pressing the item makes sure the tab's own registration holds, then posts
the override with a new request identity through the intent queue. An accepted
override closes the menu; a refusal keeps it open with the server's reason in its
alert.

After an accepted override that leaves no runner and no transfer, the tab issues
again the request it was last refused for unconfirmed playback, when that request
was a `play` or a song selection that wanted playing and the refusal is at most
120,000 ms old by the wall clock. An older refusal, or one the clock places in the
future, only takes over. A refused movie selection is not held: the movie starts
from its own Play button. The held request is also dropped when the session stops
being unconfirmed by other means, when a later request from the tab succeeds, and
when the tab disconnects or registers a new incarnation.
