# Baander roadmap

Updated: 2026-10-15. This is the working delivery record for the remediation,
registry, and web-state plans. Update it when scope changes or a stage is verified.
Completed code is not proof of production or performance qualification.

External acceptance: deployment access/inventory for `de`, `ca`, `sg`, `au`, and
`fi`, and the S3 backup configuration are unavailable (user confirmed 2026-10-04).
Continue local implementation and emulated acceptance; actual regional deployment,
regional measurements, and backup-destination restore qualification remain pending.
React Native and Android work is deferred at the user's request (2026-10-05).

## Resume here

Read [AGENTS.md](AGENTS.md) for working rules and the
[testing guide](docs-book/part-2-developer-guide/testing.md) for runners. This
roadmap records scope and acceptance; the linked design documents, current source,
and CI workflow are the executable detail. Measurements in later historical
batches describe their own checkpoints; the current gate below takes precedence.

### Handoff state on 2026-10-15

Any harness can take over from this section; nothing below depends on a tool that
only one agent host has.

- **Branch.** `plan/registry-threat-model` continues from published `master`
  (`bb6bdc3c`). API contract reconciliation was fast-forwarded into `master` and
  pushed to both remotes at the user's request. This branch completes the registry
  threat model and focused security regressions; its checkpoint below records
  verification and remaining risks. This follow-up remains local until publishing
  is requested.
- **Previous baseline.** Item 8 was merged and pushed. `master` (eced16d6) holds its fifteen
  commits and the roadmap commit on top of 63466d0d by fast-forward, code-reviewed
  with both findings applied and every gate green (the item 8 checkpoint below).
  `origin/master` and `baander-github/master` are at the same commit, the working
  tree is clean, and nothing is unmerged or unpushed. The local branch
  `plan/item8-catalog-authorization` points at the same commit and can be deleted.

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
- **Open from item 8's review, not acted on.** Two concurrent identical
  add-collaborator requests can hit the unique constraint and answer 500; a reorder
  under concurrent writers has no version check; sharing has no invitation step, so
  an owner can add any user unasked; removing a song is not judged by the actor's
  visibility; the visible-song count materialises rows. None blocks the item.
