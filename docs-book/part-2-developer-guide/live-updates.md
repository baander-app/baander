# Live updates

A mounted view needs to learn when its server data changes. A query's stale time
does not schedule that work: it only determines whether the cached result is fresh
when another operation checks it. Baander's live-update contracts connect each
reader to the changes that require another authorized read.

The backend captures committed changes, projects revisions, and delivers authorized
hints over the existing WebSocket connection. Browser adoption is still in progress;
the backend contract alone does not enable automatic view refresh.

## Registering a reader

Each feature owns its query descriptors. A descriptor records its family, query
key variants, source tables or external source, authorization audience, refresh
policy, and verification. Include list, detail, filtered, and paginated keys.
Readers that keep server results outside React Query also need a descriptor;
Recents, Timeline, sidebar configuration, and EQ profiles are examples.

Classify the source before choosing a refresh policy:

- Stored state changes when a database transaction commits.
- Sampled state, such as worker occupancy, needs a bounded cadence while visible.
- Demand-driven state, such as an external lyrics search, follows explicit input.
- Deployment-fixed definitions need a new read when the server incarnation changes.

The coverage test inspects actual consuming calls. Unused generated endpoint
exports do not require descriptors. A new consuming call must be classified even
when an adjacent call already uses the same family. Classification establishes
coverage; a behavior test must still prove that the right change refreshes it.

## Mapping a stored change

The source catalog lives in `tests/Fixtures/LiveUpdates/capture-sources.json`.
Record the queried tables and relationships, relevant columns, old and new
audiences, delete dependencies, and any response cache between storage and the
reader. Do not include secret values or row snapshots in change notifications.
Lease heartbeats that do not alter visible state need no refresh.

An audience describes who may learn that data changed. User-private data uses its
owner; library and playlist data follows current access; administrative data
requires the current role. Shared authenticated data must be explicitly classified.
Neither a family name nor a notification grants access to a resource.

## Committed capture and projection

The capture migration installs triggers on the catalog's declared tables. A
business write appends family and audience identifiers to `query_change_inbox`
in the same transaction. A rollback therefore removes the hint as well as the
write. Triggers compare the relevant fields, retain old and new audiences, and
resolve deletion dependencies before their parent rows disappear. Bulk personal
updates use statement transition tables to record each affected recipient once.
The inbox stores identifiers rather than source rows or secrets. TOTP capture
compares only whether the secret is absent, because that changes visible security
status.

`DoctrineLiveChangeProjector` takes an advisory lock and folds at most 1,000
committed inbox rows into `query_scope_revision`. Updating the revisions and
deleting those selected inbox rows happen in one transaction. An inbox ID is an
address, not a commit-order cursor: an earlier ID can commit after a later one,
and the next batch still processes it. Revision rows remain after deletion of
their source resources so a surviving counter cannot restart at an older value.

The projector owns its transaction. Its 225ms statement deadline leaves time for
rollback before PostgreSQL's 250ms transaction deadline; a failed projection
discards the connection. The revision reader accepts an idle connection or a
read-only transaction and queries at most 500 scopes per batch. An authorization
snapshot can therefore read membership and revisions in one consistent
transaction. Both tables are migration-managed and excluded from Doctrine's
entity schema comparison.

## Message contract

PHP and TypeScript validate the same examples in
`tests/Fixtures/LiveUpdates/protocol.json`. The finite family vocabulary appears
in `LiveFamily` and `LIVE_FAMILIES`; the fixture tests detect disagreement.

A subscription contains only `type: live.subscribe` and `version: 1`.
Clients cannot select users, rooms, families, or raw query keys. Server messages
use `live.ready`, `live.changed`, `live.reset`, or `live.health` and carry a
subscription identity, authorization generation, opaque authorization fingerprint,
family names, and pipeline health. Generations are positive decimal strings,
bounded by PostgreSQL bigint, so JavaScript does not round them.

