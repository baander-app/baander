# Concepts

> Shared domain vocabulary for this project — entities, named processes, and status concepts with project-specific meaning. Seeded with core domain vocabulary, then accretes as ce-compound and ce-compound-refresh process learnings; direct edits are fine. Glossary only, not a spec or catch-all.

## Identity

### Internal UUID

The persisted identity used inside the application for primary keys, foreign keys and domain events. Public transport contracts do not expose or require it to identify an entity.

### Public ID

An entity's independent, 21-character NanoID, represented by `PublicId` in code and usually `publicId` in resources. Public routes, relationships, cursors and realtime messages use this identity; existing snake-case contracts use `public_id`. A contextual name such as `videoId` still carries a public ID. Resolving it does not grant access to the entity.

### Browser device token

The client-generated UUID sent as `deviceId` during device registration and session claims, and in the `X-Baander-Device-Id` header. It identifies the browser installation within the account. The device resource's `publicId` addresses rename and forget operations and appears as a listening session's `activeDeviceId`.

### EQ audio-output token

The optional client value stored in an EQ profile's `deviceId`. It names an audio output according to the client and does not refer to the persisted device resource. The profile itself has a public ID.

### OAuth client identifier

The registered client's public ID, carried by OAuth's `client_id`, administration's `clientId` and the access-token JWT's `client_id` claim. It is separate from a token identifier or secret.

## Web server lifecycle

### Drain

The web server's single way of stopping: whatever asked it to stop, it stops taking new connections, lets the work it already has finish within a bounded time, tells clients to come back, and exits on its own.

A drain has a deadline, which an operator configures, and a shorter drain window inside it. During the window, client connections are still open: requests in progress and file downloads finish, real-time connections are closed with a code that tells clients to reconnect, the readiness probe reports not ready, and the liveness probe still answers but says the server is draining. Streams still running when the window closes are cut so that the client sees an error rather than a normal end, which lets a player resume instead of skipping to the next track. A drain runs once however many stop requests arrive. Anything that has not stopped by the deadline is killed, and the server then exits with a failure status.
*Avoid: graceful shutdown (for this process specifically), stop.*

### Worker reload

Replacing the web server's worker processes while the server itself keeps running and keeps its client connections. A reload never starts a drain, and a reload requested during a drain is ignored.

## Authentication

### Passkey user handle

The user's public ID encoded as bytes for WebAuthn registration, authentication and account signals. It identifies the account independently of the authenticator's credential ID. Passkeys enrolled with UUID handles are invalidated and require re-enrollment; authentication has no UUID-handle fallback.

### Refresh token chain

The sequence of refresh tokens one sign-in produces through rotation. Each rotation consumes the presented token and issues a replacement that links back to it; every token in the chain is bound to the same DPoP key. Revoking the chain ends that sign-in everywhere it was in use and closes the WebSocket connections it opened; it is what logout and reuse detection do.
*Avoid: token family (the vendor term).*

### Consumed refresh token

A refresh token that a rotation has already used. It stays stored, marked with the instant it was consumed, so a later presentation can be judged: a lost-response grace or a reuse.

### Lost-response grace

The server's answer to a client that lost the response of a rotation: a consumed refresh token presented again by the same DPoP key, within the grace window, while its replacement is still unused, gets a fresh pair and the unused replacement is revoked. Any other presentation of a consumed token is a reuse, which revokes the chain. The window is an operator setting measured on the database clock; zero turns the grace off.

### DPoP store outage

The state in which the Redis store behind the DPoP nonce and replay checks cannot answer. The checks stay fail-closed: no proof is accepted, but the server answers "come back later" (503 with a retry delay) rather than a nonce challenge or a denial, so clients keep their session and retry. The two revocation routes, logout and revoke, are the exception: they accept a proof whose replay check could not run once every other check passed, log one warning, and revoke the chain.

### Pending logout

A logout the browser has recorded but the server has not yet confirmed. The tab ends its session locally at once; the record keeps the DPoP key pair and the tokens of the ended session so the logout can be signed and sent again on the next start or when the browser is back online, until the server answers that the chain is revoked or gone.

### WebSocket handshake ticket

A single-use credential obtained through an authenticated, DPoP-protected API
request. It authorizes one upgrade at `/api/ws`, bound to the requesting user,
access token and browser Origin. It expires within thirty seconds, or when the
access token expires if that is sooner. Redemption consumes it atomically and
checks current token and user state. A ticket is a bearer capability during that
short window; it does not sign later WebSocket messages.

### Socket reconciliation

The periodic check inside the web server that closes every WebSocket connection whose token chain is dead: every access token of the chain is revoked or expired, or the chain is gone from the database. The chain is the unit, not the handshake token, because a rotation revokes the token a socket was opened with while the session goes on; rotation alone never closes a socket. The close on logout, revoke and reuse verdicts is the fast path; the reconciliation is the backstop that ends a socket within a minute whatever ended its chain, including a console process in another container or an expiry nobody refreshed.

