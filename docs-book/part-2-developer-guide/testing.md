# Testing

Baander uses PHPUnit 13 with four test suites in the tracked `phpunit.xml.dist`.
Disposable container runners exercise the application's PHP runtime without using
the development database or Redis instance. The Makefile commands remain available
for a configured development test environment.

## Web audio graph

Run `yarn --cwd ui/web test:audio-graph` after installing Chromium, Firefox and
WebKit with `yarn --cwd ui/web exec playwright install chromium firefox webkit`.
WebKit needs the host libraries that Playwright's Ubuntu images provide; on other
hosts, run the suite in the `mcr.microsoft.com/playwright` image of the installed
Playwright version, with `--network none`. The runner also requires
OpenSSL for its temporary HTTPS certificate. It serves a disposable fixture at
`audio.baander.app`, explicitly mapped to loopback; it does not contact the public
application.

These browser tests render the real `AudioProcessor` nodes with
`OfflineAudioContext` and compare stereo, processing order, and normalization
against reference signals. They replace external WASM analysis modules;
they do not certify production codecs or audible clicks during live graph
changes. A separate loader check serves the production TypeScript loader and
shipped WASM to Chromium, checking instance memory and analysis-state isolation.
Stereo metering cases additionally run the production metering worklet and WASM
against native mono, right-only, asymmetric, and opposite-phase signals, and
compare the independent-channel analyser fallback. The same native cases verify
paired phase samples and correlation; worklet tests also cover quadrature,
silence, ring ordering, and programme reset. Native media tests use locally generated WAV files and the
real playback hook, store, service, and processor to exercise repeated preloaded
handoffs, crossfade overlap, promoted-element controls, and interrupted fades.
One resume regression delays a context-resume rejection across track reselection
and verifies that the newer native playback continues advancing. Hook unit tests
also cover deferred success, native play rejection, and same-track replay.
The active-media error regression reloads an already playing element to a local
404 response, checks the real Chromium `MediaError`, and verifies stop and
reselection recovery.

