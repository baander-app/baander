# Live-Update Qualification

End-to-end browser verification and production-mode capacity qualification for committed change capture, revision projection, and WebSocket hint delivery.

## Live-update qualification

The [live-update guide](live-updates.md) describes adding producers and readers.
Start with the frontend contracts and the disposable backend selection:

```bash
yarn --cwd ui/web test tests/live-updates
bash scripts/test-backend-container.sh tests/Unit/Shared/LiveUpdates
```

The frontend suite covers protocol parsing, the AST consumer census, generation
tracking, bounded HTTP admission, recovery, account ownership, feature readers,
drafts, preference mirrors, and shared sampling. The census requires classification
of actual consuming calls and named behavior proofs; a classified call or a proof
file's existence alone does not establish a passing browser flow. PostgreSQL
capture tests must also exercise commit, rollback, deletion dependencies, audience
changes, and bulk writes through the disposable backend runner. Do not use a
DAMA-wrapped request to infer visibility from an independent producer connection.

For the real-browser capacity scenario, refresh this worktree's isolated preview
and pass its URL explicitly:

```bash
make preview-up
E2E_BASE_URL="$(make preview-url)" yarn --cwd ui/web e2e:headless \
  tests/e2e/live-updates/capacity.spec.ts --retries=0
```

Run this workload serially with other preview mutations and failure drills. It
uses 32 distinct signed-in principals and a separate administrator diagnostics
client through the real HTTP, WebSocket, authentication, and rendering paths.
The managed fixture executor creates test-owned accounts and media, records
ownership in `var/live-capacity/<run>/manifest.json`, and cleans only its own
objects. Preserve an interrupted manifest until cleanup succeeds. The long-lived
executor holds the preview lifecycle lock; its burst receipts and HTTP diagnostics
provide concurrent observations without a second CLI invocation behind that lock.
Keep credentials out of saved receipts.

The preview capacity test has a 900-second deadline and retains
`capacity-raw-receipts.json` in its Playwright output. Keep failed attempts too.
Record the browser version, native PHP/Swoole runtime, worker identity, pool limits,
and preview development/debug/Vite overhead with timings. Sharing the pinned
native base with CI does not turn a preview result into production-mode performance
evidence.

Acceptance targets are p95 committed change to rendered eligible view within
three seconds for 32 principals when source reads finish within one second, and
final convergence within five seconds after 10,000 capture rows over ten seconds.
These are qualification targets, not measured results. Distinguish producer commit
brackets from host dispatch-to-render upper bounds. Notification observations need
the final visible title as well as the accessible unread count: a painted `99+`
badge cannot establish final convergence. Report durable backlog drain separately.

Use Chromium's `Request.timing().responseEnd` for source HTTP duration and retain
host callback times separately. Awaiting a Playwright protocol response before
recording completion can add observer delay after the browser has already painted
the data. Require valid browser timings; do not substitute host durations when
those timings are absent. Keep ordinary and burst measurements separate.

A historical 2026-10-30 preview run passed with 320 ordinary observations: p95 1,765ms,
worst-principal p95 2,146ms, and final burst convergence within 1,455.71ms. The
producer committed 10,000 capture rows in 10,029.28ms. All 1,344 source reads
finished within 956.83ms; clients peaked at two concurrent GETs and four starts per
second. Pool assignments returned to their quiet envelopes and owned cleanup left
no survivors or pending capture rows. These results describe that native preview
workload. Later application fixes do not make that historical run a qualification
of the current production configuration. The roadmap records the retained receipts
and current acceptance status.

For production-mode capacity, select the CI image built from the checkout under
qualification and resolve its immutable ID before starting the disposable runner:

```bash
CI_IMAGE_ID="$(docker image inspect --format '{{.Id}}' baander-ci:local)"
BAANDER_TEST_IMAGE="$CI_IMAGE_ID" BAANDER_TEST_CHECKOUT_IN_IMAGE=1 \
  php scripts/test-live-capacity-container.php
```