Resource contents remain behind their normal authorized HTTP endpoints. The
fingerprint identifies a change in authorization; it is not an access credential.
A healthy connection alone does not prove that publication or subsequent reads
succeeded.

`live.ready` establishes the subscription's permitted families. `live.changed`
names affected families. An authorization change sends `live.reset` with the union
of the old and new families, including families whose access was removed. That
union tells the browser what to purge; it is not the new permission set. The client
must fence pending work and obtain the current recovery snapshot before scheduling
resource reads.

## Authorization and recovery

`GET /api/live/recovery` uses the normal DPoP authentication path and returns a
private, non-cacheable snapshot of permitted families, the authorization fingerprint,
and pipeline health. Disabled or deleted accounts are denied. Database failures
cannot produce a successful baseline.

The snapshot reads the current account, library access, playlist access, revisions,
and backlog in one read-only repeatable-read transaction. Context-owned ports
resolve access with scalar database reads, so retained entities and token roles
cannot preserve revoked permissions. A 500ms statement deadline and a one-second
transaction deadline bound each snapshot. A failed rollback closes the connection.

## Worker delivery

HTTP worker zero checks for committed changes every 250ms, including when no
browser is connected. Each HTTP worker reconciles its own subscriptions every
second, rotating fairly through at most 32 principals per pass. Overlap guards
prevent concurrent passes. Worker shutdown clears timers and pending frames;
draining workers stop starting projection and reconciliation work.

Database services are released at the read or projection boundary, before socket
delivery. Coroutine cleanup provides a second release path. Send and watchdog
callbacks also register cleanup: logging can acquire pooled services too. Each
connection has at most one pending frame, with family changes coalesced without losing reset
information. Delivery limits the socket queue plus the outgoing frame to 64KiB;
a 100ms watchdog closes a peer whose native send remains blocked.

Health messages are due every five seconds. Read or projection failure reports
degraded health while retaining the last baseline. Recovery to current health is
reported even when the revision vector did not change. A backlog older than five
seconds is also degraded. Clients still need their own expiry and HTTP recovery
policy when no message arrives.

## Inspecting the runtime

The existing server statistics HTTP endpoint and `app:server:stats` command expose
`live_updates` for each worker. Reading these diagnostics does not acquire database
services or disclose subscription identities.

| Field | Meaning |
| --- | --- |
| `projector_enabled` | This worker started the projection timer. |
| `last_successful_projection_check_age_ms` | Age of the last successful check, including empty or busy returns; it does not prove revisions advanced. |
| `last_successful_reconciliation_age_ms` | Age of the last successful authorization and revision read. |
| `max_subscription_read_age_ms` | Oldest successful read among this worker's current subscriptions. |
| `backlog_observed` | Whether a successful snapshot has observed the inbox. |
| `last_observed_oldest_pending_age_ms` | Backlog age at that observation; null means empty only when `backlog_observed` is true. |
| `projection_error_count`, `read_error_count` | Failures since this worker started. |
| `active_subscriptions` | Current subscriptions on this worker. |
| `pending_send_count` | Pending coalesced frames, excluding a send already in progress. |
| `slow_peer_close_count` | Connections closed for delivery overload. |

Null check or read ages mean no successful operation has been recorded. The
backlog observation is historical and does not advance with the clock. Use these
fields together: a recent empty-or-busy projection check cannot establish that
pending changes have been published.

## Verification

Run the frontend coverage and protocol suites with
`yarn --cwd ui/web test tests/live-updates`. Run the backend contract suites through
the [disposable test runner](testing.md) with
`bash scripts/test-backend-container.sh tests/Unit/Shared/LiveUpdates`.
The shared fixtures must pass on both sides before a protocol change is integrated.

For a new stored source, also verify rollback, deletion, ownership changes, and
bulk writes against disposable PostgreSQL. For a new
reader, verify local mutation feedback, remote changes, inactive variants,
recovery, and access removal. Editors must retain drafts; refreshing a profile
list must not apply a profile or restart playback.