### Signed delivery URL

A video manifest or segment URL that carries an HMAC signature and an expiry instead of requiring a token. It is a bearer capability: whoever holds a valid one is served, with or without a session, until it expires or the signature fails; the signing endpoint issues one only to a signed-in member of the video's library. A token presented with it must still be valid, and the DPoP proof is not read on delivery routes.

Every child playlist, initialization file, media fragment and subtitle URL inherits the manifest's absolute expiry. Reading a playlist cannot extend it; an authenticated signing refresh can issue a new top-level URL. Expiry refuses new delivery requests after the deadline without revoking bytes already admitted or buffered by the player.

## Playlists

### Playlist collaborator

A user the playlist's owner has named on a playlist. While the playlist is collaborative, a collaborator may view it and add, remove and reorder its songs, adding only songs their own library membership lets them see; when the owner turns collaboration off, collaborators keep viewing and lose editing. The owner alone changes the playlist's metadata, deletes it and names collaborators; a collaborator may leave; an administrator who is not the owner may view it and nothing more.

### Playlist revision

An opaque marker of the complete playlist state returned with its displayed song order. A reorder must present that revision; if the playlist changed, the caller reloads it before choosing an order again. Hidden songs affect the revision without exposing their identifiers.

## Rate limiting

### Limiter generation

The Redis token shared by every subject of one named limiter. Clearing the limiter
replaces this token, making previously read state unwritable without scanning
subject keys. Consumers retry against the new generation; other named limiters
retain their own state.

### Limiter revision tombstone

An empty subject state with an opaque revision that Redis retains for ten seconds
after an absent read or per-subject reset. Snapshots are writable for at most two
seconds, so a paused writer cannot mistake an expired state for the absence it
previously read.

## Provider requests

### Public destination policy

The destination check shared by metadata, IPRD catalog and push transports. It
permits approved HTTPS hostnames on port 443 only when every resolved address is
public, then pins an approved address while preserving TLS hostname verification.
It has no LAN exception. Webhooks and operator-configured lyrics retain separate
policies.

## Media availability

### Accepted directory

A discovered directory whose file-index updates and ingestion intent committed
together. The scan summary counts these directories as queued. Acceptance does
not mean the asynchronous transport has received the message or catalog ingestion
has finished; the outbox relay owns that handoff and its bounded retries.

### Available source

A library file whose latest successful discovery recorded it as present. A completed
scan or queued import that finds the file missing marks its retained index record
unavailable. A later scan restores availability and queues the returning file even
when its content is unchanged. This observation does not diagnose a failed mount
or guarantee that the file is still reachable at playback time. A catalog track
without an index observation is unavailable until a scan confirms its source.

## Media execution

### Transcode execution generation

The identity of one admitted encoding attempt inside a job-specific output
directory. A seek or restarted attempt advances the generation so stale output
cannot be delivered as current output. Different jobs have independent generations.
This identity is distinct from a worker deployment's boot generation.

### Transcode control revision

The monotonically increasing order of committed desired-state changes for a video
job. The worker applies the latest revision, preserving paused state across a seek
and treating cancellation as terminal.

### Owned video job

A transcode job belonging to one playback user, video and quality tier. Another
user's job for the same source has independent controls, execution claims and
output. Historical jobs whose owner cannot be resolved retain no owner.

### Rendition set

The immutable set of owned video job public IDs and their individual execution
generations carried by a signed adaptive HLS or DASH manifest request. It fixes
which outputs the player may switch between; a changed set needs a new signature.

### Published presentation

The closed video and audio fragments recorded by one generation's atomic native
manifest, with their actual clocks, durations and codec declarations. Delivery
uses this index to advertise available media. Public fragment indexes are
zero-based ordinals within each generation and track; native file sequence numbers
remain separate.

### Canonical audio track

The primary playback job's audio track shared by the video representations in a
signed rendition set. Other quality tiers do not become separate audio choices.
A source without audio has no canonical audio track.

### Source audio selection

The source audio chosen for movie encoding: the first track marked default in
discovery order, or the first audio track when none is marked. No audio means no
selection. Its nullable ordinal is a position among audio streams, separate from
the global FFprobe stream index. The same choice supplies mapping, filter
attributes and manifest language without reordering discovered tracks. It is
independent of the selected video's Source timestamp origin and does not add
interactive track controls.

### Selected video endpoint

The end of the selected non-attached movie video, expressed in Source position
coordinates. Qualified selected-stream timing excludes extension from audio,
subtitles, artwork and unselected video. The probed movie duration carries this
endpoint when it can be resolved; otherwise it retains a container-based estimate.
That estimate is not a verified selected video endpoint and does not establish
exact-end coverage.

### Source position

A playback position measured from the beginning of the source video. Subtracting
the execution generation's source start time gives its local media position. The
movie seek control spans the full source duration even while the published
presentation contains only part of the remaining media.

