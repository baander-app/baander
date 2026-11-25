# Production Runtime Drills

This page covers the drills that run the production runtime against disposable services: container startup, outbox and producer atomicity, the Swoole web server's runtime and drain, WebSocket handshake and proxy log security, request identity across the web workers, and the manual Safari checks.

## Production Runtime Drills

```bash
bash scripts/test-container-startup.sh
timeout 300 bash scripts/test-worker-runtime-container.sh
bash scripts/test-worker-command-container.sh
bash scripts/test-outbox-runtime-container.sh
bash scripts/test-producer-runtime-container.sh
bash scripts/test-web-runtime-container.sh
bash scripts/test-web-shutdown-container.sh
```

These run the production kernel and actual repositories against disposable
PostgreSQL and Redis on isolated networks, and clean up on exit. Each covers one
part of the runtime:

- The startup contract uses isolated executable probes to check argument, PID and
  signal handling.
- The legacy worker-runtime drill checks transport redelivery with a historical
  Supervisor fixture; it does not represent deployment packaging.
- The worker-command drill exercises the current PID-1 supervisor and the committed
  lease lifecycle.
- The outbox drill checks notification projections, receipts, channel handoffs, and
  replay after a lost acknowledgement.
- The producer drill rejects outbox writes, verifies rollback through a second
  connection, and retries through the same bus. It covers operator user creation,
  registration tokens/preferences, email verification token retention, and recovery
  after an ORM flush closes the entity manager. That is three auth producers, not
  every event producer, and it is not a whole-schema audit.

The web drill starts the real Swoole server (`app:serve`) in the production
environment, with Mailpit receiving its mail. It fails unless a password-reset
email for a user whose saved language is Danish arrives in Danish, an in-progress
rendition streams with `Accept-Ranges: none` and grows as it is written, and the
server's own encode of a generated track answers `Range: bytes=100-199` with 206
once complete. It also replays Safari's opening requests (`bytes=0-1`, then
`bytes=0-65535`) against an in-progress rendition and compares the answers with
`tests/Fixtures/WebRuntime/safari-in-progress.txt`. For the in-progress cases the
fixture holds the rendition's encoder lock and writes its partial file itself, so
encode speed cannot change the answers. A different answer fails the drill with a
diff; when the change is intended, update the baseline in the same commit. The
baseline records what the server answers, not whether Safari plays it.

The drill then checks health alerts, with the monitor checking every 2 seconds
(`HEALTH_MONITOR_INTERVAL_SECONDS=2`). It stops PostgreSQL, reloads the server's
workers during the outage through the swoole bundle's API server
(`PATCH http://127.0.0.1:9200/api/server`), and starts PostgreSQL again. It fails
unless the admin and the super-admin each receive exactly one PostgreSQL alert,
delivered after PostgreSQL returns and naming an outage that began before the
reload, and no second alert arrives in the next three checks.

Next, the drill stops Redis while signed-in sessions exist. It signs in as the
admin twice through the real login, each time with a fresh DPoP key
(`app:auth:setup-clients` has seeded the first-party client). It then stops the
Redis container and drives both sessions with `scripts/web-runtime/redis-outage.php`
inside the app container. The first token pair is the session that survives the
outage; the second pair is logged out during it. The drill fails unless:

- the refresh of that session answers `503` with a `Retry-After` delay,
  `Cache-Control: no-store`, no `DPoP-Nonce` and no `WWW-Authenticate`, and
  `GET /api/auth/me` with the session's access token answers the same `503`, not
  `403`, each within the web client's 10-second attempt;
- a logout with the second pair answers `200` during the outage, because logout
  verifies its proof without the replay check;
- the monitor logs Redis unhealthy during the outage;
- after Redis starts again, the same refresh token refreshes, taking one nonce
  challenge when the restart lost the stored nonce, and the new access token is
  accepted;
- the second pair's access token and refresh token answer `401`, since its chain
  was revoked during the outage;
- the admin and the super-admin each hold exactly one Redis alert, and no second
  alert arrives in the next three checks.

The web shutdown drill, `scripts/test-web-shutdown-container.sh`, stops the web
server the way a container runtime does and takes about five to six minutes. It
has two parts:

1. A bare Swoole server with the settings of `app:serve` runs as PID 1 and
   records Swoole's shutdown order: a master timer started in `onBeforeShutdown`
   keeps client connections open and served, the connections close when the
   reactor threads join, and only then do the workers stop. The drain depends on
   this order, so a Swoole upgrade that changes it fails here.