The runner executes capacity and both populated-view cases in one serial browser
invocation. Its overall budget is 4,200 seconds: 1,800 seconds for capacity,
two 900-second populated-view cases, and 600 seconds for setup and
cleanup. Login uses the production limiter; its admission and `429` retry delays
belong to setup, not the measured latency windows. These execution budgets leave
the measured acceptance thresholds unchanged. The runner verifies the selected
image, backend source, fixture and built frontend identities. It requires
`APP_ENV=prod` and `APP_DEBUG=0` and rejects a Vite frontend. It uses disposable
services and the real OAuth/DPoP, WebSocket, projection and compiled service-pool
paths. Keep the runtime identity, raw browser receipts and cleanup ledger with the
result. A passing preview or shared native base does not replace this gate.
The first CI-image run passed both populated-view cases, but its capacity case
stopped during the 26th login, before measurement, and completed owned cleanup
without survivors. The subsequent login-navigation fix has focused regression
coverage. The exact cause of the earlier blank-root observation was not isolated.
A login setup failure supplies no ordinary or burst latency result.

The next CI attempt completed all 32 logins but failed the readiness barrier.
Its recorded reconnect intervals were 301.503–303.561 seconds: outgoing health
messages did not refresh Swoole's client-receive idle timer. The shared transport
now sends an owned 30-second keepalive, with focused lifecycle regressions. That
attempt also produced no capacity measurements. A later rebuilt CI image passed
the capacity case; the earlier failures remain retained history.

The latest 2026-10-31 capacity case passed in 957.117 seconds on immutable image
`sha256:a591d2f471859c98c0eae1c474d6ffc30b2825d596f332d5f371dda3dab48526`,
built from checkout `db2c5cd2d779a7878795d0f45efce18e9cae11a4`. Its identity
receipt records PHP 8.5.2, Swoole 6.2.0, production mode, debugging disabled and
the built frontend. Production-limited setup took 918.557 seconds before
measurement. Each of the 32 clients recorded one readiness cycle; the oldest had
been ready for 906.980 seconds when measurement began.

| Measurement | Passing CI result |
| --- | --- |
| Ordinary visible renders | 320 observations; p95 892ms; worst-principal p95 and overall maximum 950ms. |
| Burst production | Exactly 10,000 capture rows in 10,029.440ms, or 997.065 rows/second. |
| Final count and visible marker | All 32 clients converged within a 1,187.106ms host-anchored upper bound from the final commit's start bracket. |
| Observed burst inbox drain | Empty within a 2,223.106ms upper bound from the same host-anchored final-commit-start bracket. |
| Source HTTP | All 1,344 reads returned 200 with valid Chromium timings; maximum 196.982ms overall and 170.419ms during burst/recovery. Every phase met the one-second source premise. |
| HTTP admission | At most two concurrent measured GETs and four starts per second per client. |
| Release and cleanup | Subscriptions returned from 32 to zero; all four workers met their warmed pool-assignment envelopes; owned users, tokens and survivors were zero after cleanup. |

The final-render bound anchors producer-relative commit-start intervals at host
dispatch, avoiding an assumed producer/host clock offset. Inbox drain uses the
same conservative anchor. Each worker retained two new lazily created pools,
both with zero active assignments; unchanged object counts are not the release
criterion.
The 32 complete SPAs, PHP diagnostics client and receipt bindings add observation
cost, and periodic samples can miss short peaks. Retain these limits and the raw
receipts when comparing another configuration. Allocator usage and object counts
did not return to identical cold baselines; sampled reclamation and released pool
assignments do not establish indefinite memory stability.

That three-case invocation recorded two passes and one failure: capacity and
retained song pages passed, while Timeline failed its host-event concurrency
assertion. A subsequent observer attempt also failed because it combined
Playwright timing fields with different origins. Both failed runs remain in the
verification record. After correcting the observer to use one browser clock,
both populated-view cases passed in a targeted 6.9-minute invocation on the same
immutable image. Application sources, built assets and fixtures were unchanged;
the earlier capacity pass still qualifies that runtime. Owned cleanup completed
with no survivors or retained manifests and empty scoped and global inboxes.

