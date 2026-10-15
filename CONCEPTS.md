# Concepts

> Shared domain vocabulary for this project — entities, named processes, and status concepts with project-specific meaning. Seeded with core domain vocabulary, then accretes as ce-compound and ce-compound-refresh process learnings; direct edits are fine. Glossary only, not a spec or catch-all.

## Web server lifecycle

### Drain

The web server's single way of stopping: whatever asked it to stop, it stops taking new connections, lets the work it already has finish within a bounded time, tells clients to come back, and exits on its own.

A drain has a deadline, which an operator configures, and a shorter drain window inside it. During the window, client connections are still open: requests in progress and file downloads finish, real-time connections are closed with a code that tells clients to reconnect, the readiness probe reports not ready, and the liveness probe still answers but says the server is draining. Streams still running when the window closes are cut so that the client sees an error rather than a normal end, which lets a player resume instead of skipping to the next track. A drain runs once however many stop requests arrive. Anything that has not stopped by the deadline is killed, and the server then exits with a failure status.
*Avoid: graceful shutdown (for this process specifically), stop.*

### Worker reload

Replacing the web server's worker processes while the server itself keeps running and keeps its client connections. A reload never starts a drain, and a reload requested during a drain is ignored.

## Authentication

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

### Socket reconciliation

The periodic check inside the web server that closes every WebSocket connection whose token chain is dead: every access token of the chain is revoked or expired, or the chain is gone from the database. The chain is the unit, not the handshake token, because a rotation revokes the token a socket was opened with while the session goes on; rotation alone never closes a socket. The close on logout, revoke and reuse verdicts is the fast path; the reconciliation is the backstop that ends a socket within a minute whatever ended its chain, including a console process in another container or an expiry nobody refreshed.

### Signed delivery URL

A video manifest or segment URL that carries an HMAC signature and an expiry instead of requiring a token. It is a bearer capability: whoever holds a valid one is served, with or without a session, until it expires or the signature fails; the signing endpoint issues one only to a signed-in member of the video's library. A token presented with it must still be valid, and the DPoP proof is not read on delivery routes.

## Playlists

### Playlist collaborator

A user the playlist's owner has named on a playlist. While the playlist is collaborative, a collaborator may view it and add, remove and reorder its songs, adding only songs their own library membership lets them see; when the owner turns collaboration off, collaborators keep viewing and lose editing. The owner alone changes the playlist's metadata, deletes it and names collaborators; a collaborator may leave; an administrator who is not the owner may view it and nothing more.

## Backend verification

### Test lane

A disjoint set of selected test files that the backend coordinator runs in one
container with its own result artifacts. Ordinary tests share a ParaTest worker
pool; native tests run one fresh process per file without networking. Each
functional/integration lane owns a disposable Compose project with separate
PostgreSQL, Redis and application containers. Together, the lane manifests must
contain every discovered test file exactly once.