2. `app:serve` runs as PID 1 through `docker/general/start-web.sh`, with
   disposable PostgreSQL and Redis and `WEB_DRAIN_SECONDS=10` (a drain window of
   3 seconds and a `max_wait_time` of 1 second). The drill fails unless:
   - `docker stop`, `SIGINT`, `DELETE /api/server` and `swoole:server:stop` each
     run one drain and exit `0` within the deadline, and `SIGTERM` followed by
     `SIGINT` still drains once; `swoole:server:stop` itself exits `0`;
   - a worker reload through `PATCH /api/server` keeps the server and an open
     WebSocket, and starts no drain; web has no CPU pool to restart;
   - an open WebSocket receives close code `1012` before its connection ends,
     and an idle server closes the drain window as soon as every worker has
     reported drained;
   - `/ready` on a connection kept alive from before the stop answers `503` with
     `Connection: close`, and `/live` answers `200` with `"draining": true`;
   - a short request in flight at the stop completes with its full body, and a
     held progressive stream is cut so that curl exits with `18`;
   - a client that stopped reading a stream does not hold the server past the
     deadline;
   - a stopped event worker is killed by Swoole and the server still exits `0`,
     and a stopped manager is killed by the drain watchdog at the deadline, with
     exit `1`;
   - with the server started through `docker exec` rather than as PID 1, no web
     process remains after the stop; web starts no FFmpeg or CPU pool children;
   - with PostgreSQL stopped after the start, the server still drains once and
     exits `0`;
   - with PostgreSQL down, `app:serve` cannot read the database's default time
     zone, refuses to start and exits non-zero, naming the failed connection
     without logging a credential.

The drill accepts the same image variables as the other runtime runners and
enforces its own 15-minute timeout.

Fixtures under `tests/Fixtures/` prepare and observe only those disposable
databases; do not invoke them against an application database. The runtime runners
accept `BAANDER_TEST_IMAGE` and `BAANDER_TEST_POSTGRES_IMAGE`, and CI sets
`BAANDER_TEST_CHECKOUT_IN_IMAGE=1` to test the checkout already built into its image.
The outbox, producer and web runners also enforce their own 180-second timeout.

### WebSocket handshake security

The security runtime runner starts production `app:serve` with disposable
PostgreSQL, Redis and OAuth keys on an internal network:

```bash
BAANDER_TEST_IMAGE=baander-ci-prewarm:local bash scripts/test-security-runtime-container.sh
```