The HTTPS ingress is a separate owned container using the same selected immutable
CI image. Only ingress joins both the browser-facing network and the internal
network; the application, PostgreSQL and Redis remain internal. The application
trusts the ingress container's exact internal IP as its proxy. The runner still
requires the published browser endpoint to bind to host loopback.

The backend source digest compares application and package sources, migrations,
configuration and `composer.lock`. It excludes generated `config/reference.php`,
which Symfony rewrites during cache compilation. Fixture and built-frontend
hashes are checked separately. `capacity-runtime-identity.json` records the actual
PHP and Swoole versions, environment, debug setting, frontend and immutable image
identity. A successful identity check establishes the tested setup; workload
latency, populated-view behavior and cleanup still require their own passing
receipts.

A populated infinite view with ten retained pages and a 10,000-item catalog needs
its own read and full-walk timings, with loaded content, selection, and scroll
preserved. Recovery evidence must distinguish a socket outage, an open socket with
degraded publication, an authorization reset or denial, and failed resource HTTP
reads. Keep cross-client and background mutations, stale in-flight responses,
unsaved drafts, preference conflicts, and playback continuity in that verification.
Compare warmed per-worker pool observations before and after the workload through
the same observer path; the diagnostics request can itself borrow services.
Missing workers, persistent extra assignments, and progressive growth need an
explanation rather than a cold-versus-warm allocation comparison.

Count retained pages separately from HTTP attempts. A startup or recovery read can
repeat the first cursor without adding a retained page. Keep every attempt, verify
the ten distinct cursors and the rendered 1,000-row virtual spacer, then measure
the exact ten-page refresh from the committed change. Timeline has a separate
100-page walk over 10,000 albums; a hint during that walk must produce one later
serial walk. Report its complete-walk latency and request amplification separately
from notification latency.

Concurrency requires start and end timestamps from the same clock origin. The
pinned Playwright Chromium adapter shifts `Request.timing().startTime` to the
response's request time, but measures `responseEnd` from the original request
event. Adding them counts an interception delay twice. The observer retains those
fields and host callback order as diagnostics; its concurrency gate uses raw CDP
`Network.requestWillBeSent.timestamp` and `Network.loadingFinished.timestamp`.
Failed requests retain their completion event. Missing timing receipts, unexpected
redirects or a count mismatch fail qualification; no overlap tolerance is applied.

The corrected observer's retained-song case loaded ten logical pages of 100 songs
through twelve initial HTTP attempts, including two repeated startup reads. The
committed-change refresh made exactly ten successful reads over the same ten
cursors. Dispatch to the refreshed visible frame took 1,931ms; the selected public
ID and scroll position of 16,000px were preserved. All 22 requests had matching
host and CDP receipts. CDP concurrency peaked at one; host callbacks reported two.

Timeline rendered 10,000 albums through two serial 100-page walks after a hint
during page 25. All 200 requests returned 200 with 100 albums in exact page order,
and every attempt had a matching CDP interval. Peak concurrency was one, with no
missing timings or observer errors. The first walk took 17,341ms and the second
15,074ms; start to the final visible-title frame took 38,262ms. Request
amplification was 2×. Host callbacks again reported a peak of two. Both cases
completed cleanup with zero survivors, empty scoped and global inboxes, and no
retained manifests. Keep their `qualification.json` receipts with the separate
capacity result.

An earlier 2026-10-31 CI invocation passed both populated-view cases with PHP 8.5.2,
Swoole 6.2.0, production mode, debugging disabled and the built frontend. These
historical measurements predate the final review fixes and do not qualify the
latest Timeline run:

| Workload | Data and requests | Host-observed timing |
| --- | --- | --- |
| Retained song pages | Ten pages of 100 songs; ten initial reads and exactly ten refresh reads; selected public ID and scroll position preserved. | Change dispatch to refreshed visible frame: 2,930ms; observed song-request concurrency peaked at two. |
| Timeline | 10,000 rendered albums; a hint during page 25 required two serial 100-page walks, with 200 successful reads of 100 albums and peak concurrency one. | First walk: 17,136ms; final walk: 15,012ms; start to final visible-title frame: 38,046ms; request amplification: 2×. |