Two specs cover a stream that the server cuts midway, as it does when the web
server [drains](../part-1-operator-guide/upgrading.md#stopping-and-restarting-the-web-server).
`media-interruption.spec.ts` plays a plain `<audio>` element in Chromium, Firefox
and WebKit through a loopback proxy that resets the connection mid-body, for a
chunked response and for a Content-Length response. It asserts that each engine
reports an error or a stall, and that any `ended` event comes after an error:
Firefox reports the error, plays what it buffered and then fires `ended`. A clean
chunked end, which fires `ended` in every engine, is recorded for contrast. A
second test records how each engine reloads the same URL after an outage and
seeks to a saved position. `stream-recovery.spec.ts` runs the real player hook
and store in Chromium through an outage in which `/live` and the stream answer
`502`. It checks that the player shows it is reconnecting, holds its clock at the
saved position, resumes the same track there once `/live` answers, and records
one play. Playwright's Linux WebKit decodes media with GStreamer, not Safari's
media stack, so Safari's resume is a [manual check](#safari-resume-after-a-server-restart-manual).
These verify element ownership and timing, not sample-accurate gaplessness or
authenticated streaming. Rebuild timing and worklet graph attachment
also have unit regressions in `audio-processor-rebuild.test.ts`. Deferred module
and WASM loading, connection replacement, and React StrictMode cleanup have
processor, service, and playback-hook regressions. Native browser lifecycle tests
use small fixture worklets to verify readiness and teardown; they do not replace
tests of the production WASM algorithms. The browser suite runs as a blocking
step in the frontend workflow.

Run `bash scripts/test-dsp-analysis.sh` with Emscripten 6.0.3 to test the actual
WASM analysis modules and production worklet code against reference signals.
The gate rebuilds twice and checks reproducibility and shipped artifact parity.
See [DSP qualification](../../packages/dsp/README.md) for covered contracts and
the remaining loudness and codec qualification limits.

## Browser authentication transport

`yarn --cwd ui/web test:browser-auth` runs `ui/web/tests/e2e/browser-auth/`
against a scripted server on the loopback; it runs as a blocking step in the
frontend workflow. The Chromium project runs every scenario except the ones
tagged `@webkit`, with the named hosts `web.baander.app`, `api.baander.app` and
`foreign.baander.app` resolved to the loopback through a browser flag. The WebKit
project runs only the tagged scenarios, which check what WebKit's storage gives
back; WebKit has no such flag, so that project uses `localhost` and `127.0.0.1`
as two secure origins and skips the foreign host. These loopback names are the
one accepted exception to the `baander.app` rule in `AGENTS.md`. On a host
without a WebKit build, run the suite in the Playwright image with the
repository mounted and `node node_modules/@playwright/test/cli.js test -c
tests/e2e/browser-auth/playwright.config.ts` from `ui/web`.

The scripted server's refresh state machine (`refresh-state.ts`) answers refresh
tokens the way `/api/auth/refresh` does on PostgreSQL and is pinned to
`tests/Integration/RefreshTokenGraceTest.php`; it also answers logout, and an
outage mode makes refresh and logout answer `503` with `Retry-After` until the
test ends it. The suite checks that the service worker signs native image and
media requests with the page's DPoP credentials and sends none to a foreign
origin, that an expired token refreshes once and retries, and that a refresh
failure is bounded. Its modes cover a nonce challenge answered with one
re-signed proof, a `503` with `Retry-After` that keeps the session and retries
after the delay, a lost refresh response re-presented inside the grace window,
two tabs sharing one real credential store so the second tab adopts the pair the
first rotated instead of presenting a consumed token, and a service worker
stopped through CDP that pulls its credentials back from the page before signing
the next request.

The pending-logout scenarios run on the real auth store. A logout while offline
ends the session at once and is sent exactly once when the browser comes back
online; a logout during a server outage is kept and delivered on the next
trigger once the outage ends; a reopened page whose entry's access token expired
refreshes first and logs out with the new token; an entry whose chain was
revoked elsewhere is dropped after the logout `401` and the refresh refusal; two
pages of one origin send one logout for one entry; and the stored key of a
pending logout reads back after a reload and still signs a proof. Mock proofs
qualify the browser transport; they do not validate the server's DPoP
cryptography.

## Web store isolation and debugging

Run `yarn --cwd ui/web test:store-debug` for the disposable Chromium workflow at
`https://debug.baander.app:5187`, mapped to loopback with a temporary certificate.
It checks production selection/view-mode stores, render isolation, no-op updates,
and enabling, inspecting, exporting, and disabling the store debugger. The frontend
workflow runs this gate. Local prerequisites are Chromium and OpenSSL, as for the
audio graph tests; port 5187 must be free.

In a development build, enable the debug panel from Diagnostics, open Store Timeline
or Store Inspector, and select **Enable store tracing and reload**. Recording is
opt-in through `baander-store-debug` in local storage and is disabled in production
builds. Disabling also reloads, removing tracing wrappers instead of keeping a
conditional branch on every action. Enabling recording incurs snapshot/stack costs.

The timeline links synchronous nested calls, shows changed fields and before/after
state, and exports schema-version-1 JSON. Async completions link to their originating
action; updates after an `await` retain their call stacks without inferred parentage.
History is bounded and reports discarded events. Redacted/truncated/native values
make captures incomplete; exports are diagnostic and do not yet support replay.

Unit tests verify creator/action identity when tracing is disabled, no-op subscriber
and persistence behavior, secret redaction, immutable snapshots, and event ordering.
Player/session tests additionally cover clock isolation and sync under continuous
playback. Performance assertions use counts rather than timing-sensitive thresholds.

## Test Suites

| Suite | Directory | Scope |
|-------|-----------|-------|
| **Unit** | `tests/Unit/` | Domain behavior and application/infrastructure contracts; no Symfony kernel boot |
| **StaticAnalysisRules** | `packages/baander-phpstan-rules/tests/` | Custom PHPStan rules, including parsed-source request-payload fixtures |
| **Functional** | `tests/Functional/` | With Symfony kernel container, database, and services |
| **Integration** | `tests/Integration/` | Database, transport, and other integration contracts against configured test services |

Four suites cover the session work of ROADMAP item 5 and run through the
functional runner in the CI image (`BAANDER_TEST_IMAGE=baander-ci-prewarm:local
bash scripts/test-functional-container.sh <file>`); the integration ones need the
runner's disposable PostgreSQL and Redis and do not run on the host:

- `tests/Integration/RefreshTokenGraceTest.php`: the lost-response grace of
  `auth.refresh.reuse_grace_seconds` and every other reuse of a refresh token
  through the production refresh endpoint. A consumed token presented again with
  its own DPoP key inside the window, while its replacement is unused, gets a
  fresh pair; every other reuse revokes the chain before the `401`, and a
  different key revokes nothing. The two-session cases run a child process
  (`tests/Fixtures/Auth/refresh-race.php`) that waits on a row lock.
- `tests/Integration/RefreshTokenConcurrencyTest.php`: two database sessions
  presenting one token leave exactly one usable pair; a logout concurrent with a
  refresh ends the chain without a deadlock; a signing or flush failure after the
  consume rolls back and the retry succeeds; a login whose signing fails commits
  nothing; a commit whose acknowledgement is lost leaves a usable pair; a lock
  held past the lock timeout answers `503` inside the web client's attempt
  timeout; fifty refreshes leave the connection pools at their starting sizes.
  The faults are inert injectors in the production kernel
  (`tests/Fixtures/Auth/IssuanceFaults.php`).
- `tests/Integration/DpopStoreOutageTest.php`: the five DPoP edges during a Redis
  outage. A refused store (a kernel whose Redis clients point at a closed port)
  and a hung store (`CLIENT PAUSE` on the disposable Redis) both get `503` with
  `Retry-After`, no `DPoP-Nonce` and no `WWW-Authenticate`, inside the web
  client's attempt timeout; a genuine replay is still `403`; a refresh whose
  post-commit nonce store fails answers `200` without a nonce; and the same token
  refreshes once the store answers. Logout and `/api/oauth/revoke` are the
  exception: during the refused outage and under pool exhaustion they answer
  `200` and revoke the chain. Every other proof check still refuses a wrong key,
  hash, method, URL or age; a replay of a successful logout meets the revoked
  token; and the nonce store is never touched.
- `tests/Integration/RefreshTokenGraceTest.php` also proves the socket
  reconciliation: with the connections of a revoked chain, an expired chain, a
  live chain and a chain whose handshake token a rotation revoked registered,
  one pass of `RevokedSocketReconciler` closes the first two and leaves the
  other two open, and a logout whose close the web server could not finish
  answers `200` with the error logged. The unit probe
  `tests/Unit/Auth/Infrastructure/Swoole/RevokedSocketReconcilerProbeTest.php`
  proves each tick releases its pooled services.
- `tests/Functional/Auth/LogoutTest.php`: logout revokes the chain of the access
  token that authenticated the request, so neither token works afterwards, and
  `/api/oauth/revoke` revokes only the caller's own tokens and answers a foreign
  token exactly like an unknown one.

The video delivery surface has its own real-firewall matrix in
`tests/Integration/SignedDeliveryFirewallTest.php`, run through the same runner
against the disposable PostgreSQL and Redis. A player holds a signed manifest URL
and may or may not hold a token, so the suite crosses anonymous, owner, library
member, unrelated user and administrator with every credential condition on the
seven signature-only delivery endpoints (the HLS master, media and subtitle
manifests, the DASH manifest, the init, media and subtitle segments), the signing
endpoint and the quality ladder. The contract it records follows the order the
firewall works in. A valid signed URL is a bearer capability and serves any holder
without a credential. A credential that is presented must be valid: an expired,
revoked, foreign-audience, malformed or fingerprint-mismatched token answers `401`
even with a valid signature. The DPoP proof is never read on a delivery route, so a
missing, foreign-key or reused proof answers `200` there and records no replay key.
A signature fault answers `403` on every delivery route before the streaming port
is called, the segment index included. On the signing endpoint and the ladder, a
missing proof, a foreign key, a reused proof and an unbound token answer `403`, a
bad token `401`, and a video of another library `403`. A DPoP-bound token
presented with the Bearer scheme answers `401` everywhere the `api` firewall
authenticates. Every refusal row also asserts which component refused: the API
answers every `403` with one generic body, so the suite registers a
kernel-exception probe that records the refusing exception's message, and the
`401` rows read the authenticator's code from the body. One assertion scans every
response body and every log file of the kernel for the tokens, proofs and
signatures the matrix used; the signature scan is what found the request URL,
signature included, in the web processor's `url` field and the access log, and
`StreamSignatureRedactionProcessor` now redacts it.
`tests/Unit/Shared/Infrastructure/Security/DeliveryRouteInventoryTest.php` keeps
the delivery route set equal across the security configuration, the binding
listener's exemption list and the rate limiter's exemption list, and the suite
derives its unsigned-request loop from that inventory.

The catalog, library and playlist mutations have their own matrices.
`tests/Integration/CatalogMutationFirewallTest.php` crosses thirty routes (albums,
songs, movies, genres, covers, cover extraction, the admin deletes and previews, the
admin duplicates listing and the library routes) with anonymous, member, unrelated
member and administrator: every denied row asserts the role voter's refusal, an
unchanged catalog snapshot, empty in-memory transports, no storage call and no Redis
growth, and every administrator row asserts the change and the port calls it makes,
with scan-all, file deletion on a real library root, album deletion with a cover,
cross-library merge and path validation as their own rows. Its kernel,
`tests/Fixtures/Auth/CatalogMutationOAuthKernel.php`, replaces the Messenger
transports with in-memory ones and makes the storage port synthetic.
`tests/Integration/PlaylistMutationFirewallTest.php` crosses every playlist mutation
and both collaborator routes with owner, collaborator, collaborator with sharing
switched off, unrelated member, administrator and anonymous, each refusal asserting
the voter's message. `tests/Unit/Shared/Infrastructure/Security/MutationRouteInventoryTest.php`
holds the two suites' route keys to every write route under `/api/` the router knows,
minus an explicit list of routes other suites own, so a new mutation route fails a unit
test until it has rows. The read suite also proves the denied-filter, related-resource
and anonymous-detail rows, and the delivery suite proves the signing endpoint's own
`400` answers through the kernel.

The other real-firewall suites under `tests/Integration/` are
`OAuthFirewallAcceptanceTest.php` (the production firewall with real RS256 tokens,
ES256 proofs and Redis replay storage: a bound proof reconstructs the user, a
replay is refused, an invalid presentation is refused), `TrackStreamFirewallTest.php`
(audio track streams: authorization before filesystem resolution, exact range bytes,
membership and token revocation between range requests, replayed proofs),
`CatalogReadFirewallTest.php` (library-scoped catalog reads and searches through
real membership), `ImageReadFirewallTest.php`, `PlaylistReadFirewallTest.php`,
`ArtistMutationFirewallTest.php`, `LyricsFirewallTest.php`, `CatalogMutationFirewallTest.php`,
`PlaylistMutationFirewallTest.php` and `PushUnsubscribeFirewallTest.php` (the
production OAuth and DPoP chain over the image, playlist, artist, catalog and library
mutation, lyrics and push unsubscribe routes with PostgreSQL adapters).

## Running Tests

Use an application image that satisfies the current Composer lockfile. To build
one locally and select it for the disposable runners:

```bash
docker buildx build --target ci --load -t baander-ci:local .
export BAANDER_TEST_IMAGE=baander-ci:local
```

The default image reference in the runners may require registry access. An existing
locally qualified image can be selected with the same variable. Check its PHP
extensions and locked Swoole version; host PHP is not a substitute unless those
requirements pass. The unit and messaging runners stream the current checkout and
installed Composer dependencies into an isolated container. Image selection does
not select an older copy of the application source.

The shared backend coordinator runs all four suites when no selection is given:

```bash
bash scripts/test-backend-container.sh
bash scripts/test-backend-container.sh --testsuite Unit,StaticAnalysisRules
bash scripts/test-backend-container.sh tests/Integration/RefreshTokenGraceTest.php --filter test
bash scripts/test-backend-container.sh --coverage-text=coverage/report.txt --coverage-clover=coverage/clover.xml
```

It requires Python 3, Docker and the Docker Compose plugin on the host. `BAANDER_TEST_PROCESSES` defaults to
`2` and accepts integers from `1` through `8`; `1` is the diagnostic setting.
`BAANDER_TEST_TIMEOUT` bounds the complete run, including setup, and defaults to
`3600` seconds. Cleanup has separate bounded attempts after interruption or timeout.
`BAANDER_TEST_IMAGE` selects the application runtime, and
`BAANDER_TEST_POSTGRES_IMAGE` selects the extension-capable database image. CI uses
`BAANDER_TEST_CHECKOUT_IN_IMAGE=1` with its freshly built image; local runs copy the
current source and installed dependencies into each container.

The coordinator discovers the selection once, then runs three phases. Ordinary unit
and rule tests use ParaTest workers recycled after 25 files. The explicit native
manifest, `scripts/backend-native-tests.json`, assigns Swoole, fork, signal and
fixed-resource tests to one worker in a privileged container with networking
disabled; that worker starts afresh for every file. Add new native tests to this
manifest. Functional and integration files are distributed round-robin across at
most the requested number of independent app/PostgreSQL/Redis stacks declared in
`compose.test.yaml`. Each stack uses a unique disposable Compose project and runs
one worker with a fresh process for every file. Scheduling stays at class/file
level; ParaTest's method-level `--functional` mode is not used.

Each stack owns its cache, temporary files, sockets, database, Redis server and
OAuth keys. No writable host mounts or published ports are used. Database and Redis
DSNs are exported so nested PHP processes reach the same stack. Each database runs
migrations twice. Setup uses root inside the disposable container; tests run as
`www-data`, preserving permission checks. Test worker PHP processes receive an
explicit 512 MiB memory limit. Coordinator and reporting PHP processes receive
2 GiB because full-suite serialized coverage needs more memory than individual
test files.

JUnit validation requires every discovered class, method and dataset label exactly
once. Crashes, missing reports, unexpected identities and configured strict failures
fail the run. Skipped or incomplete cases remain observed identities without
suppressing PHPUnit's failure policy. The coordinator pins one random seed across
lanes; use `--random-order-seed` to reproduce it. Custom configurations preserve
relative paths and source filters, but suite-specific bootstraps are rejected.

Logs, manifests, JUnit and serialized coverage remain under the printed
`var/test-runs/<run>/` directory, including failed runs. Set
`BAANDER_TEST_ARTIFACTS` to choose a different parent directory. Containers and
networks and volumes are removed on completion, timeout or interruption, and
cleanup failure fails the run. Service stacks use bounded
`docker compose down --volumes --remove-orphans` after collecting diagnostics. Final coverage is published only after identity and artifact checks;
merging retains uncovered executable lines and exclusions. Validate the standard
reports with `python3 scripts/validate-backend-coverage.py coverage` before using
`coverage/metrics.json`.

Runner regressions use `python3 -m unittest discover -s scripts/tests` and the
`BackendTestRunnerTest` PHPUnit file. The destructive isolation probe,
`python3 scripts/test-backend-stack-isolation.py`, creates two disposable stacks to
check database outages, Redis pause/flush, cache/socket separation and inherited
DSNs. Runtime security drills remain separate serial commands.

For qualification, `python3 scripts/compare-backend-runs.py <reference> <candidate>`
compares selected and completed identities, seed, timings, coverage denominators
and exact executable/covered line sets. Coverage comparison requires host PHP and
the installed Composer dependencies to read serialized artifacts; the Python
regression suite does not.

For strict unit and messaging checks, run from the checkout:

```bash
bash scripts/test-unit-container.sh
bash scripts/test-messaging-container.sh
bash scripts/test-worker-retirement-container.sh
bash scripts/test-worker-recovery-container.sh
bash scripts/test-worker-command-container.sh
bash scripts/test-worker-operator-container.sh
bash scripts/test-docker-context.sh
bash scripts/test-worker-containment-container.sh
bash scripts/test-functional-container.sh tests/Functional/Controller/FavoritesControllerTest.php
bash scripts/test-functional-container.sh tests/Integration/CoverExtractionPersistenceTest.php
bash scripts/test-functional-container.sh tests/Integration/CoverlessAlbumKeysetTest.php
bash scripts/test-functional-container.sh tests/Integration/WorkerDeploymentLeaseSchemaTest.php
```

These require Docker and installed Composer dependencies. The unit runner uses
the application image with networking disabled and explicitly selects
`Unit,StaticAnalysisRules` from `phpunit.xml.dist`. That configuration randomizes
test order within dependency constraints and treats unexpected output as risky.
The unit runner sets a 256 MiB PHP memory limit so the combined unit and PHPStan-rule
suite can compile its analysis container. This budget applies only to that isolated
runner; it does not change the tracked PHPUnit configuration or deployment settings.
The operator runner requires Python 3 and Docker. Its default host mode also
requires PHP with `posix` and `pdo_pgsql` (`BAANDER_TEST_PHP_BINARY` selects its
executable) and a local Unix endpoint (`BAANDER_TEST_DOCKER_ENDPOINT`). The host
controller reaches a loopback-published database port; the worker reaches the same
database through its private network. Its outer 240-second timeout bounds native
database waits; DBAL `connect_timeout` is not a hard connection deadline.

`BAANDER_TEST_OPERATOR_IN_CONTAINER=1` runs the entrypoint in a separate trusted
controller container with PHP and a verified static Docker CLI. The CLI comes from
`docker:29.7.2-cli` by default (`BAANDER_TEST_DOCKER_CLI_IMAGE` overrides it). Only
the controller receives the daemon socket mount. Its SELinux process label is
disabled to access that socket without relabeling it; this exception applies only
to the trusted test controller. Credentials are streamed into private files owned
by its user, and it reaches PostgreSQL on a separate controller network with
no published database port. The controller verifies that its socket reaches the
same daemon before issuing an operator action. The worker keeps its private
network and receives no
mounts or Docker authority. With no explicit endpoint override, setup uses the
runner's ambient Docker connection, supporting CI runners outside the daemon host.

Both modes exercise `bin/worker-deployment.php` create, reconciliation, start,
status and recovery against disposable PostgreSQL/Redis with canonical private
credentials. They verify real outbox delivery, scheduler work, lease/child
identities and retirement of the immutable boot. Both modes pass locally, including
container mode from a workspace with only the runner script and no host PHP or
Composer dependencies. The fixture image does not certify the production build.
Forgejo now includes a
blocking operator lifecycle step using container mode and
`BAANDER_TEST_CHECKOUT_IN_IMAGE=1` to copy source and dependencies from the built
image. Adding that gate does not establish a passing Forgejo run. Status does not
certify readiness.

To check the actual production worker target, build it separately and pass its
local image reference to the production runner:

```bash
docker buildx build --target worker --load -t baander-worker:test .
BAANDER_TEST_IMAGE=baander-worker:test bash scripts/test-worker-production-container.sh
```

The runner has a 300-second outer deadline; image build time is outside that bound.
It verifies the worker user, entrypoint, disabled web health check and production
environment and disabled PHP error display; checks that development dependencies,
baked OAuth keys and agent artifacts are absent; and runs Composer's production platform checks. It then uses
`BAANDER_TEST_USE_IMAGE=1` with container controller mode to run the lifecycle
against that exact immutable image. This skips the fixture rebuild and injects no
source, dependencies, keys or cache into the worker. Test preparation and Docker
authority remain in separate containers. The scope is the worker lifecycle,
outbox and scheduler, without OAuth certification. Forgejo now includes blocking
context-exclusion, production-worker build and production-lifecycle steps. Their
configuration does not establish a passing Forgejo run. The clean worker build,
platform checks and direct-image lifecycle pass locally with native Swoole pinned
to Composer's required 6.2.0. PIE installation failures fail the image build.

The messaging runner waits for PostgreSQL TCP readiness (not its temporary
initialization socket) and prints service logs on readiness timeout. It also
explicitly selects `phpunit.xml.dist` and provisions
disposable PostgreSQL and Redis for its selected transport, outbox, access-token
cache transaction, deployment lease, and PGroonga compatibility tests. The lease
tests use the actual migration and independent PostgreSQL connections to exercise
ownership, expiry, lock contention, stale epochs and uncertain commit recovery.
The runner includes `bin/worker-lease-agent.php` and exercises fresh helper processes
against PostgreSQL, including blocked calls, parent deadlines, cancellation,
malformed responses and output limits. Unit tests separately exercise monotonic
lease authority and the runtime coordinator with controlled helper responses;
they verify that pending renewal cannot block worker shutdown at lease expiry.
The separate schema test above uses the fully migrated application kernel to check
that ORM introspection preserves the DBAL-owned lease table. The search tests exercise
production Doctrine filtering and scored SQL against mapped PGroonga indexes;
they require the project's extension-capable database image. Both runners fail on
PHPUnit notices and skipped tests. Neither reads an
ignored local `phpunit.xml` or runs the whole functional suite. The Makefile's
`phpunit`, `paratest`, and `ci` targets also select
`phpunit.xml.dist` explicitly. A configured development test environment can use
`make exec`:

```bash
# Run all tests
make phpunit

# Run a single test file
make exec cmd="./vendor/bin/phpunit -c phpunit.xml.dist tests/Unit/Catalog/Domain/Model/AlbumTest.php"

# Run a specific suite
make exec cmd="./vendor/bin/phpunit -c phpunit.xml.dist --testsuite Unit"

# Run the custom PHPStan rule tests
make exec cmd="./vendor/bin/phpunit -c phpunit.xml.dist --testsuite StaticAnalysisRules"

# Run a specific controller's functional tests
make exec cmd="./vendor/bin/phpunit -c phpunit.xml.dist --filter NotificationControllerTest"

# Run a single test method
make exec cmd="./vendor/bin/phpunit -c phpunit.xml.dist --filter testCreateAlbum"

# Run with Xdebug off (faster)
make exec cmd="XDEBUG_MODE=off ./vendor/bin/phpunit -c phpunit.xml.dist"
```

The worker containment runner creates dedicated, disposable containers with one
CPU, 256 MiB of memory, no networking, no added capabilities, and no host mounts.
It runs the supervisor core as PID 1 with a child and a TERM-ignoring descendant.
Both graceful TERM and supervisor SIGKILL must stop the container and descendant
activity within the test deadline. The fixture distinguishes direct-child drain
from verified containment: it exits as PID 1 without acknowledging containment or
releasing a deployment lease, then the external runner checks namespace shutdown.
The runner fails on unexpected exit status or
OOM and removes its containers on exit. It tests the proposed container boundary;
it does not certify per-child descendant cleanup on restart, host systemd behavior,
deployment lease takeover, or measured production resource defaults.

The retirement runner additionally needs host PHP and Docker CLI access to a local
Unix daemon endpoint. It starts a restricted restart-always deployment fixture,
rejects an incorrect boot identity, then exercises the real external retirement
adapter. Confirmed force-removal must prevent restart of the old full container ID;
a subsequent absent-container request must fail closed. PostgreSQL controller tests
in the messaging runner separately verify that retirement failures preserve the
lease and concurrent recovery cannot release a newer epoch. The recovery runner combines the real Docker adapter with PostgreSQL lease
coordination. It needs host PHP with `pdo_pgsql`, publishes only an ephemeral
loopback database port, and destroys its disposable resources. It verifies
wrong-owner and wrong-label rejection, expiry without takeover, confirmed removal,
new epoch acquisition and stale-token rejection. The fixture exercises external
controller startup and recovery, not a complete `LeasedWorkerRuntime` deployment.
The predecessor is created with restart disabled and remains unstarted until its
inventory binding and one-shot start claim commit. The fixture checks visibility
through an independent PostgreSQL connection at the real Docker start call, then
verifies that a repeated controller start cannot restart the predecessor. A second
fixture makes Docker create a real container and then discards its acknowledgment.
It verifies durable creation admission, refusal to recreate, exact-recipe
reconciliation without process activity, and registered startup/recovery.

The worker-command runner boots the actual `app:worker` as PID 1 against disposable
production-mode PostgreSQL and Redis after fresh and repeat migrations. It verifies
three direct child roles, their exact committed deployment tuple (overriding a bogus
inherited epoch), and the namespace/boot-qualified Redis consumer. It kills the
consumer and scheduler separately to require whole-container draining, tests TERM and forced database lease
expiry, and confirms a duplicate
lease claim exits with zero launch attempts. It checks that ownership remains
reserved after shutdown. It also seeds a real registration event before startup,
requires the supervised relay to commit the notification, receipt and channel
handoffs, requires Redis acknowledgment with no pending, delayed or failed work,
and repeats the checks after clearing the event acknowledgment. Outbound
preferences are disabled, the disposable user is unverified and no webhook
endpoints exist; no external delivery is requested. A paused manual console request
runs the registered cache sweep in dry-run mode through the dedicated scheduler
stream; duplicate delivery must not invoke it twice. These checks do not establish
application readiness, autoscaling, production capacity or media ownership.
CI runs this as a blocking step using `BAANDER_TEST_CHECKOUT_IN_IMAGE=1`, which
copies source and Composer dependencies from the built application image.
The functional `Console/ServeCommandTest` verifies that `app:serve` and the legacy
server name resolve to the same real command and preserve its options.

The functional runner creates an isolated PostgreSQL/Redis network, extracts the
checkout into a fresh directory, and runs all migrations twice before executing the
requested PHPUnit paths or options. With no arguments it selects `tests/Functional`.
The second migration run checks that the recorded history produces no pending work.

Scheduler occurrence persistence can be checked with:

```bash
bash scripts/test-functional-container.sh tests/Integration/SchedulerOccurrenceStoreTest.php tests/Integration/SchedulerOccurrenceExecutionStoreTest.php tests/Integration/SchedulerOccurrenceGuardTest.php tests/Integration/SchedulerOccurrenceDispatchStoreTest.php tests/Integration/SchedulerOccurrenceRelayTest.php tests/Integration/WorkerDeploymentLeaseSchemaTest.php
```

These checks cover immutable snapshots, independent-connection visibility,
competing inserts, rollback and a lost commit acknowledgment. The actual migration
also enforces command/JSON bounds and minute-aligned instants; Doctrine's schema
filter preserves the tables. Execution checks require a committed attempt before
the nested effect, verify the configured Kernel services, and force a failure
after an effect through actual Redis/Messenger retries. Redelivery must leave the
consumed attempt intact without repeating the effect. Missing/future occurrences,
lost commit acknowledgments and wrong-owner return markers cannot grant execution.
Admission also rejects absent, malformed, expired or replaced deployment authority
without consuming an attempt. Tests cover lease-row contention, authority lost
during insertion with transaction rollback, and exact historical-owner receipts
after expiry or replacement. The existing fresh-install execution migration
requires the deployment tuple; this rewrites undeployed history and does not
upgrade or reset an existing local database.
Dispatch tests exercise committed reservations, skipped locked rows, token replacement
and idempotent acceptance receipts on PostgreSQL. Real Redis tests send through the
explicit JSON serializer and reproduce acceptance followed by a lost acknowledgment;
recovery republishes the same occurrence identity without granting a second attempt.
A recorded acceptance stops repeated publication while consumers are stopped. Unit
tests require the rest of a reserved batch to be attempted after a failed send or
receipt. The configured Kernel resolves the relay and its explicit async sender.
This does not test automatic polling, fencing effects after lease expiry, broker
crash durability or native console/media execution. No occurrence relay loop is
enabled yet.

Scheduled-job source parameters can be checked against the real ORM and PostgreSQL:

```bash
bash scripts/test-functional-container.sh tests/Integration/ScheduledJobParameterPersistenceTest.php
```

These checks verify the physical JSON column, fresh ORM reads, parameter order and
numeric representation before materializing an occurrence, plus the relevant schema
comparison. Native JSON preserves the encoded values; it does not solve stale
schedule writes or Doctrine's signed-zero-only dirty-checking limitation.

The occurrence console adapter and worker-stop policy can be checked with:

```bash
bash scripts/test-functional-container.sh tests/Integration/ScheduledConsoleExecutorTest.php tests/Integration/ScheduledConsoleWorkerStopTest.php tests/Integration/SchedulerOccurrenceGuardTest.php
```

Real CLI children verify literal argument handling, PHP heap settings, concurrent
stdout/stderr draining, output limits, deadlines, ignored TERM followed by KILL,
unexpected signals and exact direct-child reaping. The actual Messenger worker
must stop before its next queued job after an uncertain result, including nested
handler exceptions; ordinary known failures may continue. Kernel checks verify the
executor port and stop subscriber registration. These fixtures do not certify
business-command side effects, descendant containment, kernel-stalled processes or
total deployment memory. The occurrence handler's unit tests also require the
paused state and uncertainty to survive persistence failures without issuing a
return receipt or granting a second execution.

The functional runner also supplies the isolated PostgreSQL/Redis environment
aliases used by integration tests that need the full migrated schema and kernel.
`CoverExtractionPersistenceTest` disables DAMA rollback with its supported attribute
and observes commits through a second connection. It injects a real flush failure,
then verifies that the worker's Swoole pool release and Symfony reset allow the
same handler to retry successfully. Its exact expected pool-reset diagnostic is
asserted; unexpected-output checks remain enabled.
It uses a 512 MiB PHP limit and a 300-second overall timeout. Database and Redis
values supplied through the environment override the tracked PHPUnit defaults.
The test firewall uses the test authenticator; this does not verify production
bearer-token or DPoP authentication. The Favorites controller selection is verified;
the runner's availability does not establish that the entire functional suite passes.

Migration ordering is configured through `MigrationVersionComparator`. It preserves
legacy class identities while placing their table creation before dependent changes.
New migrations use `VersionYYYYMMDDHHMMSS`; unfamiliar naming formats need an
explicit ordering review. Do not rename applied migrations to change their order.
Existing irreversible migrations still prevent a general full downgrade.

## Coverage

`make phpunit` runs with HTML, Clover, and JUnit report generation:

- **HTML**: `reports/coverage/`
- **Clover**: `reports/clover.xml`
- **JUnit**: `reports/junit.xml`

## Unit Test Conventions

- **Manual object construction** — tests build domain objects directly (e.g., `Album::create(...)`) rather than using factories. Zenstruck Foundry is available but not the default convention.
- **Test file structure** mirrors `src/` — a test for `src/Catalog/Domain/Model/Album.php` lives in `tests/Unit/Catalog/Domain/Model/AlbumTest.php`.
- **No mocks in domain tests** — unit tests exercise real domain logic. Mocks are reserved for infrastructure and external dependencies.
- Use `createStub()` when configuring return values without interaction expectations; use `createMock()` when the test asserts calls. Strict runs treat PHPUnit notices as failures.
- Use `baander.app` or its subdomains for test domains and email addresses. Keep HTTP/DNS mocked or explicitly routed to disposable local services; the project domain does not authorize production traffic. Preserve literal IP cases that test network boundaries. Some inherited fixtures still use other domains; do not copy those examples into new tests.

### Example: Unit Test (Domain Logic)

Unit tests live in `tests/Unit/` and test pure domain logic with no framework or container:

```php
final class AlbumTest extends TestCase
{
    public function testCreateAlbum(): void
    {
        $album = Album::create(
            libraryId: Uuid::generate(),
            title: 'Abbey Road',
            type: 'album',
        );

        $this->assertNotNull($album->getId());
        $this->assertNotNull($album->getPublicId());
        $this->assertSame('Abbey Road', $album->getTitle());
    }

    public function testCreateAlbumWithEmptyTitleThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Album::create(
            libraryId: Uuid::generate(),
            title: '',
            type: 'album',
        );
    }
}
```

### Example: Unit Test (Application Handler)

Application handler tests mock domain interfaces (repositories, ports) but test real orchestration logic:

```php
final class StartPlaybackHandlerTest extends TestCase
{
    public function testStartsPlaybackAndDispatchesEvent(): void
    {
        $sessionPort = $this->createMock(PartySessionPortInterface::class);
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);

        $handler = new StartPlaybackHandler($sessionPort, $eventDispatcher);

        $session = /* ... create or reconstitute a PartySession ... */;

        $sessionPort->method('findByUuid')->willReturn($session);
        $sessionPort->expects($this->once())->method('startPlayback');
        $eventDispatcher->expects($this->once())->method('dispatch');

        $command = new StartPlaybackCommand(sessionId: $session->getId(), position: 0);
        ($handler)($command);
    }
}
```

## Functional Test Conventions

Functional tests live in `tests/Functional/` and extend `App\Tests\Functional\TestCase`, which provides a `KernelBrowser`, test user factories, and request helpers. Each test is wrapped in a database transaction by DAMA DoctrineTestBundle, so tests share a single connection and never pollute each other.

### Base Class: `App\Tests\Functional\TestCase`

| Method | Purpose |
|--------|---------|
| `createTestUser(?string $email, string $name, string $password)` | Creates a `ROLE_USER` user with a random email |
| `createAdminUser()` | Creates a `ROLE_ADMIN` user |
| `createSuperAdminUser()` | Creates a `ROLE_SUPER_ADMIN` user |
| `authenticatedRequest(string $method, string $uri, User $user, array $content)` | Sends an HTTP request as the given user. Sets `X-Baander-Test-User-Id` header, which the `TestAuthenticator` picks up to authenticate without a real JWT. |
| `anonymousRequest(string $method, string $uri, array $content)` | Sends an unauthenticated HTTP request |
| `assertJsonResponse(Response $response, int $expectedStatus, ?string $expectedKey)` | Asserts status code + valid JSON. If `$expectedKey` is set, asserts the key exists in the decoded body. Returns the decoded array. |

### Authentication in Tests

Tests do not use real JWT tokens or `loginUser()`. Instead, the `TestAuthenticator` (registered in `config/packages/test/security.yaml`) reads the `X-Baander-Test-User-Id` header and returns a `SecurityUser` for the corresponding user ID. This is set automatically by `authenticatedRequest()`.

```php
// Anonymous request — no auth headers
$response = $this->anonymousRequest('GET', '/api/genres/');
$this->assertJsonResponse($response, 401);

// Authenticated as a regular user
$user = $this->createTestUser();
$response = $this->authenticatedRequest('GET', '/api/genres/', $user);
$this->assertJsonResponse($response, 200);

// Authenticated as admin
$admin = $this->createAdminUser();
$response = $this->authenticatedRequest('POST', '/api/genres/', $admin, [
    'name' => 'Rock',
    'slug' => 'rock',
]);
$this->assertSame(201, $response->getStatusCode());
```

### Response Shape Gotchas

Controllers use two different response shapes — know which one your test expects:

| Method | Shape | When to use |
|--------|-------|-------------|
| `successResponse($data)` | `{"data": {...}}` | Most GET, PATCH, PUT responses |
| `created($data)` | `{...}` (flat, no wrapper) | POST 201 responses — the data is the top-level object |
| `paginatedResponse($p)` | `{"data": [...], "meta": {...}}` | List endpoints with pagination |
| `errorResponse($msg, $status)` | `{"error": $msg, "status": $status}` | Error responses |

When asserting on a `created()` response, decode the body directly instead of passing `'data'` as the expected key:

```php
// created() — flat response
$response = $this->authenticatedRequest('POST', '/api/favorites/', $user, [...]);
$this->assertSame(201, $response->getStatusCode());
$data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
$this->assertSame('song', $data['entityType']);

// successResponse() — wrapped in 'data'
$data = $this->assertJsonResponse(
    $this->authenticatedRequest('GET', '/api/favorites/', $user),
    200,
    'data',
);
$this->assertSame('song', $data['data'][0]['entityType']);
```

### PostgreSQL jsonb Float Round-Trip

PostgreSQL `jsonb` stores numbers without type annotations. A whole-number float like `5.0` survives the PHP → PostgreSQL → PHP round-trip as integer `5`, which fails a `assertSame(5.0, ...)` assertion. Always use non-whole-number floats in test payloads:

```php
// Bad — survives round-trip as int 5
['volume' => 5.0]

// Good — survives round-trip as float 5.5
['volume' => 5.5]
```

### DAMA Transaction Isolation

Functional tests using the configured DAMA extension are wrapped in a transaction
that is rolled back on teardown. This means:

- **No cleanup needed** — inserted rows vanish automatically.
- **Single connection** — all tests share one DB connection within a test run.
- **`$client->disableReboot()`** — the base class calls this to prevent the kernel from rebooting between requests, which would break the transaction.

This shared test connection cannot prove what another connection sees after a
producer commits or rolls back. Production runtime drills use independent
connections and disposable services for that purpose; their fixtures are not
DAMA-wrapped functional tests.

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
PostgreSQL and Redis on isolated networks, with cleanup on exit. The startup
contract uses isolated executable probes to check argument, PID and signal handling.
The legacy worker-runtime drill checks transport redelivery using a historical
Supervisor fixture; it does not represent deployment packaging. The worker-command
drill exercises the current PID-1 supervisor and committed lease lifecycle.
The outbox drill checks
notification projections, receipts, channel handoffs, and replay after a lost
acknowledgement. The producer drill rejects outbox writes, verifies rollback
through a second connection, and retries through the same bus. It covers operator
user creation, registration tokens/preferences, email verification token retention,
and recovery after an ORM flush closes the entity manager. This is coverage of
three auth producers, not every event producer or a whole-schema audit.

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

The drill then stops Redis while signed-in sessions exist. It signs in as the
admin twice, each time with a fresh DPoP key, through the real login
(`app:auth:setup-clients` has seeded the first-party client), stops the Redis
container, and drives both sessions with `scripts/web-runtime/redis-outage.php`
inside the app container. The first pair is the session that survives the
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
   - a worker reload through `PATCH /api/server` keeps the server, the CPU
     pool's processes and an open WebSocket, and starts no drain;
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
   - with the server started through `docker exec` rather than as PID 1, no
     FFmpeg or CPU pool process remains after the stop;
   - with PostgreSQL down, the start-up cleanup logs its failure once and the
     server still drains and exits `0`.

The drill accepts the same image variables as the other runtime runners and
enforces its own 15-minute timeout.

Fixtures under `tests/Fixtures/` prepare and observe only those disposable
databases. Do not invoke them against an application database. The runtime runners
accept `BAANDER_TEST_IMAGE` and `BAANDER_TEST_POSTGRES_IMAGE`; CI sets
`BAANDER_TEST_CHECKOUT_IN_IMAGE=1` to test the checkout already built into its image.
Outbox, producer and web runners also enforce their own 180-second timeout.

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
early, or cannot seek after the encode has finished, open the deferred follow-up
from the [item 1 open-items plan](../../docs/plans/2026-10-10-1312-fix-item1-open-items-plan.md):
change the in-progress rendition's answer to Safari's byte-range probe.

### Safari Resume After a Server Restart (Manual)

The browser suite checks the player's resume in Chromium and how Firefox and
Playwright's WebKit report a cut stream. Neither shows what Safari does, so check
it by hand, on macOS and on iOS, against the development stack:

1. Record the macOS version and its Safari version, and the iOS version and its
   Safari version.
2. Play a track whose original file Safari plays, and wait until it has played
   for at least 30 seconds.
3. Restart the web container with `docker compose restart app`.
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

### Example: Functional Test (Full Pattern)

```php
final class FavoritesControllerTest extends TestCase
{
    public function testIndexRequiresAuthentication(): void
    {
        $response = $this->anonymousRequest('GET', '/api/favorites/');
        $this->assertJsonResponse($response, 401);
    }

    public function testIndexReturnsAddedFavorites(): void
    {
        $user = $this->createTestUser();
        $this->addFavorite($user, 'song', 'song-abc123');

        $data = $this->assertJsonResponse(
            $this->authenticatedRequest('GET', '/api/favorites/', $user),
            200,
        );

        $this->assertCount(1, $data['data']);
        $this->assertSame(1, $data['meta']['total']);
    }

    public function testAddReturns201(): void
    {
        $user = $this->createTestUser();

        $response = $this->authenticatedRequest('POST', '/api/favorites/', $user, [
            'entityType' => 'song',
            'entityPublicId' => 'song-create',
        ]);

        $this->assertSame(201, $response->getStatusCode());
        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('song', $data['entityType']);
    }

    private function addFavorite($user, string $entityType, string $entityPublicId)
    {
        return $this->authenticatedRequest('POST', '/api/favorites/', $user, [
            'entityType' => $entityType,
            'entityPublicId' => $entityPublicId,
        ]);
    }
}
```

## Functional Test Coverage

The functional suite covers these controllers (tests live in `tests/Functional/Controller/`):

| Context | Controller | Tests | Notes |
|---------|-----------|-------|-------|
| Session | DeviceController | 19 | Device registration + rename |
| Notification | PushSubscriptionController | 15 | Subscribe/unsubscribe/remove-all |
| Notification | NotificationController | 19 | List, markRead, markAllAsRead, delete |
| Notification | WebhookController | 13 | Admin-gated CRUD |
| UserPreference | AccentColorController | 7 | GET + PUT |
| UserPreference | AudioPreferencesController | 14 | Versioned save, history, rollback |
| UserPreference | LayoutPreferencesController | 13 | Strict 2-field payload |
| UserPreference | PlayerPreferencesController | 13 | Strict 9-field payload |
| UserPreference | EqDeviceProfileController | 33 | CRUD + activate, ownership and safe 404 responses |
| UserPreference | SidebarConfigController | 10 | Per-media-type config |
| UserPreference | ThemeMoodController | 7 | GET + PUT |
| Catalog | GenreController | 16 | CRUD, ROLE_ADMIN on write |
| Favorites | FavoritesController | 13 | Add/list/remove, type filter |
| Shared | HealthCheckController | 5 | /health, /ready, /live probes |

## Bugs Found by Functional Tests

The functional suite has surfaced production bugs that were fixed:

- **Push subscribe `Assert\Choice`** — `Assert\Choice([...])` used positional args; Symfony 7 requires `Assert\Choice(choices: [...])`. Subscribe was always 500.
- **Notification `findByPublicId`** — passed a raw string to a `PublicIdType` Doctrine column, which rejects non-PublicId values. Mark-read and delete were always 500.
- **Notification `save()`** — never persisted the domain model's `publicId`, so the entity got a divergent listener-generated ID.
- **Notification `markAllAsRead`** — used `->set('e.isRead', true)`, which PHP coerced to string `"1"`, causing a PostgreSQL boolean type mismatch. Always 500.
- **Notification `markRead` controller** — returned the stale domain model (isRead:false) after marking read.
- **GenreController missing authorization** — `update()` and `destroy()` lacked `#[IsGranted('ROLE_ADMIN')]`. Any authenticated user could modify or delete genres.
- **Missing `user_theme_moods` migration** — the ThemeMood entity had no database table.

EQ profile show, update, delete, and activate now enforce ownership in the
application port implementation. Foreign, missing, and malformed profile IDs return
404; unrelated administrators have no bypass. Default-profile deletion by its owner
still returns 422. The 33 functional tests exercise Symfony's firewall with the test
authenticator, not the production OAuth/DPoP handshake. Notification mark-read and
delete also have ownership guards and cross-user 403 regression coverage; the earlier
ownership warning in this guide was stale.

### Design Gaps Pinned by Tests (Not Yet Fixed)

Some tests document known design-level gaps by asserting the *current* behavior rather than the ideal:

- **Dead-code optimistic locking** — AudioPreferences, LayoutPreferences, and PlayerPreferences controllers catch `RuntimeException` for version conflicts, but `saveForUser()` never throws it. The 409 response path is unreachable.
- **Favorites missing Choice constraint** — `AddFavoriteRequest.entityType` has `NotBlank` but no `Choice`, so invalid types pass DTO validation and crash in `FavoriteType::from()` with a 500.

## Static Analysis

PHPStan runs alongside tests:

```bash
# Run PHPStan
make phpstan

# Check architectural dependencies
make exec cmd="vendor/bin/deptrac analyse --no-cache --no-progress"
```

Run these in the application PHP runtime with the current Composer dependencies.
Existing baselines record debt; do not regenerate or expand them to hide a failing
check. Address runtime defects before annotation cleanup. The full application
publisher requires both analyses to succeed, in addition to its test gates.

## Application publication gates

The application publisher in `.forgejo/workflows/ci.yaml` requires backend quality,
frontend quality, and DSP qualification. Frontend qualification includes the embedded
player and Chromium authentication, audio graph, and store-debugger suites. Checkout
steps in the application, frontend, and DSP workflows fetch and verify the event SHA,
so a later branch update cannot replace the source under qualification.

`PublicationWorkflowTest` checks the actual YAML dependency graph, executes gate shell
commands with failing local tools, and exercises checkouts against a local Git remote
whose branch advances. It rejects unavailable or mismatched event commits. These are
local contracts and shell regressions; they do not certify a live Forgejo scheduler.
Standalone CLI and base-image publication policies need their own review.

## Frontend Testing

The web frontend uses Vitest:

```bash
cd ui/web
yarn test              # Run tests
yarn test:watch        # Watch mode
yarn test:coverage     # With coverage
```

See the [Frontend Development](frontend-development.md) page for more details.

The native audio graph suite also runs normalization against the shipped loudness
WASM without mounting Equalizer. It checks output attenuation, independent volume
and mute, chain rebuilds, disable, and programme reset. Unit coverage checks
settings restoration, native-readiness rejection, expiry, and passive playback
volume ownership.
