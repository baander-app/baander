# Public API identities

## Contract

Baander's internal UUID identifies a persisted entity inside the application.
Its PublicId identifies that entity to clients. Public HTTP requests, responses,
pagination cursors, signed delivery paths, JWT identity claims and WebSocket
messages must neither expose nor require an internal UUID. This supersedes the
earlier schema-only reconciliation that retained UUID API contracts.

Public resources use `publicId` for their own identity, removing `uuid` and any
`id` that exposed the primary key. Existing contextual reference names such as
`songId`, `videoId`, `parentId` and `libraryId` may remain, but their values are
public IDs. Existing snake-case public-ID fields remain consistent with their
resource contract. Slugs remain valid public names where deliberately supported;
UUID fallback resolution is removed. Invalid or inaccessible public identities
retain the established validation and authorization outcomes.

Provider IDs, OAuth token IDs, WebAuthn credential IDs, browser device tokens,
idempotency keys and runtime worker indices are separate protocol identities.
They are not database primary keys. Preserve their semantics and distinguish
them explicitly in schemas and tests. In particular, a device's client token is
different from its persisted public ID. OAuth client identifiers already use
public IDs; both JWT signing paths and principal resolution must agree.

Internal aggregate IDs, foreign keys, domain events, outbox records and cache
keys may retain UUIDs. Transport projections resolve relationships through
application ports and repository projections, preserving library and ownership
scopes. Do not add repository calls to resource serializers or new cross-context
repository dependencies. Do not replace a UUID leak with an empty or incorrect
relationship value.

## Implementation units

1. Catalog, Media, Playlist and Library: remove UUID response fields; project
   related public identities for songs, movie videos, duplicate albums, images,
   genre parents and deletion previews. Resolve artist-credit, playlist-song,
   genre-parent and library inputs by public ID. Add public IDs to Library and
   Genre, including state, mapping, direct writers, hydration and lookups.
2. Auth: remove user/client/login-block/passkey UUID contracts, switch admin user
   and settings resolution, and coordinate JWT issuance, refresh and principal
   lookup. Add real public IDs to login blocks and passkeys. WebAuthn user handles
   use the user's public ID; account signals and authenticate results agree.
   Already-issued UUID-subject access tokens are rejected; clients refresh into
   the new claim contract or sign in again. Do not add a UUID principal fallback.
3. Session, Party, Activity, Discovery and Notification: migrate public session,
   device, party member and related identities, realtime payloads and notification
   cursors. Add public IDs to listening sessions, devices and webhooks. Preserve
   separate browser device tokens. Project activity relationships without leaking
   internal event data. Keep ordering and authorization for cursor resolution.
4. Transcode, Radio and UserPreference: migrate video manifest signing, transcode
   session routes and references, radio entity references and EQ profiles. Trace
   radio provider station UUIDs separately from persisted identities. Add public
   IDs only where a persisted entity needs public addressing. Existing transcode
   job PublicIds remain the delivery identity.
5. Scheduler, Recommendation and monitoring: add scheduled-job and recommendation
   public IDs; migrate routes, resources and source/target references. Give public
   scheduler receipts an independent identity without changing internal occurrence
   uniqueness or request deduplication. Job monitors already have public job IDs;
   remove serialized internal message data from their public projection.
6. Clients: update web and embedded-player models, account comparisons, route
   parameters, selectors, pagination deduplication and fixtures. Update narrow
   native callsites affected by removed identity fields without extending native
   feature scope. Regenerate OpenAPI and the generated web client from the final
   backend contract. Remove UUID fallback use in playback and identity helpers.

Each context owner owns its model, persistence mapping, application contracts,
transport adapters and tests. One PostgreSQL specialist owns the consolidated
forward migration and coordinates column names with every context owner.
New public IDs are generated independently of UUIDs, backfilled for existing
rows, unique and non-null. Keep primary keys, foreign keys, revision values and
local user data. Test fresh installation and upgrading populated tables in
disposable infrastructure. Do not reset development databases.

## Verification and acceptance

Focused behavioral tests must prove public IDs resolve to the correct authorized
entity and that supplying its internal UUID fails. Exercise nested relationships,
empty and nullable associations, notification cursor ordering, playlist mutations,
passkeys, both JWT issuers and authentication/refresh, device tokens versus public
device IDs, scheduler idempotency and realtime party/session/transcode traffic.
Use fixtures whose UUIDs and public IDs differ; recursively reject known internal
IDs in public output, including tokens, manifests and diagnostics. Protocol UUID
exceptions require explicit evidence rather than a blanket UUID schema ban.

Run focused tests during implementation, then the authoritative backend suites in
`baander-ci-prewarm:local`, PHPStan, Deptrac, container wiring and OpenAPI freshness
checks. Run web typecheck, lint and tests, embedded-player checks and the serial
security/web runtime drills affected by authentication and signed delivery.
Verify migrations and lookups using disposable PostgreSQL, including existing
rows and unique public-ID constraints. Inspect remaining UUID annotations,
parsers, serialization and client references; classify every public occurrence.

Apply independent simplification and code review findings before delivery. Record
actual counts, elapsed times and remaining limitations in `ROADMAP.md`, define
identity terms in `CONCEPTS.md`, and update affected API/operator documentation
without plan references in book pages. Apply prose-fix to documentation. Commit
locally; pushing requires a separate request.

## Workflow

Independent review found one decision that cannot be resolved by a database
backfill: an enrolled authenticator's user handle cannot be changed. Existing
passkey invalidation or a legacy protocol exception needs the user's choice.
That question is pending; existing credential migration and handle changes wait
for the answer. Other implementation units may proceed. Verification must cover
the chosen behavior with an existing credential fixture. Scheduler receipts use
an independent persisted public ID while retaining internal deduplication keys.

The compound-engineering skills are unavailable in this environment. Use the
documented manual workflow: independent plan review before implementation,
bounded ownership, local commits, simplification, independent code review and
recorded verification. The user has authorized the identity correction; no
additional approval is needed for this pre-release API change.