Both fixtures completed cleanup with zero owned survivors, zero scoped capture
backlog and no retained manifest after bounded drain retries. These host timings
include observer and render work; they are separate from source HTTP durations
and notification latency. Keep the runtime identity and both qualification JSON
files with these results; the roadmap records their artifact locations.
The Timeline fixture has no album covers. Its 200 metadata reads do not measure
cover-image refresh fanout or establish latency for an image-bearing Timeline.

Run the recovery, populated-view and scanner scenarios against the same isolated
preview, serially with capacity and other lifecycle commands:

```bash
E2E_BASE_URL="$(make preview-url)" yarn --cwd ui/web e2e:headless \
  tests/e2e/live-updates/recovery.spec.ts \
  tests/e2e/live-updates/populated-views.spec.ts \
  tests/e2e/live-updates/scan.spec.ts --retries=0
```

Before preparing scan media, the browser scenario records a fresh `GET /health`
response and requires its `messenger` component to be healthy. A healthy top-level
status or saved worker-readiness flag does not establish that background workers
are running.
The scanner scenario then prepares a tagged WAV and local lyrics, runs the real
`app:library:scan` command, and observes worker ingestion through the mounted
album and its authorized API response. It requires automatic metadata-provider
sync to be disabled and no matching global webhooks. It checks those conditions
without changing them. Owned notification preferences disable external delivery;
the local lyrics prevent a provider lookup. Cleanup waits for the owned jobs,
outbox events and queued deliveries to finish before deleting their data. An
unfinished or unobservable chain retains its manifest for inspection and cleanup.
Do not clear shared queues to make a failed scenario pass.

Managed preview lifecycle cases close the fixture executor before requesting a
worker reload or full restart, then reopen the same owned run for observation and
cleanup. The lifecycle helper owns its direct PHP child through a 3,600-second
command deadline and a separate 120-second shutdown budget. Teardown requests TERM
and waits for confirmed child closure before reopening the fixture. An uncertain
close retains the command receipt and manifest; it does not grant permission for
a replacement command or concurrent cleanup. Fixture cleanup has its own bounded
90-second retry period and must finish without survivors.

Recorded preview passes cover the recovery scenarios, ten retained song pages and
the serial 10K Timeline walk, including owned cleanup. The scanner's worker-health
precondition rejected an exited worker pair. Managed preview recovery restored
worker availability without resetting data; the retained scan events then relayed,
the owned track was ingested and cleanup completed without survivors. A fresh
scanner browser case also passed with healthy workers. This qualifies the scan and
ingest path under normal worker availability; the original worker-exit exception
remains unidentified. The earlier CI-image populated-view passes remain historical
evidence. The latest image passed capacity and retained song pages; Timeline
remains open as recorded above.

Keep the following evidence boundaries when reporting acceptance:

| Behavior | Evidence boundary |
| --- | --- |
| Cross-client playlists, private notifications and read agreement | Real authorized browser contexts, a denied identity, and same-account mark-read convergence. The playlist producer is an independent committed fixture writer. |
| Interrupted delivery, wake, worker reload and full restart | Real browser transport, frozen-page lifecycle, managed preview controls and subsequent rendered changes. Lifecycle cases do not themselves commit while the server is down. |
| Dirty metadata and playback continuity | Browser holds the user's own successful PATCH, enters newer text, refreshes an unrelated notification and reconnects. It verifies the same audio element, source and queue with advancing native time. |
| Remote metadata conflict, deletion and access loss | Component/coordinator and metadata-hook tests establish conflict choices, unavailable state and draft retention. The browser audio/draft case does not perform a remote metadata edit or deletion. |
| Preference conflicts and feedback suppression | Coordinator/hook/component tests establish retained edits, version conflicts, bounded retry and no feedback writes. There is no preference-conflict browser case. |

These are composed proofs at their respective boundaries. Neither inventory
classification nor the historical capacity result substitutes for an unrun gate.
Record delivered scope, retained artifacts and actual results in the roadmap.
