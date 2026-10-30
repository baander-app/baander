# Live updates

A mounted view needs to learn when its server data changes. A query's stale time
does not schedule that work: it only determines whether the cached result is fresh
when another operation checks it. Baander's live-update contracts connect each
reader to the changes that require another authorized read.

The initial implementation defines coverage and message validation. Delivery and
automatic refresh are not enabled by these contracts alone.

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

## Verification

Run the frontend coverage and protocol suites with
`yarn --cwd ui/web test tests/live-updates`. Run the backend contract suites through
the [disposable test runner](testing.md) with
`bash scripts/test-backend-container.sh tests/Unit/Shared/LiveUpdates`.
The shared fixtures must pass on both sides before a protocol change is integrated.

For a new stored source, also verify rollback, deletion, ownership changes, and
bulk writes against disposable PostgreSQL once capture is installed. For a new
reader, verify local mutation feedback, remote changes, inactive variants,
recovery, and access removal. Editors must retain drafts; refreshing a profile
list must not apply a profile or restart playback.
