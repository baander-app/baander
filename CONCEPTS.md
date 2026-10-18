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

### Transcode control receipt

A durable record that one session has applied a request identity. It prevents the
same request from applying again after other controls have intervened. Controls
fan out transactionally to sibling renditions of the same playback owner and video.

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

## Backend verification

### Test lane

A disjoint set of selected test files that the backend coordinator runs in one
container with its own result artifacts. Ordinary tests share a ParaTest worker
pool; native tests run one fresh process per file without networking. Each
functional/integration lane owns a disposable Compose project with separate
PostgreSQL, Redis and application containers. Together, the lane manifests must
contain every discovered test file exactly once.