### Source timestamp origin

An explicit timestamp anchor established from the selected movie stream's first
verified presentation packet. Subtracting this anchor maps source timestamps to
Source position. It can differ from the container's reported start time and is
separate from a generation's requested source start position. An absent anchor
retains the existing input timestamp handling; it does not mean an inferred zero.

### Transcode control receipt

A durable record that one session has applied a request identity. It prevents the
same request from applying again after other controls have intervened. Controls
fan out transactionally to sibling renditions of the same playback owner and video.

### Source subtitle track key

The letter `s` followed by a subtitle stream's absolute FFprobe index, such as
`s2`. It identifies that source track in extraction jobs, generation output and
signed subtitle URLs. Language, title and dispositions describe the track and do
not identify it; two tracks may share a language.

## Asynchronous execution

### Asynchronous workload catalog

The admitted message classes, transport selections, resource classes and routing
modes checked against compiled Messenger wiring. A default workload routes to its
transport without a stamp; an explicit-only workload can run inline and enters its
transport only through an explicit selection.

### Workload resource class

A catalog description of a workload as `io`, `cpu` or `coordination`. It describes
the work without reserving execution slots, CPU or memory.

### Consumer identity

The Redis consumer name generated from a deployment namespace digest, supervisor
boot ID, worker role and launch generation. Its registration is scoped to a Redis
stream and consumer group.

### Worker deployment pair

The fixed control and execution containers admitted together under one deployment
namespace and boot identity. Each has its own memory, CPU and PID ceilings and a
disjoint CPU set. Control owns the lease and coordination; execution observes the
lease and owns native processes. A private IPC volume connects the pair.

### Ordinary admission target

The desired number of concurrent ordinary asynchronous receives and owned deliveries within a worker deployment pair's admitted resident-consumer maximum. Lowering the target leaves existing occupied permits held until their work completes.

### Occupied permit

A grant held by one ordinary consumer while it receives work and through any returned delivery's handler and transport completion. An uncertain receive or completion keeps the permit occupied until exact evidence resolves ownership; it does not authorize replacement work.

### Joint retirement barrier

The retained boot reservation that prevents replacement until both worker units
have confirmed containment or cancellation of an unclaimed creation intent.
Lease expiry and a direct-child exit do not satisfy this barrier.

### Native execution attempt

A bounded request owned by the execution daemon and identified by deployment,
boot, lease epoch, client generation and attempt identity. An uncertain reply does
not authorize another process or release its reserved slot.

## Backend verification

### Test lane

A disjoint set of selected test files that the backend coordinator runs in one
container with its own result artifacts. Ordinary tests share a ParaTest worker
pool; native tests run one fresh process per file without networking. Each
functional/integration lane owns a disposable Compose project with separate
PostgreSQL, Redis and application containers. Together, the lane manifests must
contain every discovered test file exactly once.

## Store debugging

### Recorder instance

One successfully created store admitted by the enabled store debugger. Its
recorder-local ID distinguishes it from stores with the same display name and is
not reused after retirement or a history clear. Explicit retirement stops recording
that instance.

### History baseline

The immutable diagnostic captures immediately before the store debugger's retained
event window. Eviction folds discarded events into this baseline; clearing starts a
new epoch from current recorded captures. Reconstruction applies retained events to
the baseline through the selected event.

### Isolated store verification

A comparison of one recorded synchronous action with the current registered pure
transition, calculated from detached captured data. It reports match, mismatch or
unavailable and leaves live application state and recorder history unchanged. Its
initial scope is the catalog selection store's `select` and `clear` actions.

## Audio codec

### HE-AAC profile

The object type an AAC stream or encoder session uses: AAC-LC codes the whole spectrum; HE-AAC v1 adds spectral band replication, HE-AAC v2 adds parametric stereo on top. The native binding and the Electron service name them `lc`, `he` and `hev2`. The ADTS header of an HE-AAC stream still names the AAC-LC profile and the core rate; a decoder recognises the profile from the data it carries.

### Spectral band replication

The HE-AAC tool that codes only the lower half of the spectrum with AAC-LC at half the sample rate and reconstructs the upper half from it, guided by per-frame envelope energies, noise floors and inverse filtering levels carried in a fill element after each channel element. A stream's output rate is twice its core rate once it carries SBR data, and a frame is 2048 samples.
*Avoid: SBR as a separate stream or codec.*

### Parametric stereo

The HE-AAC v2 tool that codes a stereo pair as one SBR channel plus per-band intensity differences and coherence; the decoder widens the mono core to two channels. A stream carrying it has one stream channel and two output channels.

### Codec delay

Samples at the input rate before the first input sample appears in the decoded output of a stream the encoder produced: 2048 for AAC-LC, 5635 for the HE-AAC profiles. The encoder reports it and its flush drains it.