Its 360-second deadline covers malformed HTTP probes, the
[request-identity drill](#request-identity) and WebSocket ticket checks.
The WebSocket fixture obtains tickets through the real OAuth/DPoP firewall, then
upgrades with only a ticket query and an Origin, as a browser does. It checks
proof replay, Origin/path/query rejection without consumption, single-use and
concurrent redemption, expiry, disabled users, logout closure and rejection of a
pending ticket after logout. Paused and stopped Redis cases must return bounded
503 responses, and fresh issuance and upgrades must work after recovery. The runner
searches the application logs for the actual ticket and access-token values it used.

The shutdown fixture also obtains a ticket before opening its socket. Both
fixtures use `https://baander.app` as their configured browser Origin even though
they connect to the disposable server over loopback. Neither fixture bypasses the
Origin check for local addresses.

`app.websocket.allowed_origins` defaults to the origin of `APP_URL`. An explicit
empty list denies every upgrade; additional entries must be serialized HTTP or
HTTPS origins and must agree with the API's CORS policy. External TLS proxies must
omit credential queries from access logs and avoid raw-request error logging.
The bundled nginx configurations apply that policy to exact `/api/ws` requests;
pre-location parser errors and an operator's upstream proxy remain separate limits.
The proxy drill validates all three configurations against a refused upstream and
checks query/referrer redaction without disabling ordinary-route diagnostics:

```bash
docker build -f docker/nginx/Dockerfile.test -t baander-websocket-proxy-test:local docker/nginx
bash scripts/test-websocket-proxy-logs.sh
```

CI runs both security drills as blocking steps.

### Request identity

The security runtime runner also checks that no worker answers a request with an
identity that another request left behind.
`tests/Fixtures/request-identity-runtime.php` runs after the malformed HTTP probes
and before the first Redis outage, against the same `app:serve` and its four web
workers, the default of the deployment profile.

The web workers use fixed dispatch (`dispatch_mode: 2`): a connection's descriptor
in the server selects its worker, and every request on that connection runs there.
A check that keeps one connection, or opens a new one for each request, can reach
a single worker and pass while the others fail. In the run of 2026-11-24 the
37 requests sent before the drill, each on a connection of its own, had all
reached worker 2, and the other three workers had received none.

The drill therefore compares two sides, three times over: a signed-in user's
requests, then requests without credentials. For each side it opens three
connections per worker, twelve here, and it closes none before it ends, so that
their descriptors in the server are consecutive. It reads every worker's request
count through the fan-out behind `app:server:stats` before the signed-in side,
between the two sides, and after the side without credentials. A reading is itself
one request on the worker that accepts its control connection, so a count has to
rise by at least two on each side. A count that rose less, a worker whose PID
changed and a worker missing from a reading each make the drill inconclusive, and
an inconclusive drill fails.

The drill fails unless:

- after an admin's `GET /api/admin/users` and `GET /api/auth/me` on twelve
  connections, the same two requests without `Authorization`, `DPoP` or `Cookie`
  headers answer `401` and `404` on twelve fresh connections, as a freshly started
  server answers them;
- the same holds after a member's requests, and again after the admin has signed
  out through `POST /api/auth/logout`;
- each of 120 profile requests of the admin and the member, written in five
  rounds of 24 before any answer of the round is read, receives its own user's
  profile.

The last check places requests of both users in every worker at once. It cannot
make a worker switch from one request to another between authentication and the
read of the user: the web workers hook only curl and process calls (`hook_flags`
in `config/packages/swoole.yaml`), and the profile route makes neither.
`tests/Integration/RequestIdentityInterleaveTest.php` forces that switch. Its child
process sends two users' profile requests through the production firewall, each in
a coroutine of its own. `RequestSuspension` holds the first request on a channel
between the firewall and its controller until the coroutine of the second has
ended and released its pooled services. Each answer must name its own user.

Both checks were run on 2026-11-24 against the code before the three fixes they
guard: the pool resetter that runs every `kernel.reset` method of a service, the
per-coroutine token storage and authorization checker, and `StaleTokenListener`.
On commit `8b27243c2`, which has none of them, the drill failed, with every
worker's count rising by at least three on each side: all twelve connections
without credentials answered `200` and `200` with the admin's profile after the
admin's requests, `403` and `200` with the member's profile after the member's,
and `200` and `200` with the admin's profile after the admin had signed out. The
120 concurrent profile requests passed on that commit too. The interleave test
failed with the three fixes reverted on the branch head, where the suspended
request received the other user's profile, and with only the per-coroutine token
storage and authorization checker reverted, where it received `404`. With the
fixes in place both pass, and the runner took 104 of its 360 seconds on a loaded
development host, about one second more than without the drill.

### Safari Playback of an Encoding Rendition (Manual)

The web drill records the server's answers to Safari's opening requests as a
baseline; it does not show whether Safari plays them. While a rendition is still
encoding, the server answers Safari's byte-range probe with `200`,
`Accept-Ranges: none` and the whole body as it grows. Whether Safari plays that
stream is checked by hand, on macOS and on iOS.

Prepare:

1. Record the macOS version and its Safari version, and the iOS version and its
   Safari version.
2. Turn `transcode.enabled` on and pick a track whose original file Safari cannot
   play. The player then falls back to a transcoded stream; in Web Inspector's
   network tab the stream request carries `format=`.
3. The web client asks for the first of `opus`, `aac` and `mp3` that Safari's
   `canPlayType()` accepts. Record which format it chose. To check a format it did
   not choose, start the stream from Web Inspector's console on the Baander tab,
   for example `a = new Audio('/api/stream/track?id=<publicId>&format=aac'); a.play()`;
   the page's service worker signs that request as it does the player's.
4. Make sure the rendition is not cached: use a track that has not been streamed
   in that format, or remove the track's directory under
   `CONVERT_STORAGE_PATH/audio-renditions/`. The first request then starts the
   encode and streams it while FFmpeg writes it.

For each format:

1. Start playback. Seeking is not expected to work while the rendition encodes.
2. Keep it playing until the encode has finished. A new request for the same URL
   answers with `Accept-Ranges: bytes` once it has.
3. Seek forward and back: with the player's position bar, or with
   `a.currentTime = <seconds>` for a stream started from the console.
4. Record whether playback started, whether it kept playing past the end of the
   encode, and whether the seek landed.

If, for any recorded Safari version and format, playback does not start, stops
early, or cannot seek after the encode has finished, open a follow-up to change
the in-progress rendition's answer to Safari's byte-range probe.

### Safari Resume After a Server Restart (Manual)

The browser suite checks the player's resume in Chromium and how Firefox and
Playwright's WebKit report a cut stream. Neither shows what Safari does, so check
it by hand, on macOS and on iOS, against the development stack:

1. Record the macOS version and its Safari version, and the iOS version and its
   Safari version.
2. Play a track whose original file Safari plays, and wait until it has played
   for at least 30 seconds.
3. Restart the web server with `make restart-app` (or `make restart INSTANCE=persistent` for the persistent instance). The command refreshes the whole instance, not only the web container.
4. Record whether the player showed "Reconnecting…" or "Tap to resume", whether
   it resumed the same track, and how far the resumed position was from the
   position at the restart.
5. Repeat with a transcoded track: turn `transcode.enabled` on and pick a track
   whose original file Safari cannot play and whose rendition is not cached (see
   the previous check). Restart while the rendition still encodes. The player
   should keep showing "Reconnecting…" until the restarted encode is complete,
   then resume at the position.

A resume that skips the track, starts it from the beginning or never ends the
reconnecting state is a defect in the player's recovery
(`ui/web/src/features/player/stores/player-playback-recovery.ts`).