- **Next.** Address WebSocket proof possession and origin validation from the
  [application threat model](docs-book/part-2-developer-guide/application-threat-model.md#open-findings-and-qualification-work).
  The combined application/registry model and focused regressions are qualified;
  their remaining security findings are still open. React Native and Android
  remain deferred. The local dev database note at the end of this section stands.

The backend release-gate package is complete (see the next two sections). Eight
plans followed it, in this order, and all eight are delivered:

1. [General settings and email language](docs/plans/2026-10-07-1736-feat-general-settings-email-language-plan.md):
   one definition-driven settings mechanism, the per-user email language, and the
   features behind the existing admin toggles. Delivered on 2026-10-08. The
   [open-items follow-up](docs/plans/2026-10-10-1312-fix-item1-open-items-plan.md)
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
2. [Admin/CLI parity](docs/plans/2026-10-08-1627-feat-admin-cli-parity-plan.md):
   every action on the admin pages has a console command that reaches the same
   Application use case as its controller. `AdminCliParityTest` fails on an admin route
   with neither `#[CliCounterpart]` nor `#[CliParityExemption]`, and
   `CommandDocsCoverageTest` on a command without an operator docs page. The work
   also added the server control channel for diagnostics and QoL, cooperative job
   cancellation, admin-only library rename, delete and scan with a leased scan claim,
   and it ends a disabled user's tokens and WebSocket connections. Delivered on
   2026-10-09. The [follow-up](docs/plans/2026-10-09-1728-feat-deferred-cli-parity-plan.md),
   also delivered on 2026-10-09, gave commands to the routes it had deferred: the
   catalog and player admin actions (metadata editing, per-song lyrics fetch and apply,
   cover upload and delete, artist create and credits, album, song, movie and artist
   delete), webhook administration and server discovery registration. No deferred
   exemption remains, and the parity test fails on an exemption that defers its
   command. The admin notification bell needs no command: it reads the
   administrator's own inbox. The
   [open-items follow-up](docs/plans/2026-10-10-1012-fix-item2-open-items-plan.md)
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
3. [Timestamp convention](docs/plans/2026-10-10-1752-refactor-timestamp-convention-plan.md):
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
4. [Web server drain on container stop](docs/plans/2026-10-11-1121-fix-web-server-drain-plan.md):
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

5. [Browser credential and session qualification](docs/plans/2026-10-12-1748-fix-browser-credential-session-qualification-plan.md):
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

6. [Queued server-side logout](docs/plans/2026-10-13-1841-feat-queued-server-side-logout-plan.md):
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

7. [Video delivery authorization matrix](docs/plans/2026-10-14-1319-test-video-delivery-authorization-matrix-plan.md):
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

8. [Catalog authorization matrix and playlist collaborators](docs/plans/2026-10-14-1532-feat-catalog-authorization-matrix-plan.md):
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

The local dev database (`baander` in `baander-postgres`) records the migration class
names from before the rename and is about 40 migrations behind (last migrated on
2026-07-15). `make migrate` now refuses it. Reconcile its recorded history with
`doctrine:migrations:version`, or reset it, before it can reach the timestamp
migration (user decision, 2026-10-10: kept out of item 3).

Other local work can proceed independently: qualify the registry's remaining
failure, fixed-runner load, resource, and soak gates. Regional hosts and the S3 destination remain external
blockers. Do not mark deployment or restore acceptance complete from local cluster
tests. React Native and Android remain deferred; Android's device login posts to a
pairing endpoint that does not exist and needs a decision when that work resumes.

## Current quality-gate checkpoint

Last recorded on 2026-10-12, at the end of the ROADMAP item 4 drain work and its code
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

Plan: [backend release gates](docs/plans/2026-10-06-1612-refactor-backend-release-gates-plan.md).
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
| Current local work | Remaining backend boundaries, final-image quality gates, browser auth qualification, and registry failure/resource tests | Deptrac reaches zero; full final-image checks and the relevant integrated acceptance pass |
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

The full web lint audit on 2026-10-04 reported 134 errors and 14 warnings before
this follow-up, across the application, tests, and embedded player package.
Changed-file lint passing does not satisfy this release gate. Resolve the errors
at their source; do not broaden exclusions or suppressions to obtain a green run.
After centralizing the image lifecycle, the rerun reports 130 errors and 14 warnings.

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

These packages remain tracked until their full acceptance criteria are verified.
Prior commits contain substantial work; audit the current implementation and
record evidence before marking an entire package complete.

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

- [x] Implement the C++20 registry with Beast/Asio, OpenSSL, nlohmann/json,
  pinned dependencies, CMake/Ninja, GoogleTest, and bounded async rqlite pools.
  The PHP registry is replaced; no Symfony serialization, Redis, Sentinel, or
  Electric is used. Local build, sanitizer, and cluster evidence is recorded above.
- [ ] Finish release qualification of that implementation on constrained hosts,
  full failure injection, fixed-runner performance, and the actual regional network.
- [ ] Verify atomic credential-owned registration, digest-only credentials,
  idempotent uncertain-commit retries, reserved offline identities, revisioning,
  parameterized SQL and statement errors, bounded input/rate limits, private TLS
  database endpoints, and liveness/readiness behavior.
- [ ] Require explicit linearizable reads and committed writes. Return 503 with
  Retry-After when quorum cannot provide an authoritative result; never downgrade.
- [ ] Qualify concurrency, partitions, leader loss, stale replicas, disk/certificate
  failures, shutdown, sanitizers, fuzzing, backups, and 24-hour soak/leak gates.
- [ ] Run constrained optimized acceptance on five hosts and actual regional links.
- [ ] Configure and rehearse encrypted, versioned five-minute S3-compatible backups
  and isolated restoration (five-minute RPO; rehearsed 30-minute RTO).
- [ ] Deploy and validate five voters, then upgrade voters one at a time. Preserve
  volumes and prevent accidental competing-cluster bootstrap after quorum loss.

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

## Long-horizon acceptance backlog

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

Implementation follows the [reviewed plan](docs/plans/registry-threat-model.md).
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
The application model's existing WebSocket proof, rate-limit and outbound-policy
findings also remain open.

### Application threat-model delivery (2026-10-14)

Branch `plan/application-threat-model` implements the reviewed
[plan](docs/plans/application-threat-model.md). The
[application threat model](docs-book/part-2-developer-guide/application-threat-model.md)
records assets, trust boundaries, source/test evidence and open findings. PHP
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
proxy configuration correction. GitNexus was stale (indexed commit `2503505`).
Source and caller inspection and Git diff supplied the edit evidence. Prose-fix
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

- [ ] Verify the two-command deployment contract end to end: web owns delivery and
  client connections; worker owns consumers, scheduler, outbox relay, media execution,
  control services, and child cleanup. No competing supervisor or hidden web pool.
- [ ] Use validated routes/resource classes with a handler, independent JSON codec,
  retry policy, and unique consumer identity for every asynchronous message type.
- [ ] Reserve responsive control capacity independently of catalog/metadata/media
  backlog. Bound CPU, RAM, device slots, restarts, and shutdown for entire child trees.
- [ ] Make autoscaling use measured backlog age, job duration, utilization, and
  admission budgets with stable scale-up/down behavior and measured default profiles.
- [ ] Exercise leases, scheduler occurrences, process retirement, orphan cleanup,
  deployment recovery, and transport outages. Do not inherit live Doctrine
  connections across forks or depend on a running HTTP server to supervise workers.
- [ ] Qualify shared web/worker media control: leased ownership, ordered revisions,
  session generations, stale-segment exclusion, cancellation under saturation,
  seek/pause/resume/reconnect, and no duplicate encoders on client reattachment.

Related detailed media plans remain part of the roadmap until reconciled:
[long-process segmenter](docs/plans/2026-07-16-001-refactor-long-process-segmenter-plan.md),
[transcode cache/sweep end-to-end](docs/plans/2026-07-16-002-feat-transcode-cache-sweep-e2e-plan.md),
and [FLAC/DSP CPU work](docs/plans/2026-07-08-001-perf-flac-dsp-cpu-plan.md).
Existing implementation must be evaluated against their behavioral and resource
contracts; neither old prose nor the current branch name establishes completion.

### Registry release and growth

- [ ] Public API retains registration fields `publicId`, `url`, `name`, `version`,
  `apiKey` and recognizable success envelopes. Enforce HTTPS hostname URLs without
  embedded credentials; 8 KiB bodies, depth eight, IDs/names 128 characters,
  versions 64, URLs 2,048. Verify malformed/invalid/forbidden/oversize/throttled/
  unavailable statuses 400/422/403/413/429/503 respectively.
- [ ] Use `schema_migrations` checksums and one credential-owned `registries`
  table. Store only SHA-256 credential digests for random 256-bit credentials;
  never log/return credentials. Offline after ten minutes never releases ownership.
- [ ] Test simultaneous claims, wrong credentials, retry after uncertain commit,
  3–2 partitions, total quorum loss, stale followers, full disks, invalid TLS,
  graceful shutdown, and convergence after rejoin without manual data merging.
- [ ] Every PR: release build with warnings as errors, unit/API and three-node
  integration tests, static analysis, dependency/license checks, ASan/LSan/UBSan,
  separate TSan, malformed-input/disconnect/deadline/retry/reconnection/shutdown
  coverage, and fixed-runner performance smoke tests. Confirmed regression above
  10% against the accepted baseline blocks release.
- [ ] Nightly and pre-release: five-node partition/failover, parser fuzzing, isolated
  backup restoration, and a 24-hour workload soak. After warm-up compare equivalent
  quiescent windows; fail sustained RSS growth over 1 MiB/hour or 10% of baseline.
- [ ] Test snapshots, compaction, node catch-up, and backup upload inside the host
  resource limit. Sanitizer workers may be larger; optimized deployment binaries
  must still meet the agreed whole-host 512 MiB constraint.
- [ ] Rehearse loss of any two voters without acknowledged-data loss, leader recovery
  within 30 seconds, and cluster-wide restore from encrypted/versioned backups.
  Measure the actual regional topology before rollout, not only emulated latency.
- [ ] Expand APIs from five toward twenty only after measurements justify it;
  retain five voters, optionally use non-voting replicas, and route authoritative
  reads to the leader. Region changes require membership/runbook updates; emergency
  one/two-host operation never implies unsafe automatic quorum reduction.

### Secondary clients, native qualification, and release operations

- [ ] Remove shared compilation blockers and require dependent shared/mobile/desktop
  checks. Tighten Electron navigation/origin and IPC validation and test failures.
- [ ] Reconcile the [React Native styling plan](docs/plans/2026-07-15-001-refactor-rn-styling-pattern-plan.md)
  and [React Native/TV tooling plan](docs/plans/2026-07-15-002-chore-rn-tv-dx-tooling-plan.md)
  against current code after backend/web priorities are satisfied.
- [ ] Make codec/DSP builds reproducible and run reference vectors before claiming
  native paths release-certified. Track remaining loudness/codec qualification in
  [DSP qualification](packages/dsp/README.md); browser graph tests alone are insufficient.
- [ ] Verify clean application installs/upgrades on disposable PostgreSQL, restore
  backups before migration rollout, correct production dependency-copy ordering,
  environment defaults/startup behavior, and meaningful health/readiness probes.
- [ ] Align all first-party manifests/documentation to Apache-2.0; retain all vendored
  third-party notices/licenses and verify dependency-license checks in CI.
- [ ] Cut the first production release only after its acceptance evidence is recorded.
  Remove the temporary pre-release AGENTS.md policy only on the user's explicit
  announcement, then establish real compatibility/migration commitments from there.

### Developer tooling after the first debugger

- [ ] Keep trace records versioned and exportable, with bounded recording and visible
  gaps/truncation/redaction. Keep state snapshots detached from live references.
- [ ] Define replay scope explicitly: pure state transitions versus asynchronous
  completion, external input, network effects, native media, and nonserializable
  objects. Record enough provenance for deterministic supported cases.
- [ ] Add timeline navigation and isolated state reconstruction before any live replay.
  Never execute stored functions or silently replay external effects from imported traces.
- [ ] Add deterministic replay/reference tests and a version-upgrade policy when actual
  replay is implemented. A diagnostic timeline is not yet an event-sourced application.
- [ ] Retain disabled instrumentation and unrelated-render performance gates as the
  debugger and store APIs evolve; enabled tracing overhead is explicitly permitted.

## Completion and next updates

Before edits, assess symbol impact with whatever index the harness has (GitNexus
where it is connected, otherwise the search tools and the test suites). Before
commits, inspect the actual scope with `git status` and `git diff --stat`, including
unindexed test callbacks, and commit by path.
Keep independent changes reviewable and record checks without implying broader
coverage. The complete programme is done only when blocking checks, security and
workflow integrations, and registry consistency/resource/failover/restore gates pass.

## API contract reconciliation

Implementation follows the [reviewed plan](docs/plans/api-contract-reconciliation.md).
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
  relative links and whitespace checks passed. GitNexus remains stale, so source
  inspection supplied the missing impact evidence.

React Native and Android remain deferred. Their hand-written clients still use
obsolete album-track, playlist-method/song/reorder and session endpoints; this
checkpoint does not qualify those clients. The current checks cover the corrected
contracts and existing route inventory, not every response field or a live
Forgejo run.

## Messenger without Swoole

Implementation follows [the qualification plan](docs/plans/messenger-without-swoole.md).
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
  whitespace checks passed; the stale GitNexus index was supplemented with source
  and caller inspection.

This qualifies the exercised console/Messenger paths with actual extension
absence. The web server still requires Swoole; no dependency or API/schema changed.
The full-suite coverage baseline remains the prior ParaTest checkpoint; this
follow-up reran the affected selection and static gates. The blocking Forgejo step
is configured, but no live Forgejo result is claimed.

## Parallel backend verification

Implementation follows [the ParaTest plan](docs/plans/parallel-test-execution.md)
on `plan/parallel-test-execution`. The shared coordinator, explicit native manifest,
identity checks, coverage merging and blocking report validator are implemented.
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
