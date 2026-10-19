# Baander roadmap

Updated: 2026-10-19. This is the working delivery record for the remediation,
registry, and web-state plans. Update it when scope changes or a stage is verified.
Completed code is not proof of production or performance qualification.

External acceptance: deployment access/inventory for `de`, `ca`, `sg`, `au`, and
`fi`, and the S3 backup configuration are unavailable (user confirmed 2026-10-04).
Actual regional deployment, regional measurements, and backup-destination restore
qualification remain deferred until those prerequisites are available.
React Native and Android work is deferred at the user's request (2026-10-05).

## Resume here

Read [AGENTS.md](AGENTS.md) for working rules and the
[testing guide](docs-book/part-2-developer-guide/testing.md) for runners. This
roadmap records scope and acceptance; the linked design documents, current source,
and CI workflow are the executable detail. This section is the sole current work
selection and status summary. Dated handoffs, measurements and limitation lists
below record their own checkpoints; they do not assign new work. Unchecked items
retain requirements or deferred scope, without making every item a next task.

- **Next implementation priority:** select the next concrete bug, unfinished
  feature or named release decision by the rule below. Latest-target
  [rapid native scrubbing](#rapid-native-scrubbing-2026-10-19),
  [encrypted Registry backup automation](#registry-encrypted-backups-2026-10-19)
  and [desktop renderer security](#desktop-renderer-security-2026-10-19)
  are delivered locally. The
  [desktop discovery rendering fix](#desktop-discovery-rendering-2026-10-19)
  is also delivered locally.
  [Store timeline navigation](#store-timeline-navigation-2026-10-19) and inspection
  of selected captured state are delivered locally. General reconstruction and
  replay remain open; actual-destination backup acceptance remains deferred.
- **Delivered local scope:** backend/static boundaries, browser credentials, worker
  lifecycle, asynchronous routes, reserved control capacity, measured autoscaling
  and shared media control retain their recorded evidence. The historical full
  backend checkpoint remains **10,062 tests / 82,056 assertions**, followed by
  focused checks for [shared media control](#shared-webworker-media-control-2026-10-19)
  and [rapid native scrubbing](#rapid-native-scrubbing-2026-10-19); it was not rerun
  for the scrubbing fix.
  Registry registration, authoritative reads, local failure/recovery, transport TLS,
  sanitizers, bounded load checks and encrypted backup/upload/offline restore
  through disposable TLS S3 storage are covered; see the reconciled
  [Registry status](#registry-release-and-growth).
- **Deferred release acceptance:** real regional access, S3 destination and backup
  rehearsal, constrained whole-host measurements, an accepted fixed-runner
  performance baseline and the 24-hour soak remain prerequisites for release.
  Their absence does not select more local failure-injection work. React Native
  and Android remain deferred at the user's request.
- **Selection rule:** start work for a concrete bug, an unfinished feature or a
  named release decision that existing evidence cannot answer. Reuse the existing
  checks first; add a regression only for the specific gap. A historical limitation
  or an unchecked release requirement alone does not justify broader testing;
  there is no obligation to exhaustively cover failure combinations.
  The proposed same-process TLS expansion is abandoned; it was not qualified.
  There is no standing task to complete “full failure injection” or add further
  certificate scenarios.

Roadmap reconciliation on 2026-10-19 changes documentation only. Existing source,
CI and recorded checkpoints establish the statuses below; no product tests were
added or rerun for this cleanup. Prose-preservation, link and whitespace checks
verify this delivery.

### Handoff state on 2026-10-17

Historical handoff: the statements below describe the 2026-10-17 checkout and
workflow. Use [Resume here](#resume-here) to select current work; later delivery
checkpoints supersede this handoff where their scope overlaps.

- **Bounded coroutine DNS.** Implementation on `plan/bounded-coroutine-dns`
  moves complete native lookup into bounded helpers and shares caller budgets
  across DNS and transport. Backend, static, native DNS, isolation and affected
  runtime checks pass; independent review's test finding is resolved. See the
  [verification checkpoint](#bounded-coroutine-dns-2026-10-17).

- **Playlist concurrent writes.** Implementation on
  `plan/playlist-concurrent-writes` closes duplicate collaborator creation and
  stale reorder overwrites. Backend, static, web and runtime qualification pass,
  generated-client repeat generation is unchanged, and independent code review
  found no issues. The user authorized merging through `b57b965e` into `master`
  and pushing to both remotes on 2026-10-17. See the
  [verification checkpoint](#playlist-concurrent-writes-2026-10-17).

- **Migration consolidation.** Implementation `78165f61` on
  `plan/consolidate-doctrine-migrations` replaces the historical chain with one
  guarded initial migration. The user authorized merging into `master` and pushing
  to both remotes on 2026-10-17. All 9,498 backend tests and required gates pass. The
  authorized reset of local `baander` is complete; `baander_test` is unchanged.
  See the [verification checkpoint](#doctrine-migration-consolidation-2026-10-17).

- **Public identity baseline.** `master` contains implementation `6149f5b1`,
  fast-forward merged on 2026-10-17. Public APIs use entity public IDs while UUIDs
  remain internal. Independent plan/code review and simplification have completed,
  with findings applied. The user approved existing passkey re-enrollment on
  2026-10-15. Public user handles and credential invalidation pass focused CI,
  static checks and the final full backend run. The user authorized the merge and
  push to both remotes on 2026-10-17.
- **Previous published baseline.** Provider destination policy was merged into `master`
  and pushed to both remotes at `0067f342`, following atomic rate limits,
  WebSocket handshake tickets, registry security qualification and repository
  cleanup. Completed older plans, obsolete repository analysis tooling, the
  standalone `cli/` tree and root `bun.lock` were removed. Six older plans for open
  or deferred work remain alongside the recent security and identity plans.
  Book pages describe current behavior without plan references.

- **History.** On 2026-10-14 the `Claude-Session:` trailer was removed from the 25
  local commits that carried it with a message filter; every tree is unchanged, so
  `git diff` between an old and its rewritten commit is empty. Both remotes now hold
  the rewritten history, so any checkout of `master` taken before the rewrite (head
  71df8f57 or earlier) must reset to the pushed history; the pre-rewrite heads stay
  in this clone's reflog until it expires.
- **Remotes.** `origin` is the Forgejo instance (`ssh://git@192.168.50.151:222/martin/baander.git`,
  whose `.forgejo/workflows/ci.yaml` is the authoritative gate) and `baander-github`
  is `git@github.com:baander-app/baander.git`. Push only when the user asks.
- **Gates.** Build the CI image with the command in the
  [testing guide](docs-book/part-2-developer-guide/testing.md#running-tests) and pass
  its tag as `BAANDER_TEST_IMAGE`; the checkpoints below used a local tag of that
  `ci` target named `baander-ci-prewarm:local`. The suite runners are
  `scripts/test-unit-container.sh` and `scripts/test-functional-container.sh <path>`.
  Both use `scripts/test-backend-container.sh`; invoke that coordinator without a
  selection to run all four suites. It defaults to two workers and a 3,600-second
  overall timeout, creates disposable Compose projects for service tests, and
  removes their containers, networks and volumes after collecting diagnostics.
  Use `BAANDER_TEST_PROCESSES=1` for diagnosis. The static gates run inside the same
  image with the checkout mounted read-only at `/var/www/html`: mount `src`, `tests`,
  `config`, `migrations`, `bin`, `scripts`, `templates`, `translations`, `vendor`, `packages`
  (required: `vendor` symlinks into it), `phpstan.dist.neon`, `deptrac.yaml`,
  `deptrac.baseline.yaml`, `composer.json`, `composer.lock`, `symfony.lock`, `.env`,
  `.env.test` and `openapi.json`, with `--security-opt label=disable` and
  `XDEBUG_MODE=off`, then run `php vendor/bin/phpstan analyse --memory-limit=1G`,
  `vendor/bin/deptrac analyse --no-cache`, `php bin/console lint:container --env=test`
  and `php bin/console app:export-openapi-spec --env=test --check`. The OpenAPI
  export fails on the host on an unrelated PropertyInfo error, so regenerate the
  specification in the image, writing to a path outside the mounted file and copying
  it into the checkout. Web gates run in `ui/web`: `yarn typecheck`, `yarn lint`
  (zero errors, seven standing warnings), `yarn test --run`, and `yarn generate`
  after an API change. Host runs skip the integration suites and fail
  `StagedOAuthSecretBundleTest` and the Swoole, drain and process-pool suites for
  host reasons; use the image for the authoritative numbers.
- **Workflow.** AGENTS.md names the compound-engineering skills (`ce-plan`,
  `ce-doc-review`, `ce-work`, `ce-simplify-code`, `ce-code-review`). A harness
  without them follows the same sequence by hand: a plan under `docs/plans/`
  reviewed before implementation, a branch named after the plan, path-limited local
  commits, a simplification pass, an independent code review whose justified findings
  are applied, and the delivered item with its checkpoint counts recorded here.
  Commit messages carry no agent attribution trailer.
- **Remaining from item 8's review.** Sharing has no invitation step, so an
  owner can add any user unasked; removing a song is not judged by the actor's
  visibility; the visible-song count materialises rows. These remain deferred.
  The duplicate collaborator and unguarded reorder findings are closed by the
  [playlist implementation](#playlist-concurrent-writes-2026-10-17).
- **Priority at this handoff.** Superseded by [Resume here](#resume-here).
  The subsequent Registry disk, worker and shared media-control deliveries retain
  their checkpoints below. Latest-target native scrubbing remains open; regional
  and S3 acceptance require their external prerequisites.

The backend release-gate package is complete (see the next two sections). Eight
plans followed it, in this order, and all eight are delivered:

1. General settings and email language:
   one definition-driven settings mechanism, the per-user email language, and the
   features behind the existing admin toggles. Delivered on 2026-10-08. The
   open-items follow-up
   closed the rest on 2026-10-10.

   Closed on 2026-10-10: the web server raises health alerts, from HTTP worker 0 every
   60 seconds, so a stopped worker or a sustained Redis or PostgreSQL outage alerts
   once; a server that starts during an outage alerts once and worker reloads do not
   repeat an alert; an alert that cannot be delivered while PostgreSQL is down is
   delivered once it can be, naming the outage window
   ([learning](docs/solutions/architecture-patterns/health-checks-run-in-a-messenger-consumer-cannot-see-outages-that-stop-it.md)).
   The worker heartbeat lives in Redis, so the web container reads it, and a worker
   outage no longer makes the web container unready; the memory check measures each
   web worker against its memory limit; the worker's five-minute health job is gone.
   `scripts/test-web-runtime-container.sh` runs the real Swoole server in CI and fails
   when a credential email misses the user's language or a rendition does not stream
   while it encodes and answer byte ranges once complete; it also keeps a baseline of
   the server's answers to Safari's opening requests. Safari playback of a still-encoding
   rendition is a documented manual check in the
   [testing guide](docs-book/part-2-developer-guide/testing.md#safari-playback-of-an-encoding-rendition-manual).
2. Admin/CLI parity:
   every action on the admin pages has a console command that reaches the same
   Application use case as its controller. `AdminCliParityTest` fails on an admin route
   with neither `#[CliCounterpart]` nor `#[CliParityExemption]`, and
   `CommandDocsCoverageTest` on a command without an operator docs page. The work
   also added the server control channel for diagnostics and QoL, cooperative job
   cancellation, admin-only library rename, delete and scan with a leased scan claim,
   and it ends a disabled user's tokens and WebSocket connections. Delivered on
   2026-10-09. The follow-up,
   also delivered on 2026-10-09, gave commands to the routes it had deferred: the
   catalog and player admin actions (metadata editing, per-song lyrics fetch and apply,
   cover upload and delete, artist create and credits, album, song, movie and artist
   delete), webhook administration and server discovery registration. No deferred
   exemption remains, and the parity test fails on an exemption that defers its
   command. The admin notification bell needs no command: it reads the
   administrator's own inbox. The
   open-items follow-up
   closed the rest on 2026-10-10 (see below). Still open:
   - `ui/rn` feature drift: the test setup loads again, but `tsc` reports 84 errors and
     25 Jest tests fail, from missing `ui/shared` exports (`TVStatusCard`,
     `TVTable`), API response shapes in `catalog-api.ts`, React Native 0.86 removals
     (`StyleSheet.absoluteFillObject`), missing `tvSpacing` tokens and TV components that
     do not forward `testID`. Deferred with React Native work (user decision,
     2026-10-10); a `ui/rn` CI job waits until they pass.

   Closed on 2026-10-10: a delete with audio files holds the library under a claim of
   its own kind, so scans wait for it and a queued import waits, then skips files that
   are gone; a delete with files refuses when every file is missing (an empty mount)
   or the library folder is gone; nested library roots are refused at creation; the
   missing `unlinkat()` is an accepted, documented limit; a lyrics insert race answers
   409 or the stored lyrics, and LRCLIB requests time out after 10 seconds; enrichment
   re-reads its target before applying; admins grant and revoke library access from
   the admin user page and with `app:library:member:*`; API messages follow the user's
   saved language, then `Accept-Language`, per request; `auth.reconnect` and its tokens
   are gone; leaving or ending a party removes the sockets from its room;
   `findAfterId()` is gone.

   Closed on 2026-10-09: a finished lyrics run's queued fetches can be cancelled from
   the job monitor; every admin write command has `--json`; the dead SSE counter and
   SSE docs are gone; the WebSocket registry, reconnection tokens and CPU pool tables
   hold the rows they promise; the Swoole timers that log release pooled services;
   `room.join` no longer lets any user join another party's room.
3. Timestamp convention:
   every instant column is `timestamptz` at microsecond precision, written and read in
   UTC. Delivered on 2026-10-10. `InstantType` replaces Doctrine's `datetime_immutable`
   and `datetimetz_immutable`, and every PostgreSQL connection sets its session time
   zone to UTC. Migration `Version20261010180000` relaxes 125 `timestamptz(0)` columns
   and converts 11 zone-less columns in 5 tables, reading their values as UTC.
   `doctrine_migration_versions.executed_at` stays zone-less, because Doctrine
   Migrations parses it that way. Hand-written binds in the outbox, notification
   delivery, the recommendation pool and the token repositories keep microseconds and
   the offset. The song "added" cursor pages exactly within one second. API output
   keeps whole seconds (`Y-m-d\TH:i:sP`): the transcode and recommendation fields that
   returned PostgreSQL's raw text now use that format. `InstantColumnConventionTest`
   and `InstantBindConventionTest` fail on a regression. Nothing is open.

   Found and fixed on the way: both transcode `/list` endpoints answered 500 because a
   parameter route caught `list`; `TranscodeJobDto` read two columns that do not
   exist; the recommendation pool did not percent-decode the database password; and
   `doctrine:migrations:migrate` now refuses a database that records migrations the
   directory lacks, where Doctrine would have rerun them. Accepted limits: a `since`
   filter at whole-second API precision can return a row from its own second again,
   and a cursor issued before the change may repeat one row.
4. Web server drain on container stop:
   stopping or restarting the web container no longer interrupts listeners. Delivered on
   2026-10-11 (branch `plan/item4-web-shutdown`). `docker stop` used to end in SIGKILL:
   the CPU pool's health timer kept Swoole's master running after SIGTERM, and shutdown
   then waited on a heartbeat thread with a 60-second interval. Now SIGTERM, SIGINT,
   `DELETE /api/server` and `swoole:server:stop` enter one drain from `onBeforeShutdown`,
   bounded by `WEB_DRAIN_SECONDS` (30 by default; the stop timeout must be at least 5
   seconds longer).

   Swoole closes client connections before it signals the workers, so the master holds
   a drain window open while the workers drain. In that window WebSockets close with
   1012 and `/ready` answers 503. Requests and file downloads in progress finish. Streams
   still open near the end of the window are cut as errors, never ended cleanly, and
   every FFmpeg stops with its process group. A watchdog process kills a manager or pool
   that does not stop, and the server then exits 1. An idle stop takes under a second.

   When its stream fails or stalls, the web player probes `/live` and, once the server
   answers, resumes the same track at its position; a transcoded track waits for its
   complete rendition. Firefox reports a cut stream as an error and then fires `ended`;
   the error wins (user decision, 2026-10-11). Session-sync sockets reconnect with a
   current token, a refresh that fails during a restart no longer signs users out, and
   the playing device keeps its position on rejoin. Production PHP runs at 512M.

   `scripts/test-web-shutdown-container.sh` runs the server as PID 1 and covers every
   stop route, stalled clients, a stopped worker and manager, PostgreSQL down, and worker
   memory against the limit; CI runs it. `media-interruption.spec.ts` and
   `stream-recovery.spec.ts` cover the browsers.

   Found and fixed on the way: a throwing streamed-response generator ended the stream
   cleanly and killed its worker; a stopped encode ended a progressive stream as if it
   were complete; a drain left video sessions live for 60 s with no loop behind them,
   so the drain now ends them as failed (kept by user decision, 2026-10-12); a start-up
   cleanup query in the master left the server half-started when PostgreSQL was down;
   two unit tests hung or crashed the unit run in the CI image. The code review added:
   `/live` reports `"draining": true` so the player waits out a draining server; a worker
   that reported drained withdraws while a late request runs; `swoole:server:stop` no
   longer dies with a PID 1 master; `POST /api/auth/refresh` answers 401 only for a
   refused token and 503 with `Retry-After` for server faults, so a restart no longer
   signs users out; a DPoP-nonce 400 no longer ends the web session; a session socket
   still connecting is closed on teardown. Still open:
   - Safari's resume after a restart is a manual check that has not been run;
     Playwright's Linux WebKit uses GStreamer and could not seek after an outage.
   - The manual restart of the dev stack with a song playing has not been run; the dev
     database must be reconciled first (below).
   - No drill drains an HLS video session; that path has unit coverage only.
   - In development, Xdebug's `develop` mode crashes a coroutine woken by
     `Coroutine::cancel()`, so a dev server stopped with a stream in flight may log a
     worker crash. Production and the drills run without Xdebug.
   - Closed by item 5: a refresh whose response was lost after the server rotated the
     token signed the user out at the next refresh (the lost-response grace), and a
     Redis outage answered every refresh with a 400 challenge (now 503 with
     `Retry-After`).
   - A request that reaches a worker within the master's last 100 ms tick of the drain
     window can still be reset.
   - The web player retries a `play()` rejection other than "needs a gesture" until its
     two-minute budget runs out, and the cost of Swoole's heartbeat scan, now every
     second, with many idle WebSockets is unmeasured.

5. Browser credential and session qualification:
   a signed-in listener stays signed in through a refresh whose answer never arrived, a
   second tab of the same account, a Redis outage, a service worker the browser restarted
   and a server restart, and logout ends the session everywhere it was in use. Delivered
   on 2026-10-13 (branch `plan/item5-browser-session`).

   Found and fixed on the way: logout revoked nothing (the controller passed the raw JWT
   where a token id was expected), so a refresh token stayed redeemable for 30 days and
   the session socket stayed open; `/api/oauth/revoke` let any signed-in user revoke any
   token whose string they knew; two tabs of one account never coordinated their
   refreshes; an idle service worker the browser respawned forwarded media requests
   without credentials; the DPoP nonce and jti checks turned a Redis failure into the
   same boolean as a miss, so token endpoints handed out nonces they could not store and
   every DPoP-bound request answered 403; a listener put an unstored nonce on every API
   response, so even a refresh inside the nonce lifetime was challenged; the jti cache's
   TTL never reached Redis; `TokenPairIssuer` signed after its commit; the rotation
   re-stamped `used_at` with PHP's clock; a hung Redis blocked requests until TCP gave
   up; the host vendor directory had drifted 82 packages behind `composer.lock`. The
   code review added: a reuse verdict closes the chain's WebSocket connections as a
   logout does; a chain revocation lists the access tokens to close under the refresh
   row locks, so a replacement a concurrent rotation just committed is closed too;
   logout and `/api/oauth/revoke` answer 503 with `Retry-After` when the chain or its
   sockets could not be revoked, instead of a 200 that hid the fault; a tab whose
   logout was in flight no longer keeps the session a sibling tab rotated meanwhile,
   and a rotation adopted during a login no longer leaves the tab loading.

   Server: a used refresh token presented again by the same DPoP key within
   `auth.refresh.reuse_grace_seconds` (60, maximum 60, 0 disables) while every live
   replacement is unused gets a fresh pair and the replacements are revoked; the decision
   is taken from rows locked `FOR UPDATE` inside the refresh transaction, the
   consume-race loser runs the same evaluation under the lock, and every other reuse
   revokes the chain in its own transaction before the 401. A different key never
   revokes. Chain rows lock in one order (client, presented refresh row, successors,
   access rows) and both issuing transactions set a local `lock_timeout`. One
   `DpopStoreUnavailableException` from the two Redis-backed checks is answered at five
   edges (refresh, login, passkey, the token endpoint's DPoP listener, the request
   binding listener) with 503, `Retry-After: 5` and `Cache-Control: no-store`, no nonce
   header; every Redis connection, the factory's and the Symfony cache pools', carries a
   1 s connect and read timeout. Logout revokes the chain of the presented access token
   and closes the WebSocket connections opened with any of its access tokens through a
   new server control operation; `/api/oauth/revoke` revokes only the caller's own
   tokens and answers foreign and unknown tokens alike.

   Web: one refresh runs inside a 30 s budget with a 10 s attempt timeout, one transport
   retry, one nonce retry and one 503 wait (`Retry-After` plus jitter, capped at 10 s),
   a fresh proof per attempt, and one outcome for the initiator and every queued request;
   tabs of one origin refresh under a Web Lock, re-read the stored record and adopt a
   rotation another tab made, write credentials by compare-and-put against an epoch that
   only grows, and broadcast rotation and clearing as events without tokens; a restarted
   service worker pulls its credentials from the requesting tab over one bounded channel
   bound to the client id and origin, and capacity overflow answers a synthetic 503.

   Evidence: `RefreshTokenGraceTest` (12), `RefreshTokenConcurrencyTest` (9, two database
   sessions, a logout against a refresh of the same chain in both lock orders, signing and
   flush failures, a lost commit acknowledgement, a lock past its timeout, pool stability) and
   `DpopStoreOutageTest` (9, a refused and a paused Redis) on real PostgreSQL and Redis;
   `LogoutTest`; the browser-auth suite's eighteen Chromium scenarios, including a nonce
   challenge, a lost response recovered by the grace, the 503 wait, a worker stopped
   through CDP, two pages of one account on the real store and a logout that clears the
   other page; `scripts/test-web-runtime-container.sh` stops Redis on the real server
   and asserts the 503s, one alert and the recovery. Still open:
   - The refresh rate limiter is keyed by the token string before any proof check, so a
     string holder can spend a victim's bucket; it is skipped in `dev`.
   - Closed by item 6: a logout during a Redis outage, or one the server answered 503,
     ended the session locally only; the server now accepts the outage logout and the
     web app keeps a pending logout until the server confirms it.
   - A connectivity gap longer than the grace window after a lost response still ends
     the session; the retry then lands outside the window.
   - The runtime drill stops Redis rather than pausing it, so the read timeouts on
     Swoole-hooked streams are proven on the test kernel, not observed on the server.
   - Closed by item 6: password change and client revocation revoked without closing
     sockets; the socket reconciliation now ends them within a minute.
   - The main e2e suite still uses `baander.test` fixtures and does not run in CI.

6. Queued server-side logout:
   a logout ends the session on the server even when it happens during a Redis outage,
   while the browser is offline, or when the web server could not close the session's
   sockets. Delivered on 2026-10-13 (branch `plan/item6-queued-logout`, on top of the
   unmerged item 5 branch).

   Server: `POST /api/auth/logout` and `POST /api/oauth/revoke` accept a DPoP proof whose
   replay check could not run (a refused or hung Redis, an exhausted pool) after every
   other check passed, log one warning with the route, the thumbprint and the user, and
   revoke the chain in PostgreSQL; every other route keeps the fail-closed 503. A
   revocation whose socket close failed answers 200 with the error logged, since the
   token is gone; 503 is kept for a revocation that did not commit. A reconciliation tick
   in HTTP worker 0 closes, once a minute, every WebSocket connection whose token chain
   has no live access token left (revoked, expired or no longer stored), whatever ended
   it, through a new tagged timer port the drain subscriber stops; the chain is the unit,
   so a rotation alone never closes a socket (a review finding changed the query from
   per-token to per-chain liveness). The planned PostgreSQL-queued close job was dropped
   during review (user decision): the in-process close cannot fail the way a queue would
   retry, and the reconciliation bounds every open-socket case with one timer.

   Web: a logout ends the session in the browser first and keeps a pending entry, with
   the ended session's key pair and tokens, in the credential store's second record,
   written with the tombstone in one transaction; a drain delivers it on start, on
   `online`, on visibility and on a backoff timer, per entry under the shared auth lock,
   refreshing with the entry's own pair first when the access token expired or the logout
   answered 401, and ends the entry on a logout 200 or the refresh's refusal (user
   decision over a 7-day cap); at most 20 entries, the oldest sent once before it is
   dropped.

   Evidence: `DpopStoreOutageTest` (13, logout and revoke during a refused Redis and
   under pool exhaustion, the retained refusals, the replay cases, the untouched nonce
   store), `RefreshTokenGraceTest` (14, a close the server could not finish answered 200
   and a reconciliation pass closing a revoked and an expired token's connections while a
   live one stays), the reconciler's unit and probe suites, the browser-auth suite's
   pending-logout scenarios, and the web runtime drill, which now logs out a second pair
   during the Redis outage and sees it refused after Redis returns. Still open:
   - A logged-out browser holds the ended session's refresh token and signing key until
     the logout lands or the refresh token is refused; a script on the origin can keep
     that chain alive by rotating it, and no absolute chain lifetime bounds it yet.
   - The revoke endpoint's request body is not covered by the DPoP proof, so a replay
     during an outage can reach another chain of the same user whose token id the
     replayer holds.
   - A socket of a revoked chain stays open for up to one tick when the hot-path close
     failed.

7. Video delivery authorization matrix:
   the seven signed delivery routes, the signing endpoint and the quality ladder are
   exercised through the real firewall against PostgreSQL and Redis, for anonymous,
   owner, library member, unrelated user and administrator under every credential
   condition. Delivered on 2026-10-14 (branch `plan/item7-authorization-matrix`).

   The recorded contract: a valid signed URL is a bearer capability that serves any
   holder without a credential, a member of another library included; a credential
   that is presented must be valid, so an expired, revoked, foreign-audience,
   malformed or fingerprint-mismatched token answers 401 even with a valid signature;
   the DPoP proof is never read on a delivery route, so a missing, foreign-key or
   reused proof serves and records no replay key; a signature fault (missing,
   expired, wrong key, tampered path or query, substituted segment index) answers 403
   on every delivery route before the streaming port is called; the signing endpoint
   and the ladder refuse a missing proof, a foreign key, a reused proof and an unbound
   token with 403, a bad token with 401 and a video of another library with 403; and
   a DPoP-bound token presented with the Bearer scheme answers 401 on every route the
   `api` firewall authenticates (user decision, RFC 9449 section 7.1, over recording
   the previous acceptance). Every 403 is told apart by a kernel-exception probe that
   records the refusing exception, since the API answers one generic 403 body; 401
   rows read the authenticator's code. Membership removal and token revocation after
   signing leave the signed URL serving and refuse new grants.

   Two defects fixed on the way: `OAuth2Authenticator` accepted a bound token under
   `Bearer`, and the signed URL's signature reached `prod.log` and `error.log`
   through the web processor's request URL and the Swoole access log; a Monolog
   processor now redacts the `sig` value from every record. A unit guard holds the
   delivery route set equal across the firewall's public rows, the binding listener's
   exemptions and the rate limiter's exemptions, and the fixture the matrix loops over
   is derived from it.

   Evidence: `SignedDeliveryFirewallTest` (77 tests, 2,571 assertions: 42 credential
   rows on a manifest and a segment representative, 18 bound-endpoint rows on the
   ladder and the sign endpoint, the signature kinds on every route, the cross-route
   proof, the foreign-library member, the two transitions and a credential scan over
   every response body and log file), `DeliveryRouteInventoryTest` (2),
   `StreamSignatureRedactionProcessorTest` (2), `OAuthAccessTokenJwtTest` (9), and
   `LogoutTest` and `DisabledUserAccessTest` moved to the DPoP scheme. Not covered:
   `HEAD` requests, the audio, catalog and image suites (their own real-firewall
   tests stand), and the WebSocket handshake's query-token authenticator.

8. Catalog authorization matrix and playlist collaborators:
   every catalog, library and playlist mutation and the library-scoped reads the read
   suite skipped are proven through the real firewall against PostgreSQL and Redis, the
   playlist's unused collaborator table became a working feature with sharing controls
   on the playlist page, the signing validation answers through the kernel, and the
   fallback-identity audit is recorded with one tightening. Delivered on 2026-10-14
   (branch `plan/item8-catalog-authorization`, merged into `master` the same day).

   The recorded contract: every catalog and library change is administrator-only, and a
   refused request leaves no row, file or queued job behind; reads stay bounded by
   membership, so a filter naming something of another library answers an empty page.
   Playlists are shared with named collaborators (user decision, chosen over owner-only
   mutations and over an administrator grant): the owner names and removes
   collaborators by public id, a collaborator views and, while the owner keeps
   collaboration on, changes the songs under their own library membership, and may
   leave, while a user holding no collaborator row is refused as a leaver before any
   user lookup, so a repeat leave answers 403 (code review fix, a deviation from the
   plan's idempotent 204); the owner alone edits details, deletes and manages
   collaborators; a non-owner administrator views and nothing more. Users are named by public id and no UUID
   leaves the API (user decision). Every playlist refusal names the rule that decided.
   The audit found no path that acts as an administrator without a signed-in user; the
   library lookup's unrestricted default became an explicit choice at its six callers.

   Fixed on the way, found by the matrix: adding a song never checked that the actor
   could see it and an unknown id answered 500; a partial reorder deleted the entries
   it was not told about; the song list's genre filter matched names while the API
   documents slugs; a new artist cover image row was never linked to its artist; two
   admin duplicate-listing error messages had no translation. A unit guard holds the
   two mutation suites' route keys to every write route under the API prefix, so a new
   mutation route fails a unit test until it has rows.

   Evidence: `CatalogMutationFirewallTest` (130 tests: thirty routes crossed with
   anonymous, member, unrelated member and administrator, with scan-all, file deletion
   on a real root, cover rows, cross-library merge, path validation and a credential
   scan), `PlaylistMutationFirewallTest` (72 tests: eight routes crossed with owner,
   collaborator, collaborator with sharing off, unrelated member, administrator and
   anonymous, plus the visibility, reorder, idempotency and router rows),
   `CatalogReadFirewallTest` (60, with the genre-form, denied-filter, related-resource and
   anonymous-detail rows), `PlaylistReadFirewallTest` (21, with the collaborator rows),
   `SignedDeliveryFirewallTest` (90, with the signing 400 rows),
   `MutationRouteInventoryTest` (2), `PlaylistCollaboratorListRepositoryTest` (3), the
   playlist, voter, handler and lookup unit suites, and twelve web tests for the sharing
   dialog, the detail page and the list badge. Not covered: `HEAD` requests, audio,
   image and user-administration suites (their own tests stand), and a browser pass of
   the sharing dialog.

The local dev database (`baander` in `baander-postgres`) was about 40 migrations
behind, with class names from before the rename and a last migration date of
2026-07-15. Its reset was kept out of item 3 by the user on 2026-10-10. The user
authorized a separate reset for the 2026-10-17 migration consolidation; that reset
is now complete. Other old histories require their own target-specific approval
and manual reset. Do not mark the initial migration as executed to bypass refusal.

Registry release prerequisites are consolidated under
[Registry release and growth](#registry-release-and-growth); they are not an
automatic local task queue. Local cluster tests do not establish deployment or S3
restore acceptance. React Native and Android remain deferred; Android's device
login posts to a pairing endpoint that does not exist and needs a decision when
that work resumes.

## Current quality-gate checkpoint

Historical checkpoint collection. The heading and anchors are retained for links;
[Resume here](#resume-here) identifies current status and priority. Each result
qualifies only its recorded tree and scope.

### Bounded coroutine DNS (2026-10-17)

Implementation on `plan/bounded-coroutine-dns` follows the
[reviewed plan](docs/plans/2026-10-17-1325-fix-bounded-coroutine-dns-plan.md).
Backend, static, native DNS, isolation and affected runtime checks pass.
Independent review's test-coverage finding is resolved and its stronger native
regressions pass.

`NativeDnsResolver` runs the system resolver's complete native A/AAAA lookup in a
fresh PHP helper, preserving both-family and native error semantics. The calling
coroutine polls while other work can advance. No capped Swoole address list
substitutes for the complete reply; one forbidden answer still refuses the target.
The existing approved-host, public-address, TLS, pinning, proxy and redirect
controls remain in force.

`PublicHttpClient` carries one absolute monotonic deadline through DNS and every
approved redirect hop. `PushTransport` spends one thirty-second budget on DNS and
sending, reducing its five-second connection timeout to the remaining budget.
Direct destination-policy calls without a caller deadline use thirty seconds.
Exhausted lookup budgets and helper capacity stop before transport dispatch.

Admission is shared across resolver instances and admits at most eight owned
children per PHP process, including unreaped children. Replies are limited to
64 KiB. Polling uses five-millisecond sleeps, shortened near the deadline with a
one-millisecond minimum. Timeout and cancellation kill the direct helper; cleanup
polls for up to 250 milliseconds, and an unreaped child keeps its slot. Inherited
process accounting is discarded after fork without signaling the parent's
children. The helper closes inherited descriptors, starts without application
bootstrap or inherited environment credentials, and arms an alarm rounded up to
the remaining second, capped at thirty seconds.

Verification in `baander-ci-prewarm:local`:

- Before deadline integration, the HTTP/push selection ran **88 tests / 214
  assertions** with **three errors and two failures** in **17.4 seconds**.
  Deadline callbacks were absent and expired or late resolutions were accepted.
  This failed run establishes the regressions before the fix. Artifacts:
  `var/test-runs/20261017-133735-6e90bf86`.
- Initial native lifecycle qualification passed **11 tests / 31 assertions** in
  **19.1 seconds**. The added runtime fixture retains 48 timeout/cancellation
  outcomes across both hook modes, capacity recovery and unchanged descriptor
  counts; its final result is included in the full native lane below.
- All **64 Python runner regressions** passed in **0.200 seconds**. The new
  container runner passes shell syntax checks. Log:
  `/tmp/baander-dns-runner-regressions.log`.
- The dedicated DNS drill passed with both-family success, definitive AAAA no-data,
  family SERVFAIL and **101 addresses** through native TCP fallback, including a
  late private answer refused by the policy. Stalled lookups took **0.1555** and
  **0.1551 seconds** with hooks disabled and enabled; the heartbeat advanced
  **32** and **31** times, respectively, and no HTTP request was dispatched. The
  parent-SIGKILL fixture observed helper SIGALRM and reaped the orphan. Log:
  `/tmp/baander-dns-runtime.log`.
- Final Composer platform requirements, PHPStan, Deptrac, container lint and
  OpenAPI drift gates passed in **72.10 seconds**. Deptrac reports **zero
  violations, warnings and errors**, with **489 existing baseline skips**.
  Artifacts: `var/test-runs/dns-static-_ovbwblm`. Log:
  `/tmp/baander-dns-final-static.log`.
- The full backend run passed **9,560 tests / 79,002 assertions in 911 files**
  with zero failures, errors or skips, in **767.0 seconds** including coordination
  (**767.03 seconds** wall time). Ordinary: **6,029 / 23,383**, **59.1 seconds**;
  native: **659 / 8,127**, **102.4 seconds**; service lanes: **1,533 / 32,225**,
  **550.9 seconds**, and **1,339 / 15,267**, **572.1 seconds**. This adds **20 tests**
  to the prior 9,540-test playlist checkpoint, with no removals. Artifacts:
  `var/test-runs/20261017-134855-162a970a`.
- Independent code review completed across eight lenses. Its one P2 finding
  concerned a test: the oversized fixture's invalid JSON could fail without
  proving the output cap. The fixture now emits a valid **100,001-byte** IP list,
  and the regression requires the specific overflow error, no retained child and
  a successful next lookup. Focused post-review native validation passed
  **15 tests / 128 assertions** in **10.098 seconds** of PHPUnit execution,
  **16.8 seconds** in the native lane
  and **27.6 seconds** including coordination (**27.71 seconds** wall time).
  These are the same fifteen native cases with two stronger assertions. Artifacts:
  `var/test-runs/20261017-140501-6033a407`; log:
  `/tmp/baander-dns-review-native.log`. No review findings remain unresolved.
  The passing full run above precedes this test strengthening and excludes its
  stronger assertions. Review receipt:
  `/tmp/compound-engineering-1000/ce-code-review/20261017-135610-dns/`.
- Both implementation simplification passes found no changes needed.
- All **20 runner-isolation checks** passed in **43.67 seconds**, covering
  independent database, Redis and filesystem state. Log:
  `/tmp/baander-dns-final-isolation.log`; artifacts:
  `/tmp/baander-stack-isolation-zw1h76rs`.
- The production security runtime drill passed **15 malformed-request rejection
  cases** and its WebSocket/Redis-outage checks in **64.64 seconds**, with unchanged
  user/credential counts. Log: `/tmp/baander-dns-final-security-runtime.log`.
- The production web-runtime drill passed in **54.34 seconds**, including the
  Safari response baseline, PostgreSQL outage alerts without duplicates, and Redis
  outage/recovery/logout. Log: `/tmp/baander-dns-final-web-runtime.log`.
- Post-review scoped PHPStan passed in **17.69 seconds**. Artifacts:
  `var/test-runs/dns-review-static-38idtit2`; log:
  `/tmp/baander-dns-review-phpstan.log`. The full static run above passed before
  the test strengthening.
- Final container and network inventories contain no backend-test, DNS, security,
  web-runtime or isolation resources. Disposable infrastructure was removed.
- The security guide, threat model, testing guide and this roadmap passed
  prose-preservation audits, relative-link checks and whitespace checks.

Initial runtime qualification is recorded as failed. The dedicated DNS container
first inherited its image's `www-data` user and could not configure its disposable
resolver; setup now explicitly uses root while application probes remain
`www-data`. The cancellation fixture also initially assumed every
`Coroutine::cancel()` call must return true. In Swoole 6.2, an asynchronous
io_uring cancel can return false when the target's short sleep completes during
the cancellation operation, even while the target coroutine remains alive. The
fixture now requests cancellation within a bound, yielding between attempts, then
still requires all four cancellation outcomes and four deadline outcomes per
round. Increasing deadlines did not establish the cause and is not treated as a
fix. No production cancellation retry was added. Initial static attempts found
fixture typing issues and, in a **72.75-second** run, one redundant resource-type
assertion. The fixture retains the actual write that verifies the parent handle
remains usable; the final static run above passes.

No frontend behavior or API contract changed, so web suites and client generation
were not repeated. Worker lifecycle code was untouched, so worker drills were not
repeated; the affected production security and web-runtime drills ran instead.

Limits: process launch, cleanup and operating-system scheduling prevent a hard
real-time return guarantee. Existing provider and Messenger retries can extend the
overall operation, and push delivery is not exactly once. The per-process helper
bound scales with configured workers; no cross-worker admission service is added.
Webhook, operator-configured lyrics, mail, tracing and browser playback retain
separate policies. Live-provider, deployment trust-store and regional qualification
remain pending; React Native and Android remain deferred. No application API,
schema, dependency or frontend behavior changed. No local application database was
migrated or reset. The work is committed locally; no merge or push is included.

### Playlist concurrent writes (2026-10-17)

Implementation on `plan/playlist-concurrent-writes` follows the
[reviewed plan](docs/plans/2026-10-17-1239-fix-playlist-concurrent-writes-plan.md)
and closes the duplicate collaborator and stale reorder findings from item 8.
Backend, static, web and runtime qualification pass. Generated-client repeat
generation is unchanged, and independent code review found no issues. Delivered
locally on 2026-10-17.

Every existing playlist save uses compare-and-swap on its persistence version,
then locks and compares its complete original song positions and collaborator
membership before reconciling collections. The immutable snapshot protects hidden
entries as well as visible ones. A stale save rolls back and answers 409 instead
of replacing the winning metadata or memberships or recreating a deleted playlist.
Playlist deletion locks the parent before its children, matching save order;
external song and collaborator cascades also invalidate the original membership.

Detail responses return an opaque 64-character lowercase hexadecimal revision
derived from the stored version and complete memberships of the aggregate used to
assemble the displayed songs. Metadata-only list responses carry no revision.
Reordering requires `expectedRevision`, checks current edit permission again and
returns the saved revision. Missing or malformed revisions answer 422; stale
revisions and persistence conflicts answer 409. Clients refresh and reconcile
before submitting another order. Partial reordering still retains unlisted songs
and existing visibility rules.

Collaborator addition retries only typed persistence conflicts, for at most three
attempts. Each attempt reloads the playlist and checks current ownership; an
already-present collaborator succeeds without a write. Exhaustion answers 409.
The OpenAPI specification and generated web client describe these contracts. The
client refresh also synchronizes existing public-ID documentation annotations,
with no other TypeScript behavior changes.

`Version20261017130000` adds a nonnegative `persistence_version` to playlists,
starting existing rows at zero. The initial migration remains intact, and the
forward upgrade preserves playlist identities, metadata and memberships.
`FinalSchemaBootstrap` applies the current migrations in timestamp order inside
an empty isolated schema, reusing provisioned extensions. Fresh migration runs
execute both files; a second run has no pending work. No local application database
was migrated or reset for this work.

Verification in `baander-ci-prewarm:local`:

- The stale-save regression failed before the persistence fix: **1 test / 10
  assertions**. The completed persistence selection passed **98 tests / 538
  assertions** in **74.6 seconds**: domain **66 / 187**, service **32 / 351**.
  Artifacts: `var/test-runs/20261017-125208-de3fb0b8`.
- Composer platform requirements, PHPStan, Deptrac, container lint and OpenAPI
  freshness passed after persistence implementation, before the API changes.
  Artifacts: `var/test-runs/public-identity-static-0ebmelej`.
- The API selection discovered **318 tests**. Ordinary checks passed **200 tests /
  539 assertions**; service checks ran **118 / 5,421** with one anonymous-fixture
  failure caused by an owner GET retaining authentication state. The fixture now
  uses its raw kernel without unrelated Swoole isolation. The corrected firewall
  selection passed **74 tests / 3,164 assertions** in **61.3 seconds**. Artifacts:
  `var/test-runs/20261017-125903-e392470f`. The initial selection is recorded as
  failed.
- The first full backend run selected **9,540 tests in 909 files** and took
  **765.8 seconds**. Ordinary checks ran **6,024 / 23,363 assertions** without
  failures or errors but with two warnings: `PublicIdSchemaTest` assumed the first
  request property was `songIds`, which the revision declaration displaced. Its
  fixture now selects the property by name; the corrected ordinary rerun below
  verifies the fix.
  Native checks passed **644 / 8,001**, and service lanes passed **1,533 / 32,225**
  and **1,339 / 15,265**. The full run is recorded as failed because warnings are
  blocking. Artifacts: `var/test-runs/20261017-130027-541111ff`.
- The corrected ordinary selection passed **6,024 tests / 23,365 assertions**
  without warnings, errors, failures or skips in **50.466 seconds** of PHPUnit
  execution and **69.0 seconds** including coordination. Artifacts:
  `var/test-runs/20261017-131317-af3c0e49`. Both runs used seed `1550408278`.
  Together with the unchanged passing native and service lanes above, qualification
  covers **9,540 tests / 78,856 assertions**. This is the full run plus a corrected
  ordinary rerun, not one clean full coordinator run. The selection adds **42 tests**
  to the previous 9,498-test checkpoint, with no test removals.
- Final-tree Composer platform requirements, PHPStan, container lint and OpenAPI
  drift checks pass. Deptrac reports **zero violations, warnings and errors**, with
  **489 existing baseline skips**. The static run took **71.34 seconds**. Artifacts:
  `var/test-runs/playlist-static-dqfdf2mb`.
- Web verification passed **2,054 tests in 186 files** in **28.94 seconds** of
  Vitest execution (**29.69 seconds** including command overhead). Typechecking
  passed in **17.74 seconds**; lint passed in **21.08 seconds** with zero errors
  and seven standing warnings. Logs: `/tmp/baander-playlist-final-web-tests.log`
  and `/tmp/baander-playlist-final-web-lint.log`.
- Generated-client repeat generation passed in **5.77 seconds** with unchanged
  output. Log: `/tmp/baander-playlist-client-drift.log`.
- Serial producer and outbox runtime drills passed in **19.09 seconds** and
  **20.28 seconds**, respectively. Logs:
  `/tmp/baander-playlist-final-producer-runtime.log` and
  `/tmp/baander-playlist-final-outbox-runtime.log`.
- Consumer recovery passed in **117.80 seconds**: the killed consumer became
  unhealthy at 92 seconds, its replacement recovered unacknowledged work, and
  shutdown was clean. External deployment recovery passed in **32.14 seconds**
  against real Docker and PostgreSQL 18.4. This verifies the external controller,
  not the complete `LeasedWorkerRuntime` deployment. Logs:
  `/tmp/baander-playlist-final-worker-runtime.log` and
  `/tmp/baander-playlist-final-worker-recovery.log`. Full timings:
  `/tmp/baander-playlist-verification-1.json`.
- Disposable resources were removed. Only the pre-existing development nginx,
  PostgreSQL and Redis containers and their network remained.
- Independent code review completed across ten lenses with zero findings. Receipt:
  `/tmp/compound-engineering-1000/ce-code-review/20261017-130128-playlist/`.
- Persistence simplification removed a redundant new-aggregate flag and avoided
  temporary UUID and playlist-song objects when comparing stored scalar rows. API
  and qualification simplification needed no changes. The four guide updates and
  this roadmap passed prose-preservation audits and whitespace checks.

Limits: an aggregate saved inside an enclosing transaction remains provisional
until the outer commit. An outer rollback does not rewind its in-memory snapshot;
callers must discard it and reload before another write. Direct SQL writers and
other writers that bypass this repository remain outside the concurrency contract.
Invitation flow, remove-song visibility, visible-count materialization, live
provider qualification and mobile work remain deferred. The user authorized
merging through `b57b965e` into `master` and pushing to both remotes on 2026-10-17.

### Public API identities (2026-10-17)

The implementation on `plan/public-api-identities` replaces internal entity UUIDs
at HTTP, JWT, cursor, signed-delivery and realtime boundaries with independent
public IDs. It supersedes the schema-only reconciliation below, which preserved
some UUID-valued API contracts. Existing relationship field names remain where
appropriate; their values resolve the related entity's public ID without a UUID
fallback. Provider identifiers, OAuth token identifiers, WebAuthn credential IDs,
browser device tokens and idempotency keys retain their separate protocol meaning.
Delivered locally on 2026-10-17.

Current scope:

- Catalog, Media, Playlist and Library project public identities for resources and
  relationships and resolve public inputs under existing media/owner scopes.
- Auth user, client, login-block and passkey resources use public identities.
  Both JWT signing paths and principal resolution use public claims; old
  UUID-subject access tokens are rejected, with refresh or sign-in issuing the
  new claims. Internal token persistence and DPoP identities remain unchanged.
- Session, Party, Activity, Discovery and Notification project public relationships
  and realtime identities. Browser device tokens remain distinct from persisted
  device public IDs; notification cursors retain ordering and access checks.
- Transcode manifest inputs, session/job projections, Radio references and EQ
  profile identities use public IDs. Radio provider station identifiers and EQ's
  client audio-output `deviceId` retain their existing meanings.
- Scheduler jobs and occurrence receipts and Recommendation resources have public
  identities. Occurrence request deduplication remains internal, and monitoring
  projections omit internal serialized message data. Direct database writers and
  relationship hydration have been updated alongside ORM mappings.
- A forward migration backfills independent, unique, non-null public IDs without
  replacing internal primary/foreign keys or resetting development databases.
  Web and embedded-player consumers and generated API bindings are updated.

Independent plan review, code review and simplification are complete, with findings
applied. Corrections include the Recommendation pool's direct insert, ownership
filtering for personalized recommendations, current media-access checks on
Transcode job views, required Scheduler relationship identity and narrow application
contracts at controller boundaries. A further independent review found that a leave
broadcast's identity read could fail after persisted membership removal, leaving
sockets subscribed. Identity projection failures now close the internal party room
before propagating the original error, without broadcasting UUIDs. Controller
identity-read outages refuse dispatch and leave the socket responsive. Independent
fixture review preserves OAuth/proof/revocation, authorization, localization and
cursor assertions while replacing stale public-boundary UUIDs.

Verification in `baander-ci-prewarm:local`:

- `PublicIdentityMigrationTest` passed **6 tests / 201 assertions** in
  **0.516 seconds** in service lane 2, covering populated migration and identity
  persistence. In the same run, Auth public-identity acceptance passed **3 tests /
  22 assertions**, and job-monitor cursor pagination passed **6 tests / 46
  assertions**. Artifacts: `var/test-runs/20261015-213735-381483da`.
- The native WebSocket selection passed **56 tests**. The combined selection of
  **96 tests** failed only on missing strict schema annotations. Those annotations
  have been corrected. That failed run is not recorded as a pass.
- The first full backend run executed **9,498 tests in 906 files** in
  **738.2 seconds** and failed on stale fixtures, corrected before the passing
  2026-10-15 run below. This failed run is not a qualification pass. Artifacts:
  `var/test-runs/20261015-214412-ce91666d`.
- The corrected selection ran **620 tests in 32 files** in **153.4 seconds**:
  **619 passed**, with one stale user-profile assertion still expecting `uuid`.
  That assertion was corrected before the passing full backend run below. Artifacts:
  `var/test-runs/20261015-215937-c4d401b4`.
- The corrected full backend run passed **9,507 tests in 906 files / 78,433
  assertions** in **731.1 seconds** on 2026-10-15. Timing JSON and JUnit results
  remain in `var/test-runs/20261015-220516-680d0d68`. This checkpoint precedes the
  public passkey user-handle and credential invalidation changes.
- After the passkey changes, a focused CI run passed **159 tests in 13 files /
  2,516 assertions** in **98.0 seconds** on 2026-10-17. This includes populated
  migration verification (**7 tests / 203 assertions** in **0.547643 seconds**),
  public-handle registration, authentication and account signals with real
  cryptography (**94 unit tests / 434 assertions**), and OAuth/integration checks.
  Artifacts: `var/test-runs/20261017-110836-5c088ef2`.
- The first full backend rerun after the passkey changes executed **9,521 tests
  in 907 files** in **796.0 seconds** on 2026-10-17 and failed in two tests.
  `DrainCoordinatorTest` raced the child process's command-line availability;
  its fixture now waits up to one second for readiness before checking the same
  watchdog and timer behavior. `PasskeyRegistrationOutboxTransactionTest` omitted
  the required public user handle and hit that guard before its intended outbox
  failure; both attempts now supply the handle, preserving rollback and retry
  assertions. Independent review found no issues with either correction. The
  focused and full runs below verify both corrections; this failed run is not a
  qualification pass. Artifacts: `var/test-runs/20261017-111208-6c1914fb`.
- Focused verification of those corrections passed **5 tests in 2 files / 43
  assertions** in **63.3 seconds**, using seed `603235677`. Artifacts:
  `var/test-runs/20261017-112547-d4068314`. PHPStan passed for both edited files;
  logs remain in `var/test-runs/public-identity-fixture-static-je2u9tqj`.
- The final full backend run passed **9,521 tests in 907 files / 78,489 assertions**
  in **735.6 seconds**, with zero errors, failures or skips, using two workers and
  seed `603235677`. Ordinary: **6,010 tests / 23,298 assertions**, **60.0 seconds**;
  native: **644 / 8,001**, **92.3 seconds**; service lanes: **1,636 / 34,246**,
  **553.3 seconds**, and **1,231 / 12,944**, **427.6 seconds**. Report completeness
  validation passed, and resource checks found no retained test, security-drill or
  web-runtime containers, networks or volumes. Artifacts:
  `var/test-runs/20261017-112729-94834a03`.
- Composer platform requirements, PHPStan, Deptrac, container lint and OpenAPI
  freshness checks all passed after the passkey changes. Deptrac reports **zero
  violations, warnings and errors**, with **489 existing baseline skips**. Static
  logs remain in `var/test-runs/public-identity-static-gdf13cng`.
- Focused frontend verification passed **274 tests in 37 files**, followed by
  **8 EQ cases in 2 files**. The full web suite passed **2,054 tests in 186
  files** in **36.08 seconds**; the embedded-player suite passed **167 tests in
  12 files**. Web, embedded-player and shared typechecks pass after API generation.
  Web lint reports **zero errors and seven standing warnings**. The final passkey
  web selection passed **17 tests in 2 files** in **1.09 seconds**.
- React Native reports **278 type errors**, identical to the normalized `HEAD`
  baseline. Android verification is blocked by a package absent from its lockfile.
  These results do not qualify either deferred client.
- Serial runtime drills passed before the passkey changes: **15 rejection cases**,
  WebSocket proof, replay, origin, logout and Redis-outage checks, streaming and range
  delivery, PostgreSQL outage alerts, and Redis refresh/logout recovery. Their
  temporary logs have expired; these are prior checkpoint results.

The user approved invalidating existing passkeys and requiring re-enrollment on
2026-10-15. An enrolled authenticator's immutable user handle cannot be repaired by
a database backfill. New registrations use the user's public ID as the WebAuthn
user handle; the migration takes an `ACCESS EXCLUSIVE` lock before deleting old
credentials, with a five-second lock timeout. Accounts, passwords, roles and OAuth
tokens are preserved. Registration now decodes browser credential IDs as base64url
before comparing them with the authenticator response. Independent Auth and migration
reviews found no remaining issues. Focused CI and the final full backend run verify
the migration and public-handle behavior.

### Provider destination policy (2026-10-15)

Seven metadata adapters, Spotify token acquisition, IPRD catalog requests and
persisted web-push subscriptions now validate their approved HTTPS destinations
before connecting. The shared policy requires port 443 and checks every returned
A/AAAA address. Empty, mixed public/private, reserved, multicast and IPv6
translation/transition answers fail closed. Connections pin one approved address
while retaining hostname and certificate verification; ambient proxies and
connection reuse are disabled.

Ordinary provider redirects are rejected. Cover Art Archive alone may follow up
to three GET redirects within its approved CAA/Internet Archive domains, validating
and pinning each hop and removing credentials on cross-origin transitions. Push
uses an explicit cURL handler, rejects 3xx reports and disables the installed
Guzzle rewind-retry path; 404/410 subscription expiry remains intact. Provider
payload mappings and existing 429 retries remain, with TMDB now checking the
second response's status after a retry. Logs omit API-key queries, push capability
URLs and raw provider/transport failure details.

Verification uses `baander-ci-prewarm:local` (PHP 8.5.2 ZTS, Swoole 6.2.0,
PHPUnit 13.1.11, ParaTest 7.22.4):

- Focused checks pass: **161 tests in 11 files / 676 assertions**, **59.0 seconds**
  including setup and cleanup. The ordinary lane ran 149 / 503; the Messenger
  integration lane ran 12 / 173. Random seed: `660332318`.
- The focused selection covers every provider adapter, DNS/host restrictions,
  same-origin DNS changes, protected transport options, redirect bounds and
  cleanup, Spotify token POSTs, credential-free logs, and persisted push endpoints.
  Actual Symfony and Guzzle cURL requests use disposable TLS peers for trusted
  success, untrusted certificates, hostname mismatch and redirect refusal.
  Test wrappers inspect production pinning before routing to loopback; production
  policy has no private-address exception. No live providers were contacted.
- Composer platform checks, PHPStan, Deptrac, container lint and OpenAPI drift
  checks pass. Independent plan/code review and simplification are complete.
- All four backend suites pass: **9,391 tests in 885 files / 75,870 assertions**,
  without coverage, in **779.4 seconds**. Ordinary: 5,899 / 22,641 assertions;
  native: 642 / 7,952; service lanes: 1,018 / 17,461 and 1,832 / 27,816. Report
  completeness checks and disposable-stack cleanup pass. Random seed: `1269042221`.
- The production security drill passes in **65.26 seconds**, including fifteen
  malformed-request cases, WebSocket proof/ticket/replay checks, Redis outage
  recovery and credential-log scanning. The web-runtime drill passes in
  **57.68 seconds**, including localized mail, progressive/ranged playback,
  PostgreSQL outage/worker reload, and Redis outage/logout/recovery.
- Disposable containers, networks and volumes were removed; development
  PostgreSQL and Redis were not reset. Documentation links, prose audits and
  whitespace checks pass. No frontend behavior changed; web tests were not rerun.

The initial focused image run failed strict notice handling on Minishlink's known
optional GMP/BCMath notice. The test helper now verifies that exact notice, source
and expected count during construction, forwards all other errors, and restores
PHPUnit's handler immediately. No production suppression or test-wide relaxation
was added. Four PHPDoc annotations were corrected after the initial PHPStan run.
Earlier host assertions were corrected to observe Symfony's wrapped responses and
normalized headers rather than the unwrapped fixtures.

Limits: native DNS lookup is synchronous and can block a coroutine worker beyond
the HTTP deadline. The installed Swoole resolver [rejects AF_UNSPEC](https://github.com/swoole/swoole-src/blob/v6.2.0/ext-src/swoole_coroutine_system.cc#L154-L190)
and [caps returned addresses](https://github.com/swoole/swoole-src/blob/v6.2.0/src/network/dns.cc#L778-L817),
so replacing the combined A/AAAA lookup requires separate qualification.
HTTP budgets are capped at thirty seconds; existing provider and Messenger retries
can extend overall work and do not promise exactly-once push delivery. These local
checks do not qualify live provider availability, deployment trust stores or
regional operation. Webhook, operator-configured lyrics, mail, tracing and browser
playback remain separate policies. No application API, schema or dependency changed.

### Atomic rate limits (2026-10-15)

All 19 configured limiters retain Symfony's fixed/sliding-window calculations but
commit state through Redis compare-and-set. Each operation owns its snapshot;
generation and revision checks prevent concurrent requests spending the same last
token or restoring state cleared by an administrator. Empty reads and subject
resets leave ten-second revision markers; Redis rejects snapshots older than two
seconds. Definite conflicts retry at most eight times within a two-second monotonic
budget. Uncertain writes are not replayed, and an in-flight Redis call can finish
after that budget expires.

Unavailable or corrupt state stops protected work with a generic 503,
`Retry-After: 1` and `Cache-Control: no-store`. Confirmed exhaustion, media
exemptions and development bypasses retain their existing behavior. Logout and
revocation alone bypass generic API limiter availability failures, preserving
confirmed exhaustion and all remaining authentication/proof checks. Password-reset
and verification-resend catch-all handlers now propagate this availability outcome.
HTTP and CLI administration clear the consumed generations and report partial
failures; the catalog and generated client expose `storageNamespace`. The dedicated
limiter cache pools are removed.

Verification against `baander-ci-prewarm:local` (PHP 8.5.2 ZTS, Swoole 6.2.0,
PHPUnit 13.1.11, ParaTest 7.22.4):

- The full four-suite run passed **9,271 tests in 879 files**, with **75,463
  assertions**, without coverage, in **772.2 seconds**. Ordinary: 5,779 tests /
  22,234 assertions; native: 642 / 7,952; service lanes: 1,018 / 17,461 and
  1,832 / 27,816. The coordinator validated report completeness and removed its
  disposable services. Random seed: `1823255201`.
- The corrected focused Redis/DPoP run passed **23 tests / 1,589 assertions** in
  **90.2 seconds** including setup and cleanup. Six independent PHP consumers
  read the same final-token revision before release; exactly one succeeds for
  each window policy. Tests cover fresh-process reset isolation, paused/refused
  Redis, expired snapshots, corruption recovery, ordinary exhaustion and logout
  during an outage. Consumer HTTP tests prevent token creation and dispatched
  work on unavailable budgets; CLI failures keep success data off stdout.
- Web API generation, typecheck and lint pass; lint retains **seven existing
  warnings and zero errors**. Web tests pass: **2,051 tests in 185 files**,
  **28.71 seconds**.
- Composer platform checks, PHPStan, Deptrac, container lint and OpenAPI drift
  checks pass on the final code and test tree.
- The production security drill passes in **65.72 seconds**: fifteen malformed
  HTTP cases, WebSocket proof/ticket/replay checks, paused and stopped Redis
  failures, recovery and credential-log scanning.
- The production web-runtime drill passes in **57.58 seconds**: localized
  credential email, progressive/ranged playback, the Safari request baseline,
  PostgreSQL outage and worker reload, and Redis outage/recovery. Protected
  requests returned 503 with a one-second retry delay, logout returned 200 and
  revoked its chain, and the other session refreshed after recovery. Health
  alerts remained single-shot across both outages.
- Disposable test containers, networks and volumes were removed; development
  PostgreSQL and Redis were not reset. Documentation link checks, prose audits
  and whitespace checks pass. Independent code review and simplification found
  no remaining production-code blocker.

The initial focused run found one outdated retry-delay assertion, corrected to
identify the limiter's earlier rejection while retaining strict DPoP-specific
checks. Independent review corrected unconditional security wiring, corruption
recovery and the new recovery fixture's subject digest. One full run was stopped
before qualification when that fixture defect was found; the complete successful
run above follows its correction. Initial verification failures also included a PHPUnit helper-name collision
and four PHPStan test typing/assertion issues.

Limits: effective configuration changes start fresh budgets; different rolling
configurations do not share one budget. A named clear cannot repair wrong-type
subject keys or missing/invalid record generation fields; those remain unavailable
until expiry or targeted repair. Redis failover, distributed load and regional
qualification are not established by these local checks. Native application work
remains deferred.

### WebSocket tickets (2026-10-15)

The raw upgrade accepts a single-use ticket from the DPoP-protected
`POST /api/ws/tickets` endpoint. It requires GET `/api/ws`, valid framing, one
canonical ticket parameter and an exact configured Origin before consumption.
Redis atomically verifies bindings and consumes the ticket; fresh PostgreSQL
scalar reads check its token owner, expiry, revocation, DPoP binding and enabled
user. Tickets expire within thirty seconds and never outlive their access token.
The Shared adapter now uses an Auth application contract; its foreign repository
dependency and baseline entry are removed.

The web and embedded transports obtain fresh tickets for reconnects and cancel
pending issuance on disconnect or replacement. Application logs redact tickets;
exact `/api/ws` proxy access logs omit queries and referrers, and its raw-request
error logging is suppressed. Ordinary proxy diagnostics remain enabled. OpenAPI
and generated web bindings describe the new endpoint. The manual plan, independent
review and simplification workflow completed without a remaining code blocker.

Verification uses `baander-ci-prewarm:local` (PHP 8.5.2 ZTS, Swoole 6.2.0,
PHPUnit 13.1.11, ParaTest 7.22.4) and disposable services:

- The four-suite run selected and executed **9,195 tests in 873 files** without
  coverage, in **747.0 seconds**. Ordinary: 5,725; native: 642; service lanes: 1,006
  and 1,822. It found two route-inventory failures and one disabled-user fixture
  Origin error. Both affected files were corrected and rerun in the same image:
  **6 tests, 116 assertions**, all passing in **54.5 seconds**. The full run is
  recorded as failed; final acceptance includes these reruns of the corrected files.
- PHPStan, Deptrac, Composer platform checks, container lint and OpenAPI drift
  checks pass. PHPStan's initial seven test-annotation errors were corrected and
  the static gates rerun successfully. API generation and the subsequent web
  typecheck pass.
- Web: **2,051 tests in 185 files**, **35.34 seconds**. Embedded player:
  **167 tests in 12 files**, **3.01 seconds**. Both typechecks pass; web lint has
  zero errors and seven standing warnings outside the changes.
- The tracked nginx fixture, built from the production `nginx:alpine` base,
  runs nginx **1.31.7**. All three configurations pass syntax and log checks:
  twelve upstream-failure probes, eight redirects and three positive logging
  controls in **2 seconds**, including duplicate and encoded query forms.
  The probe is a blocking Forgejo step alongside the security runtime drill.
- The production shutdown drill passes in **123.44 seconds**, including worker
  reload with an open ticket-authenticated socket, close code 1012 during drain,
  held streams, stopped workers/manager, unreachable PostgreSQL and process cleanup.
- The final security runtime drill passes in **64.80 seconds**: fifteen malformed
  HTTP cases, real-firewall proof rejection and replay checks, strict upgrade
  rejection, atomic one-use/concurrent redemption, expiry, disable and logout,
  paused/stopped Redis failures and recovery, and credential scanning in logs.
  The logout fixture now seeds a normal refresh chain; tokens without a chain
  retain the limitation below.
- Disposable test, security, shutdown and proxy containers were removed. Local
  development PostgreSQL and Redis were not reset. Documentation links, YAML,
  shell syntax and whitespace checks pass. Prose audits cover the new protocol,
  updated handoff and checkpoint; historical acceptance records are preserved.

Limits: an unused stolen ticket can race its owner; it does not sign WebSocket
messages. Revocation concurrent with registration retains the reconciliation bound,
and tokens without refresh chains rely on reconciliation rather than immediate
logout closure. The embedded party message vocabulary remains unqualified.
Pre-location proxy errors, external TLS proxies and regional deployments are not
qualified by the local checks. Native application work remains deferred.

Historical checkpoints follow; their counts and coverage describe their own trees.

Recorded on 2026-10-12, at the end of the ROADMAP item 4 drain work and its code
review fixes (branch `plan/item4-web-shutdown`), all in a CI image built from the branch
unless noted:

- Unit suite with coverage, as CI runs it: 6,022 tests in 24 shards pass. Functional:
  1,449 tests in 131 shards; Integration: 1,010 tests in 101 shards; no failures. A
  coverage-mode run of the functional and integration suites hit one PostgreSQL deadlock
  in `InstantColumnMigrationTest` that did not recur in eight reruns.
- PHPStan: no errors on a full scan. Deptrac: 0 violations. Container lint passes.
  The OpenAPI specification check passes. The Docker build-context check passes again;
  it had failed on master since the Pi tooling was removed.
- Runtime drills: the web drain drill (also in CI mode), the web runtime drill, the
  worker runtime, command, operator and production drills, the outbox and producer
  drills and the container start-up check pass.
- Web: 1,904 tests in 177 files pass; the typecheck passes; lint has zero errors and
  the four existing warnings. The audio-graph suite passes in Chromium and Firefox on
  the host and in WebKit in the Playwright container.
- `ui/rn` is deferred indefinitely (84 `tsc` errors and 25 Jest failures); no CI
  job runs it.

The item 8 checkpoint, on 2026-10-14: unit 6,220; functional 1,448 in 134 files;
integration 1,326 in 106 files; web 2,030 in 183 files with typecheck and lint at zero
errors; PHPStan clean; Deptrac 0; container lint and the OpenAPI check passing, all in
the CI image against the branch tree, which `master` now carries unchanged.

The item 7 checkpoint, on 2026-10-14: unit 6,176; functional 1,445 in 133 files (the
item 6 line's 1,455 was over-counted: the functional file set and test methods are
unchanged since); integration 1,096 in 104 files; web unchanged from item 6 (no `ui/`
change); PHPStan clean; Deptrac 0; container lint and the OpenAPI check passing, all in
the CI image against the branch tree.

The item 6 checkpoint, on 2026-10-14: unit 6,158; functional 1,455; integration 1,046;
web 2,018 with typecheck and lint at zero errors; browser-auth 24 (Chromium and WebKit);
PHPStan clean; Deptrac 0; container lint, the OpenAPI check and the web runtime drill
passing.

The item 5 checkpoint, on 2026-10-13: unit 6,128; functional 1,455;
integration 1,040; web 1,978 with typecheck and lint at zero errors;
browser-auth 18; PHPStan clean; Deptrac 0; container lint and the OpenAPI check passing.

The item 3 checkpoint, on 2026-10-10: unit 5,950; functional 1,449; integration 1,000;
web 1,842 (from item 2); PHPStan clean; Deptrac 0.

The item 1 checkpoint, earlier on 2026-10-10: unit 5,917; functional 1,439;
integration 982. The item 2 checkpoint: unit 5,846; functional 1,438;
integration 981; web 1,842; PHPStan clean; Deptrac 0.

The previous checkpoint, on 2026-10-09 at `master` `f40085e9`: unit 5,799;
functional 1,422; integration 959; web 1,836 with typecheck and lint at zero
errors; PHPStan clean; Deptrac 0; container lint and the OpenAPI check passing.

## Backend release-gate package (2026-10-06 to 2026-10-07)

The package removed every Deptrac violation and ran the whole suite in the final CI
image. It also fixed the defects found on the way, which the user chose to fix
rather than defer.

**Boundaries.** Deptrac went from 185 active violations to 0 through application
ports and narrow named contract layers, with no baseline growth.

**Defects fixed.**

- Authentication:
  - password login always failed (token metadata looked up the wrong column);
  - refresh tokens were not bound to the DPoP key;
  - passkey login was unregistered;
  - client fingerprint binding was not enforced;
  - a password change left every session working;
  - password reset tokens were stored in plain text, never expired, never sent and never redeemable;
  - email verification tokens were stored in plain text and never sent;
  - user lookups by email could not use the unique index.
- Authorization:
  - any user could create libraries, validate paths and create radio sources;
  - party creation accepted any video or job;
  - the API limiters were configured but not enforced.
- Data:
  - missing foreign keys and indexes;
  - redundant indexes;
  - VARCHAR columns;
  - schema-wide constraint naming, with a PGroonga naming standard;
  - jobless transcode sessions;
  - duplicate job-monitor rows on retry;
  - second-precision job timestamps;
  - closed date ranges in analytics.
- Behavior:
  - the "flush failed" admin action;
  - library scans from the CLI;
  - artist sort counts;
  - DESC and null-year pagination;
  - job-monitor DESC paging and duration sorting;
  - admin-created users without notification preferences;
  - double processing in debug ingest;
  - WebSocket `party.join`;
  - the failure transport, which is now a listable PostgreSQL table whose failed retries stay listed.

**OAuth.** The authorization-code (PKCE S256), device-authorization (RFC 8628) and
refresh grants and personal-access clients run on Baander's own DPoP-bound token
pipeline; League remains only the resource server. Introspection is removed. The
OAuth endpoints answer in RFC 6749/8628 form. Operators manage clients from the
admin Security page and `app:oauth:client:*`. The web app has `/device` and
`/oauth/authorize` pages for device apps such as a future Apple TV client.

**Self-service account flows.** Password reset and email verification now send
email after the response, redeem hashed single-use tokens, and have web pages.

**History.** The repository history was rewritten to start on 2026-08-01, and the
listed dates were replaced everywhere in it.

**Decisions recorded for later.**

- Disabled accounts cannot redeem reset tokens.
- Reset requests and redemptions share one per-IP limit.
- Party references do not keep transcode jobs alive.
- The per-account reset limit stays at 10 per 15 minutes.

## Latest verified backend batches

Push registration now atomically creates or rotates an owned endpoint. Unrelated
claims return 409 without changing credentials; same-owner updates preserve the
ID and creation time. Concurrent registrations are tested against real PostgreSQL
unique-index locking, and delivery tests consume the rotated keys. Registration
and bulk removal use application ports, with no controller ORM/entity dependencies
and no implicit flush or commit of caller work. Production provider defaults are
unchanged; test configuration uses `push.baander.app`. Exact-lock tests pass 214
tests (758 assertions); Unit/StaticAnalysisRules pass 4,562 tests (19,242 assertions).
PHPStan remains at zero. Deptrac falls to 296 active violations and 672 skipped
occurrences with zero errors after removing three obsolete baseline pairs. OpenAPI
and clients document 200/201/409 and the shared success shape; web typechecking
passes. Actual browser push-manager lifecycle qualification is still pending.

Push unsubscribe now deletes only the authenticated owner's endpoint, with identical
204 responses for missing and unrelated subscriptions. Real OAuth regressions
reject deletion by unrelated members and administrators. The atomic PostgreSQL
DELETE returns deleted IDs so Doctrine can detach them without initializing lazy
references, flushing unrelated writes or committing caller transactions. Lazy
reference, rollback and independent-observer commit tests pass. The exact-lock
push suite passes 190 tests (585 assertions); full PHPStan remains at zero and
Deptrac at 298 active violations, 679 skipped occurrences and zero errors. The
exact authenticated-identity port rule permits the public contract while its
architecture fixture still rejects Auth internals. Existing timezone-free push
creation timestamps remain a separate persistence follow-up.

Lyrics reads now resolve songs through the current library scope; cached misses
remain read-only. Fetch/apply require administrator privileges before provider
calls or persistence. The real OAuth/DPoP and PostgreSQL matrix plus existing
functional tests pass 35 tests (232 assertions). The exact scope-port architecture
regression passes 20 assertions and still rejects foreign internal services.
Locked full PHPStan reports zero errors; Unit/StaticAnalysisRules pass 4,565 tests
(19,233 assertions). Deptrac remains at 298 active violations, 679 skipped
occurrences and zero errors, without baseline expansion. OpenAPI now documents
authorization, empty cached results and the apply request body; clients are
regenerated from the same specification.
The lyrics UI hides management actions from ordinary users, disables closed or
unauthorized provider searches, and distinguishes failed reads from empty caches.
Twelve UI regressions cover permissions, demotion, request-specific invalidation,
and the boolean admin selector avoiding unrelated user/token rerenders. Full web
and worker typechecks and scoped lint pass; this is component-level evidence.

Signed delivery now shares full-query signature validation across manifests and
segments. The production-firewall matrix passes 25 cases (313 assertions), with
182 related unit tests (797 assertions). It covers seven delivery routes, five
actor types, expiry/tampering, revoked credentials, and cross-library signing.
Valid signed URLs remain bearer capabilities until expiry.

Webhook delivery uses encrypted original secrets only. Hash-only signing and its
persisted selector are removed. Disposable PostgreSQL tests verify fresh installation,
preserved encrypted secrets, transactional refusal of unrecoverable local rows,
and rotation against receiver-side HMAC verification. Webhook/handler units pass
79 tests; functional/schema checks pass 21 tests, with independent-connection
migration checks. No production deployment or local database reset was performed.

Webhook delivery now disables proxy use through transport-compatible `no_proxy`
instead of the empty `proxy` option rejected by Symfony's native HTTP client.
A regression captures the real delivery options and verifies the native client
attempts the pinned local destination even with a proxy configured. The 76 webhook
units, 18 functional controller tests, and full PHPStan scan pass. The notification
runbook now describes the sole encrypted original-secret format.

The supervised `app:worker` Docker drill kills the direct outbox relay after a
committed claim and again during notification insertion inside the projection
transaction. It proves child containment, rollback with zero visible effects,
unchanged claim tokens and expiry, and a replacement worker's single projection
receipt after each natural 60-second lease expiry. The full worker drill passes.
Consumer and transport-acknowledgement crash points were qualified later; see the
completed crash gate under application security and reliability.

Radio source initialization now uses the existing application port instead of
creating and persisting an aggregate from the console boundary. Focused command
regressions verify the source settings, returned ID, and missing-source behavior;
62 Radio unit tests and container wiring pass. Deptrac falls from 296 to 290
active violations with no baseline changes; full PHPStan remains clean.

OAuth key initialization now refuses existing files, symlinks and path aliases,
validates a generated pair before exclusive 0600 writes, and removes only its own
partial files on failure. Qualified regressions cover write failures and path
replacement races. The `APP_SECRET` guide and environment templates now require
one literal value and an offline webhook rekey with matched database/secret
recovery. Full Unit/StaticAnalysisRules pass 4,580 tests (19,323 assertions), and
the full PHPStan scan reports zero errors.

Development setup now stops at the first failed subprocess, returns a failure exit
code, and withholds the success banner and later stages. A fake `bin/console`
regression reproduces failures at fresh schema drop, migration, OAuth key setup,
OAuth client setup, and dev-user creation; all five now pass.

Baander-defined HTTP headers now use the `X-Baander-` prefix across backend,
web, Android, Electron, webhook delivery, and test authentication. A shared
`BaanderHeader` enum owns PHP wire names and server keys; CORS and OpenAPI
describe the new session header. The old device header is rejected by a real
preflight regression. Unit/StaticAnalysisRules pass 4,582 tests (19,335 assertions),
affected functional tests pass 230 (3,188 assertions), full PHPStan is clean,
and OpenAPI/client drift checks pass. Deptrac still reports 296 active violations
and 672 skipped occurrences, none from the new header dependency. The full web
suite caught a stale activity-view auth mock, corrected in its test fixture;
the rerun passes all 1,630 tests across 146 files, plus typecheck and lint.

Deptrac resource layers now enforce the documented resource-to-model exception
without allowing controllers to depend on domain models. A real Deptrac fixture
tests both sides. Removed 23 obsolete resource baseline pairs and the removed
webhook signer dependency. The scan reports 238 active violations, 676 skipped
occurrences, and zero configuration errors. Uncovered contexts remain work.
The full configured PHPStan scan remains at zero errors after these batches.

The subsequent coverage audit added the missing Radio, Scheduler, Session, QoL,
Lyrics Application/Interface, and Filesystem Infrastructure layers. A real Deptrac
regression previously allowed application-to-infrastructure leaks in these contexts;
it now rejects them. This exposes 142 previously unreported occurrences across 60
dependency pairs. With the token-port repair removing four existing occurrences,
the scan reports 376 active violations and zero configuration errors. No baseline
entries were added. Some newly visible edges are legitimate application contracts
requiring precise public-contract rules; others are implementation coupling to fix.

Radio and session controllers now consume the existing authenticated-identity
contract. Fourteen regressions verify contract principals, rejection of unsupported
principals, and user-ID propagation. The related 103 tests (369 assertions) pass;
scoped PHPStan is clean. This removes 21 active dependencies on the concrete security
user, leaving 355 active Deptrac violations with zero configuration errors.

Shared message encoding now owns only the bounded, versioned JSON envelope;
eight feature codecs own their payload mappings through a shared application
contract. The format remains independent of Symfony serialization. Removed the
unused framework serializer factory and its obsolete allowlist implementation.
The active codec/transport units pass 123 tests (644 assertions); real Redis delivery,
retry, outbox replay, and container wiring pass 23 tests (369 assertions). Golden
wire fixtures, strict payload fields, numeric types, and size limits remain covered.
Scoped PHPStan and container lint pass.

Library and album/artist/song/movie reads now use an explicit library scope through
application ports and repositories, including search, counts, cursors and related
metadata. Both native PGroonga queries and Doctrine queries apply access predicates
before pagination. Multiple artist roles no longer duplicate album counts/pages.
The combined production OAuth, catalog, library, artist mutation and signed-delivery
matrix passes 91 tests (876 assertions). Scoped PostgreSQL search tests pass eight
cases (77 assertions).

Image metadata, files, presets and blurhashes now require an authenticated media
scope before reading storage or generating derived files. Library grants cover
direct and reverse album/artist covers; playlist images require owner/admin access.
Immutable projections hide inaccessible owner IDs and storage paths without
changing stored associations. Responses prohibit shared caching. The combined
Media/Catalog/Genre/library-access gate passes 95 tests (1,155 assertions), including
same-token revocation and denied derived work. Hydrated library membership
revocation now binds typed UUIDs in a transaction; independent PostgreSQL observers
verify commit, regrant and rollback after a flush failure.

Genre reads now cover accessible song, album and movie associations through an
immutable read projection. Inaccessible parent identifiers are omitted and their
visible children become scoped roots; stored hierarchy data remains unchanged.
The combined firewall and existing genre-controller gate passes 111 tests (991
assertions), with four PostgreSQL hierarchy tests (44 assertions). Existing success
fixtures now create real grants and media associations; explicit denied/orphan
cases remain covered.

Playlist detail now enforces the existing owner/admin voter before song enrichment;
public/collaborative flags do not override that policy. Detail, list and edit-response
counts include only accessible songs, and denied song IDs/filesystem paths are not
returned. Immutable list projections preserve empty playlists, and scoped song
snapshots cannot expose pending managed-entity changes. Metadata edits retain hidden
stored entries and positions. The locked production-firewall, persistence and existing
functional gate passes 25 tests (197 assertions); full Unit/StaticAnalysisRules pass
4,564 tests (19,219 assertions) and PHPStan remains at zero errors. Exact scope-port
architecture rules preserve rejection of foreign internal services. Deptrac remains
at 298 active violations with 679 skipped occurrences and zero errors; no baseline
entries were added. Playlist OpenAPI now describes the actual song payload and
authorization responses, and specification/client generation is verified together.

Malformed DPoP verification keys now return an authentication rejection instead
of an uncaught server error. Deterministic leading-zero EC key fixtures exercise
valid 32-byte JWK coordinates through the production firewall. Cache initialization
also fails explicitly when its directory or exclusive lock cannot be established.
The combined unit/static-rule gate passes 4,558 tests (19,176 assertions), and the
full configured PHPStan scan reports zero errors. Deptrac reports 298 active
violations, 676 skipped occurrences and zero configuration errors; no baseline
expansion was used.

A clean Composer installation exposed 82 version differences in the existing vendor
directory. Qualification now also uses an isolated installation matching every locked
version, with all platform requirements satisfied. It found one unused promoted
token-history property, removed without a suppression. The locked full PHPStan scan
passes with zero errors and Unit/StaticAnalysisRules pass 4,558 tests (19,178 assertions).
The locked Deptrac result remains 298 active violations, 676 skipped occurrences and
zero errors. Functional runners generate disposable OAuth keys and exclude local
private keys. The locked functional suite plus Media firewall and library transaction
regressions pass 1,030 tests (8,450 assertions), including fresh migrations and a
second no-op migration run. Locked OpenAPI output matches the regenerated client
contract; web typechecking passes. CI image Composer installation now enforces the
Swoole requirement and generates test keys with mode 0600. Optimized installation
still reports 52 PSR-4 class/path warnings, a separate autoload cleanup task.

Catalog album, artist, song, movie and video public-ID schemas now describe the
actual 21-character NanoID instead of UUID. Regression checks reject the former
schemas, and the specification/client are regenerated together. Other contexts'
public-ID schemas and mixed UUID/public-ID relationship fields still need audit.

Image authorization now starts artist checks from visible album/song relationships,
avoiding the full artist scan in the earlier 5,000-row denied-catalog probe. A
reproducible fixture captures the repository's actual SQL with migration indexes
and analyzed tables; all six direct/reverse cover, playlist and denial cases avoid
active artist sequential scans. No global JIT setting or schema change was made.
Locked-dependency authorization/persistence tests pass 23 tests (600 assertions),
and scoped PHPStan reports zero errors. The fixture is a selective-library plan
regression, not a representative production capacity benchmark.

The hardware transcoding debug command now renders an Application diagnostics
report; an Infrastructure adapter owns probing, decoder selection, and sample
FFmpeg construction. A parity check kept the console output identical across
software, NVENC, QSV, VAAPI, AMF, and VideoToolbox profiles without probing a GPU.
Focused container tests pass four tests and 56 assertions, targeted PHPStan and
container wiring pass, and Deptrac falls from 224 to 213 active violations.

Scheduler administration now uses an Application port for create, update, delete,
lifecycle changes, and command catalog reads. The service validates job type,
cron, command registration, and parameter schemas before update mutation and maps
optimistic-write conflicts to an Application exception at the HTTP boundary.
The 192 Scheduler unit tests pass with 757 assertions; targeted PHPStan and
container wiring pass. Deptrac falls from 213 to 206 active violations with no
new baseline entries. Manual occurrence triggering remains on its existing port.

Notification delivery channel and identity recognition now sits behind a Shared
Application port implemented in Notification Infrastructure. The durable bus and
relay retain the original JSON message, transactional insert, lease fencing, and
transport handoff. Focused unit tests pass 26 cases and 243 assertions; isolated
PostgreSQL replay passes five cases and 58 assertions. Targeted PHPStan and
container wiring pass; Deptrac falls from 206 to 200 active violations.

A production OAuth/DPoP direct-track test now exercises anonymous, unrelated,
member, and administrator access through real song/library repositories. Its first
run exposed a Doctrine association selection that returned HTTP 500 for authorized
tracks. The repository now selects the associated library ID as a scalar; focused
PostgreSQL lookup and the 16-case firewall/range suite pass. Revoked access and
replayed proofs fail before media path resolution. Removing the invalid entity
selection also removes two Deptrac violations, leaving 198 active. The wider browser
and session qualification was delivered as item 5 (see the top of this file).

Library authorization voting now lives in Library Infrastructure and depends on the
authenticated identity contract. The voter still grants administrators, denies
ordinary principals for its supported subjects, and abstains on unrelated subjects.
The 247 focused voter tests pass with 289 assertions; targeted PHPStan, container
wiring, and service tag discovery pass. Deptrac falls from 198 to 194 active violations.

WebSocket listening-session join, playback, and sync now call a Shared Application
port implemented by a Session Messenger adapter. The adapter preserves command
inputs, synchronous handler results, falsy values, and the no-result fallback;
the controller keeps its JSON response and error mapping. Seventy focused tests
pass with 254 assertions, full PHPStan and container lint pass, and Deptrac falls
from 194 to 189 active violations without baseline changes.

Email verification failures now live in Auth Application, where the token-verification
use case constructs them. Direct and Messenger-wrapped failures retain HTTP 400 and
the same generic error without revealing token details. The focused handler and
controller suite passes 16 tests with 67 assertions; targeted PHPStan passes, and
Deptrac falls from 189 to 187 active violations.

The QoL stream-budget 503 response now belongs to a QoL exception listener that
runs before Shared's generic error logger. The exact JSON body and four-decimal
budget value remain unchanged; unrelated failures still reach the generic 500
handler. Twenty-one focused listener/value-object tests pass with 101 assertions,
targeted PHPStan passes, and Deptrac falls from 187 to 185 active violations.

## Delivery horizons

The destination is a reliable private self-hosted backend/web application, a small
strongly consistent global registry, and secondary clients whose native media paths
are qualified. The completed store refactor is one stage in that programme, not a
replacement for it. Horizons express dependencies, not promised calendar dates.

| Horizon | Deliverable | Exit condition |
| --- | --- | --- |
| Current local work | Next concrete bug, unfinished feature or named release decision selected by the rule in Resume here; rapid native scrubbing and encrypted Registry backup automation are delivered | The selected scope is implemented and verified with affected existing checks and any regression needed for its specific gap |
| Completed foundation | Web-state architecture, render/persistence budgets, optional developer tracing | Local behavior and performance regressions passed; release-wide qualification remains separate |
| Before application release | Backend/web security, asynchronous recovery, complete contracts and enforceable gates | Fresh install and critical workflows pass real integration tests; deliberately failing checks block publication |
| Before registry rollout | C++ registry and five-voter deployment qualification | Consistency, constrained capacity, partition/failover, leak and restore gates pass |
| Before secondary-client certification | Shared/mobile/desktop correctness and reproducible native media | Affected client CI and native reference-vector/browser tests pass |
| After these foundations | Replayable developer timelines, measured API expansion, sustained operational qualification | Explicit replay semantics; twenty-API topology retains five voters; recurring release gates remain enforced |

Backend/web remediation remains ahead of secondary-client enhancements. Reliable
delivery and recovery precede new workflow expansion or more ambitious autoscaling.
No milestone is complete merely because a command, schema, test, or deployment file
exists; its stated acceptance checks must have evidence.

## Completed foundation: web stores and developer tooling

- [x] Refactor web stores into cohesive modules with explicit, typed public actions.
  Use slices for substantial shared state; keep small feature stores simple.
  Preserve atomic transitions across queue, selection, and playback ownership.
- [x] Separate the playback clock from persisted state. Time-only changes must
  cause zero player-store notifications and zero persistence writes.
- [x] Write persisted projections only when durable fields change. Keep credentials,
  DOM objects, transient playback state, and action functions out of persistence.
- [x] Prevent unnecessary renders with narrow selectors, stable action identities,
  idempotent updates, and stable derived values. Verify with render-count tests.
- [x] Give session synchronization a bounded cadence under continuous playback;
  test latest-state delivery, device ownership, transport fallback, and teardown.
- [x] Refactor EQ, auth, radio, catalog, layout, notification, and visualizer stores
  and their consumers. Replace external mutation that bypasses feature invariants
  with explicit APIs; preserve authentication and native-media guarantees.
- [x] Add an opt-in store debugger. Disabled stores must retain their normal
  implementations, without tracing wrappers, subscriptions, snapshots, or timers.
  Enabling/disabling instrumentation may require reloading the application.
- [x] Provide developer UI for live state, named actions, state changes, call
  stacks, and cross-store call flows. Bound retained history and redact secrets.
- [x] Prepare a versioned, serializable timeline for future replay: ordered event
  IDs, parent-call links, immutable state captures, and explicit redaction and
  truncation metadata. Actual replay is future work; never reissue network/media
  side effects merely to inspect recorded state.
- [x] Verify unit/integration tests, render and persistence budgets, TypeScript,
  lint, production build, and disposable-browser workflows before completion.

The completed implementation split assigned the lead shared persistence, debugger
UI, integration, and this roadmap; separate workers owned player/session and other
feature stores. These are historical assignments, not work reserved for future
agents.

Verified on 2026-10-04: 1,588 web tests, 46 native audio browser tests,
11 browser authentication tests, and the HTTPS store-debugger browser workflow.
TypeScript, changed-file ESLint, and the production build pass. Render and
persistence tests cover unchanged actions and clock-only updates. Manual browser
inspection confirms the timeline and inspector UI. These are local correctness
and count-based performance gates, not full application deployment certification.
Duration formatting also supports hours and zero-pads each field (`00:09`,
`01:05`, `01:00:00`), with boundary and rounding coverage.

## Follow-up: correctness review and remaining web gates

Historical follow-up: the full web lint audit on 2026-10-04 reported 134 errors
and 14 warnings across the application, tests, and embedded player package. After
centralizing the image lifecycle, the rerun reported 130 errors and 14 warnings.
The [next checkpoint](#web-quality-gates-restored) closed this gate with zero
errors; these counts are retained as evidence of the earlier tree.

- [x] Verify canceled image requests cannot publish stale covers or allocate leaked
  blob URLs; share one loader across album cards and retain unchanged-render budgets.
- [x] Verify starting music cancels radio fallback intent even while native radio is
  paused, and late radio failures cannot take playback ownership back.
- [x] Verify shared store helpers preserve symbol-key updates and action tracing
  after reset, and oversized property names cannot bypass trace export bounds.
- [x] Rerun combined tests, typechecks, changed-file lint, and debugger browser flow.
- [x] Bring full-project web lint to zero errors before the application release.

Verification: 1,602 web tests across 138 files, 46 native audio browser tests,
11 browser authentication tests, the HTTPS debugger browser flow, TypeScript,
changed-file ESLint, and the production build pass. New regressions
failed before their fixes. Disabled debugger instrumentation remains unchanged;
retained exports are bounded, but arbitrary JavaScript object enumeration is not
a bounded-time operation.

## Web quality gates restored

Full-project web lint now passes with zero errors. Seven warnings remain: four
React Compiler notices for TanStack virtualizers and three notices in generated
coverage assets. No lint rules, discovery paths, or baselines were weakened.

Corrections include typed API contracts and player inference outputs, isolated
component exports for hot reload, request/form lifetime ownership, and a PEQ graph
that preserves presets and responds to resizing. Playlist creation uses the actual
resource response. Named inference outputs are supported and unused tensors disposed.
The embedded player now has blocking typecheck and test steps in frontend CI.
New code follows the project's manual formatting style; Prettier is not introduced.

Validation: 1,618 web tests, 159 embedded-player tests, three performance tests,
58 browser checks, web and player typechecks, and the production build pass.
The player install was verified with the pinned Yarn version and immutable lockfile.
The broader backend,
security, native-media, and registry release gates remain separate milestones.

## Backend authorization and publication follow-up

EQ profile reads, updates, deletes, and activation now enforce ownership through
the application port. Foreign, missing, and malformed IDs return 404, including for
unrelated administrators. Owner deletion of a default profile remains 422. The old
cross-user read returned 200 in the regression reproduction; the corrected disposable
functional suite passes 33 tests with 133 assertions. It uses Symfony's firewall with
a test authenticator, so production OAuth/DPoP acceptance remains separate.

The application publisher now requires embedded-player, browser, and pinned DSP
qualification as well as backend and web checks. Application/frontend/DSP checkouts
verify the event SHA instead of fetching a moving branch. Local workflow regressions
exercise failing qualification commands, branch advancement, and missing/mismatched
commits. Live Forgejo scheduling and standalone CLI/base-image publication policies
remain to be verified.

Backend checks run in a PHP 8.5.2 container satisfying the Composer lockfile's
extension requirements, including Swoole 6.2.0. The host PHP runtime is unsuitable.
The PHPStan audit completed with 1,708 diagnostics (1,058 source and 650 test diagnostics),
without runtime/bootstrap errors. Concrete undefined methods and missing classes
precede annotation cleanup. Deptrac reports 259 violations; ten obsolete baseline
exclusions were removed. Neither baseline was expanded. These failures still block
application release; passing unit tests does not resolve them. The four annotation
diagnostics in the touched functional test were subsequently corrected.

Final combined backend unit verification passes 4,386 tests with 18,452 assertions,
including 14 publication regressions and 11 EQ adapter tests. Deptrac verification
confirms zero stale-exclusion errors after cleanup and 259 remaining violations.

## Runtime defects found by static analysis

- Genre resources now serialize their parent UUID directly. Root, child, and
  reconstituted hierarchy regressions cover the existing response contract.
- Metadata-match responses now map the actual candidate data instead of calling
  nonexistent model getters. Tests use the real tag reader and matching strategy,
  covering candidate identity, missing fields, ranking, and filtering. Optional
  source identifiers are nullable in the specification and generated client.
- Party WebSocket join, leave, play, pause, and seek failures now read Messenger's
  original exception through its standard previous-exception chain. Regressions
  reproduce the old fatal error and verify error delivery plus a usable connection.

All three defects were reproduced before their fixes. Combined backend unit
verification passes 4,397 tests with 18,509 assertions in the qualified PHP runtime.
PHPStan now reports 1,693 diagnostics, down from 1,708, with no new diagnostics in
the changed files. These are bounded runtime repairs; the remaining PHPStan and
architecture findings still require remediation. The party-sync command mismatch was carried into the next checkpoint below.
Deptrac remains at 259
violations with zero configuration errors. OpenAPI and client regeneration are
limited to the two optional candidate identifiers; the web typecheck passes.

## Party synchronization repair

Party WebSocket synchronization now supplies the authenticated connection user to
the command and reads the handled numeric position from Messenger's envelope.
Payload user IDs cannot substitute another member. Invalid/nonfinite positions,
invalid latency, missing handler results, and handler denial produce an error without
session data; a rejected message leaves the connection usable.

The shared playback synchronizer checks membership before reading the session or
updating drift. Non-members are denied, while member jitter/EMA behavior is retained.
Regression tests exercise real Messenger dispatch/result middleware and the shared
synchronizer/handler boundary. Full HTTP OAuth/DPoP and real WebSocket network
acceptance remain separate from these in-process tests.

Verification: 4,411 backend unit tests with 18,575 assertions pass in the qualified
PHP runtime. The final handler-test mock cleanup also passes the 115-test Party
suite. PHPStan reports 1,690 diagnostics, down from 1,693, with no new diagnostics
in the changed files. Deptrac remains at 259 violations and zero configuration
errors; both release gates remain blocked.

## Worker monitoring and manual retry repair

Worker job monitoring now uses the receiving worker event's queue name. Manual
retry uses Messenger's transport-selection stamp, assigns a fresh job ID before
sending, and records the authenticated administrator in its audit entry. The
response, queued message, and audit record share that new ID. Both Symfony workers
and the Swoole task-monitor decorator preserve it through success or failure. Dispatch failure
leaves the original job unmarked so it can be retried later.

Tests exercise real Messenger sender routing and worker events with the independent
JSON message codec. This repairs runtime behavior without claiming atomic retry
claims: concurrent administrative retries and send-success/audit-failure recovery
still need an explicit durable transaction/delivery design.

Verification: 4,424 backend unit tests with 18,758 assertions pass in the qualified
PHP runtime. The final explicit stamp-check cleanup also passes all five Swoole
transport tests. PHPStan reports 1,683 diagnostics, down from 1,690; the initial
changed production and regression files have no diagnostics. Final scoped analysis
also passes for the Swoole decorator and transport tests. Production container
warmup passes without wiring errors; external Redis availability was not tested in
the no-network container. Deptrac remains at 259 violations.

## PHPStan remediation completed

The full configured level-6 scan of `src/` and `tests/` now passes with zero
errors. The sustained cleanup resolved the 1,683 reported diagnostics and the
18 EQ-profile diagnostics previously hidden in `phpstan-baseline.neon`; that
baseline and its configuration include have been removed. No new suppressions
or baseline entries were added. CI and Makefile analysis use a 1 GiB budget after
512 MiB full scans intermittently exhausted memory. Deployment budgets are unchanged.

Runtime fixes include secure public/token ID generation, UUID timestamp semantics,
OAuth identity reconstruction, Messenger result propagation, OpenTelemetry span
attributes, service and identity contracts, metadata response serialization,
immutable persistence dates, transcode process handling, lyrics pagination, and
reference-compatible BlurHash encoding. Native inotify exhaustion now leaves the
watcher retryable. Regression tests verify the changed behavior; accurate collection,
payload, and test-double contracts account for the remaining annotation work.

The combined Unit and StaticAnalysisRules suites pass 4,520 tests with 19,001
assertions in the qualified PHP 8.5 container, with notices and skips treated as
failures. Container wiring also passes. The final disposable functional batch
passes 92 tests with 911 assertions against fresh PostgreSQL migrations; it uses
the real firewall with TestAuthenticator and does not replace full OAuth/DPoP
qualification. Focused transport and runtime gates are recorded in the preceding
checkpoints. This completes PHPStan remediation, not the remaining integration,
Deptrac, deployment, or registry acceptance work.

The Deptrac comparison against the cleanup's starting commit has no new violation
pairs: 257 violations remain, down from 259, with zero configuration/baseline
errors after removing four obsolete entries. The public authenticated-identity
contract has an exact layer allowance for its consuming controllers; Auth internals
remain isolated. Catalog's playlist-impact helper now accepts only song UUIDs.
At that checkpoint Lyrics Application was missing from Deptrac's collectors; the
subsequent coverage audit recorded above has closed that enforcement gap.

## Production authentication and contract drift gates

DPoP validation now preserves the actual HTTP/HTTPS scheme. Signed unit tests and
an isolated production-firewall test reproduced HTTPS proofs being accepted for
HTTP requests before the fix. The corrected OAuth security suite passes 85 tests
with 426 assertions; the production firewall passes seven cases with 100 assertions
using real RS256 access tokens, ES256 proofs, PostgreSQL records, and Redis replay
storage. This does not yet qualify the complete signed-delivery or browser matrix.

OpenAPI export now offers a non-writing `--check` gate and reports write failures
instead of returning success. The current 228 paths match runtime routing; tests
check both missing operations and documented operations without routes. Configuration
map defaults are JSON objects, and the three changed request schemas and generated
web client are regenerated together. Backend publication requires the specification
check; frontend publication requires regenerated-client parity. Failure-injection
workflow tests verify both checks prevent publication from proceeding. Export/workflow
tests pass 26 cases with 161 assertions; schema tests pass 20 cases with 169 assertions,
and the route-coverage test adds 1,164 assertions. Web typechecking and both drift
checks pass. The full configured PHPStan scan remains clean.

The full disposable functional suite passes 987 tests with 7,688 assertions after
replacing eight passive mocks with stubs. Fresh migrations and a second no-op
migration run pass; PHPUnit notices and skipped tests fail the runner.

## Application boundary remediation

Party playback synchronization now uses an application port. Activity, Library,
and Party session controllers consume the authenticated-identity contract rather
than a concrete security adapter. Owner/admin/member regressions and container
wiring pass. Review also found Library creation saving a record before rejecting an
unsupported principal; identity and UUID validation now precede every lookup/save.
Four regressions reproduced that unauthorized side effect before the fix.

Deptrac is down to 240 active violations from 257, with no added violations or stale
baseline entries. The initial combined Party/Activity/Library suite passes 295 tests
with 917 assertions; the final Library suite passes 138 tests with 301 assertions.
Scoped PHPStan remains clean. Existing architecture debt and uncovered contexts are
still release work; no suppressions or baseline entries were added.

## First-party license alignment

The root license, first-party package manifests, and current project/package
license summaries now declare Apache-2.0. The Composer lock metadata for the local
PHPStan package was regenerated without dependency version changes. Swoole Bundle's
MIT license and the TSDuck binding's BSD-2-Clause declaration remain intact;
`THIRD_PARTY.md` records those boundaries. Registry files are being handled with its
replacement. A complete packaged transitive-dependency inventory and redistribution
check remain release work; this metadata alignment does not certify those artifacts.

## Recent verified checkpoints

| Commit | Result | Verification |
| --- | --- | --- |
| `9dfd2292` | Ignore stale native play/ended notifications | 1,520 web tests; 44 browser tests; typecheck, scoped lint, build |
| `cb18f7cb` | Bind deferred resume results to playback generation | 1,528 web tests; 45 browser tests; typecheck, scoped lint, build |
| `784c8cc7` | Stop owned media errors, cancel handoffs/fades, allow reselection | 1,534 web tests; 46 browser tests; typecheck, scoped lint, build |

The media-error browser case forces an active source reload to a local 404 and
observes a real Chromium MediaError. It does not certify midstream transport
recovery. Earlier player work includes native source ownership, activity per
playback iteration, stereo metering/phase, and normalization. Complete native
codec/DSP qualification remains a separate gate.

## Original remediation programme

This is the historical scope of the original programme. Later delivery records
close much of it; this list is not an active checklist or a request to repeat its
audits. Open requirements are tracked in the scoped backlog below and selected
through [Resume here](#resume-here).

1. **Quality gates:** separate unit/browser discovery; correct fixtures and runtime
   requirements; shared typechecks; actual Vite output checks; isolated CI services;
   blocking PHPUnit, PHPStan, Deptrac, lint, typechecks, and frontend tests. Fix
   defects instead of expanding suppressions. Prove failed tests block releases.
2. **Authentication and authorization:** real-firewall DPoP/audience checks;
   signature-only delivery exemptions; library-scoped reads and streaming;
   owner/admin session control; administrator catalog mutation and cleanup;
   authorized signing with a maximum/default 24-hour lifetime; authenticated pairing.
3. **Tokens and browser credentials:** atomic refresh rotation, replay detection,
   real-database concurrency/rollback tests, bounded retries and nonce handling,
   API-origin credential scoping, requesting-client ownership, and worker lifecycle.
4. **Async work:** two deployment entrypoints (web server and worker supervisor);
   supervised queue consumers and scaling; readiness and queue age; durable outbox
   claims/recovery, correct routing, poison-message handling, stable event IDs,
   idempotent consumers, and transactional domain/outbox writes. Backend message
   formats must be independent of Symfony serialization. No exactly-once claim.
5. **Storage, webhooks, and contracts:** root/symlink boundaries on every operation;
   safe DNS/destination validation and redirects; operator LAN allowlist; documented
   encrypted-secret signing; configuration validation; working cover extraction and
   key rotation/recovery; JSON seeking; generated OpenAPI/client drift checks.
6. **Operations and clients:** fresh PostgreSQL install/upgrade and restore tests;
   correct image/startup/health configuration; shared/mobile/desktop checks;
   Electron origin/IPC validation; reproducible native media builds/reference tests;
   Apache-2.0 first-party licensing while retaining third-party licenses.

Use the PostgreSQL specialist and remediation skill for Doctrine/PostgreSQL work,
including the project's extensions and PGroonga 2+. Follow the PostgreSQL
“Don't Do This” guidance. There are no production deployments: prefer the intended
design over compatibility layers. The temporary AGENTS.md policy remains until
the user explicitly announces the first production release.

## C++ registry

The separate ThreadSanitizer gate now passes the full `thread` runner in a Debian
trixie container with Clang 19, its matching sanitizer runtime and pinned static
OpenSSL 3.5.3, under default Docker restrictions. Seventeen CTest tests, cleanup,
SQL/TLS transport, concurrent HTTP ownership/shutdown, and three-voter partition,
crash and rejoin contracts pass. A deliberate sustained race must produce an
actual TSan warning and exit 66; missed detection and runtime failure fail the
gate. Concurrent producers submit to the single event loop; this does not assert
that the pool supports direct calls from multiple threads. The separate CI job
blocks publication; 20 workflow regressions pass. Third-party instrumentation,
optimized resource limits, longer soak and regional qualification remain distinct.

Production container qualification now builds pinned C++/rqlite images and checks
five TLS voters, nonroot read-only API operation, acknowledged registration across
rolling restarts, explicit replacement joining, and graceful shutdown. Startup
rejects empty restarts, wrong identities, reduced bootstrap counts and manual peer
recovery files. A regression reproduced incomplete bootstrap forming a competing
single-voter cluster; the pinned native restart guard now rejects that state and
preserves all five persisted voters on normal restart. The required container CI
job passes through `scripts/test-registry-container.sh`. Container limits are
containment settings, not evidence that the whole-host resource gates pass.

The C++ core and bounded TLS HTTP server are implemented and committed. Local
qualification passes 16 sanitizer unit tests, real authenticated TLS rqlite API
checks, same-pool reconnect/deadline checks, concurrent ownership claims, readiness
schema recovery, and bounded graceful shutdown during a commit. Registration
credentials are never returned or logged. Real three- and five-voter authenticated
TLS clusters now pass 2–1/3–2 partitions, minority rejection, majority commits,
leader recovery within 30 seconds, abrupt one/two-voter failures and rejoin without
losing acknowledged metadata or revisions. These local tests do not qualify
regional latency, constrained capacity, soak or backup restoration. The C++
container deployment path now replaces the obsolete PHP registry source/config;
local SQLite files and existing volumes remain untouched.
The live contract also stops a majority including the leader: two of three and
three of five voters. Running APIs reject authoritative lookup, registration, and
readiness checks with 503 and `Retry-After: 1` while liveness stays healthy. Both
clusters recover persisted acknowledged records and revisions within 30 seconds
after voter restart. The three-node release gate and explicit three/five-node
ThreadSanitizer runs pass; regional failure and capacity gates remain open.
An authenticated binary backup now restores into separate fresh voter storage
with a new CA before the API starts. The fixture checks SQLite integrity,
schema checksum, credential digests, ownership, saved revisions, an absent
post-backup sentinel, and convergence on each voter. The release recipe's
three-voter run and an explicit five-voter restore pass. Scheduled encrypted
and versioned S3 backups, their destination restore, five-minute RPO, 30-minute
RTO, and resource-constrained backup operation remain unqualified.
Clean release and ASan/LeakSanitizer/UBSan builds now pass through
`scripts/test-registry.sh`, which verifies downloaded rqlite/OpenSSL checksums and
isolates OpenSSL dependency discovery. Both CI matrix variants are required before
publication. Twenty workflow tests verify event-commit checkouts and failure
propagation, including all registry modes and the container qualification job.
The registry CI gate now runs Clang 19 analysis of production C++ and checks
first-party SPDX declarations plus the five pinned direct dependency notices
against upstream. Its first-party diagnostic controls fail as expected. A
transitive dependency/license inventory and the constrained five-host,
fixed-runner regression, and long-soak gates remain outstanding.

The cluster tests exposed rqlite's HTTP-200 leadership-loss response and an API
deadline that could expire before a database failure became a 503 response.
The pool now evicts failed connections for the next independent request without
replaying uncertain writes. Database work shares the remaining API deadline with
a bounded response reserve. Failure-injection tests verify test-harness process and
listener cleanup even when readiness or teardown fails. The blocking CI recipe now
also runs the three-voter partition/failover gate.

The public HTTP contract now forces a real committed registration to lose its
response after rqlite acknowledges the write. The API returns 503 and
`Retry-After: 1` without replaying or exposing credentials; a database read sees
revision one, the same owner retries to revision two without enrollment, and an
enrolled wrong owner remains forbidden. Fresh optimized release and
ASan/LeakSanitizer/UBSan gates pass all 31 CTests and real-rqlite contracts. This
qualifies local uncertain-commit recovery, not regional partitions or host limits.

First-claim registration now requires an operator-issued enrollment capability
valid for at most five minutes and bound to the public ID and credential digest.
Matching owners can retry and heartbeat without it. The single rqlite transaction
handles competing claims
without transferring ownership; anonymous and wrong-owner attempts fail with 403.
Native HTTP, three/five-voter, restore, and production-container contracts pass
with enrollment enabled. The capability issuer reads a private key file and
prints only the token. API deployments must share the key until a future
asymmetric enrollment design replaces that operational trust boundary.
The sanitizer-backed parser fuzz gate passed its bounded run and its deliberate
ASan failure control after the enrollment parser was added to the fuzz target.
The separate ThreadSanitizer qualification also passed with enrollment enabled.

The optimized release recipe now runs a bounded three-voter, three-API load smoke:
100 registrations, 20 lookups/s and 100/60 heartbeats/s for ten seconds, then
fivefold rates for ten seconds. It fails on request errors, backlog, missed pacing,
normal p95/p99 above one/two seconds, burst p99 above three seconds, or sampled API
RSS above 64 MiB. One local run passed with normal lookup p99 4.34 ms, burst
lookup p99 4.09 ms, and peak sampled API RSS 11.89 MiB. This is not the agreed
five-host resource, global-network, five-minute burst, or fixed-runner regression
qualification; those gates remain open.

A harmless EICAR repository canary is detected by ClamAV locally and excluded
from deployment images. External scanner visibility depends on publication; it
has not been demonstrated for a public Baander endpoint.

The public routes are `POST /api/servers/register`,
`GET /api/servers/{publicId}`, `GET /health`, and `GET /ready`. Health reports
process liveness; readiness requires recent successful authoritative database
access and a compatible schema. Lookup uses an explicit linearizable read;
registration is acknowledged only after its rqlite commit. SQLite access belongs
exclusively to rqlite. Keep sockets, timers, buffers, and requests RAII-managed;
do not introduce detached threads, unbounded queues, or custom JSON/crypto code.
The database/admin endpoints stay private behind authenticated TLS.

The delivered C++20 implementation uses Beast/Asio, OpenSSL, nlohmann/json,
pinned dependencies, CMake/Ninja, GoogleTest and bounded asynchronous rqlite pools.
The PHP registry is replaced; Symfony serialization, Redis, Sentinel and Electric
are absent. Local qualification is recorded above and in the stale-follower and
disk-exhaustion checkpoints below. The single reconciled
checklist is [Registry release and growth](#registry-release-and-growth); its
deferred release prerequisites retain the deployment, backup and resource
requirements without reopening covered behavior.

Deployment names are configurable: currently `de`, `ca`, `sg`, `au`, `fi`, using
`<region>.registry.baander.app`. Regions may change. Target three to five online
hosts, with one/two-host critical/emergency visibility; a five-voter cluster still
requires three voters for authoritative operations. One or two surviving voters
must fail closed. Expansion to twenty API deployments does not expand the voter set.
Host access credentials and backup destination still need verified availability;
region/domain choices alone do not supply deployment access.

Acceptance budget: each whole host gets 1 CPU and 512 MiB; total used memory must
stay at or below 448 MiB, API peak RSS at or below 64 MiB, and normal busiest-host
CPU at or below 70%. Load: 100 servers, 60-second staggered heartbeats, 20 lookups/s;
fivefold bursts for five minutes. Normal p95/p99 must be <=1s/2s; burst p99 <=3s.
Run the load generator outside the host budgets; require no swap, OOM, or unbounded
backlog during the burst. Test 200ms inter-node RTT, 25ms jitter, 0.1% loss, <=30s
leader-loss recovery, and no acknowledged registration loss after any two voter
failures. Failed gates block release, without weakening consistency or increasing
the agreed host budget.

### Registry encrypted backups (2026-10-19)

The opt-in backup worker captures authenticated rqlite binary snapshots at startup
and every **300 seconds**, encrypts them with a public age recipient and uploads
completed ciphertext under unique timestamped S3-compatible object keys. Each
attempt has a **240-second** default deadline; attempts do not overlap or queue
missed ticks. Plaintext passes through pipes; only ciphertext is spooled.
The private recovery identity remains separately held and absent from the worker.

Atomic status retains the last successful capture time, object key and ciphertext
hash across failed attempts. A separate health invocation checks capture age for
an external monitor. The non-root, read-only container, opt-in Compose service,
secret examples and offline recovery procedure are documented in
[the Registry guide](relay/README.md). Its **512 MiB** container limit does not
qualify the agreed whole-host deployment budget. Implementation follows the
[reviewed plan](docs/plans/2026-10-19-registry-encrypted-backups-plan.md).

Verification:

- The final disposable backup contract passes in **22.779 seconds**, including
  cached image builds and cleanup. All **nine core tests** pass in **0.998 seconds**.
  The packaged worker creates **two** encrypted objects through authenticated TLS
  S3 storage and verifies their ciphertext hashes. Offline recovery rejects a
  wrong identity and tampered ciphertext, then decrypts a stored object and reuses
  the existing fresh **three-voter** restore contract. Ownership, credential
  digests, saved revisions, migration checksum, absent post-snapshot sentinel and
  voter convergence pass. The original binary restore path also passes.
  Evidence: `var/test-runs/registry-encrypted-backups-20261019/backup-contract-final-result.json`
  and its retained log.
- The core tests also pass in the CI image: **nine tests / 1.200 seconds**.
  Packaged non-root read-only startup, missing-configuration refusal and Compose
  validation pass. The dependency gate verifies **seven** pinned direct notices.
  Python parsing for **four files**, shell syntax, workflow YAML and whitespace
  checks pass.
- Simplification covered reuse, quality and efficiency at each phase. Two small
  core reuse/header-decoding changes were applied; the final integration phase
  required no changes. Its core rerun passes **nine tests / 1.190 seconds**.
- Independent code review completed **eight** lenses with no findings and a
  **Ready to merge** verdict. The receipt is retained at
  `var/test-runs/registry-encrypted-backups-20261019/review.json`. It records
  nonblocking coverage limits without adding further qualification tasks.

This delivers encrypted, versioned five-minute backup automation and local offline
recovery. Actual-destination credentials, regional networks, five-minute RPO,
30-minute RTO, constrained whole-host backup operation and the 24-hour soak retain
their deferred acceptance requirements. The backend and broad C++ suites were not
rerun; C++ source is unchanged and the contract reuses the current built images.

### Stale follower verification (2026-10-19)

The three- and five-voter partition contracts now prove divergent stored rows
before checking public discovery. Every isolated minority voter retains an
existing registration's old row and lacks a new identity after majority commits.
Both minority lookups return 503 with `Retry-After: 1` and a fixed error envelope
before a failed readiness probe can clear API initialization. Majority-capable
APIs return current public results. After healing, every voter's metadata,
revisions, timestamps and credential digests match the saved acknowledged rows;
the formerly isolated API becomes ready and returns both current records.

The native release recipe passed in **295.881 seconds** in a disposable Debian
trixie container with Clang **19.1.7**, pinned static OpenSSL **3.5.3** and rqlite
**10.5.1**. All **32 CTests** passed in **0.31 seconds**, and all **three cleanup
regressions** passed in **0.026 seconds**. Authenticated SQL/TLS, public HTTP,
three-voter partition/crash/quorum-loss, fresh binary restore and bounded load-smoke
contracts passed. The separate **five-voter contract** passed in **14.327 seconds**,
including stale-row rejection, two-voter crash/rejoin and three-of-five quorum loss.
The smoke recorded normal/burst lookup p99 of **14.28/14.23 ms** and sampled API
peak RSS of **11.55 MiB**; whole-host budgets and fixed-runner regression remain open.

An isolated source copy weakened only public lookup consistency to `none` and
rebuilt the executable. The three-voter contract then failed at the new stale
minority lookup assertion: it received HTTP 200 before readiness was probed.
The expected failure exited **1**; the qualification wrapper accepted that result.
Production C++ was unchanged. Python syntax and whitespace checks pass, and the
three-persona simplification review returned no findings. No verified sanitizer
build was available; this Python/documentation change did not rerun the C++
sanitizers, analyzer, TSan, fuzz, PHP or UI gates. Two setup failures occurred before
testing, involving SELinux access to the temporary script and archive ownership
restoration under dropped capabilities. Both were corrected before these timings.
The disposable qualification container ran as a nonroot user with capabilities
dropped and was removed afterwards. Independent plan and code reviews returned no
findings; prose preservation audits and whitespace checks pass.

Evidence: `/tmp/baander-registry-stale-20261019/`, including `qualification.log`,
`timing.json`, `negative-control.log`, `negative-control.json`, `tool-versions.txt`,
`tooling-image.txt` and `syntax-check.txt`. Full-disk qualification was still open
at this checkpoint and is delivered below. The remaining release prerequisites
are consolidated under [Registry release and growth](#registry-release-and-growth).
The historical suggestion of further certificate injection does not assign work;
existing invalid-TLS coverage remains valid.

### Registry disk exhaustion qualification (2026-10-19)

The native full-disk contract puts all three voter data directories on one private
Docker tmpfs capped at **128 MiB**. It rejects missing, ordinary, oversized,
symlinked or nonempty storage roots before allocation. Real filler writes must
reach `ENOSPC`; valid public registrations then continue until a 503 is tied to a
new voter storage diagnostic, within **2,048 requests or 120 seconds**. The API
must remain live and return `Retry-After: 1` with its fixed error envelope.

Every successful registration enters an acknowledgement ledger derived from the
submitted metadata and returned revision and timestamps. After filler removal,
the same voter directories restart; all three voters must reproduce every ledger
row exactly, including credential digests, and the API must regain authority
within **30 seconds**. The original credential retries the unavailable identity,
whether it committed before restart or not, and a fresh identity must register
and converge. Logs and the ledger stay outside the exhausted mount; cleanup
removes only the fixture-owned child and closes the remaining resources even
when another cleanup action fails.

Release and ASan/LSan/UBSan recipes require `REGISTRY_FULL_DISK_ROOT` and the
validated mount. Missing prerequisites and contract failures fail their required
CI matrix variants and block publication. The local runner instructions describe
the same Docker tmpfs setup.

Verification checkpoint:

- The complete optimized release recipe passed in **304.941 seconds** in a
  disposable Debian trixie container with Clang **19.1.7**, Python **3.13.5**,
  pinned static OpenSSL **3.5.3** and rqlite **10.5.1**. All **32 CTests** passed
  in **0.30 seconds**, the **three existing cleanup regressions** in **0.026
  seconds**, and the **13 storage ownership/oracle regressions** in **0.013
  seconds**. Authenticated SQL/TLS, public HTTP, three-voter
  partition/crash/quorum-loss, fresh binary restore and bounded load-smoke
  contracts passed. The smoke recorded normal/burst lookup p99 of **14.17/13.95
  ms** and sampled API peak RSS of **11.42 MiB**.
- Release exhausted the **134,217,728-byte** mount with **133,664,768 bytes** of
  filler and **zero available bytes**. The first pressure request returned 503
  after **0.003321 seconds**, with voter `ENOSPC` evidence. After filler removal,
  authority and exact acknowledged-row recovery returned in **1.794137 seconds**.
  The unavailable identity was absent after restart; its original credential
  retried successfully. All **five final ledger rows** converged across the
  voters and public API, including the fresh post-recovery registration.
- The complete ASan/LSan/UBSan recipe passed in **268.947 seconds** with the
  same toolchain. All **32 CTests** passed in **0.74 seconds**, the **three
  existing cleanup regressions** in **0.027 seconds**, and the strengthened
  **14 storage regressions** in **0.014 seconds**. The authenticated transport,
  public HTTP, three-voter failure/recovery and fresh binary restore contracts
  also passed. Storage qualification again reached voter `ENOSPC` with the same
  filler allocation and zero available bytes. Its first pressure request returned
  503 after **0.007575 seconds**; recovery took **1.308930 seconds**, with all
  **five final ledger rows** converged. The unavailable identity was absent before
  its successful original-credential retry.
- In the CI image, publication workflow regressions passed **37 tests / 274
  assertions** in **1.318 seconds** of PHPUnit execution (**11.6 seconds** in the
  ordinary lane). This adds **11 cases**; the first **seven** new cases failed
  before gate integration. Artifacts: `var/test-runs/20261019-185645-c05adc6d`;
  red-run log: `/tmp/baander-disk-workflow-red.log`.
- Shell syntax, the workflow test's PHP syntax, syntax parsing of all **three**
  changed Python files and whitespace checks pass. Log:
  `/tmp/baander-registry-disk-20261019/static-checks.log`.
- Final container inspection found the owned storage removed and no fixture
  children remaining. The disposable container and tooling image tag were
  removed; existing services and data were untouched.
- Three-persona simplification reviews completed for both implementation units
  without changes. Independent code review found no production defects and
  identified one coverage gap: direct cluster cleanup failure aggregation. The
  added regression verifies remaining teardown actions run after a cleanup
  failure. The strengthened storage selection passed **14 tests in 0.014
  seconds** in the isolated native container. This test-only addition follows
  the release run's 13-test snapshot. Log:
  `/tmp/baander-registry-disk-20261019/final-storage-tests.log`; review receipt:
  `/tmp/compound-engineering-1000/ce-code-review/20261019-185753-d9ef317f`.

Evidence: `/tmp/baander-registry-disk-20261019/`, including `release.log`,
`sanitize.log`, `qualification-results.json`, `tool-versions.txt`,
`final-storage-tests.log` and `static-checks.log`.

The observed qualification covers bounded voter filesystem exhaustion. It does
not establish SQLite `SQLITE_FULL` or power-loss durability. Those are evidence
limits, not additional default tasks. Whole-host capacity, constrained
snapshots/compaction, regional networks, S3 destination restoration, fixed-runner
performance, transitive dependency/license inventory and the 24-hour soak retain
the prerequisites and scope recorded under
[Registry release and growth](#registry-release-and-growth).

## Long-horizon acceptance backlog

This section retains delivered scope, deferred features and release prerequisites.
It is not an ordered work queue. Dated delivery records retain their original
findings and limits; reconcile them with later records before selecting work.
Only [Resume here](#resume-here) sets the current priority.

### Application security and reliability

- [x] Exercise anonymous, owner, unrelated user, library member, administrator,
  and revoked-token cases through the real firewall. Include missing proofs,
  wrong keys, replayed proofs, audience variations, expired signatures, and
  cross-library requests. Cover master/rendition/DASH/subtitle manifests and segments.
  Delivered as item 7 on 2026-10-14: `SignedDeliveryFirewallTest` (77 cases) with the
  route-inventory guard; `HEAD` requests and the audio, catalog and image suites stay
  out of its scope.
- [x] Check every catalog mutation and library-scoped query, including search,
  counts, and related resources. Eliminate fallback administrator identities.
  Signing accepts only authorized media paths and valid bounded durations.
  Delivered as item 8 on 2026-10-14: `CatalogMutationFirewallTest` (130 cases),
  `PlaylistMutationFirewallTest`, the read and delivery rows and the route inventory
  guard; the audit found no fallback identity and made the one unscoped default
  explicit; `HEAD` requests and the audio, image and user-administration suites stay
  out of its scope.
- [x] Verify concurrent refresh against PostgreSQL: one usable replacement,
  rollback recoverability on persistence/signing failure, and replay detection.
  Exercise expiry, logout, multiple tabs, concurrent requests, service-worker
  restart, nonce challenges, and foreign-origin credential exclusion together.
  Bound retry attempts for both initiating and queued Axios requests. Bind each
  signing request to its requesting browser client, and close MessageChannels and
  timers on success, timeout, cancellation, logout, and service-worker restart.
  Delivered as item 5: `RefreshTokenGraceTest`, `RefreshTokenConcurrencyTest` and
  `DpopStoreOutageTest` on real PostgreSQL and Redis, `LogoutTest`, the eighteen
  browser-auth Chromium scenarios and the web runtime drill's Redis outage.
- [x] Verify actual Messenger middleware/Redis delivery when Swoole dispatch fails,
  concrete outbox consumers without replay re-enqueue, expiring leases across
  independent PostgreSQL connections, poison-event retry/dead-letter handling, and
  idempotent durable notification effects. External delivery remains at-least-once;
  uncertain outcomes are not represented as exactly-once.
- [x] Exercise Messenger/Redis fallback with the Swoole extension unavailable.
  The [runtime checkpoint](#messenger-without-swoole) covers console boot, real Redis
  delivery, retry/dead-letter handling, handler registration and nested PHP absence.
  The web server still requires Swoole.
- [x] Finish supervised crash tests at the consumer acknowledgement boundary.
  Claim, mid-projection transaction, and Redis-send-before-delivery-ack crashes
  verify unchanged leases, rollback where needed, natural expiry, and recovery
  through the current worker runtime. The accepted-but-unacknowledged send is
  replayed. A consumer killed after handler return but before Redis XACK preserves
  its committed receipt and run count when the exact pending entry is redelivered.
  External delivery remains at-least-once.
- [x] Test storage directory-boundary comparisons, validation before mkdir,
  traversal and symlink escapes across read/write/delete/derived-file resolution.
- [x] Test webhook DNS and connection-time destination checks, corrected link-local
  ranges, disabled redirects, empty-by-default LAN allowlist, category validation,
  and original-secret signature verification. Since there is no production install,
  remove obsolete legacy formats rather than inventing compatibility obligations.
- [x] Complete bounded cover extraction through the durable async transport and
  an explicit JSON message contract. A functional test verifies request dispatch
  only queues the batch, then worker receipt fans out extraction jobs.
- [x] Stage and validate private OAuth key bundles before offline token
  invalidation; document cutover and recovery. Verify JSON position/action
  validation. The bundle/rotation integration checks pass on disposable PostgreSQL.
  The seek broker retains the latest pending control signal per job under bursts.
  Track streams use the current media read scope on every request; a real-firewall
  test covers anonymous, unrelated, member, revoked member, and administrator access.
- [x] Reconcile documented API routes, identifier schemas, the generated web client
  and the embedded creation-response consumer. The historical missing-route work
  already delivered bidirectional coverage and blocking drift checks; the
  [current checkpoint](#api-contract-reconciliation) records the remaining
  semantic corrections and verifies 244 paths / 313 operations. Hand-written
  React Native and Android clients remain deferred by the user's instruction.
- [x] Eliminate the configured PHPStan diagnostics, including the old baseline,
  without new suppressions; verify the full scan and combined unit/rule suites.
- [x] Eliminate remaining Deptrac boundary violations using application ports
  (0 active violations on 2026-10-07, baseline not expanded).
  No blanket suppressions or inflated baselines. Verify the
  correct Symfony/Vite artifacts, Composer extensions, isolated CI networks,
  matching Redis credentials, and explicitly failing readiness timeouts. Use the
  current Deptrac count at the top of this document; lower counts recorded in older
  batches are historical measurements. League exchange tests reject missing and
  incorrect PKCE verifiers from codes issued by the controller.
- [x] Build a truthful application and registry threat model, then run focused
  penetration-defense regressions for credential leakage, replay, cross-tenant
  access, malformed HTTP/JSON, rate-limit abuse, SSRF, TLS failures, and quorum
  downgrade. Record observed findings and remediation. The EICAR scanner canary
  is only a scanner check, not evidence of penetration resistance.

### Registry threat-model delivery (2026-10-15)

The [registry threat model](docs-book/part-2-developer-guide/registry-threat-model.md)
records the standalone C++ service's assets, credential ownership, replay,
parsing, egress, TLS and quorum boundaries. The registry has no application tenant
model; public lookup is intentional and credential ownership controls mutation.

- A pinned-parser reproduction accepted a valid JSON object followed by a raw NUL,
  including another JSON value after that byte. The shared parser now rejects raw
  NUL before DOM parsing, retaining size-limit precedence and valid escaped
  Unicode. Native tests cover embedded/trailing NUL, hidden suffixes and the
  positive controls. This also protects configuration and database response parsing.
- The HTTP contract adds eleven malformed JSON byte/type cases, checks unchanged
  public metadata/revision and fresh liveness, and checks responses, headers and
  captured logs for actual credential, digest, enrollment and database secret markers.
- Replaying the original owner body and enrollment token after a newer update
  restores old metadata with an advancing revision. This regression documents
  accepted replay behavior; it does not claim freshness or replay prevention.
- Two API instances now exercise distinct-owner takeover refusal despite valid
  enrollment, full stored-row invariance, capability identity binding and the
  legitimate owner's retry through the second API without enrollment.

Verification uses the native CI recipes in a disposable Debian trixie container
with Clang 19.1.7, pinned OpenSSL 3.5.3 and rqlite 10.5.1. The optimized release gate
passed in 292.4 seconds: 32 CTests, three cleanup tests, authenticated SQL/TLS,
HTTP, three-voter ownership/partition/quorum-loss, fresh restore and load-smoke
contracts. The smoke recorded normal/burst lookup p99 of 5.58/14.02 ms and sampled
API peak RSS of 11.4 MiB; it does not qualify regional or whole-host budgets.
The ASan/LeakSanitizer/UBSan recipe passed the same 32 CTests, three cleanup tests
and SQL/TLS, HTTP, three-voter and restore contracts in 253.5 seconds. Production
Clang 19 analysis, its analyzer/compiler failure controls, first-party license
checks and five direct dependency notices passed in 199.3 seconds. The existing
Boost.Asio diagnostic remains outside first-party scope. The sanitizer-backed fuzz
recipe passed the 32 CTests, three cleanup tests and native contracts, then 10,000
parser executions in 34 seconds and the deliberate ASan failure control; the full
recipe took 300.1 seconds. Both disposable qualification containers were removed.
Logs remain under `/tmp/baander-registry-security-d0865f1dfc/` (release) and
`/tmp/baander-registry-security-2fe31ae4b9/` (sanitizer, static and fuzz).

Early attempts stopped on a new fixture's receipt/resource mismatch and a missing
Clang sanitizer runtime in the disposable tooling. Both were corrected before the
successful runs above; their time is excluded from those passing-gate measurements.
Independent plan, code, simplification and documentation reviews passed, and
prose-preservation audits and whitespace checks passed.

The shared enrollment key, absent credential rotation/recovery, global rate-limit
fairness, duplicate JSON key behavior, live HTTP fuzzing, inbound legacy TLS and
session saturation probes remain explicit limitations. Regional deployment,
constrained-host soak and S3 backup/restore acceptance remain open. Existing
container startup guard evidence is historical; this task does not rerun that gate.
The application model's WebSocket proof, rate-limit and outbound-policy findings
were open at that checkpoint; the subsequent WebSocket ticket, atomic rate-limit
and provider destination deliveries close them within their recorded scope.

### Application threat-model delivery (2026-10-14)

Branch `plan/application-threat-model` delivered the
[application threat model](docs-book/part-2-developer-guide/application-threat-model.md),
which records assets, trust boundaries, source/test evidence and open findings. PHP
Discovery registration/pairing is local persistence. Registry internals and quorum
downgrade were pending at that checkpoint; the registry delivery above now completes
the combined model/regression item while retaining its open security findings.

Delivered changes:

- Both HTTP layers use explicit `TRUSTED_PROXIES`, empty by default; development
  no longer trusts a Docker subnet and Swoole no longer trusts every peer. The
  real command consumes the raw environment string while Symfony uses CSV;
  a container-resolution regression protects against nested-array startup failure.
- Malformed login JSON, email/password/TOTP types and rate-limit identity fields
  receive bounded rejection. The initial real-runtime drill reproduced three 500
  responses before validation was corrected.
- Application logs redact WebSocket query tokens as well as signed delivery
  signatures, including encoded parameter names. Real-firewall tests add method
  binding, revocation, protected-route and credential-disclosure cases.
- Lyrics requests reject redirects. Local Native/Curl TLS fixtures cover trusted
  success, untrusted certificates, hostname mismatch and private HTTP downgrade.
- `scripts/test-security-runtime-container.sh` is a blocking Forgejo step with
  disposable PostgreSQL/Redis, an internal network and bounded socket deadlines.
  It checks malformed wire/JSON input, oversized declarations, fresh-connection
  liveness and unchanged credential/user counts without resetting development data.

Open findings are explicit: WebSocket query authentication accepts a bound JWT
without proof possession; rate-limit cache failure and unlocked concurrent updates
can admit requests beyond the budget; metadata, radio-catalog and push egress need
separate destination policy. A local PHP stream reproduction follows HTTPS to
loopback HTTP; CI PHP 8.5.2 forwards Authorization while host PHP 8.5.11 strips it.
This is transport evidence, not an exploit against a live provider. Live socket
upgrades, authenticated pairing races, exhaustive parser/DoS testing and all
external transports remain unqualified. Regional qualification and mobile work
retain their earlier pending/deferred status.

The WebSocket, rate-limit and provider destination findings above describe this
historical checkpoint. They are superseded by [provider destination policy](#provider-destination-policy-2026-10-15),
[WebSocket tickets](#websocket-tickets-2026-10-15) and
[atomic rate limits](#atomic-rate-limits-2026-10-15). Their recorded qualification
limits remain visible in the current checkpoints.

Verification checkpoint: authoritative gates passed against
`baander-ci-prewarm:local` (CI target, PHP 8.5.2 ZTS, Swoole 6.2.0,
OpenSSL 3.0.18), with the current checkout streamed or mounted into disposable
containers.

- Unit and StaticAnalysisRules: 6,260 tests, 29,375 assertions across 26 passing
  shards. The unsharded `test-unit-container.sh` exited 139 before a report; the
  complete rerun used its same isolated image/archive with
  `scripts/run-phpunit-shards.php --testsuite Unit,StaticAnalysisRules`, matching
  Forgejo's fresh-process execution. This does not diagnose the unsharded native
  crash.
- Security runtime: 15 rejection cases passed, with fresh-connection liveness,
  unchanged user/token/login-block counts and no credential canary in runtime logs.
- Functional: 1,458 tests, 13,016 assertions across 134 passing shards, using
  `scripts/test-functional-container.sh tests/Functional` with
  `BAANDER_TEST_IMAGE=baander-ci-prewarm:local BAANDER_FUNCTIONAL_TIMEOUT_ACTIVE=1`.
- Integration: 1,355 tests, 37,054 assertions across 106 passing shards, using
  the same disposable runner with `tests/Integration`. The extended OAuth firewall
  file passes 29 tests and 227 assertions. Backend suites total 9,073 tests and
  79,445 assertions.
- PHPStan: zero errors. Deptrac: zero violations, 495 skipped, 15,629 allowed,
  17,848 uncovered, zero warnings/errors; baseline unchanged. Container lint and
  OpenAPI `--check` pass. Commands in the CI image were
  `php vendor/bin/phpstan analyse --memory-limit=1G --no-progress`,
  `vendor/bin/deptrac analyse --no-cache --no-progress`,
  `php bin/console lint:container --env=test` and
  `php bin/console app:export-openapi-spec --env=test --check`, using read-only
  checkout mounts and `--network none`. Initial PHPStan findings in test fixture
  annotations and the outbound peer loop were corrected before this checkpoint.
- Browser-auth: 23 Chromium cases pass on the host with
  `yarn --cwd ui/web test:browser-auth`. The host cannot start WebKit because its
  system libraries are absent. Its one case passes in
  `mcr.microsoft.com/playwright:v1.60.0-noble` with `--network none`, the full `ui`
  directory mounted read-only and `node node_modules/@playwright/test/cli.js test
  -c tests/e2e/browser-auth/playwright.config.ts --project=webkit
  --output=/tmp/browser-auth-results` from `ui/web`. Total: 24 browser cases.
  An initial container attempt omitted `ui/shared`; the corrected mount includes
  it. No browser production code changed, so web build/type/lint gates were not
  rerun for this backend/documentation change.

The unavailable compound-engineering skills were replaced by the documented
manual sequence: saved plan, independent plan review before implementation,
simplification and independent code review, including the runtime-discovered
proxy configuration correction. Source and caller inspection and Git diff supplied
the edit evidence. Prose-fix
preserves the evidence limits in the guide and model. No API schema change or
generated-client update is required. The delivery is merged into `master`; the
verification checkpoint applies unchanged to the fast-forwarded implementation.

### Parallel test execution

- [x] Adopt ParaTest 7.22.4 with disposable Compose stacks for backend verification.
  Unit/static-rule workers recycle after 25 files; native and service tests retain
  fresh processes per file. Each service lane owns its PostgreSQL, Redis, cache,
  temporary files and sockets. Identity and coverage comparisons, isolation drills
  and timing evidence are recorded in the
  [qualification checkpoint](#parallel-backend-verification). Delivered and merged
  into `master` on 2026-10-15.

### Worker architecture and resource control

The detailed design is [Web and worker runtime redesign](docs/plans/2026-07-17-001-feat-messenger-enterprise-hardening-plan.md).
Its historical baseline is not a current completion report; reconcile against source
and passing integration gates as each item is closed.

Implementation checkpoint on 2026-10-17: U1 of the
[reviewed two-command deployment plan](docs/plans/2026-10-17-1430-refactor-two-command-deployment-plan.md)
implements the storage admission foundation. Manifest version 2 admits named source
volumes read-only and derived volumes read-write, verifies immutable volume identity
and access mode, and requires explicit media memory and PID budgets.

The authoritative shared unit selection passes **378 tests / 886 assertions** in
an **8.0-second** lane, including worker CLI, configuration, mount and containment
suites. Host creation, start and recovery checks pass **57 tests / 564 assertions**
in **80.1 seconds**.

U2 moves ordinary background execution to Redis `async`. Recommendation admission
returns typed results, preserves foreground CLI counts and exit behavior, and
queues a worker job containing only its identifier. Workers generate image
derivatives and publish them atomically; web serves the original while a derivative
is pending.

Focused functional, image, recommendation and transport checks pass. The native
selection ran **197 tests / 509 assertions**, with **one fileinfo-dependent
skip**; the image case passes in the CI functional image. An initial broader CI
selection of **567 tests in 22 files** found one outdated assertion, now corrected.
The affected catalog mutation suite passes in a later selection covering U3,
codecs and controls: **257 tests / 4,273 assertions in 14 files**, **107.1 seconds**.
These selections overlap and do not establish a full-gate result.

U3 implements durable video and audio execution authorities, atomic admission with
rollback, ordered synchronous controls and bounded control request state. Leases
use the database clock, and generation fences reject stale owners. Forward
migration `Version20261017150000` adds the runtime schema; the initial migration
is preserved.

The frozen focused selection passes **25 tests / 305 assertions in 5 files** in
**70.2 seconds**, alongside the broader selection recorded above. Review changes
to partial indexes and cleanup are applied. Final verification after review passes
**49 tests / 545 assertions in 7 files** in **79.6 seconds**. Artifacts:
`var/test-runs/20261017-150155-13778b36`.

All five CI static gates pass: Composer platform requirements, PHPStan, Deptrac
with zero violations, container lint and OpenAPI drift checking. Types were
corrected and an obsolete dependency baseline entry removed; no suppressions were
added. The U2/U3 foundation phase is verified. The media role, durable video/audio
execution, generation-aware delivery and web executor removal are implemented.
Phase 2 authoritative verification passes **1,184 tests / 6,793 assertions in
106 files** in **126.1 seconds**; artifacts:
`var/test-runs/20261017-151907-3a82c762`. Final focused CI verification after
heartbeat, playback timeline, progress and encoder thread-budget changes passes
**1,112 tests / 6,065 assertions in 94 files** in **116.8 seconds**; artifacts:
`var/test-runs/20261017-152959-5493fc63`. These selections overlap. All five CI
static gates pass after those changes; log:
`/tmp/two-command-static-phase2-final.log`. The refreshed generated client and
frontend checks pass: **2,061 tests in 186 files** in **32.62 seconds**, typechecking
in **18.0 seconds**, lint in **20.9 seconds** and production build in
**19.6 seconds**.
Source-root configuration checks pass **173 tests / 208 assertions** after
`MEDIA_STORAGE_PATH` was wired into the filesystem and admitted runtime recipe.
Final review corrections scope video jobs and generation paths to each playback
owner, sign immutable adaptive rendition sets, fan out controls only to the same
owner/video, and retain durable session/request receipts. Audio eviction reuses
its canonical row with generation-fenced reset. Worker QoL restores, samples and
merges learning under shared derived storage. Forward migrations
`Version20261017160000` and `Version20261017170000` preserve the initial migration
and historical jobs while fencing prior active/complete output for re-encoding.
All **20 stack-isolation checks** pass; artifacts:
`var/test-isolation/two-command`. The initial full backend run found four obsolete
fixture or naming expectations; those tests were corrected before the later
full-run and focused-correction checkpoint below. This initial failed run is not
recorded as a qualification pass.
The final delta code review completed with **13 reviewers**. All **four confirmed
findings** are fixed and independently checked. Receipt:
`/tmp/compound-engineering-1000/ce-code-review/20261017-160205-c86f3c2e/`, including
`post-snapshot-fix-verification.json`. Completed-primary control checks pass
**23 tests / 248 assertions**. The four real-PostgreSQL sibling rollback cases
also pass **90 assertions** in the full run's service lane 2; that lane's unrelated
index-test failure is recorded below. The latest player selection passes
**33 tests**, including rejected-intent, zero-seek, paused-preroll and media-error
regressions. The latest full frontend run passes **2,089 tests in 186 files** in
**29.24 seconds**. Lint passes in **21.01 seconds** with **zero errors and seven
standing warnings**, and build including typechecking in **19.13 seconds**.
Evidence: `/tmp/two-command-frontend-cursor-readiness/results.json`.

The CI integration selection passes **35 tests / 336 assertions in 7 files** in
**86.1 seconds**; artifacts: `var/test-runs/20261017-160023-cae5e039`. Authority
drills pass: containment in **19.44 seconds**, retirement in **9.4 seconds** and
recovery in **32.5 seconds**. Harness verification passes **69 Python tests**,
**20 harness cases** (13 cleanup, two final-drill and five receipt cases), and
**18 process-guard tests / 41 assertions**. The operator cleanup runner is now
part of CI's backend runner regression step.

The real worker-command drill exposed loss of empty environment values.
Child launch now passes literal `NAME=value` environment entries, and nested
inheritance checks pass **9 tests / 28 assertions** in **0.608 seconds**. The
PHPStan `proc_open` stub now matches the verified native key/value-pair type;
no suppression was added. The real worker-command acceptance rerun passes in
**228 seconds**. These focused results do not establish final production
acceptance.

Production qualification found that the io_uring-enabled Swoole 6.2 build failed
coroutine file reads under default Docker seccomp: the reproduction exited **1**.
There is no supported runtime INI backend switch. The image now builds without
io_uring, the obsolete development INI option is removed, private media jobs cap
AIO at **one core / one maximum thread**, and web uses **one core / four maximum
threads**. The production wrapper now exercises real coroutine file read/write
under the admitted restrictions. Authenticated browser startup exposed an HTTP-to-HTTPS DPoP URI mismatch;
proof construction now uses the request origin and pathname, with five URI cases
and focused independent review. Backend proof checks retain their enforcement.
Native harness isolation selected Chromium **148.0.7778.96** from the pinned
Playwright image, without media feature flags or a media JavaScript library.
Standalone playback and paused-seek probes pass; evidence:
`/tmp/two-command-chromium-hls-results/result.json`.

Dedicated result files separate phase receipts from production Redis warnings;
fixture PHPStan passes in **10.91 seconds** with cleanup verified. The reporting
correction's production run passes in **280.64 seconds**, reaches all seven browser
phases, and verifies fourteen contained identities, zero survivors and cleanup.
That result predates the current seek-generation correction. An earlier run
exposed an intermittent old native seek replacing a paused position of **30**
with **33.246624**, advancing generation/revision from **5/11** to **6/12**.

Native events now carry their captured execution generation. Existing playback
locks reject stale events while explicit seeks and progress retain their separate
contracts; no schema change is required. Authoritative verification passes
**79 tests / 512 assertions in 5 files** in **61.6 seconds**; artifacts:
`var/test-runs/20261017-183627-76a8d28c`. Independent follow-ups
`native-seek-generation-fence-followup` and
`deterministic-stale-seek-acceptance-followup` are resolved. The production fixture
now submits the stale **33.246624** seek after paused **30**, with the worker live
and absent, and checks unchanged desired state, generation and revision. The prior
image's pass predates these final changes; the current-image result is recorded
below.

A subsequent production run failed after **312.87 seconds** despite passing the
stale-generation probes. Resume exposed a cursor-readiness defect: a running
sibling could be advertised before its target fragment closed. Readiness now
requires the desired-cursor fragment for all desired statuses, and media-error
pause events preserve the playback error instead of persisting a user pause.
Authoritative verification passes **54 tests / 333 assertions in 5 files** in
**25.0 seconds** (**11.7 seconds** in the lane); artifacts:
`var/test-runs/20261017-184926-df5a8b1e`. PHPStan passes in **54.36 seconds** and
Deptrac in **5.2 seconds**, with cleanup verified; results:
`/tmp/two-command-static-cursor-readiness/results.json`. The independent
`running-target-readiness-error-pause-followup.json` review finds no issues.
The final current-image run below verifies the corrected readiness behavior.

Paused-preroll backend verification passes **53 tests / 327 assertions** in
**36.9 seconds**; artifacts: `var/test-runs/20261017-174751-0d947a51`. PHPStan
passes in **54.76 seconds** and Deptrac in **5.2 seconds** with zero violations;
evidence: `/tmp/two-command-static-paused`.

Final static verification after the seek-generation correction passes export
preparation in **6.1 seconds**, Composer platform requirements in **0.4 seconds**,
PHPStan in **51.96 seconds**, Deptrac in **5.2 seconds**, container lint in
**2.1 seconds** and OpenAPI drift checking in **2.1 seconds**. The duplicate
custom-stub issue is resolved. All recorded exits are zero and cleanup passes;
results: `/tmp/two-command-static-seek-fence/results.json`.

The remaining runtime drills pass: web runtime in **59.02 seconds**, web shutdown
in **108.75 seconds** and legacy consumer recovery in **122.94 seconds**. Shutdown
covers all stop routes and records **36 MiB / 512 MiB** peak web-worker memory
(**7.0%**). The legacy fixture observes the killed consumer as unhealthy after
**90 seconds**, then recovers unacknowledged work. Results:
`/tmp/two-command-remaining-drills/results.json`. These complement the authority
and **228-second** worker-command results above. Those separate drills do not by
themselves establish the combined worker/browser result recorded below.

The full backend run executed **9,679 tests in 927 files / 78,504 assertions**
in **983.8 seconds**. Ordinary, native and service lane 1 pass; service lane 2
fails solely on an obsolete `CoveredIndexPersistenceTest` expectation. Artifacts:
`var/test-runs/20261017-170639-17d75655`; log:
`/tmp/two-command-backend-accepted.log`. The corrected file passes a focused rerun
of **3 tests / 56 assertions in 1 file**, **49.9 seconds** including coordination
(**29.6 seconds** in its service lane). Artifacts:
`var/test-runs/20261017-172920-0cc78a9e`; log:
`/tmp/two-command-index-accepted.log`. This records the full run and its focused
correction; it is not an uninterrupted passing full-suite rerun.

Final current-image production acceptance passes in **274.89 seconds**, exit
**0**, with Chromium **148.0.7778.96**. The immutable worker image is
`sha256:17cd8e17983adc08348cdbf16dc52c97a85c7f185a1197b82c2f2f0af361125b`. All seven browser phases pass:
authenticated, attached, sought-paused, web-restarted, worker-contained,
worker-recovered and complete. Both live-worker and worker-absent stale-generation
probes report `staleGenerationIgnored: true` and preserve paused position **30**.
Final containment checks **14 identities** with **zero survivors**; cleanup
reports exit **0** and `cleanupVerified: true`. Results:
`/tmp/two-command-combined-cursor-readiness-result.json`; artifacts:
`var/test-runs/baander-worker-operator-1792256034-1425218`.

The two-command deployment item is closed by this production/browser result,
backend full-run evidence plus its focused correction, affected static/frontend
gates and independent review. Operator and testing guidance at this checkpoint
described manifest version 2, shared media storage, worker execution and explicit
recovery. Subsequent route, control-capacity, autoscaling, lifecycle and shared
media-control deliveries below supersede this checkpoint's remaining-work list.

#### Validated asynchronous routes (2026-10-18)

The [validated-route plan](docs/plans/2026-10-18-1126-fix-validated-async-routes-plan.md)
is implemented. One catalog now supplies the default Messenger routes, resource
classes, retry policies and worker receiver order. Container compilation checks
eligible handlers, independent JSON codecs and effective transport configuration.
Producer dispatch validates the complete sender selection before sending, including
explicit transport overrides. Synchronous, inline and received-message handling
retain their existing paths.

The final production inventory contains **17 workloads**: **15 default routes**
and explicit-only lyrics fetching and webhook delivery. The plan's sixteen-workload
inventory missed webhook delivery through the notification intent relay. The real
worker drill exposed that omission; the catalog and regression coverage now include
all three relay channels: email, push and webhook. The test environment adds only
`FailureTransportProbe`, for **18 workloads / 16 default routes**.

Host admission and direct worker startup reject Redis URL options that replace the
configured stream, group or generated consumer identity. Host admission rejects
invalid URLs before container creation. Direct startup rejects them before worker
locks, lease acquisition or child launch. Invalid worker transport configuration
exits with code **2**, names the setting and omits its value.
Accepted launches retain identities derived from deployment, boot, role and launch
generation on both Redis streams. Resource classes remain descriptions; this item
does not reserve control capacity or qualify saturation behavior.

Verification uses the CI image `baander-ci-two-command:local` with the current
checkout streamed into disposable containers. The final worker-command drill passes
in **248.11 seconds**, exit **0**, covering three hostile startup configurations,
the generated consumer identity on both streams, outbox and pre-acknowledgment
crash recovery, replay, scheduler authority and shutdown. Log:
`/tmp/validated-async-worker-final.log`.

Composer platform checks, PHPStan, Deptrac, container lint and OpenAPI drift checks
pass, with no baseline expansion. The full PHPStan run takes **54.06 seconds**;
the other gates take **0.4 / 5.3 / 2.1 / 2.3 seconds** respectively. Static-run
cleanup succeeds. Evidence: `/tmp/validated-async-static/results.json` and
`/tmp/validated-async-phpstan-final/results.json`; the first records the initial
missing test-type annotations, corrected before the passing PHPStan rerun.
The final three-file PHPStan check passes in **8.71 seconds**, covering the
post-review tests and corrected transport fixture. Evidence:
`/tmp/validated-async-review-phpstan/results.json`.

The full coordinator executed **9,818 tests in 932 files / 75,546 assertions**
in **884.4 seconds**. Ordinary, native and service lane 1 pass. Service lane 2 reported
**130 errors**, all from the catalog firewall fixture replacing Symfony's transport
with a bare in-memory definition. The fixture now changes only the DSN, preserving
the factory, serializer and receiver metadata. Its focused correction and the
catalog/compiler tests pass: **172 tests in 3 files / 3,549 assertions** in
**84.9 seconds**, including all **130 firewall cases / 3,488 assertions** and the
three post-review rejection cases. This records the full run and its focused
correction, not an uninterrupted passing full-suite rerun. Artifacts:
`var/test-runs/20261018-120906-40a1ac74` and
`var/test-runs/20261018-122500-9ee9b819`; logs:
`/tmp/validated-async-backend-full.log` and `/tmp/validated-async-firewall-fix.log`.

The no-Swoole selection passes **42 tests in 3 files / 164 assertions** in
**72.9 seconds**, with no skipped cases. Artifacts:
`var/test-runs/20261018-122643-f6e41e0f`; log:
`/tmp/validated-async-no-swoole.log`.

Independent `ce-code-review` completed with **zero actionable findings**. Its
initial verdict held completion for the full/no-Swoole checks and roadmap evidence
recorded here. A coverage suggestion added three explicit-only workload rejection
cases for missing codecs, missing handlers and wrong-transport handlers. Receipt:
`/tmp/compound-engineering-1000/ce-code-review/20261018-121042-caadf11b/review.json`.
The final simplification pass found no further changes; documentation passed
`prose-fix`, link checks and `git diff --check`.

Frontend gates and backend runner/isolation self-tests were not repeated: no web
code, runner protocol, backend isolation fixtures or native-test classification
changed. Local application databases, baseline services and media remain untouched;
all verification infrastructure is disposable. Reserved control capacity,
autoscaling and shared media-control scope were open at this checkpoint and are
delivered below; broader coverage is not a standing follow-up task.

#### Reserved worker control capacity (2026-10-18)

The [reserved-control plan](docs/plans/2026-10-18-1245-feat-reserved-worker-control-plan.md)
implements a fixed control/execution pair under one boot and a joint retirement
barrier. Control owns lease renewal, relay, scheduler polling and media
coordination. Execution observes committed authority and owns separate ordinary
and scheduled consumers, native probes, encoders, CPU helpers and scheduled console
children. Peer boot/epoch evidence and native readiness gate business admission;
missing peers fail startup after thirty seconds, and draining is bounded to
thirty-five seconds per unit.

Version 3 manifests require separate memory/CPU/PID ceilings, disjoint CPU sets
and a private named IPC volume. The revised admission profile reserves control
2624 MiB/128 PIDs and execution 4480 MiB/256 PIDs, including a 320 MiB console
reservation, for three video slots, one audio slot and no devices. CPU placements
are selected explicitly from available daemon CPUs. The paired fixture requires
four CPUs and 13 GiB; combined web/media and pressure runs require 15 GiB including
4352 MiB dependencies, 2048 MiB web and 1024 MiB headroom. Standalone admission
totals 12480 MiB; with web it totals 14528 MiB. PHP kernel heaps are 256 MiB.
These are declared admission limits, not universal measured capacity defaults.

Forward migration `Version20261018130000` imports confirmed singleton retirement
history and refuses active leases, unresolved creation claims, incomplete
retirements and inconsistent journals. Operators must retire old deployments with
the old controller before upgrading and quiesce controller activity. The migration
preserves application data; no local application database reset is included.

Verification uses the CI image with the checkout copied into disposable services:

- The final journal/pair-manifest selection passed **66 tests / 704 assertions**
  in **121.9 seconds**. Artifacts: `var/test-runs/20261018-130004-6ca0c972`.
- The final real paired worker-command drill passed in **296.3 seconds**, with
  cleanup. It covers startup, relay claim/projection/handoff crashes, retained
  original leases and receipts, scheduler-consumer death before XACK, natural
  redelivery, duplicate refusal, scheduler failure, TERM and lease-expiry draining.
  Log: `/tmp/reserved-control-command-final.log`.
- The full coordinator executed **9,857 tests in 934 files / 79,728 assertions**
  in **825.3 seconds**, with zero skipped cases, four errors and eight failures
  from fixture expectations, constraint naming and absent native bootstrap. The
  corrected selection passed **283 tests / 1,370 assertions** in **120.2 seconds**,
  including fifteen new native cases. A subsequent post-profile selection covering
  restored runtime regressions and parent-owned native cleanup passed **246 tests /
  621 assertions** in **78.8 seconds**, with zero skipped cases. Artifacts:
  `var/test-runs/20261018-132006-2c7bbea0`,
  `var/test-runs/20261018-133633-70437957` and
  `var/test-runs/20261018-134727-431ba2bc`. These are a failed full run plus focused
  corrections and additions, not an uninterrupted passing full-suite rerun.
- Latest discovery contains **9,909 tests in 934 files**; it was not run as one
  full suite. Compared with the prior 9,818-case full checkpoint, discovery adds
  **110 cases** and removes **19**. Three additions were already verified in that
  checkpoint's post-full-run catalog correction. Removed coverage comprises nine
  singleton containment cases, superseded by paired retirement/inventory/recovery,
  and six local-spawner plus four helper-process cases, superseded by remote
  spawner/native-daemon/protocol coverage. Restored runtime files contain 67 cases
  against their prior 63, including two overflow cases.
- The no-Swoole selection passed **42 tests / 164 assertions** in **71.2 seconds**,
  without skips. Artifacts: `var/test-runs/20261018-134955-07e96464`.
- All five static gates pass without baseline expansion. Composer platform,
  Deptrac, container lint and OpenAPI checks took **0.4 / 5.4 / 2.1 / 2.3 seconds**;
  corrected PHPStan passed with zero errors in **56.26 seconds** (**68.85 seconds**
  with its runner). The first PHPStan run's twenty annotation/inference errors were
  corrected. Evidence: `/tmp/reserved-control-static-final/results.json`.
- All **69 Python runner regressions** passed in **0.732 seconds**.
- The production-worker, operator and combined media drill passed in **198.3
  seconds** on the revised profile, including actual audio/video decoding,
  attachment, persisted pause/seek/resume, web replacement, worker containment and
  recovery, final retirement and verified cleanup. Artifacts:
  `var/test-runs/baander-worker-operator-1792324276-1876507`; image:
  `baander-worker-reserved-control:local`. Earlier production attempts failed on a
  128 MiB kernel heap and an OOM-killed 512 MiB PostgreSQL fixture; neither is
  counted as a pass, and both motivated the documented profile corrections.
- Containment, retirement, recovery and isolation passed in **18.83 / 9.55 /
  15.91 / 43.12 seconds**, respectively. Evidence:
  `/tmp/reserved-control-lifecycle-results.json`.
- The web-ownership regression passed **1 test / 56 assertions** in **50.97
  seconds** including coordination (**31.5 seconds** in the service lane). Artifacts:
  `var/test-runs/20261018-142144-3d3eaf75`.
- Final scoped fixture PHPStan passed with zero errors in **11.31 seconds**.
- Final monitored CPU qualification passed with **1,135 occupied-slot samples**
  across the sixty-second window: three video encoders and one deliberately paused
  audio encoder remained admitted. Desired-state observation p99/max were
  **0.233721 / 0.258274 seconds**; heartbeat p99/max were **0.010040 / 0.029077
  seconds**. The ordinary backlog stayed at least **1,984**, while **1,920**
  deliveries were acknowledged and **five** due scheduled occurrences returned.
  Execution consumed **156.918957 CPU seconds** over the measured pressure period;
  drain took **one second**. Artifacts:
  `var/test-runs/control-capacity-qualified/cpu/qualification.json`.
- Final monitored memory exhaustion passed: the exact native-daemon victim was
  selected, execution's `oom_kill` counter advanced **0 → 1**, and owned failure
  triggered an automatic **five-second** drain. Control survived without an OOM.
  CPU and memory receipts include successful bounded Docker event capture for
  both immutable IDs, a live observer through drain and intentional monitor
  shutdown. Artifacts:
  `var/test-runs/control-capacity-qualified/memory/qualification.json`.
- Five negative replays reject missing/failed capture, unexpected observer exit,
  absent output and a control OOM event. A separate saved-evidence replay hides
  the cgroup OOM-kill observation and verifies exact-container Docker-event fallback.
  These validate evidence interpretation, not additional live pressure passes.
  Evidence: `/tmp/reserved-control-observer-negative.json` and
  `/tmp/reserved-control-oom-fallback-replay.json`.
- Final PID qualification passed in **60.740 seconds** including setup and
  verified cleanup. Execution's kernel `pids.events max` counter advanced
  **0 → 19**; native launch failed on attempt **3**, triggering automatic joint
  drain in **one second**. Control's heartbeat maximum was **0.009744 seconds**,
  its peak PID usage was **11 / 128**, and its PID-max counter stayed zero. It
  suffered no OOM; complete exact-ID event capture remained live through drain.
  Artifacts: `var/test-runs/control-capacity-pid-verified/qualification.json`;
  result: `/tmp/reserved-control-pid-verified-result.json`.
- Earlier PID fixtures correctly failed qualification: the first left released
  slots available after the first fork refusal; subsequent rapid drains outran
  baseline/counter sampling. The final fixture waits for valid baseline samples,
  refills slots for a bounded interval and captures the same-cgroup kernel counter
  at refusal. No application drain or responsiveness threshold changed.
  CPU/memory used image
  `sha256:08cfba69d7b955a2a699d63ee06697f4b8bf15f6c96981b41bd9ba0f6be5e228`;
  final PID used
  `sha256:a0c71f217a8e3310daccb525351afbc90dfc273e86614b9c9f81fb54331c5e72`.
  The image difference contains fixture corrections, with no application change.

The reserved-control item is delivered by these monitored CPU/memory/PID results,
the production media and worker-command drills, backend full-run evidence plus
focused corrections, static gates and reviewed implementation. Final pressure
fixture PHPStan after the counter-capture correction passed with zero errors in
**9.01 seconds**, with cleanup verified; evidence:
`/tmp/reserved-control-static-pressure/results.json`.

Autoscaling was open at this checkpoint and is delivered below with measured
profiles. Smaller-host/default-profile expansion and regional acceptance remain
outside that measured envelope; select any further qualification against a named
release decision. Earlier production/browser checkpoints
retain their original scope. Baseline services, application databases and media
remain untouched; all new verification resources are disposable.

#### Measured ordinary worker autoscaling (2026-10-18)

Implementation on `plan/measured-worker-autoscaling` follows the
[reviewed plan](docs/plans/2026-10-18-1500-feat-measured-worker-autoscaling-plan.md).
The user confirmed scaling workers within each admitted pair. The production
profile, resident ordinary pool, receive gate, independent controller and status
evidence are implemented. Resident maxima one, two and four passed local measured
qualification and comparison; the operator example selects maximum two within
that envelope. Implementation and qualification are delivered. Final independent
review reports **Ready to merge**, with all **six** original findings resolved and
**zero** subsequent actionable findings. Receipt:
`/tmp/compound-engineering-1000/ce-code-review/20261018-164437-autoscale-reconcile/final-readiness.json`.

Version 4 manifests require a complete, fingerprinted autoscaling profile. Control
reserves **2944 MiB / 129 PIDs**, including **320 MiB** for its independent
controller. Execution reserves **4160 + 320 × (maximumConcurrency − 1) MiB** plus
any scheduled console reservation, and **256 + maximumConcurrency − 1 PID slots**.
All admitted ordinary consumers start at boot and remain resident and fully
reserved when parked. Scaling changes permission to receive; it reclaims no RSS
or connections. The scheduler consumer and three-video/one-audio media topology
retain separate reservations and the existing joint retirement barrier.

The receive gate retains global occupancy through handling, acknowledgment and
retry publication. Lower targets wait for held permits to drain before admitting
replacement receives. Independent queue, slot and execution-cgroup observations
feed the bounded policy; stale evidence prevents growth, severe memory/PID pressure
closes ordinary admission, and recovery requires fresh samples. Status exposes
desired, occupied, busy and parked counts, missing or uncertain evidence, sample
ages, pressure and decision reasons. Atomic Redis retry promotion checks the
current score and inserts/removes a due member in one Redis script, preventing
competing successful promotions of the same member. A later removal failure can
retain already-published work for redelivery; transport semantics remain
at-least-once.

Verified implementation checkpoints:

- Profile and recipe arithmetic passed **216 tests / 416 assertions in 6 files**
  in **30.7 seconds**. Artifacts: `var/test-runs/20261018-150855-1688c041`.
- Real Redis retry-promotion checks passed **11 tests / 321 assertions**, with a
  **36.4-second** service lane, covering competing pollers, a rescored future
  member, serialization bytes, retention and denied Redis operations. Artifacts:
  `var/test-runs/20261018-150724-00eb4111`.
- Observation adapters passed **21 tests / 134 assertions in 3 files** in
  **60.3 seconds**, including alternate transport database selection, unread,
  pending, due and future work, unknown lag, bounded timeouts and cgroup sampling.
  Artifacts: `var/test-runs/20261018-151442-7b1bef60`.
- Integrated controller/runtime checks passed **72 tests / 1,272 assertions in
  9 files** in **24.6 seconds**. Artifacts:
  `var/test-runs/20261018-153427-9846b098`. These focused selections overlap.
- The full backend coordinator passed **10,002 tests in 944 files / 81,597
  assertions** in **833.826 seconds**. Ordinary: **6,419 / 25,767**,
  **64.0 seconds**; native: **681 / 7,952**, **114.7 seconds**; service lanes:
  **1,490 / 29,190**, **590.4 seconds**, and **1,412 / 18,688**,
  **618.3 seconds**. Artifacts: `var/test-runs/20261018-154531-d27650c8`.
  This snapshot precedes the added queue-state regression and three retry
  lifecycle cases. Their focused CI run passed **49 tests / 1,224 assertions**
  in **62.3 seconds**: ordinary **46 / 1,100**, **10.8 seconds**, and actual
  worker lifecycle **3 / 124**, **29.1 seconds**. Artifacts:
  `var/test-runs/20261018-155931-45b5cb2a`.
- Qualification-verifier regressions with stricter idle checks passed **46 tests**
  in **27.813 seconds**. Live observation-gap and ordinary CPU checks passed for
  all three profiles; retry lifecycle coverage is recorded above.
- The no-Swoole selection passed **45 tests / 288 assertions** in
  **90.1 seconds**. Artifacts: `var/test-runs/20261018-160504-aad2e2f3`.
- The latest static run passed Composer platform requirements in **0.40 seconds**,
  Deptrac in **5.30 seconds**, container lint in **2.10 seconds** and OpenAPI drift
  in **2.30 seconds**. PHPStan passed after its fixture reference-parameter
  annotation correction in **74.82 seconds**. All five gates are green and cleanup
  passed. Evidence: `/tmp/autoscale-final-static.log` (the four passing gates and
  initial PHPStan failure) and `/tmp/autoscale-final-phpstan/results.json` (the
  corrected PHPStan pass).
- The first live one-slot comparison failed when Messenger's thirty-second alarm
  interrupted three fixture I/O waits. A real alarm reproduced a **0.999785-second**
  wait; waiting until the remaining monotonic deadline completed in
  **2.000089 seconds** after the correction. The deployment comparison rerun is
  recorded below; this failed run is not a qualification pass.
- All **69 Python runner regressions** passed in **0.729 seconds**.
- Two phase simplification reviews completed with **zero accepted changes**.
  One connection-reuse proposal was declined because it would change
  authentication, DNS and failure behavior. Receipts:
  `/tmp/baander-u1-u2-simplify-wmqvkj7i/receipt.md` and
  `/tmp/baander-u3-u5-simplify-tn4g6uit/receipt.md`.
- The qualification-phase simplification applied shared threshold reuse and
  declined a reverse-reader proposal because it added parser complexity without
  measured benefit. Receipt: `/tmp/baander-u6-simplify-ucrcj1_t/receipt.md`.
- A fresh production worker image built in **43.624 seconds**:
  `sha256:b97ce7781e526b67249eb7af1f679e01fe8fb3679e227c35b97dc932076f06ee`.
  The real worker-command drill passed in **291.914 seconds**, covering relay,
  projection/handoff and consumer-acknowledgment crashes, scheduler failure, TERM,
  lease expiry and retirement. The full production media wrapper passed in
  **201.971 seconds** with the recommended maximum-two profile, **eight phases**
  and verified cleanup. The wrapper verified the image contract, production
  platform, exclusion of development dependencies and coroutine file I/O. Artifacts:
  `var/test-runs/autoscale-production-media`; runtime evidence:
  `/tmp/autoscale-final-runtime-gates.log`.
- All **20 backend stack-isolation checks** passed in **43.198 seconds**, with
  no cleanup errors. Evidence: `var/test-runs/autoscale-stack-isolation/results.json`.
  The unchanged native lane is covered by the full backend run above.
- The version 4 reserved-control CPU/memory/PID drill passed in **281.436
  seconds**. CPU pressure retained a minimum backlog of **1986**, acknowledged
  **1359 deliveries** and returned **five** scheduled occurrences while execution
  consumed **157.371644 CPU seconds**. Control recorded **1503 heartbeat
  samples**, maximum **0.017274801 seconds**; **206 media-control observations**
  had maximum **0.251185469 seconds**. Memory exhaustion advanced execution's
  `oom_kill` counter **0 → 1** and triggered an automatic **five-second** drain.
  PID exhaustion advanced its max counter **0 → 44** and triggered automatic
  draining within the same integer-second observation interval. Control survived
  both faults. All three phases left control's OOM-kill counter unchanged and
  recorded **zero exact-container Docker OOM events** for control. Every phase
  verified cleanup, completed joint retirement and no remaining reservation.
  Artifacts: `var/test-runs/autoscale-control-capacity-v4/{cpu,memory,pid}/`,
  including `qualification.json`, `cleanup.json` and `capacity-retirement.json`;
  total result: `/tmp/autoscale-final-control-capacity.json`.

Local measured qualification used an **AMD Ryzen 7 5800X3D**, **8 cores /
16 threads**, **64 GiB physical RAM** host; Docker reported **67337879552 bytes**.
Control had one reserved CPU, execution two disjoint CPUs, and web/dependencies
shared a fourth CPU. All three profiles used the same workload, declared thresholds
and production code. The one-slot baseline was retained from the valid earlier
run and accepted by the current stricter verifier; maxima two and four are fresh
runs. Earlier failed maximum-two attempts remain failed evidence.

| Resident maximum | First/repeat I/O burst (seconds) | Speedup over maximum one | Execution peak cgroup memory (MiB) | Execution peak PIDs | Peak database connections | Checkpoint (seconds) |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | 97.42 / 97.43 | 1.00× | 878.5 | 31 | 35 | 358 |
| 2 | 56.99 / 56.97 | 1.71× / 1.71× | 973.6 | 32 | 37 | 336 |
| 4 | 39.21 / 38.48 | 2.48× / 2.53× | 1021.4 | 34 | 39 | 422 |

All three checkpoints and the comparative thresholds passed, with cleanup
verified for every profile. Artifacts:
`var/test-runs/autoscaling-qualified-v3/{1,2,4}/qualification.json` and
`var/test-runs/autoscaling-qualified-v3/comparison.json`; host CPU receipt:
`var/test-runs/autoscaling-qualified-v3/host-cpu.json`.
The operator example recommends minimum one/maximum two with execution
**4800 MiB / 257 PID slots**, including **320 MiB** for scheduled console work;
control remains **2944 MiB / 129 PIDs**. Every manifest field remains required.
The internal constructor retains conservative maximum one. Maximum four is an
I/O-heavy option within the same qualified envelope with execution **5440 MiB /
259 PID slots**, including console. It improved I/O completion but not ordinary
CPU-work completion: **27.87 seconds**, compared with **26.50 seconds** at maximum
two. Maximum two is the general starting profile with fewer resident processes
and connections.

Maximum two reached two in both I/O bursts and returned to one between them.
Ordinary CPU work grew to two before pressure reduced it to one, with execution
using **35.22 CPU seconds** in that phase. Media-control p99/max were
**0.197508 / 0.249981 seconds**; heartbeat p99/max were **0.010339 / 0.016336
seconds**. All **five** scheduled occurrences returned, with maximum completion
**2.811135 seconds**; ordinary pressure work acknowledged **1369 deliveries**.
The stale-controller probe kept work unread and completed it after recovery;
recovery took **2.830745 seconds**.

The media phase retained three active video encoders and one audio slot held by
stopping its encoder after initial output. It does not establish uninterrupted
audio throughput under pressure. The first burst follows harness work with
initialized duration history; it is not pristine cold-start performance. Blocking
waits model I/O, not a provider latency distribution. Startup and RSS observations
include the qualification observer; dependency/media preparation is outside the
reported container-start-to-checkpoint interval. Full resident reservations remain
required despite lower sampled usage.

Full harness startup to first checkpoint took **77.25 / 106.28 / 66.33 seconds**
for maxima one/two/four, with **one-second** start-clock precision. These include
fixture-image preparation, dependencies/media, web/pair readiness and the gap
probe; they are not isolated production startup measurements. PostgreSQL, Redis
and web each used **0.5 CPU** on CPU 3, with configured memory/PID ceilings
**4096 MiB / 64**, **256 MiB / 64** and **2048 MiB / 128**. Five-container memory
ceilings, including the pair, sum to **13.5 / 13.8125 / 14.4375 GiB**. These are
configured limits rather than measured dependency use; operator helper overhead
is outside the totals. Receipts:
`var/test-runs/autoscaling-qualified-v3/{1,2,4}/startup-accounting.json`.

The version 4 reserved-control pressure drill uses maximum one at
**2944 MiB / 129 control PIDs** and **4480 MiB / 256 execution PIDs**, including console; it does not qualify
OOM or PID exhaustion for maxima two or four. Those profiles have separate
CPU/native pressure evidence above. Final independent review is complete, with
no remaining blockers, as recorded in the review receipt above. The recommendation
covers this hardware, placement and workload;
smaller-host, live-provider, regional and longer failure/load qualification remain separate.
Existing historical checkpoints retain their own profiles and acceptance scope.
Baseline application data and media are preserved.

Worker lifecycle and outage qualification checkpoint (2026-10-18), implementing
the [reviewed plan](docs/plans/2026-10-18-1713-test-worker-lifecycle-outage-qualification-plan.md):

The new standalone runner exercises Redis and PostgreSQL pauses in separate
maximum-two deployments without HTTP. Redis unavailability is observed while
lease authority remains valid, followed by explicit PID 1 TERM; PostgreSQL
unavailability must cause automatic authority-loss drain. Both stopped immutable
unit IDs retain their reservation until joint retirement is recorded, and a
premature successor is refused. Repeated recovery, a fresh successor boot at a
higher epoch and stale predecessor operations are checked independently.

Redis recovery follows the exact outbox event and manual occurrence retained
before shutdown. PostgreSQL recovery verifies identified work seeded after
successor admission. After the stale predecessor operations, both phases seed
and complete another independently identified event and occurrence. Each batch
requires one notification projection, both intended channel deliveries, one returned
occurrence effect, empty stream/pending/delayed state and no failed messages. A lost publication
reply can leave `dispatched_at` null after successful execution; completion uses
`dispatch_token` and the exact returned effect. Consumed executions without return
receipts remain uncertain. These checks add no production behavior or transport
timeout policy and make no exactly-once, automatic-replacement or broker
crash-durability claim.

The real Redis transport cases drop a successful `XREADGROUP` or `XACK` reply and
independently inspect the exact pending entry and effect count. Both preserve the
same uncertain occupied permit and deny further receive. The lease-helper test
observes a separate PostgreSQL backend while the parent's transaction remains
usable and rollback remains effective. The native daemon test sends TERM with a
live identified FFmpeg group and checks exit, group containment and socket removal.

Characterization checkpoints: helper/native selections pass **32 tests / 516
assertions** in **71.0 seconds** (`var/test-runs/20261018-171837-43f554d9`), and
transport admission cases pass **5 tests / 180 assertions** in **42.3 seconds**
(`var/test-runs/20261018-172110-2d536e09`). The no-Swoole transport/helper selection
passes **37 tests / 696 assertions** in **86.4 seconds**
(`var/test-runs/20261018-172707-aa370178`). These focused results overlap later
qualification and do not establish a full-suite pass.

The standalone lifecycle drill passes through the container controller with
checkout-in-image source in **271.775 seconds**, including setup and cleanup.
Redis explicit-stop drain takes **30 seconds**; PostgreSQL automatic authority-loss
drain takes **32 seconds**. Their phase checkpoints take **73 / 57 seconds**,
respectively. Both phases record maximum two, absent HTTP, epochs **1 → 2**,
exact returned work and a subsequent independently identified batch, empty queues
and verified cleanup with exit status zero. Evidence:
`var/test-runs/worker-lifecycle-qualified`. This local run exercises the configured
CI path; no completed remote run is claimed. An earlier fixture run incorrectly
required a publication receipt after an accepted send with a lost reply. Its cleanup
was verified; the corrected assertion follows existing scheduler semantics and
required no production change.

All **69 Python runner regressions** pass in **0.745 seconds**. Operator cleanup
checks pass **16 cleanup, two final-drill and five phase-receipt cases**. Logs:
`/tmp/lifecycle-runner-regressions.log` and `/tmp/lifecycle-cleanup-tests.log`.
Three simplification reviewers and seven final code reviewers report no findings;
the code review verdict is ready to merge. Receipts:
`/tmp/baander-lifecycle-simplify-receipt.md` and
`/tmp/compound-engineering-1000/ce-code-review/lifecycle-20261018-review/review.json`.
Authoritative backend qualification passes **10,010 tests in 945 files / 81,813
assertions** in **909.281 seconds**. The finalizer verified completed test identities
and cleanup with status zero. Lane results (tests / assertions) are ordinary
**6,420 / 25,776** in **64.4 seconds**, native **682 / 7,964** in **114.3 seconds**,
and PostgreSQL lanes **1,680 / 23,166** in **693.4 seconds** and **1,228 / 24,907**
in **528.5 seconds**. Artifacts: `var/test-runs/20261018-173431-7196b3a0`.
This snapshot precedes a fixture-only CLI entry guard correction in `9bd34d6a`:
PHPStan found an undefined `$argv` in `ReplyLossProxyServer.php`. After correction,
the actual proxy selection passes **5 tests / 180 assertions** in **49.9 seconds**
(`var/test-runs/20261018-175245-849c5e9a`); focused review reports no findings.

All final static gates pass: Composer platform requirements (**0.40 seconds**),
PHPStan (**58.66 seconds**), Deptrac (**5.40 seconds**), container lint (**2.20
seconds**) and OpenAPI drift (**2.30 seconds**). No baseline or production changes
were needed. Receipts: `/tmp/lifecycle-core-static/results.json` records four
passing gates and the initial PHPStan failure;
`/tmp/lifecycle-phpstan-final/results.json` records the corrected pass.
Affected runtime drills pass: worker command (**328.423 seconds**), external paired
recovery (**16.363 seconds**), TERM/KILL containment (**19.904 seconds**) and
production operator (**34.706 seconds**). The production drill uses the existing
`baander-worker-autoscaling-final:local` image; application files are unchanged.
The new lifecycle CI path uses the current image. Timings and exit statuses:
`/tmp/lifecycle-gates.json`; logs: `/tmp/lifecycle-gate-worker-command.log`,
`/tmp/lifecycle-gate-recovery.log`, `/tmp/lifecycle-gate-containment.log` and
`/tmp/lifecycle-gate-production-operator.log`.
All **20 backend stack-isolation checks** pass in **43.373 seconds**. Evidence:
`var/test-runs/lifecycle-stack-isolation` and
`/tmp/lifecycle-gate-stack-isolation.log`. The reviewed lifecycle and outage scope
is accepted. Shared web/worker media control was open at this checkpoint and is
delivered below. Live-provider and regional qualification retain their external
prerequisites.

- [x] Verify the two-command deployment contract end to end: web owns delivery and
  client connections; worker owns consumers, scheduler, outbox relay, media execution,
  control services, and child cleanup. No competing supervisor or hidden web pool.
- [x] Use validated routes/resource classes with a handler, independent JSON codec,
  retry policy, and unique consumer identity for every asynchronous message type.
- [x] Reserve responsive control capacity independently of catalog/metadata/media
  backlog. Bound CPU, RAM, device slots, restarts, and shutdown for entire child trees.
- [x] Make autoscaling use measured backlog age, job duration, utilization, and
  admission budgets with stable scale-up/down behavior and measured default profiles.
- [x] Exercise leases, scheduler occurrences, process retirement, orphan cleanup,
  deployment recovery, and transport outages. Do not inherit live Doctrine
  connections across forks or depend on a running HTTP server to supervise workers.
- [x] Qualify shared web/worker media control: leased ownership, ordered revisions,
  session generations, stale-segment exclusion, cancellation under saturation,
  seek/pause/resume/reconnect, and no duplicate encoders on client reattachment.
- [x] Define and qualify latest-target behavior for rapid native scrubbing.
  Consecutive native targets settle at the latest target while ordered pause/resume
  intent and stale-generation fences remain enforced. See the
  [delivery checkpoint](#rapid-native-scrubbing-2026-10-19).

#### Shared web/worker media control (2026-10-19)

Implementation on `plan/shared-media-control-qualification` preserves seek/progress
fences and retry identities through HTTP and WebSocket position controls.
Acknowledgements describe command handling, without claiming worker observation
or mutation after a rejected fence. A paused native seek could wait for a missing
segment without emitting `seeked`, preventing the seek command itself. The player
now submits the fenced command on `seeking`; `seeked` completes restoration.
During seeking, only a rendition refresh for the execution already attached to the
media element is deferred; a newly ready generation must attach. The
[reviewed plan](docs/plans/2026-10-18-1822-test-shared-media-control-qualification-plan.md)
records the qualification scope.

The final CI-image backend run passes **10,062 tests in 946 files / 82,056
assertions** in **919.656 seconds**, with no failures, errors or skips and verified
finalization/cleanup. Lane counts (tests / assertions) and lane wall times are ordinary
**6,430 / 25,808** (**66.7 seconds**), native **724 / 8,173** (**117.0 seconds**),
and PostgreSQL **1,680 / 23,166** (**698.1 seconds**) and **1,228 / 24,909**
(**521.7 seconds**). Lane wall times include setup and report collection; PHPUnit
reports its own test duration separately. Evidence:
`var/test-runs/media-backend-final-20261019/run.log` and
`var/test-runs/20261019-174024-880d5c39`, including `verified-absence.json` with
empty container, network and volume lists. Focused adapter verification also passes
**163 tests / 4,304 assertions** in **82.751 seconds** (`var/test-runs/20261018-182616-dfede000`).

The production-derived pressure drill passes at three occupied video slots and
one audio slot, ordinary maximum two and execution CPU pressure. Its **63.062875
seconds** of pressure (**70.816-second** media phase) yield **162 control
acknowledgements**, p99 **0.282443541 seconds** / maximum **0.285865401 seconds**.
The **1,223 heartbeat samples** yield **0.010419372 / 0.018601489 seconds**; both
meet the **one-second p99 / two-second maximum** budgets. Seek/cancel handling is
**0.019852651 / 0.029492010 seconds**, within two seconds; seek readiness and exact
cancelled-encoder retirement are **19.173580421 / 0.241075307 seconds**, each within
its thirty-second watchdog. The run records **750 full-occupancy / 53
post-cancellation samples**, **125.377477 execution CPU seconds** and **2,031
ordinary backlog acknowledgements**.

Four running and four paused reattachments retain one reference and one attempt
at generation **2**; paused seek advances **2 → 3**. Two old files remain present
while new init/segment requests return **404**. Five native launches show no
duplicate attempts or overlapping predecessors. Independent owners, socket
reconnect/replay, terminal cancellation, later deliberate admission and pending
cancellation without a launch pass. Evidence and verified cleanup:
`var/test-runs/media-control-third/{qualification.json,media.json,cleanup.json}`.
All **20 stack-isolation checks** pass, with no error or cleanup errors
(`var/test-runs/media-stack-isolation-20261019/results.json`); no elapsed time was
recorded. The native-HLS browser drill passes all **eight phases** and **eleven client
controls** with the canonical session/job retained. Paused native seeks take
**14.516 / 12.580 seconds**, reconnect **1.405 seconds** and paused remount **3.819
seconds**, each within the 45-second playback watchdog. Browser generations advance
**3 → 4 → 5**, operator seek reaches paused generation **6** at **30 seconds**, and
worker recovery reaches paused generation **7** at the same position. The **15
native launches** have no duplicate generations or overlapping predecessors.
Initial attachment records four attachments/one session and **679,592 decoded
bytes**. Paused seek (**12.704-second** phase) rejects the retained initial init/media
URLs with **404**; recovery (**10.517-second** phase) retains two generation-6 files,
rejects their init/media URLs with **404**, and decodes **692,610 bytes** from
generation **7**. Both containment checks retire **seven identities** with no
survivors. All phases, launch, containment and verified cleanup receipts are under
`var/test-runs/media-browser-prepared-20261019/`. Shared media control is accepted
within the single-intention scope above; rapid native scrubbing remains open.

The revised player passes **39 hook tests**, including regressions that failed
before the seek-dispatch and ready-generation attachment repairs. Final frontend
commands pass: tests **1.835 seconds** (Vitest reports **1.12 seconds**), typecheck
**18.648 seconds**, lint **21.568 seconds** (zero errors, seven standing warnings),
build **1.29 seconds**. Evidence: `var/test-runs/media-player-fix-20261019/red.log`
and `var/test-runs/media-player-prepared-20261019/`. Composer platform requirements
(**0.4 seconds**), Deptrac (**5.3 seconds**), container lint (**2.1 seconds**) and
OpenAPI drift (**2.3 seconds**) pass; PHPStan passes after fixture PHPDoc
corrections in **58.36 seconds**. Static receipts and verified cleanup:
`var/test-runs/media-final-static-20261019/results.json` and
`var/test-runs/media-phpstan-final-20261019/results.json`.

Three final player simplification reviewers covered reuse, quality and efficiency.
They removed one redundant `seeked` conditional; reuse and efficiency had no
findings. The final frontend checks above follow that change and the corrective
attached-execution guard. Earlier simplification of the pressure fixture removed
unused monitor history. Earlier eight-lens code reviews
(`media-20261018-184128`, `media-final-20261018-185122`) reported no actionable
findings in those snapshots. Review `20261019-174338-b9e53067` covers the revised
player; its rapid-scrubbing finding was adjudicated as a residual interaction risk,
with no actionable repair established within the fenced, no-client-retry scope.
Receipts: `var/test-runs/media-player-final-20261019/review/`. Independent validation
of the later attached-execution correction is clean
(`var/test-runs/media-player-prepared-20261019/independent-validation.json`).
Consecutive native
seek targets can carry a generation retired by the first accepted seek; earlier
dispatch increases exposure. Latest-target rapid scrubbing remains a distinct
open item above.

Earlier attempts exposed the adapter regressions, incompatible browser image mode
and the final-seek failure. Desktop shutdown interrupted the 2026-10-18 backend
run; it remains failed. Recovery removed only its **nine stopped containers, two
networks and four volumes**, with cleanup verified in
`var/test-runs/20261018-184909-d70fe443/recovery-cleanup.json`. Earlier temporary
frontend and review logs were lost; the final results above use retained evidence.
On 2026-10-18, all **69 Python runner regressions** passed in **0.757 seconds**,
and **23 cleanup/final-drill/phase-receipt cases (16 + 2 + 5)** passed. Those harness
scripts are unchanged; their temporary logs were lost, so these outcomes are
recorded from the session without retained artifact links.

Held audio proves occupied capacity, without measuring continuous throughput.
Subtitle authority, readiness-wait revalidation and stale-lease/successor publication
have deterministic backend coverage; a response opened before retirement may
finish. The historical segmenter plan's web-owned process/Swoole-table topology is
superseded by the paired deployment. Cache/sweep, measured FLAC DSP CPU,
hardware/HDR and DASH/ABR acceptance remain open within their existing plans.
Current work selection is recorded in [Resume here](#resume-here); live-provider,
regional and S3 destination acceptance retain their external prerequisites.

Related detailed media plans remain part of the roadmap until reconciled:
[long-process segmenter](docs/plans/2026-07-16-001-refactor-long-process-segmenter-plan.md),
[transcode cache/sweep end-to-end](docs/plans/2026-07-16-002-feat-transcode-cache-sweep-e2e-plan.md),
and [FLAC/DSP CPU work](docs/plans/2026-07-08-001-perf-flac-dsp-cpu-plan.md).
Existing implementation must be evaluated against their behavioral and resource
contracts; neither old prose nor the current branch name establishes completion.

#### Rapid native scrubbing (2026-10-19)

Implementation on `plan/rapid-native-scrubbing` follows the
[reviewed plan](docs/plans/2026-10-19-rapid-native-scrubbing-plan.md).
Consecutive native seek gestures retain the latest target while an earlier seek
replaces the attached execution generation. The player combines consecutive
queued seek targets while keeping pause and resume commands in order. It
dispatches the latest target using only the generation returned by its preceding
freshly accepted seek. Same-session polling cannot replace the attachment during
that chain. Session, track and component replacement invalidate pending intent;
generation fences and the latest pause/resume intent remain enforced.

A fresh accepted seek returns its resulting generation from the existing receipt
transaction through the synchronous handler and HTTP response. Stale, replayed,
terminal and otherwise unproven operations supply no continuation authority.
Stored receipt and schema formats are unchanged. No retry framework or new browser
runner was added. Local implementation commits: `98f7df17`, `014809bb`, `b4e97aec`.

Verification:

- The affected CI-image backend selection passes **207 tests / 4,769 assertions**
  in **104.5 seconds**, using `baander-ci-lifecycle:local`. Artifacts:
  `var/test-runs/20261019-193527-cef524ef`. The historical
  **10,062 tests / 82,056 assertions** full backend checkpoint above was not rerun
  for this change.
- Full PHPStan passes with **zero errors**; Deptrac passes with **zero violations**.
  Container lint, OpenAPI freshness, Orval generation and `generate:check` pass.
- The player hook passes **43 tests** in **1.17 seconds**: the existing **39**
  cases, **two** parameterized source-replacement/queued-pause cases, an
  A/pause/C ordering regression and a final-acknowledgement/reconciliation case.
  The initial regressions failed before their respective repairs; the final
  reconciliation case was added after the repair.
  Full lint passes with **zero errors and seven standing warnings**. Build
  including typecheck passes; Vite's build portion takes **0.753 seconds**.
- Three simplification reviewers covered reuse, quality and efficiency. Reuse of
  the shared generated response type was applied; no other findings remained.
  Focused verification logs: `var/test-runs/rapid-native-scrubbing-20261019/`.
- Independent code review identified a queued-status ordering error and raised
  the interval between the final acknowledgement and fresh read for verification.
  Both are resolved in `b4e97aec`; fresh independent review found no new defects
  in that correction. The final source simplification pass is clean. Receipts:
  `var/test-runs/rapid-native-scrubbing-20261019/review/` and
  `var/test-runs/rapid-native-scrubbing-20261019/review-resolution.json`.
- The final existing native-HLS browser drill passes all **eight phases** and
  **eleven client controls**, exit **0**. With the first real HTTP response held,
  native targets **22 → 26 → 30 seconds** send only **22** and **30**; the first
  response supplies accepted generation **4**. The canonical session/job reaches
  paused position **30** at generation **5**, then resumes decoding. The rapid
  seek takes **14.511 seconds**. Results:
  `var/test-runs/rapid-native-scrubbing-browser-final-20261019/browser/result.json`.
  Its `cleanup.json` records `originalExitStatus: 0`, `cleanupExitStatus: 0` and
  `cleanupVerified: true`.

The latest-target rapid native scrubbing item is delivered locally and closes the
interaction gap left open at the shared media-control checkpoint above. Select
subsequent work by the rule in [Resume here](#resume-here); deferred release
acceptance retains its existing prerequisites.

### Desktop renderer security (2026-10-19)

The desktop change follows the
[reviewed plan](docs/plans/2026-10-19-desktop-renderer-security-plan.md) and enforces
one renderer trust policy for navigation and privileged IPC. Only live,
app-owned main frames at the exact configured Vite origin or the resolved main and
configuration HTML files may invoke handlers. Backend URLs remain API destinations;
they do not grant renderer privileges. Guards attach before loading either startup
window and deny untrusted navigation, redirects, frames and renderer-created windows.

Every registered configuration, keyring, discovery, deep-link and window handler
checks its sender before service access. Payload validation precedes configuration,
CORS, keyring, theme and native-window effects. Credential deletion now supplies the
username required by the existing operation. Failed configuration saves keep setup
open. First-run handoff and configured startup share live-main-window registration,
so titlebar actions and media shortcuts target the current window.

Verification checkpoint:

- **14 focused tests** pass in **300.427 ms** in the frontend CI image built from
  `docker/runner/Dockerfile`, tagged `baander-runner:desktop-verification`. These
  regressions cover the concrete trust, IPC and lifecycle fixes.
- Actual main, preload and renderer TypeScript checks pass in that image. The
  desktop script checks all three projects directly; frontend CI requires those
  checks and the focused suite. Narrow desktop compiler corrections make the
  previously ineffective references-only check usable.
- The local desktop build passes in **20.35 seconds** after correcting asset paths.
  Real Electron startup initially exposed a missing WASM-hash artifact and
  file-based routing that navigated away from the trusted HTML entrypoint. The build now generates
  all six WASM hashes from the asset source, verified against the bundled bytes.
  File-based UI uses hash routing while HTTP UI retains browser routing. A clean
  Vite development startup also passes.
- The local Xvfb/Electron smoke passes **four launches in 6.50 seconds**: first-run
  setup and configured restart for both built files and the exact development
  origin. Each verifies a visible login page, local API requests, reload, denied
  external navigation/popups and native titlebar IPC. The development origin is
  served by a local static SPA fixture, not a running Vite process. Xvfb has no
  window manager: focused fixtures prove maximize state transitions; the native
  check proves dispatch and a boolean state response. Physical media keys are not
  exercised, and the user's keyring is untouched.
- Workflow YAML parsing and whitespace checks pass. Evidence:
  `var/test-runs/desktop-renderer-security-20261019/` contains `runtime.log`,
  `ci.log`, `web.log`, `axios.log`, `verification.json` and `review.json`.

Independent code review found two issues: clean development startup needed WASM
hashes from the asset source, and an unused, unguarded menu IPC listener remained.
Both are corrected. Runtime investigation also found that file-origin API path
resolution dropped DPoP headers. The correction has a failing-before/passing-after
regression; the affected Axios suite passes **12 tests in 875 ms**. Final web and
worker TypeScript checks and affected-file ESLint pass in the CI image after these
corrections. No review findings remain unresolved.

Electron version support, CSP redesign, main-window sandbox migration and the
configuration page's discovery-result HTML interpolation remain outside this
patch's acceptance scope. React Native and Android remain deferred. No release
certification follows from the desktop checkpoint.

The discovery-result interpolation deferred above is addressed by the later
[desktop discovery rendering checkpoint](#desktop-discovery-rendering-2026-10-19).

### Desktop discovery rendering (2026-10-19)

The desktop discovery change follows the
[reviewed plan](docs/plans/2026-10-19-desktop-discovery-rendering-plan.md).
Discovered server rows use DOM elements and text nodes for the advertised name,
URL and version. A row listener captures the accepted URL for selection; selecting
a row fills the existing input and leaves saving to the user.

Discovery receipt accepts only JSON objects with string name, URL, version, API
version and timestamp fields, and an absolute HTTP(S) URL with a hostname. Local
HTTP servers, IP literals, ports and paths remain supported. Invalid announcements
leave the collection unchanged and are ignored without emitting an unhandled
error; later valid responses still work. Discovery advertisements remain
unauthenticated, and this patch does not change broadcast targets or timers.

Verification checkpoint:

- The real first-run renderer regression failed before the fix: crafted discovery
  metadata created an injected image element instead of literal text.
- The frontend CI image built from `docker/runner/Dockerfile`, tagged
  `baander-runner:desktop-verification`, passes **37 Node tests in 303.287 ms**:
  the 14 existing cases and 23 focused discovery cases. A fake datagram socket
  proves accepted response shapes, invalid-input rejection and later recovery.
- All three actual desktop TypeScript projects pass in that image. The desktop
  build passes in **19.93 seconds**. Whitespace checks pass; the desktop workspace
  has no lint script.
- The native Electron smoke passes **four launches in 6.69 seconds**. Built-file
  and development-origin first-run windows display crafted metadata literally,
  create no injected elements or attributes, fill the selected URL without saving,
  and preserve repeat rows and clearing. Existing startup, API, navigation and
  titlebar checks remain green. The renderer receives crafted events through main
  IPC; fake-socket service regressions cover UDP receipt separately. No real UDP
  broadcast is used. Evidence:
  `var/test-runs/desktop-discovery-rendering-20261019/`.
- Simplification review found no changes needed. Independent code review
  completed with zero findings.

### Store timeline navigation (2026-10-19)

The recorded store timeline now has an event cursor and read-only inspection,
following the
[reviewed plan](docs/plans/2026-10-19-store-timeline-navigation-plan.md). Developers
can select a retained event and move through the filtered order. An update shows
its own captured before and after values, completeness and existing redaction or
truncation markers. Actions and completions report that they have no state capture.

New live events preserve the selected event ID. Filtering it out, clearing the
recorder or evicting the event removes selection. Navigation does not change live
stores or trigger effects, and missing captures are never replaced with another
event's values or the latest store map. Schema v1 has no store registration epoch
or historical baseline; general multi-store reconstruction and replay remain open.

Verification checkpoint:

- In the frontend CI image built from `docker/runner/Dockerfile`, tagged
  `baander-runner:desktop-verification`, the focused selection passes **16 tests
  in 1.58 seconds**: five timeline cases, including four new cases, and eleven
  existing recorder cases. The full web suite passed **2,104 tests in 186 files
  in 33.21 seconds** before behavior-preserving simplification; the focused and
  browser checks passed again afterward. The full suite was not repeated.
- The extended debugger browser case passes **1/1 in 3.1 seconds**, through the
  disposable local `debug.baander.app` HTTPS fixture. Desktop **1280×900** and
  narrow **390×844** views show no document or capture overflow. It uses existing
  Playwright tooling because the `agent-browser` CLI is unavailable.
- Final web and worker TypeScript checks, affected-file ESLint and the Vite build
  pass; the build takes **701 ms**. Full ESLint passed before simplification with
  zero errors and seven existing warnings; the final affected-file check has zero
  errors and warnings. Independent code review completed with zero findings or
  testing gaps. Evidence:
  `var/test-runs/store-timeline-navigation-20261019/`.
- The adjacent release-image reconciliation uses existing source: `Dockerfile`
  places source, local packages and patches before Composer; the production
  container runner checks defaults, and CI builds and invokes that runner. No new
  release-image build or runtime test was performed for this reconciliation.
  Application backup restoration remains deferred.

### Registry release and growth

The local contracts listed below are implemented and covered. The entries
reconcile existing source and recorded runs; checking an entry records that
evidence, not a new test run. The deferred requirements remain release blockers
within their stated scope.

- [x] Public API retains registration fields `publicId`, `url`, `name`, `version`,
  `apiKey` and recognizable success envelopes. HTTPS hostname URLs exclude embedded
  credentials; bodies are bounded at 8 KiB, depth eight, IDs/names 128 characters,
  versions 64 and URLs 2,048. Malformed/invalid/forbidden/oversize/throttled/
  unavailable statuses are 400/422/403/413/429/503. Evidence:
  [HTTP contract](relay/tests/run_http_contract.py) and
  [core tests](relay/tests/core_test.cpp).
- [x] `schema_migrations` checksums and one credential-owned `registries` table
  preserve ownership, revisions and reserved offline identities. Random 256-bit
  credentials are stored only as SHA-256 digests and never logged/returned; ten
  minutes offline does not release ownership. Parameterized SQL and statement
  errors are covered by [core tests](relay/tests/core_test.cpp),
  [security tests](relay/tests/security_test.cpp) and the HTTP contract.
- [x] Authoritative lookups use explicit linearizable reads; registration is
  acknowledged after commit. Quorum loss returns 503 with `Retry-After: 1`, while
  liveness remains available. Never downgrade consistency to serve a result.
  Concurrent claims, wrong-owner refusal, a real
  acknowledged write with a lost response, owner retry, 3–2 partitions, total
  quorum loss, leader recovery and rejoin are covered by the recorded HTTP and
  three/five-voter contracts in [C++ registry](#c-registry).
- [x] Stale rows and false local absence are proven on every minority voter in
  three- and five-voter partitions, with public rejection and exact convergence
  after healing. See [stale-follower verification](#stale-follower-verification-2026-10-19).
- [x] Real bounded filesystem exhaustion returns public unavailability and recovers
  exact acknowledged rows. See the
  [full-disk checkpoint](#registry-disk-exhaustion-qualification-2026-10-19) for
  voter `ENOSPC` evidence and its storage-layer limits.
- [x] Authenticated SQL/TLS transport rejects hostname mismatch, untrusted server
  and client certificates, expired server certificates and legacy TLS, then
  recovers. The [transport contract](relay/tests/run_transport_contract.py) and
  HTTP contract also cover connection/deadline recovery and graceful shutdown.
- [x] CI requires warnings-as-errors release builds, unit/API/three-voter tests,
  ASan/LSan/UBSan, separate TSan, parser fuzzing, static analysis, direct dependency
  notices and five-voter production-container qualification before publication.
  Container checks cover bootstrap refusal, persistent acknowledgements, rolling
  restart/replacement and shutdown. Local isolated binary restore and bounded
  optimized load-smoke results are recorded above. Evidence:
  [native runner](scripts/test-registry.sh),
  [container contract](relay/tests/run_container_contract.py) and
  [CI workflow](.forgejo/workflows/ci.yaml).
- [x] Implement encrypted, versioned five-minute S3-compatible backup automation
  with a separately held private recovery identity. The
  [delivery checkpoint](#registry-encrypted-backups-2026-10-19) records packaged
  encrypted upload and fresh-voter offline restore through disposable TLS S3
  storage. Actual-destination and RPO/RTO qualification remain deferred.

Remaining release implementation, selected when it advances a named release
milestone:

- [ ] Add fixed-runner baseline comparison and enforce confirmed regressions above
  10%, once the stable runner and accepted baseline are selected. The bounded
  optimized load smoke already exists.
- [ ] Complete the packaged transitive dependency/license inventory. First-party
  declarations and seven pinned direct dependency notices are already checked.
- [ ] Add scheduled nightly five-voter partition/failover, parser-fuzz and
  isolated-restore orchestration using the existing runners. Push/PR CI and local
  five-voter results do not establish a nightly schedule.

Deferred measurements and rehearsals, requiring an agreed environment or access:

- [ ] Qualify the agreed five-host optimized deployment budget, including
  snapshots, compaction, node catch-up and backup upload. Each whole host remains
  limited to 1 CPU and 512 MiB, with the full latency, burst, recovery and memory
  thresholds in [C++ registry](#c-registry). Optimized deployment binaries must
  meet those limits; sanitizer workers may be larger.
  Select this work when the target hosts or an explicit equivalent measurement
  environment and release decision are available.
- [ ] Select the stable performance runner and record an accepted baseline for
  regression comparison. Existing bounded load-smoke results do not establish it.
- [ ] Run the 24-hour workload soak on the intended deployment profile. After
  warm-up compare equivalent quiescent windows; fail sustained RSS growth over
  1 MiB/hour or 10% of baseline. The soak requires a reserved run window and
  recorded profile; short local runs do not complete it.
- [ ] Qualify encrypted, versioned five-minute S3-compatible backups and rehearse
  isolated restoration with five-minute RPO and 30-minute RTO. Local fresh-storage
  binary restore and encrypted upload/offline restore through disposable TLS S3
  storage are covered; this rehearsal needs actual-destination access.
- [ ] Validate the actual regional five-voter deployment and upgrade voters one at
  a time while preserving volumes and bootstrap guards. Measure the regional
  topology and verify two-voter loss without acknowledged-data loss and leader
  recovery within 30 seconds. Local five-voter failure/rejoin and rolling restart
  are covered; actual host access and inventory remain unavailable.

Future growth remains conditional: expand APIs from five toward twenty only when
measurements justify it; retain five voters, optionally use non-voting replicas,
and route authoritative reads to the leader. Region changes require membership
and runbook updates. One/two-host emergency operation never permits unsafe
automatic quorum reduction. This is not the current implementation priority.

### Secondary clients, native qualification, and release operations

- [x] Require shared TypeScript checks in frontend CI; the existing shared gate
  remains required. Mobile work remains deferred at the user's request.
- [x] Enforce desktop renderer trust and IPC validation across both startup paths,
  with working titlebar actions and live media targets. Focused, static and real
  Electron checks pass; see
  [desktop renderer security](#desktop-renderer-security-2026-10-19).
- [ ] When the user resumes deferred React Native work, reconcile the [React Native styling plan](docs/plans/2026-07-15-001-refactor-rn-styling-pattern-plan.md)
  and [React Native/TV tooling plan](docs/plans/2026-07-15-002-chore-rn-tv-dx-tooling-plan.md)
  against current code after backend/web priorities are satisfied.
- [ ] Make codec/DSP builds reproducible and run reference vectors before claiming
  native paths release-certified. Track remaining loudness/codec qualification in
  [DSP qualification](packages/dsp/README.md); browser graph tests alone are insufficient.
- [x] Qualify fresh application installation, refusal of unknown histories and
  repeat no-op migration on disposable PostgreSQL. See
  [migration consolidation](#doctrine-migration-consolidation-2026-10-17).
  Worker/container startup and health checks retain their recorded scope.
- [x] Reconcile release-image dependency ordering and production defaults against
  existing implementation and CI. `Dockerfile` copies application source, local
  packages and patches before Composer; the production worker container check
  validates production defaults and runs in CI. This source reconciliation adds
  no image build or test run; see the
  [timeline delivery checkpoint](#store-timeline-navigation-2026-10-19).
- [ ] Rehearse application backup restoration before migration rollout. This
  deferred release prerequisite does not reopen covered migration or runtime
  behavior.
- [x] Align first-party manifests/documentation to Apache-2.0 while retaining
  vendored third-party notices. See [license alignment](#first-party-license-alignment)
  and the Registry direct-license gate above. Packaged transitive-license
  qualification remains a release prerequisite.
- [ ] Cut the first production release only after its acceptance evidence is recorded.
  Remove the temporary pre-release AGENTS.md policy only on the user's explicit
  announcement, then establish real compatibility/migration commitments from there.

### Developer tooling after the first debugger

- [x] Keep trace records versioned and exportable, with bounded recording and visible
  gaps/truncation/redaction, and snapshots detached from live references. Delivered
  in the [debugger foundation](#completed-foundation-web-stores-and-developer-tooling);
  preserve these invariants when that feature changes.
- [ ] Define replay scope explicitly: pure state transitions versus asynchronous
  completion, external input, network effects, native media, and nonserializable
  objects. Record enough provenance for deterministic supported cases.
- [x] Add timeline navigation and read-only inspection of the selected update's
  own captured before and after values. See
  [store timeline navigation](#store-timeline-navigation-2026-10-19).
- [ ] Define general multi-store historical reconstruction before live replay.
  Selected-event inspection does not reconstruct other stores or fill gaps from
  newer captures. Never execute stored functions or silently replay external
  effects from imported traces.
- [ ] Add deterministic replay/reference tests and a version-upgrade policy when actual
  replay is implemented. A diagnostic timeline is not yet an event-sourced application.
- [x] Retain disabled instrumentation and unrelated-render performance gates. Their
  [foundation checkpoint](#completed-foundation-web-stores-and-developer-tooling)
  records the existing checks; reuse them when debugger/store APIs change. Enabled
  tracing overhead is explicitly permitted.

## Completion and next updates

[Resume here](#resume-here) selects current work. Historical limits and deferred
release acceptance do not authorize an expanding test programme. Before edits,
assess symbol impact through source searches, caller inspection and the relevant
test suites. Before
commits, inspect the actual scope with `git status` and `git diff --stat`, including
unindexed test callbacks, and commit by path.
Keep independent changes reviewable and record checks without implying broader
coverage. The complete programme is done only when blocking checks, security and
workflow integrations, and registry consistency/resource/failover/restore gates pass.

## API contract reconciliation

This is the historical schema-only checkpoint. Its retained UUID API contracts
are superseded by the [public-identity correction](#public-api-identities-2026-10-17),
delivered locally on 2026-10-17. The measurements below describe the earlier tree.

The old 24-path finding predates the delivered route-coverage and drift gates;
current routing and the regenerated specification agree on 244 paths and 313
operations. The specification still has 126 component schemas, with 16 corrected
schemas and no added or removed operation.

- NanoID declarations now match `PublicId`'s 21-character alphabet across Activity,
  Auth, Discovery, Party, Transcode and lyrics application. Discovery's inline and
  path schemas match its request DTOs. Recent-item nullability is preserved, and
  unused PartyMember/TranscodeJob resource declarations have direct tests.
- Genuine UUID contracts remain UUIDs, including passkeys whose field is named
  `publicId`. Playlist add/reorder examples now use song UUIDs. Song `albumId`
  documents its existing public-ID-or-UUID behavior; no runtime identifier,
  routing, persistence or authentication policy changed.
- `openapi.json` and the Orval web client were regenerated together. The embedded
  remix consumer now reads the direct transcode creation resource. Its regression
  failed before the fix and now checks the returned UUID, unchanged request and
  HTTP-failure behavior.
- Canonical API, glossary, shared-kernel, Party/Shared context and export-command
  guides now describe the tracked specification, endpoint-specific identifier and
  envelope contracts, current routes and export checking. Existing refresh grace
  and logout/revocation semantics are documented from their implementation. An
  invalid cursor example is replaced with an explicit opaque placeholder.

Verification in `baander-ci-prewarm:local`, PHP 8.5.2:

- The affected backend selection passed 124 tests / 2,279 assertions across nine
  files in 104.9 seconds, including setup, report validation and cleanup. It covers
  identifier schemas, the value object, export failures, bidirectional route
  coverage, Discovery/Playlist/Lyrics controllers and publication drift gates.
  Artifacts: `var/test-runs/20261015-174147-90abf5f2`.
- Web tests passed 2,032 cases across 184 files; embedded tests passed 161 cases
  across 11 files, including the two new remix regressions. Regeneration parity
  passed against the regenerated artifact.
- Web and embedded typechecks passed. Web lint passed with zero errors and the
  seven standing warnings; the production build and Symfony/Vite manifest,
  application-asset and auth-worker verification passed.
- Platform requirements, PHPStan, Deptrac (zero violations, warnings or errors;
  495 existing baseline entries), container lint and OpenAPI export parity passed.
  Static logs: `/tmp/baander-paratest-static-qocqaf03`. All disposable containers,
  networks and volumes were removed.
- Independent plan/code review and simplification completed. The export-scope
  and cursor-example findings were applied; prose-fix audits, JSON examples,
  relative links and whitespace checks passed. Source inspection supplied the
  impact evidence.

React Native and Android remain deferred. Their hand-written clients still use
obsolete album-track, playlist-method/song/reorder and session endpoints; this
checkpoint does not qualify those clients. The current checks cover the corrected
contracts and existing route inventory, not every response field or a live
Forgejo run.

## Messenger without Swoole

`BAANDER_TEST_DISABLE_SWOOLE=1` removes only the dedicated loader inside each
disposable app container before PHP discovery or boot. The coordinator records
PHP version, configuration hashes, extensions and native symbols before and after
removal; every other extension and setting must remain unchanged. CI has a blocking
selection for the absence fixture, handler registration and real Redis delivery.

Qualification exposed unconditional coroutine identity queries during console
boot, used by both the container mutex and service pools. The bundle bridge now
returns the existing synchronous context ID `-1` when Swoole is absent. Native
thread/coroutine identity behavior and kernel initialization remain unchanged.

- The absence fixture failed before the bridge fix: 4 tests, 7 assertions and
  3 errors at the native coroutine lookup. It now checks worker/child absence,
  both identity methods, recursive mutex ownership and service pool reuse.
- CI-image qualification passed 32 tests / 236 assertions in 65.2 seconds,
  including setup, identity validation and cleanup. PHP 8.5.2 ran the same
  checkout and dependencies as normal verification. Discovery and all three
  lanes lost exactly the Swoole extension and retained the other configuration
  hashes. Console cache clearing, fresh and repeat migrations, actual Redis
  consumption, retry/dead-letter handling and transport registration passed.
  Artifacts: `var/test-runs/20261015-172557-d06191de`.
- The normal Swoole runtime passed 91 tests / 521 assertions in 68.9 seconds
  across the eight affected runner, publication, kernel/container, pool release,
  coroutine scheduling, handler registration and Redis delivery files. Artifacts:
  `var/test-runs/20261015-172725-9df9f124`. Both successful runs removed their
  disposable resources; the failed diagnostic runs did too.
- All 64 Python runner regressions passed. Independent plan and code reviews,
  including the shared execution-context correction, found no remaining issues.
- Platform requirements, PHPStan, Deptrac (zero violations, warnings or errors;
  495 existing baseline entries), container lint and OpenAPI drift passed in the
  CI image. Static logs: `/tmp/baander-paratest-static-khhlniez`. Prose-fix and
  whitespace checks passed; source and caller inspection established edit impact.

This qualifies the exercised console/Messenger paths with actual extension
absence. The web server still requires Swoole; no dependency or API/schema changed.
The full-suite coverage baseline remains the prior ParaTest checkpoint; this
follow-up reran the affected selection and static gates. The blocking Forgejo step
is configured, but no live Forgejo result is claimed.

## Parallel backend verification

Branch `plan/parallel-test-execution` delivered the shared coordinator, explicit
native manifest, identity checks, coverage merging and blocking report validator.
Local wrappers and CI use this coordinator with disposable Compose projects.
The checkpoints below record qualification, corrections and final verification.

- Runner regressions: 21 PHP tests / 53 assertions and 41 Python tests passed.
  A real CI-image coverage smoke preserved the uncovered denominator of 43,704
  executable lines. Independent plan and code reviews were applied, including
  configuration-path preservation and cleanup failure propagation.
- Isolation: the first Docker probe passed 20 checks for database disruption,
  Redis pause/flush, fixed cache/socket paths and nested-process DSNs. Its
  simplification now uses the shipped coordinator topology and passed all 20
  checks again. Timeout and SIGTERM drills returned failure and removed every
  recorded container and network without cleanup errors.
- The sequential reference run exposed an existing user timestamp persistence
  defect. The user authorized its focused fix: repository synchronization copies
  both aggregate timestamps after the entity setters. The deterministic PostgreSQL
  regression failed before the fix (2 tests / 2 failures). It and the existing CLI
  parity suite pass afterward: 9 tests / 133 assertions in
  `baander-ci-prewarm:local`, PHP 8.5.2, 42.6 seconds including setup and cleanup.
  Independent review found no issues; no schema, API shape or dependency changed.
- First full parallel run: 9,096 tests / 74,276 assertions, 869 files, no failures
  or missing identities; cleanup passed. Suite counts: Unit 6,270,
  StaticAnalysisRules 11, Functional 1,460, Integration 1,355. Runtime:
  `baander-ci-prewarm:local`, PHP 8.5.2, ParaTest 7.22.4, PHPUnit 13.1.11, seed
  3742. Total elapsed time was 724.1 seconds, including setup, report validation
  and cleanup. Artifacts: `var/test-runs/20261014-185722-ae5ac728`.
- The next reference attempt exposed a fixture leak: ordinary email-verification
  requests left Redis limiter buckets behind, and random IP reuse could collide
  with a later exhaustion assertion. The fixture now allocates deterministic IPs
  and resets every tracked bucket. The new lifecycle regression and neighboring
  rate-limit suites pass: 49 tests / 451 assertions, 50.7 seconds including setup
  and cleanup. Artifacts: `var/test-runs/20261014-191936-b5140c11`.
- Static checkpoint: platform requirements, PHPStan, Deptrac (zero violations,
  warnings or errors), container lint and OpenAPI drift all passed in the CI image.
  Docker build-context checks and all 41 Python runner regressions also passed.
  Independent review accepted the rate-limit fixture fix.
- The full parallel rerun after the limiter fix passed 9,097 tests / 74,304
  assertions across 869 files in 731.2 seconds. All discovered identities were
  observed once and cleanup passed. Artifacts:
  `var/test-runs/20261014-192156-b53cbe92`.
- Qualification comparison reads exact executable and covered lines from
  hash-validated serialized artifacts: Clover can omit statement rows that overlap
  method rows. Ten new comparator regressions and the real PHP serialization
  check pass; all 51 Python runner regressions pass. Independent review accepted
  the correction.
- The completed sequential reference passed the same 9,097 selected/completed
  identities in 1,070.6 seconds. The direct-Docker parallel run took 731.2 seconds
  (1.46× speedup). Reference artifacts:
  `var/test-runs/20261014-193447-79b5e1b5`. Coverage comparison is still pending.
- Infrastructure direction: the user selected a dedicated test Compose file with
  disposable projects. Service lanes now declare app/PostgreSQL/Redis in
  `compose.test.yaml`, with project-owned networks and volumes. Cleanup includes
  named and anonymous volumes. Compose configuration and all 58 Python regressions
  pass. Real Compose isolation passed all 20 checks and removed six containers,
  two networks and four volumes. Timeout and SIGTERM drills returned the expected
  failure and removed every recorded resource without cleanup errors. Artifacts:
  `/tmp/baander-stack-isolation-pbici7ww`,
  `/tmp/baander-stack-isolation-compose-timeout-_6ave1nj`, and
  `/tmp/baander-stack-isolation-compose-sigterm-j_8netho`. Full Compose benchmark
  qualification remains pending; earlier direct-Docker measurements do not qualify
  this topology. The runner Dockerfile
  now installs and verifies the Compose plugin.
- First full Compose run: 9,097 tests, exact selected/completed equivalence to
  the sequential reference, and successful cleanup in 752.3 seconds (1.42× faster
  than the 1,070.6-second reference). No project containers, networks or volumes
  remain. Artifacts: `var/test-runs/20261014-195442-737ed4d0`.
- Full coverage qualification exposed two runner issues: lane coverage can classify
  executable lines differently, and a service lane exceeded the reporting parent's
  512 MiB limit while serializing coverage. The finalizer and comparator now use
  the installed coverage merger's line classifications; reporting parents receive
  2 GiB while test children retain 512 MiB. The failed run is diagnostic evidence,
  not a qualification pass (`var/test-runs/20261014-200726-2b089c64`).
- The refused-store latency fixture now builds its Symfony kernel before timing
  the request, preserving its five-second bound and response assertions. Focused
  CI-image coverage passed 22 tests / 91 assertions in 250.2 seconds, including
  runner coverage-merging regressions; report validation and cleanup passed.
  Artifacts: `var/test-runs/20261014-204317-35fceb05`. All 58 Python checks pass;
  independent review accepted the coverage and timing corrections.
- Full coverage runs completed successfully with 9,097 tests / 74,304 assertions:
  the sequential reference took 2,594.7 seconds and disposable Compose parallel
  execution took 1,952.8 seconds (24.7% less elapsed time). Both cleaned up all
  recorded resources. Artifacts: `var/test-runs/20261014-204839-0f854e1b` and
  `var/test-runs/20261014-213233-7750f8da`.
- Exact coverage qualification found equal selected/completed identities and equal
  source denominators (1,988 files, 1,720 classes, 8,314 methods, 44,191 executable
  lines), but 29 reference-only covered lines. These were incidental missing-env
  warning branches: ParaTest's Symfony Process launcher promotes Dotenv values from
  `$_ENV` into child process environment variables, while the reference launcher
  inherits only the OS environment. Deterministic health-warning fixtures and a
  fresh comparison are required before default adoption; no coverage exclusions
  or comparison tolerances have been added.
- Health-warning fixtures now control and restore OS environment values explicitly.
  The existing three configuration datasets failed with populated ambient values
  before the fix and pass afterward; a new listener test checks warning details
  and once-only logging. CI-image coverage passed 12 tests / 189 assertions in
  177.2 seconds and covered all 29 previously different lines. Reports and cleanup
  passed; all 58 Python regressions remain green. Artifacts:
  `var/test-runs/20261014-221206-beb170cd`. Full comparison on this corrected
  checkout remains pending.
- Corrected full coverage qualification passed on the same checkout and seed:
  9,098 tests / 74,434 assertions across 870 files. Both runners completed exactly
  the discovered identities and matched all 44,191 executable and 35,385 covered
  lines, with equal source denominators. Validated coverage: lines 80.07%, methods
  76.10%, classes 60.41%. Reference artifacts:
  `var/test-runs/20261014-221624-109eb426`; parallel artifacts:
  `var/test-runs/20261014-225943-f1ae873c`. No test containers, networks or volumes
  remained after either run.
- The coordinator measured 2,534.4 seconds for the reference and 1,965.0 seconds for
  parallel execution with its monotonic clock (1.29×). Wall time diverged by about
  13 hours during the user interruption; available evidence cannot distinguish a
  system suspend from a clock correction. Treat this ratio as monotonic execution
  timing, not an uninterrupted wall-clock benchmark. The earlier uninterrupted
  non-coverage comparison measured 752.3 versus 1,070.6 seconds with exact identity
  parity (1.42×), and the earlier full coverage execution measured 1,952.8 versus
  2,594.7 seconds before the deterministic health fixtures.
- Local wrappers, Makefile test targets and Forgejo CI now use the shared coordinator. Coverage validation
  blocks CI, failed-run logs are uploaded after metric extraction, and runtime
  security drills remain serial. The old sequential sharder, its fixtures and the
  temporary qualification adapter have been removed. The diagnostic runner uses
  `BAANDER_TEST_PROCESSES=1`.
- Final backend verification after adoption passed 9,103 tests / 74,465 assertions
  across 869 files in 728.3 seconds, including setup, validation and cleanup. Suite
  counts: Unit 6,276, StaticAnalysisRules 11, Functional 1,461, Integration 1,355.
  This uninterrupted run used the same CI image and seed 3742. Artifacts:
  `var/test-runs/20261015-124114-0735239d`. The runner regression file passed
  26 tests / 70 assertions; publication workflow checks passed 25 tests / 208
  assertions. All 58 Python checks and Docker context checks (35 excluded host
  artifacts, 24 preserved markers) passed. Makefile filter, suite, path and image
  forwarding passed three focused checks. Independent integration and simplification
  reviews found no remaining issues.
- Final static gates passed in the CI image: Composer platform requirements,
  PHPStan, Deptrac (zero violations, warnings or errors; 495 existing baseline
  skips), container lint and OpenAPI drift. Static artifacts:
  `/tmp/baander-paratest-static-tnmhl7dt`. Final resource checks found no retained
  test containers, networks or volumes. Documentation passed prose-preservation
  review; changed limits, report paths and checkpoint counts are intentional.
- Merged into `master` by fast-forward on 2026-10-15; the verified implementation
  tree is unchanged. The user authorized publishing to both remotes. Remote CI was
  not part of local verification. Its runner image must be rebuilt with the Docker
  Compose plugin before using the new workflow. The recorded wall-clock discrepancy is
  the remaining timing limitation; exact test and coverage qualification passed.

## Repository cleanup (2026-10-15)

Removed 17 completed planning documents from `docs/plans/`. Their delivered scope,
verification checkpoints and remaining findings remain in this roadmap; the
original documents remain in Git history. The temporary cleanup plan was also
removed after review and implementation. Six July plans remain because the worker,
media/DSP and React Native work still has open or deferred acceptance.

References to deleted plans were removed from the roadmap and solution records.
The testing guide now states the Safari follow-up directly; `docs-book/` contains
no plan-document references. Independent classification and final review, deleted
filename/reference checks, prose-preservation audits and whitespace checks verify
the documentation cleanup.

Removed the retired graph-index skill and generated local cache. Maintained rules
and skills now require source/caller inspection and Git diff review directly.
Obsolete packaging and API-documentation exclusions and their fixture assertions
were removed; the other artifact checks remain. The standalone development CLI,
root Bun lockfile, dedicated build/publish workflow and generated-binary ignore
entry were also removed. PHP console commands remain available.

Verification: the synthetic Docker-context check passed with 34 excluded artifacts
and 24 preserved source/asset/license markers. Shell syntax, embedded PHP syntax,
XML and remaining workflow YAML validation passed. Repository scans found no
retired index-tool references, deleted-plan references or development-CLI consumers.
No backend application suite was rerun for these tooling and documentation removals.

Follow-up on 2026-10-17 removed the retired graph-index service registrations
from the Codex and Claude harness configurations, its command allowance, and
Baander's global registry entry. Other projects' registry entries are preserved.
The CLI binary was already absent. TOML and JSON parsing and scans of tracked
source and active harness configuration pass; the project has no active
integration references, cache or skill.

## Doctrine migration consolidation (2026-10-17)

The [reviewed plan](docs/plans/2026-10-17-consolidate-doctrine-migrations.md) replaces
53 Doctrine migrations with `Version20261017000000`. The migration creates the
80-table application schema transactionally and refuses downgrade. Both outbox
integer keys now use `BIGINT GENERATED BY DEFAULT AS IDENTITY`; other schema
semantics are preserved. The legacy comparator and its six tests were removed;
future migrations use timestamp ordering.

The initial migration checks all user namespaces through PostgreSQL catalog
ownership dependencies. Existing objects, including empty tables, collations and
text-search configurations, cause refusal before application DDL. Extension-owned
objects and an empty Doctrine metadata table are allowed. The unknown-history
guard remains; both refusals name the database and require target-specific approval
for a separate manual reset. There is no overwrite option or automatic reset.
Already migrated databases accept no-op runs and future incremental migrations.

The two seeded schedules retain their fixed internal IDs and configuration: OAuth
purging at 03:30 UTC and recommendation generation at 04:00 UTC. Independent public
IDs and revisions are generated, and both schedules derive their timestamps from
one clock sample. UTC arithmetic is independent of the session's daylight-saving
time rules.

Integration and runtime fixtures share the final-schema bootstrap. Isolated
fixtures check their own namespace and reuse extensions provisioned in `public`.
Historical replay tests were removed while current constraint, persistence,
outbox rollback, scheduler and worker concurrency tests remain. Installation,
upgrade, testing and PostgreSQL skill references now describe the consolidated
schema and the separate manual reset policy.

Verification checkpoint:

- Test discovery contains **9,498 cases in 906 files**, compared with the previous
  9,521-case checkpoint: 31 obsolete migration-history cases and six comparator
  cases removed, with 14 initialization and refusal cases added.
- Historical replay on disposable PostgreSQL 18.4 completed **53 migrations / 769
  queries in 461.4 ms**. It captured 80 tables, 773 columns, 793 constraints,
  271 indexes, three sequences and seven application extensions, including five
  PGroonga and nine trigram indexes. The candidate catalog matches after the two
  approved identity changes. Ten explicit NULL defaults normalize to absent
  defaults during PostgreSQL DDL replay; their behavior is identical. Seed fields,
  generated identities and isolated-schema initialization were checked separately.
  Evidence: `/tmp/baander-migration-baseline-20261017/`.
- The final guard regression file passes **14 tests / 178 assertions in 1.503
  seconds** in the CI image. It verifies non-interactive refusal without mutation,
  extension-only and fresh initialization, no-op runs, a future migration,
  irreversible downgrade, schedules and automatic identity allocation.
- Composer platform requirements, PHPStan, Deptrac, container lint and OpenAPI
  drift checks pass in the CI image. Deptrac reports zero violations, warnings or
  errors and 489 existing baseline skips. All **64 Python runner regressions**
  pass in **0.195 seconds**. The PostgreSQL remediation catalog audit reports no
  candidates in the affected schema.
- Plan review, simplification and independent code review completed. Review found
  a missing collation check; the catalog dependency scan and regression cases
  resolve it. No code findings remain. Review receipt:
  `/tmp/compound-engineering-1000/ce-code-review/20261017-120216-migration/`.
- The first full run selected **9,496 cases** and took **954.6 seconds**. Its
  snapshot preceded the final guard and fixture corrections: extension-owned
  composite types caused a false refusal, one retained constraint test had lost
  its insertion helper, and a missing-table test fell back to the public schema.
  Those defects are corrected. A native streaming-abort timing failure also
  occurred; its unchanged file passes a focused same-seed rerun (**4 tests / 36
  assertions**, **0.593 seconds**). This failed full run is not a qualification
  pass. Artifacts: `var/test-runs/20261017-120107-f601ec9c`.
- Runtime drills passed in the CI image: producer rollback/retry (**24.35 s**),
  outbox replay (**25.52 s**), consumer recovery (**124.04 s**) and deployment
  recovery (**33.34 s**). The killed consumer became unhealthy at 90 seconds,
  its replacement recovered unacknowledged work, and registered deployment
  recovery preserved lease fencing. Logs: `/tmp/baander-consolidate-*.log`.
- Final authoritative backend qualification passes **9,498 tests / 78,250
  assertions**, with **zero failures, errors or skips**, in **536.1 seconds**.
  The six lanes used four workers and random seed `1725390406`: ordinary
  **6,004 / 23,291**, native **644 / 8,001**, and PostgreSQL lanes
  **941 / 14,318**, **627 / 17,839**, **768 / 10,123**, **514 / 4,678**
  (tests / assertions). The native streaming test passed unchanged in this run.
  Artifacts: `var/test-runs/20261017-122142-5c3dfb14`.
- All **20 runner isolation checks** pass in **43.47 seconds**. The probes verify
  separate database, Redis and filesystem state. Evidence:
  `/tmp/baander-consolidate-isolation.log`.
- The approved manual reset recreated only `baander` in `baander-postgres`,
  preserving its owner, encoding, locale, collation version, tablespace and other
  creation settings. The first migration run executed **one migration / 450
  queries in 596.3 ms**; the second reported no pending work. Its catalog matches
  the verified candidate, and both schedules pass deterministic and generated
  field checks. The full `baander_test` dump SHA-256 and other database metadata
  are unchanged. The previously running nginx service was stopped and restarted;
  no app service was running beforehand. Its pre-existing nginx restart behavior
  remains outside this task. PostgreSQL volumes, Redis, registry storage and media
  files were untouched. Evidence: `/tmp/baander-consolidate-local-reset/`.
- Implementation and documentation were committed as `78165f61` on
  `plan/consolidate-doctrine-migrations`. After verification, the user authorized
  merging into `master` and pushing to both remotes on 2026-10-17.
