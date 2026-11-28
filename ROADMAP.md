# Baander roadmap

Updated 2026-11-27. The roadmap records remaining work, decisions and acceptance
gates. The linked guides describe current operating contracts. Git history
keeps completed milestones and their verification evidence.

## Resume here

Select work for a concrete user workflow, demonstrated runtime gap or named
release decision. Historical unchecked items, old plan limitations and incomplete
experiments do not authorize implementation or indefinite reruns. Reconcile the
[retained plans](docs/plans/) with current callers before using them. Release gates
become actionable when their named environment, access or decision is available.

Read [AGENTS.md](AGENTS.md) for workflow, [CONCEPTS.md](CONCEPTS.md) for shared terms
and the [testing guide](docs-book/part-2-developer-guide/testing/README.md) for current
runners and verification boundaries. Historical suite counts apply to their own trees.

## TV series libraries, checkpoint 2026-11-28

Implementation follows the [TV series libraries plan](docs/plans/2026-11-27-1827-feat-tv-series-libraries-plan.md).
The entries below cover scanning, matching, member playback and their verification.

- Phase A is committed through `8509b7f06`, covering U20, U1, U2, U3, U21, U4 and
  U5. TV libraries scan into provider-neutral series, seasons, episodes and video
  placements. The shared video parser handles season, absolute and date numbering,
  provider tags and sidecars. Movie ingestion uses folder identity, separates
  features from extras and preserves administrator field locks (`2f3d58555`).
  Playback resolves available placement paths within the member's library scope,
  checks the indexed hash and tries another placement when a file has vanished.
  Missing video library roots retain the catalog and mark their files unavailable.
- U6 is committed as `d2b1d69ce`. All nine providers have server-wide switches,
  enabled and configured states, and attribution in the provider list. The admin
  switch route, its console command and `app:settings:set` write the same settings.
  Switched-off adapters stop provider calls, and the album match drain preserves
  requests for recovery after MusicBrainz is enabled again.
- U7 is committed as `c07fa7433`. The provider-neutral video port supports TMDB
  search, identification, cross-references, metadata, credits and aired, absolute
  and DVD orderings. Provider availability checks read current switch settings
  without contacting a provider.
- U8 adds licensed-key TheTVDB series lookup, episode orderings and person IDs.
  A live lookup resolved TMDB series 1399 to TheTVDB series 121361. Its extended
  record identified IMDb `tt0944947` and TMDB `1399`.
- U9 reads movie, series, season and episode NFOs and local artwork. An NFO
  containing only a recognized provider ID supplies that identity for matching.
- U10 lets admins read and replace each TV or movie library's provider order
  through the API and the console. The order retains disabled providers.
- U11 queues matches after ingestion commits, claims one title at a time,
  pauses failing providers and retries when a provider becomes usable. Folder
  tags remain available when the local provider is off. NFO IDs follow its switch.
- U23 stores watched marks per member and episode or movie. Placement changes
  move marks in the same transaction and preserve a mark on an old episode
  that still has a file. Replaying the same move leaves the marks intact.
- U22 places files against the matched provider’s structure and keeps the exact
  ordering selected for the series. A refresh preserves hand assignments, native
  episode identities and the last mapping when an ordering disappears.
- U12 applies provider metadata, imports managed artwork and resolves screen credits
  against every supplied person ID. TV refresh preserves manual edits and locks.
  Movie sync now uses the video match queue.
- U13 gives admins the same review, search, apply, ordering, assignment and
  library re-match operations in the API and console. A review identifies the
  owning title. Admins can assign a file to any active episode, including one
  with no files. The re-match preview counts hand matches and assignments.
- U14 stores each member’s position per file and marks per episode or movie.
  Passing 90% clears the position. A multi-episode file marks every episode it
  plays in the opened library. An extra resumes without marking a title.
  Watch-party reports leave personal viewing state alone, and a stale report
  cannot restore a cleared position.
- U15 lists available series, seasons, episodes and movies, with owned counts,
  metadata source, file versions, extras and the requesting member’s progress.
  Filmographies and Person credit feeds use the same availability check before
  paging. The Party API documents the session and video IDs it already returns.
- U16 lets members browse a TV library by season, resume the last played file
  version or start over, and mark episodes, seasons and series watched. A
  browser receiving a playback handoff retains the opened title and its library
  context. Movie and series pages list playable extras and credit the metadata
  provider.

### Verification

- The isolated preview migrated a fresh database through
  `Version20261127194100`. A second migration run reported the current version,
  and `doctrine:schema:validate` reported valid mappings and a synchronized schema.
- U6 passed the CI-image static gates from `make ci`, covering Composer validation
  and normalization, PHPStan, Deptrac, imports, tooling architecture, tooling format
  and tooling PHPStan. Its ordinary PHPUnit lane passed 10,041 tests with 57,577
  assertions. The native lane passed 1,005 tests with 32,610 assertions. Across
  all eight lanes the run counted 15,569 tests and 156,435 assertions, with
  four failures and one teardown error. The affected fixtures covered the
  retired TV sync workload, placement-backed playback, log retention and video
  ingest cleanup. After the fixture corrections, those four test files passed
  in the CI image with 32 tests and 322 assertions. CI also exhausted its
  cleanup budget. The run's ownership receipts released its remaining resources.
- Provider route authorization passed four cases with 105 assertions, and route
  inventory passed nine tests with 58 assertions. The OpenAPI specification and
  Orval client were regenerated together and their drift checks passed for U6.
- U7 passed 61 focused CI-image tests, scoped PHPStan and Deptrac with zero
  violations and 451 existing skipped violations. The full run at that U7 commit
  counted 15,599 tests and 156,581 assertions. It reproduced the same four
  failures and one teardown error that the later fixture corrections addressed.
- During U8 and U9, 114 provider tests passed with 548 assertions. Full PHPStan
  and import checks passed. Deptrac reported zero violations and 451 baseline
  entries. After adding support for an NFO containing only a provider ID, the
  local provider passed all nine tests with 72 assertions and scoped PHPStan.
- The final U8 adapter, mapper, destination and empty-key services passed 85
  tests with 373 assertions. The mapper also passed scoped PHPStan.
- The provider review found that a clean lookup miss could hide a second
  supplied ID, and retries fetched episode details again. Both adapters now
  try the next supplied ID after a clean miss. TheTVDB reuses episode details
  for the listed revision for up to 24 hours. The three affected test files
  passed with 93 tests and 430 assertions, and scoped PHPStan passed.
- U10 passed eight domain tests and six PostgreSQL persistence tests, including
  concurrent first writes. The API, CLI, inventory and documentation checks
  passed. The fresh-install and rollback check passed 96 assertions after its
  fixture was updated for the new migration. OpenAPI drift, imports and scoped
  PHPStan passed. Deptrac reported zero violations and 451 baseline entries.
- U11 passed 108 focused CI-image tests with 983 assertions after correcting
  the schedule-count and JSON object comparison fixtures. The five storage
  adapter tests passed 41 assertions covering stale claims, rollback, sidecar
  scoping and retained identities. Scoped PHPStan and imports passed. Deptrac
  reported zero violations and 451 existing skipped violations.
- U23 passed 66 CI-image tests with 3,144 assertions, including the move,
  member-scoped capture and fresh-install suites. Scoped PHPStan and imports
  passed. Deptrac reported zero violations and 451 existing skipped violations.
- U22 passed 38 focused CI-image tests with 403 assertions for placement,
  fenced apply, refresh, confidence and the message codec. The initial combined
  run also exercised fresh installation, ingestion and routing. Six errors
  exposed metadata ownership methods from the following unit in the isolated
  snapshot. The 38-test rerun covered all six after the unit was separated.
  Scoped PHPStan and imports passed. Deptrac reported zero violations and 451
  existing skipped violations.

- U12 passed 70 focused CI-image tests with 1,055 assertions for metadata,
  artwork and person identity. The fresh-install suite passed 26 tests with 468
  assertions. Scoped PHPStan and imports passed. Deptrac reported zero violations
  and 439 existing skipped violations.

- The first U13 combined CI run passed 117 tests with 1,298 assertions for
  review, assignment, CLI parity, route authorization, schema and placement.
  OpenAPI and Orval drift checks passed. The isolated preview migrated a fresh
  database through `Version20261128124500`, repeated the migration and passed
  Doctrine schema validation.
- The final admin dialog reads and their CLI counterparts passed 52 cases
  across the corrected runs with 554 assertions. These included a provider
  outage, separate DVD ordering IDs, cross-library selection and an episode
  receiving its first file. Scoped PHPStan and imports passed. Deptrac reported
  zero violations and 439 existing skipped violations. The web client typecheck,
  OpenAPI export and Orval regeneration checks passed.
- The Phase C simplification removed duplicate movie locking and placement
  resets. The root-level movie NFO lookup used two index probes against 20,000
  sibling NFOs. The placement and lock regression suite passed 17 tests with
  260 assertions.
- The preview migration rehearsal now gives its scratch application a Redis
  identity and a control connection to its own database. Its 21 tests passed
  with 172 assertions. Both rehearsal passes succeeded on the live preview.
- A full-suite run at the U8 commit exposed an intermittent third alarm in the
  interrupted-sleep test. The corrected fixture cancels its timer after two
  signals. Its seven CI-image tests passed with 14 assertions.

- U14’s guarded progress writes, watched-mark moves and private change
  notifications passed 21 CI-image tests with 1,916 assertions. The command
  handlers passed 23 tests with 272 assertions. The HTTP suite passed six
  tests with 100 assertions against PostgreSQL, including active party
  suppression, available versions, extra playback and member isolation.
  Catalog and Party access passed 11 tests with 102 assertions. The full
  fresh-install and rollback suite passed 26 tests with 470 assertions.
  Scoped PHPStan and imports passed. Deptrac reported zero violations and
  439 existing skipped violations. The OpenAPI and Orval drift checks passed.

- U15’s member read API, PostgreSQL queries and authorization suite passed
  74 CI-image tests with 865 assertions. The credit-feed and director suites
  passed all 19 distinct cases across the corrected runs, with 255 assertions.
  Scoped PHPStan, imports, OpenAPI drift and Orval drift passed. Deptrac
  reported zero violations and 439 existing skipped violations.
- U16 passed 3,980 web tests in 336 files. TypeScript and ESLint passed
  (10 lint warnings). Session tests passed 198 cases with 1,181 assertions,
  and the PostgreSQL handoff tests passed two cases with 12 assertions.
  The file-stability suite passed eight tests with 32 assertions after the
  writer was synchronized with the first file sample. Scoped PHPStan, imports
  and both OpenAPI and Orval drift checks passed. Deptrac reported zero
  violations and 439 existing skipped violations.
- The Phase D availability review replaced repeated page offsets with bounded
  reads. The PostgreSQL suites passed 23 tests with 10,823 assertions. The
  10,000-row fixture evaluated each candidate once. The movie search kept its
  previous ranking across a batch boundary, and the credit feeds kept their
  totals and tie order.
- The full U8 run counted 15,653 tests and 156,814 assertions, with two
  errors and four failures. U9 corrected the ingest, playback access,
  workload and log-retention fixtures, and U13 corrected the sleep test’s
  signal timer.

## Roadmap open items, 2026-11-26

The owner selected all four groups of open items on 2026-11-26: the day's leftovers
(the Symfony entry's and the pipe-operator check's open items), and the security
audit's behaviour gaps, build and supply chain, and tests and gates. The
[plan](docs/plans/2026-11-26-2023-fix-roadmap-open-items-plan.md) records the design
and the owner decisions.

Owner decisions:

- A library-refused track answers `409` with reason `media_unavailable` on every
  playback route.
- Rows over a member-input bound are repaired in place and the bounds validated.
- A write over a bound answers `409` with reason `member_input_bound`.
- The song and recommendation lists refuse an out-of-range `limit`, and the web app
  reads every page.
- The recommendation `POST` answers `201` with the created recommendation.
- `.opus` originals are served as `audio/ogg`.
- PHP moves to 8.5.13 in every image together.
- rav1e and zix stay and build for the portable x86-64 baseline.
- The image-drift check retries Docker Hub `429` answers and logs in only when a token
  secret exists.
- The preset qualification runs in the libvirt guest, and the worker load proof stays
  blocked.
- Native build items may end as recorded findings.

The owner confirmed on 2026-11-26 that the Forgejo registry holds the runner build of
`f46c6148a` and the FFmpeg build of `7aa5172fb`, and skipped the preset qualification
for that night.

Delivered, behaviour:

- Member-input bounds (`61a1f0a37`, `d2ecc26bb`). A `NOT VALID` check still applies
  to every new row version, so an over-bound row failed every write that touched it,
  heartbeats and `ON DELETE SET NULL` cascades included. The `jsonb` bounds measured
  `pg_column_size()`, which reads a stored value compressed and a new one raw. The
  bounds now measure `octet_length(<column>::text)` and admit the largest value
  validation accepts: the queue's bound is 262,144 bytes, and 10,000 public IDs print
  as 250,000. The migration repairs stored rows, then validates all fifteen bounds:
  - over-long text is cut, or reset to its column default;
  - repaired playlists get a new `persistence_version`;
  - push subscriptions with an over-bound endpoint or key are deleted, and a long
    User-Agent is cut;
  - recommendations named longer than 255 characters are deleted, since cut names
    could collide on the unique index.

  `postUp()` reports the rows repaired per bound, and the migration is irreversible.
  `DoctrineTransaction` maps a violated bound to `MemberInputBoundException`, which
  answers `409` `member_input_bound`. Other check violations stay a logged `500`.
- Refused tracks (`b66a2c1fa`). Delivery answered `500`, the signed original `403`
  and the legacy stream `404` for a file outside its library. All three now answer
  `409` `media_unavailable`, and the web player stops on it with an explanation. A
  missing file keeps each route's former answer.
- Media types (`7216f3cc1`). `.opus` originals go out as `audio/ogg` (RFC 7845), and
  a migration rewrites stored `audio/opus` songs. A `.webm` file counts as WebM only
  with VP8, VP9 or AV1 video and Vorbis or Opus audio. Otherwise it is Matroska and is
  offered only to destinations that play Matroska. `/dsp/*.wasm` is served as
  `application/wasm`.
- Strict list limits (`c09665585`). The song list and the four recommendation lists
  answer `400` with `details.limit` for an out-of-range `limit`, before any lookup.
  The web app sent `limit=1000` from three artist actions; one `usePlayArtist` hook
  now reads every page.
- Transcode session share (`431ef5825`, `12c7c256d`):
  - one SQL predicate decides whether a session occupies a slot, so a dead admission
    no longer counts;
  - a start locks every silent session it may end, then reads them again;
  - a tiered start selects every tier's presentation before the first tier makes
    room;
  - cancel, pause and resume lock the video's admission row, then the execution row,
    and change the execution as stale expiry does.
- Session-limit message (`f7b8175e0`). The player names the limit when a start
  answers `409` `transcode_session_limit`. It used to show the Axios text or a generic
  preparation error.
- Recommendations (`b68d74326`). The `POST` answers `201` with the created row. A
  generation run deletes only generator rows, of its own user or of no user, and
  writes with `INSERT … ON CONFLICT DO NOTHING`, so overlapping runs both complete
  and member rows survive.
- Activity history (`2725abc41`). Its response carries `meta` with `total`, `limit`
  and `offset`, and the view model pages until `total`.
- Revoked personal access clients (`3fa8838e1`). `app:oauth:client:purge-revoked`
  deletes them with their tokens, and a seeded schedule runs it daily at 03:45 UTC.
- Actor IDs (`c173c31a2`). The limiter clear, the job monitor and the recommendation
  administration record the administrator's user ID through a Shared
  `SignedInUserInterface` port. Console paths still record `cli`. Owner decision of
  2026-11-27: the admin API and `app:recommendation:job:show` / `list --json` show a
  job's `triggered_by` and `requeued_by` as the administrator's public ID, resolved
  when the job is shown; storage keeps the user ID (`3074f145a`).
- Console listings (`44ef7d005`). `TerminalText::literalRows()` strips CSI, OSC and
  C0/C1 controls from table cells and escapes formatter tags. `AdminCommandSupport::list()`
  and every listing of stored or external text use it. `--json` is unchanged.

Delivered, build and supply chain:

- Every image carries PHP 8.5.13, and the six drifted bases are repinned
  (`e31225083`); `postgres:18-trixie` is repinned at its new index (`18b9ee13c`). The
  amd64 image behind that index is unchanged: PostgreSQL 18.7-1.pgdg13+5.
- Node and corepack move to the `dev` stage, so production, worker and dev-operator
  images carry no unowned Node tree; the PIE inventory entries are gone
  (`57bbb6ab7`).
- The FFmpeg build (`f22f623d0`, `7aa5172fb`):
  - `sources.txt` lists 44 archives by SHA-256, plus 11 forge archives and the libjxl
    and glslang trees by commit;
  - `download()` refuses a file that was not pre-seeded;
  - `cargo-c` 0.10.26 installs with `--locked`;
  - rav1e and zix build for the x86-64 baseline.

  GitHub run 45894734494 built it, and GHCR serves it as
  `ffmpeg-baander-static@sha256:762d62b2…`.
- The image-drift check reads a rate-limited manifest again after 5, 10 and 20
  seconds. The workflow logs in only when `DOCKERHUB_TOKEN` is set (`626df2407`).
- Registry (`286614af9`):
  - GCC 16's `-Warray-bounds` report in Asio's resolver is a false positive:
    `endpoint::resize()` bounds the copy by `sockaddr_storage`, and only AF_INET and
    AF_INET6 entries reach it. It stays a warning on GCC 16 and later.
    `-Wstringop-overflow` did not reproduce.
  - The ZTS image's exit 139 came from an active Xdebug, not ZTS: gdb showed
    `xdebug.so` under `zend_observer_fcall_end_all` at request shutdown. The harness
    now refuses an active Xdebug. With `XDEBUG_MODE=off`, `scripts/test-registry.sh
    release` passes in the ZTS image.
- Pins: every workflow runs on the runner built from `f46c6148a` (`bb2002895`). CI
  uses the FFmpeg build of `7aa5172fb` on both forges (`69c6504e4`, `a50e42f62`).

Delivered, tests and gates:

- `ConfigurationTest` fails when either PHPStan configuration stops including the
  rules extension or the extension stops tagging a rule (`f1ed3352e`).
- A throwing reset releases the pool's mutex and the next `get()` builds a new
  instance (`77fc84c0a`).
- The log agent's Docker proxy allow-list ended in `$`, which also matches before a
  final newline, so `…/json%0A` reached the daemon. It now ends in `\z`, and the
  integration test sends `%0A`, `%0D%0A` and `%2e%2e` forms (`7966eb39e`).
- The `409` answers' `error.details.reason` and `error.details.limit` are covered by
  `TranscodeSessionLimitResponseTest` (`431ef5825`).
- Registry stop tests cover a stop during a held schema write and during a delayed
  probe, and each checks that the stop ends the readiness coroutine (`ffd7fb2ec`).
  Against mutated server builds, each case failed with "Child wait deadline exceeded."
- Tests that skip without their environment already fail in CI, which passes
  `--fail-on-skipped` to every lane
  (`packages/baander-dev-ci/src/Backend/Coordinator.php`). Setting `failOnSkipped` in
  `phpunit.xml.dist` would only break host and IDE runs.
- The two PostgreSQL findings are in the postgres-remediation skill's Doctrine
  reference (`61a1f0a37`, `d2ecc26bb`).

Delivered, CI repairs found on the way:

- Symfony 8.1.12 stores a sliding window's size as a second entry. The Redis
  limiter's state codec refused it, so every charged `/api` request answered `503`
  outside the dev environment from `69dad984a` on (`9e22148f9`). This was the media
  ownership step's failure on run 45877146331.
- hls.js treats every `nextLevel` assignment as a smooth switch and flushes buffered
  media and the subtitle cues that end before it. The player now assigns it only when
  the level changes (`f46c6148a`). This was the Matroska movie-origin step's failure
  on runs 45657127671, 45779297953 and 45869964516.
- The website's font-arrival test failed when Chromium restarted handshakes on the
  test server's self-signed certificate; the suite now accepts that certificate
  (`79b1aa032`).
- The app-root toast test took 4.4–4.7 s of its 5 s limit, because jsdom reparses a
  whole `<style>` element for every styled-components rule. Vitest now selects the
  CSSOM injection that production uses (`b7ce66a45`).
- From `5783943a6` on, development and preview instances could not start: the log
  agent mounted the proxy's `docker_proxy` volume, which the development runtime never
  declared. The runtime now runs the proxy with the preset's hardening and recreates
  it when its configuration changes (`1500050d2`). A test checks that every mounted
  volume is declared.
- The disposable database drill had not run on CI since 2026-11-22, and it failed for
  two reasons (`56b1d0ab4`):
  - Compose refused its project, because it kept only `postgres` and `redis` while
    every development service depends on `log-agent`.
  - Its production migration run lacked the secrets the boot check of `b7206492e`
    and `2a4498344` requires.
- Owner decision of 2026-11-27: the quality gate's 25 steps after the browser drills
  run with `if: ${{ !cancelled() }}`, so PHPUnit and the stack lanes report even when
  a drill fails (`1f8b7cdbe`). `PublicationWorkflowTest` allows only that condition on
  a qualification step.

Delivered, browser checks (`2c217a26c`). On a fresh preview of `f2ca7146c`,
`api-path-encoding.spec.ts` passed in Chromium and the new `original-download.spec.ts`
passed in Chromium and Firefox. A signed original opened as a page aborts into a
download of the whole file, carries `Content-Disposition: attachment` and
`Content-Security-Policy: default-src 'none'; sandbox`, and leaves the page at
`about:blank` with no script output. This ran through the development gateway in front
of Swoole, not the preset gateway. Owner-approved cleanup removed the dead preview
record `preview-6b8641d04eb77339`, which an interrupted stop had left, and its gateway
route.

Delivered, code review fixes. The full-depth review ran eleven reviewers (correctness,
security, adversarial, data migration, API contract, reliability, project standards,
testing, maintainability, frontend races, learnings). It confirmed two findings and
raised one more finding and one residual risk. Each fix has a test seen failing first:

- A run for one user deleted the `collaborative` rows of no user, which every member
  reads, and did not write them back. It now deletes only the rows it writes anew
  (`c3ba4acd5`).
- Two overlapping play-artist requests both started playback, and the slower read
  replaced the queue. An overtaken read now plays nothing (`38fbb20cf`).
- The MusicBrainz search heading and the catalog delete listing printed stored text
  through the formatter; both now use `TerminalText::literal()` (`04263e23c`).
- The queue repair now moves `listening_sessions.revision`, so a control sent with an
  index into the old queue is refused as stale (`5d296cc58`).

Delivered, web test stability. The intermittent "removes a denied playlist snapshot"
failure in `collections.test.tsx` (runs 45900306324 and 45653910522) came from the
test: it switched to fake timers while a 100 ms coordinator wake was armed on the real
clock, so the refetch depended on host speed. It now waits until no wake is armed
(`63b200d6f`). Shuffled runs then showed a production defect: the live HTTP admission
kept attempt starts stamped in the future, so after a backward clock step it stalled
every live read once 20 such starts had gathered. It now drops them (`db3abcdaa`). The
file passed 20 straight, 40 shuffled and 10 single-core runs, and the full suite
passed (327 files, 3,930 tests).

Verification checkpoint:

- Every behaviour commit carries a test seen failing first. The commit messages
  quote the failures.
- The affected unit lanes, stack files, Vitest files, `tsc -b`, ESLint, PHPStan,
  `check:imports`, `check:tooling-architecture` and Deptrac (0 violations) passed on
  the integrated tree after each merge.
- `InitialSchemaMigrationTest` passed with 51 migrations.
- The development-environment and registry tooling suites passed (429 tests, 7
  skipped by design).
- GitHub CI run 45900306324 on `18b9ee13c` passed every browser drill: Matroska movie
  origins, media ownership in Chromium and Firefox, and the selected video endpoint
  through movie completion. It also passed the static gates and the registry jobs. It
  failed at the disposable database drill, fixed in `56b1d0ab4`, and at the
  intermittent `collections.test.tsx` case below.
- The simplification pass changed one web reader and three docblocks (`c24213665`).

Open:

- The worker load proof (8 video and 12 audio jobs) needs a live deployment.
- The preset qualification in the libvirt guest was not run.
- A member `POST` that races a generation run for the same user and key can still
  answer `500`, because the ORM flush meets the unique index.
- Console "show" outputs (`horizontalTable()`, `definitionList()` and `text()` lines in
  commands such as `app:metadata:match:apply`, song and album delete, and user setting
  `show`) still print stored text through the formatter. `TerminalText::literal()`
  would cover them.
- Write routes other than `PATCH /api/playlists/{publicId}` can answer `409`
  `member_input_bound` for a row stored over a bound before the repair, and their
  OpenAPI operations do not document it.
- The registry load smoke failed twice in three full release runs in the runner image
  (`lookup failed for load-057: HTTP 503`). It passed 4 of 4 when run alone, from
  both this tree and the base. The cause is not established.
- The development controller can wedge a preview: `down()` saves `quiescing` before
  `compose stop`, so one failed compose call leaves the instance needing `recover`.
- `tests/live-updates/personal-state.test.tsx` ("represents an idle initial session")
  fails under `vitest run tests/live-updates --sequence.shuffle --sequence.seed=4`, on
  this tree and before it; CI runs the suite unshuffled. The Frontend CI coverage run
  45653910522 failed four `waitFor` checks in three files for a reason not
  established. `LiveQueryCoordinator` still times `lastPass`, `retryAt` and `wakeAt`
  with `Date.now()`, so a backward clock step probably delays a family's refresh.

## Symfony 8.1.12 and the media ownership drill, 2026-11-26

The scheduled advisory check reported 19 Symfony advisories published on 2026-11-25 against the locked 8.1.10 and 8.1.11 components. `69dad984a` is a full `composer update` inside the existing constraints: 41 Symfony components move to 8.1.12, eleven other packages take minor or patch releases, and the scripts lock moves `league/commonmark` to 2.10.10. The Redis Messenger patch applies unchanged. `a06ba18aa` stops the local dependency check from reading Composer's root-version notice for `baander/redis-rate-limiter` as a fetch failure.

`c4297b363` fixes GitHub CI's media ownership steps, which had failed on every run that reached them since `43e0bb7cd` (2026-11-20). Since `d790a24e2`, a token carries its user's roles only when the first-party client issued it from a password or passkey sign-in. The two-command drill signed its administrators in through clients of their own, so the absent-worker phase got 403 instead of the 503 it checks for. The drills now sign in through the first-party client.

Verification checkpoint:

- In the CI image with the updated vendor tree, these pass: the online advisory check (no advisory on any owned lock), `composer validate` and `normalize`, application and tooling PHPStan, Deptrac (0 violations), `check:imports`, `check:tooling-architecture`, `check:tooling-format`, the OpenAPI drift check, and the dependency checker's 44 tests. The new checker test fails without the fix.
- The two-command drill passed end to end, with the Chromium step's environment, against a worker image built from the fix applied to `eb10d354e`.
- The full backend suite was started on `a06ba18aa` and stopped unfinished at the owner's request. GitHub CI on `c4297b363` is the outstanding check.

Open: none. `e31225083` repinned the six drifted base images, and the roadmap open items entry above records the movie-endpoint step.

Correction: the update broke every sliding-window rate limiter, so charged API requests answered `503`; `9e22148f9` fixes the state codec. The full unit lanes would have failed, but they were not run before the update shipped.

## Push without VAPID keys, 2026-11-26

Since `cd1b02c05` (2026-11-21) removed the tracked `.env`, the production image has run with the empty VAPID keys of `.env.example`. The WebPush client rejects an empty key pair in its constructor and was built eagerly, so every `SendPushCommand` failed while its handler was constructed, was retried three times and was dead-lettered, even with push turned off. GitHub CI's "Production worker lifecycle and dependencies" step failed its outbox check on every run that reached it from `227948b34` on; the tracked `.env`'s development key pair had hidden the defect since the first commit.

Owner decision: build the client lazily, and have both push handlers skip with a `notification.push` log entry when the port reports that no key pair is configured.

Delivered in `f98d8012e`: `PushDeliveryPortInterface::isConfigured()`, a lazy `WebPush` argument in `WebPushDelivery`, the skip in `SendPushHandler` and `SendPushToSubscriptionHandler`, a kernel test that boots with empty keys, and a note on the operator environment-variable page.

Verification checkpoint:

- The kernel test failed before the fix with `[VAPID] Public key should be 65 bytes long when decoded.` and passes after it.
- Notification unit tests: 279 passed. Push kernel, integration and functional tests: 21 passed across both stack lanes.
- Whole-tree application PHPStan, `check:imports` and Deptrac (0 violations) pass.
- The production worker drill (`scripts/test-worker-production-container.php`) passed against a worker image built from `f98d8012e`. On `f78a945ec` it fails at the same outbox check as CI.

Open:

- GitHub CI's Chromium media-ownership step, which this regression had hidden, then failed in its absent-worker phase. The cause was the drill's sign-in, not the outbox; `c4297b363` fixes it (see the Symfony 8.1.12 entry).
- GitHub CI's "Public website" workflow failed from `eac10e7ab` (2026-11-25) on, in five browser tests made stale by `5232d0e44`: that commit renamed the "Source Installation" page to "Installation" and moved the security page's `.env` template to the developer guide's compose-stack page, but updated only one of the tests that use them. Fixed in `eb10d354e` by following the new link name and checking `env` highlighting on the compose-stack page. With the same change, the full website suite passed locally (221 tests); the six affected tests pass on `eb10d354e`.

## Pipe-operator inspection check, 2026-11-26

PHPStan now reports what PhpStorm 2026.3's pipe-operator inspection reports, and the tree has none of those findings left. Two rules in `packages/baander-phpstan-rules` mirror the inspection's two messages: nested call chains of three to ten levels, and three to ten consecutive assignments to one variable. Both PHPStan configurations load them, so `make phpstan`, `composer analyse:tooling`, `make ci` and CI fail on a new finding. The [plan](docs/plans/2026-11-25-2138-refactor-pipe-operator-inspection-check-plan.md) records the design; the developer guide's [Pipe Operator](docs-book/part-2-developer-guide/development/coding-conventions.md#pipe-operator) section gives the house fix and the parity check.

Owner decisions: the rules match PhpStorm exactly, not the two-level shape the earlier passes converted; they cover the assignment-chain message too; multi-argument steps become named locals or helpers, never PhpStorm's quick fix.

Delivered:

- The two rules, with fixtures for every predicate and for the shapes a headless PhpStorm run settled. That run corrected two predicates read from the bytecode: closures never stop a chain, and a first-class callable is not a call.
- The conversion of the 643 nested-call findings in 402 files and the one assignment chain, in area commits: application code, unit tests, the other test directories, tooling, and `scripts/retain-build-evidence.php` on its own because it invalidates the native Docker build stages.
- `phpstan-tooling.neon` now includes the rules extension and keeps its result cache in `var/cache/phpstan-tooling`.
- Two defects found beside this work, fixed on `master`: four Swoole bundle fork files the PHP 8.5 pass rewrote without updating their reviewed hashes (`b8d9a3dc5`), and a qualification test that searched a fixture for a class name the import pass had shortened (`b5c708887`).

Verification checkpoint:

- Parity: on `d49e4d5c6`, PhpStorm 2026.3.0.1 (build 263.7307.352) run headless with a one-inspection profile reported 643 nested-call findings in 402 files and one assignment chain; the rules reported the same sites. A second probe confirmed that a three-level nest beside another call argument is not reported and that `.=` continuations keep a chain.
- On the converted tree both rules report zero in both configurations and in the files neither configuration analyses. A planted chain fails each configuration with both identifiers.
- The conversion matched the base by AST round trip (hoisted locals inlined, pipes folded back) in every file except the reviewed helper restructures each area commit names. No hoist runs ahead of another call or write.
- Suites: on `8661c86ad` the native lane (1,005), both stack lanes (2,454 and 1,943) and the tooling suite (1,831, 9 skipped) passed. On `3935fa941` the unit lanes passed (ordinary 9,868, native 975), as did the job-monitor functional files and the rule suite (25).
- Static gates on `3935fa941`: application and tooling PHPStan, Deptrac with 0 violations, `check:imports`, `check:tooling-architecture`, `check:tooling-format`, and `composer validate` and `normalize`.
- Code review at full depth: correctness, security, project standards, testing and maintainability. It confirmed one finding, a docblock that understated the rule's multi-call stop, which `3935fa941` fixes.

Open:

- Closed in `f1ed3352e`: `ConfigurationTest` fails when either configuration stops including the rules extension.
- The ordinary unit lane crashed once on `8661c86ad` with random-order seed 138315351: `DiscoveryRegisterCommandTest` reached Symfony Console's `stty` probe while a Swoole runtime hook was active outside a coroutine. The test is not in this diff and the lane passed on rerun; the hook left on by an earlier test is unidentified.
- After a PhpStorm or PHPStan upgrade, rerun the parity check in the developer guide.

## README introduction, 2026-11-25

The [README](README.md) now opens with a moment of rediscovery, explains Bånder
through browsing and playback, and defines self-hosting before describing the
deployment. It keeps the selected repository subtitle verbatim and gives hosting
and contribution separate entry points. The
[documentation plan](docs/plans/2026-11-25-2001-docs-readme-product-introduction-plan.md)
passed document review with no findings before the rewrite.

The capability comparison used the README at `a528cbb3d1620e5806115795b2300d70fd769823`.
Existing capabilities, material qualifiers, documentation destinations, all five
badge lines and the license section were retained. Known browser gaps are named
where their features appear. The stale claim that the deployment-preset procedure
had never run was replaced with the bounded
[installation record](docs-book/part-1-operator-guide/getting-started/installation.md#verified);
that earlier run is not new runtime evidence for this documentation change.

Verification checkpoint:

- The chosen subtitle appears once. All 37 unique relative link and image targets,
  including local fragments, resolved against the working files or Git tree.
  No original unique link target was removed. Badge lines and license text compare
  exactly with the baseline.
- `git diff --check` passed. The prose-fix preservation review found no lost or
  gained inline-code tokens. PHP is unavailable, so the bundled PHP audit was not
  run; token, number, normative-word and structural comparisons were inspected
  separately, followed by a semantic comparison.
- The new numeric tokens are explained in the ledger below. The added “cannot”
  describes the recorded playlist-editing gap; “only” occurs in the installation
  record's IPv4-only exclusion. Moving headings and adding the repository map are
  intentional parts of the requested rewrite; existing section anchors remain.
- Code simplification has no applicable code changes. Application tests and
  deployment checks were not run for this documentation-only change. Final scoped
  review returned no findings and confirmed all five requirements. It independently
  checked the 37 README targets, the six targets in this entry, preserved content
  and the screenshot caption.

Opening claim ledger:

| Claim | Source |
| --- | --- |
| Recognizing an old album and being taken back by its opening track. | Reader framing; no Bånder capability claim. |
| Music and movie files become a browsable, playable library in the browser. | README at `a528cbb3d1620e5806115795b2300d70fd769823`, introduction and “Browse and play movies”; operator guide, [From files to playback](docs-book/part-1-operator-guide/getting-started/understanding-baander.md#from-files-to-playback). |
| Artist and album browsing, continuous music while browsing, and adding songs to the queue. | README at the same revision, “Explore and organize your collection” and “Listen in the web player.” |
| Users can make playlists and play movies from their collection. | README at the same revision, “Build and share playlists” and “Browse and play movies.” |
| Separate accounts can receive access to selected libraries. | README at the same revision, introduction and “Manage accounts and access.” |
| The operator supplies media, runs the server and controls access. | README at the same revision, introduction and “How an instance runs”; [user management](docs-book/part-1-operator-guide/administration/user-management.md). |
| The image shows the music library in the web app. | Existing `ui/site/assets/screenshots/library.webp`, inspected during this rewrite; no image modification. |
| Added numeric tokens: 1–4, 2, 8.5 and 4. | 1–4 label the editorial grouping of [From files to playback](docs-book/part-1-operator-guide/getting-started/understanding-baander.md#from-files-to-playback); 1 and 2 also occur in existing manual path names. cgroup v2 and PHP 8.5 come from [installation prerequisites](docs-book/part-1-operator-guide/getting-started/installation.md#prerequisites). IPv4-only is an explicit exclusion in that guide's [verification record](docs-book/part-1-operator-guide/getting-started/installation.md#verified). No calculated figures were added. |

## Security audit, 2026-11-24

The security audit of `9524a6fd9` read the whole repository and attacked a
disposable worktree preview. The
[fix plan](docs/plans/2026-11-24-1754-fix-security-audit-findings-plan.md) resolved
every finding in three phases, all on `master`. The audit document is retired; read
it with `git show adb9526fa:SECURITY_FINDINGS.md`. The
[application threat model](docs-book/part-2-developer-guide/architecture/application-threat-model.md)
and the [registry threat model](docs-book/part-2-developer-guide/architecture/registry-threat-model.md)
record each closed path with its proving test and each residual. These are the
findings above low severity:

- **C1, critical, reproduced.** A request without credentials runs as the last user
  the web worker authenticated: with no credentials, `POST /api/admin/users` created
  a super admin (`201`), in the `dev` and the `prod` environment. The swoole bundle
  keeps one security token per worker and never clears it.
- **H1, reproduced.** One unauthenticated `PS256` DPoP proof with a 2048-bit exponent
  held a web worker for 206.6 s.
- **H2, reproduced.** A DASH manifest named like a media file played media from
  outside the member's library.
- **H3, reproduced.** A text-padded library file was served from the application
  origin as `text/xml` and ran script in Chromium and Firefox.
- **H4, read.** One failed `GET /ready` leaves the registry answering `503`.
- **H5.** `composer audit --locked` reports 38 advisories in 14 packages.
- **M1 to M14.** Worker deployment pair stopped by one large tag (reproduced),
  symlinked video transcoded outside its library (reproduced), admin `ftp://` path
  (reproduced), unbounded sign-in fields (reproduced), IPv6 limiter keys, limiter
  clearing by any admin (reproduced), push subscription count, the source
  installation guide, preset trust boundaries, build inputs, member field sizes,
  desktop renderer hardening, registry buffer and CA, production log volume.

### Delivered: Phase 1

- **C1.** The swoole bundle runs every `kernel.reset` method of a pooled service
  (`fa098c23b`). The inner token storage and the authorization checker are
  per-coroutine pooled services (`7e8e53ce0`). `StaleTokenListener` clears the token
  at the start of every main request (`a167d2396`). The proof (`02b09f172`) is a drill
  in the security runtime drill: it holds 12 connections on each side across four web
  workers and reads each worker's request count, so it fails when a side misses a
  worker. On `8b27243c2`, 12 of 12 connections without credentials answered `200` with
  the admin's profile; now every one answers `401` and `404`. An interleave test
  suspends one user's request while another user's request completes on the same
  worker, and each response names its own user.
- **H1.** DPoP proofs accept EC and EdDSA keys only. RSA client assertion keys are
  bounded to 2048 to 4096 bits with exponent 65537 and are verified by OpenSSL, and a
  proof larger than 4096 bytes is refused before it is parsed (`e4383d044`). The two
  `PS256` probes in the security drill are refused within 1 s. The images carry
  `ext-gmp` (`eea6b8e59`).
- **H5.** The PHP locks are updated: `6ac55bc73` moves the packages with advisories and
  `09261acc8` the remaining 50, and `composer audit --locked` is clean. The JavaScript
  locks are updated inside their ranges, and Yarn installs no version younger than
  three days (`88d561f70`). A nightly workflow audits every lock and fails on a new
  advisory or an unreachable source (`42f48f9a4`). 22 JavaScript advisories under the
  Vercel CLI and electron-builder have no in-range fix and carry reasoned exceptions.
- **Admin `ftp://` path, network side.** PHP's URL wrappers are off in every image
  (`eea6b8e59`). The confinement of metadata file reads is in Phase 2.
- **Passkey attestation.** The device attestation formats are accepted only when
  `auth.passkey.attestation` is not `none` (`498304855`, `8b27243c2`).
- **Found on the way.** The Symfony update made the swoole bundle pool the profiler's
  Twig extension, which broke every Twig render in `dev` and `test`; the extension is
  kept out of the pool (`2e48bbdf2`). Two stale messaging inventories and an outbox
  leak in the tests are repaired (`d1f34dd66`). The e2e manifests are back in the CI
  image, where CI's dependency policy check reads them (`25a78848b`). The shutdown
  drill runs the staged checkout again (`f4b5b7b0b`).

Verification checkpoint: the full backend run on `45e5f744d` passed the native lane
(995 tests) and both stack lanes (2,228 and 1,940 tests). The ordinary lane (9,401
tests) failed one SQL convention scan on a health check message, fixed in `63da8ecc2`.
The later commits were verified with the security runtime drill, the changed test
files, the dependency checker's 84 tooling tests and the web, site and desktop checks
named in their commit messages.

### Delivered: Phase 2

Each commit message records the failing output before its fix and the checks after
it.

- **H2 and M2, library confinement.** The Shared kernel's media container table
  names the containers a library may hold; every FFmpeg and ffprobe input passes
  `-format_whitelist` for its input kind, and a scan imports a file only when its
  probed format is a container of its kind. The scanner skips links that resolve
  outside the library, and a transcode resolves its source through the library, so
  `POST /api/stream/sign` and `POST /api/transcode/sessions/` answer `409` for an
  escaping source. Before the fix, the tests read H.264 through a DASH manifest named
  `.mkv` (`99b5178ba`).
- **M1.** Output past the native bound fails only its own attempt, error output
  keeps a bounded tail, and the daemon keeps serving (`99b5178ba`).
- **M3, file side.** The metadata extract and match endpoints, their console
  commands and cover extraction read only files inside a library; a path that is not
  absolute is refused before any library is read (`6ba980b84`).
- **H3.** An original's media type comes from the container table, and every
  original and rendition response is an attachment with
  `default-src 'none'; sandbox`; every other non-HTML response gets a restrictive
  default policy. In Chromium and Firefox, the old headers ran the script and the new
  ones download the file (`fa7730562`).
- **Hardening notes and L12.** The page policy adds `form-action 'self'` and allows
  `http:` media only on an HTTP page, one nonce serves each page view, and the static
  handler refuses `.php` files and dot segments other than `.well-known`
  (`beea0b6a0`).
- **M4 and L3.** Auth bodies over 65,536 bytes answer `413`; login block fields are
  cut to 255 characters and blocks older than 30 days are deleted; the per-account
  failure limit reserves a failure before the password check. The 50-process race
  ran 50 password checks against a limit of 10 before the fix (`1eddd4812`).
- **M5 and M6.** Every address-keyed limiter keys IPv6 by /64; only super admins
  clear limiters, and each clear is logged with its actor (`06a62c0dc`).
- **M7.** At most 20 push subscriptions per user, the oldest evicted, and one
  Messenger message per subscription (`4237e9ccc`).
- **M11.** Every member-writable field and collection is bounded, count caps are
  checked under an advisory lock, and a migration adds the checks `NOT VALID`
  (`862c3d7ee`).
- **L6 and list bounds.** Page and limit are validated on the 14 page-numbered
  routes, and the playlist list pages with `meta` (`53cfba09b`).
- **L2 and recommendation writes.** Recommendation writes bind a validated request,
  record their origin, are capped at 1,000 member rows per user, and listings show a
  member their own and the global rows (`6231a4296`).
- **L1 and the radio part of L9.** A member's source list omits the sync
  configuration, the country catalogue is cached six hours, and the station list
  pages at most 500 rows (`648c31131`).
- **The transcode part of L9.** A per-user transcode session share, default 6
  (`44085b755`), that copy starts count toward (`4045721e4`).
- **L4 and provider reads.** IPv6 transition forms are judged by their IPv4 address,
  and provider answers are read up to a byte limit (`f72916f76`).
- **H4, M13 and the registry part of L10.** Readiness no longer latches on a failed
  probe (`b06be50ac`); the request and database read buffers are bounded
  (`44af1bfc3`); client and node certificate authorities are separate (`23105d9b0`);
  release builds carry hardening flags, OpenSSL is 3.5.9 and TLS 1.2 is limited to
  ECDHE with AEAD ciphers (`8ceca596c`).
- **The pairing part of L10.** A completed pairing is returned only after the expiry
  and server checks (`824ce8ea6`), and every refused pairing answers one `404`
  (`67b925944`).
- **M8.** The operator guide documents one installation, the preset; the Compose
  procedure moves to the developer guide (`5232d0e44`).
- **Generated client.** `eac10e7ab`, `1d3c15c0d` and `e4fb6fc56` regenerate the
  OpenAPI spec and the web client for these API changes.

### Delivered: Phase 3

- **M9, Redis.** Messenger envelopes are signed under `APP_SECRET`, and cached values
  carry an HMAC tag (`3ea35331e`). The preset's application connects as the Redis ACL
  user `baander`, and the `default` user is reset (`8e0c41a07`).
- **M9, PostgreSQL.** The preset runs the application as a `NOSUPERUSER` role that
  owns the database (`752de602e`).
- **M9, log agent.** The log agent reads Docker through a read-only allow-listing
  proxy (`5783943a6`).
- **M10.** Every image, download and Git source of the image builds is pinned, the
  local dependency check reports any unpinned input and image digest drift, the
  prebuild installer trusts only committed hashes, and on Forgejo a fork pull request
  runs no job on the runner that holds the Docker socket (`944605d1e`).
- **M12 and L11.** The desktop renderer runs sandboxed, stores and trusts only a
  server's origin, loses the credential read channel, opens DevTools only unpackaged,
  and keeps only safe discovered servers (`508bcfccf`). The player loads its DSP
  modules relative to the build base, so they still load from `file://`
  (`1ab64d3c6`).
- **L5.** The web client encodes every path parameter, and a lint rule reports a raw
  value in an API path (`6b04e5390`).
- **M14 and L7.** Production logs at `info`, correlation IDs and request URLs are
  bounded, preset services rotate their logs, and both nginx configurations log the
  path without the query (`69e250319`).
- **L8.** An unknown environment refuses to boot, and secrets and passwords have
  minimum lengths at boot and in the preset, which also refuses every committed value
  (`2a4498344`).
- **Hardening notes and L13.** Disabling an account advances the credential epoch, a
  refused refresh answers `invalid_token`, the pending TOTP secret is sealed, and a
  user keeps at most 20 personal access clients (`0a686e063`). GD decoding is bounded
  to 50,000,000 pixels and FLAC metadata parsing to a 32 MiB budget (`fd1067312`).
- **L14.** The security guide records the burned development secrets in Git history
  and the checks that refuse them.
- **Installation run.** The documented preset installation ran once end to end on
  `e4fb6fc56` (`53027fbcd`); the installation page's Verified section carries the
  evidence. The run predates `2a707bcc8`, which reaches a preset through a rebuilt
  database image.
- **Found on the way.** `SchedulerWorkerRunnerTest` seeds a job due only in its own
  minute, so a minute rollover no longer publishes two occurrences (`7acb50157`).
  `ClientJwksFetcher` reads through `PublicHttpClient::content()` (`418af2f14`). The
  simplification pass: `e1f2cfff3`, `19f774259`, `e1664e94b` and `154935634`.

Code review: `ce-code-review` over `9524a6fd9..53027fbcd`, 18 reviewers, verdict ready
with fixes. Its four confirmed findings are fixed. The application role owns the
database and could plant an operator or function that the preset's superuser SQL
ran; the superuser session and the verification SQL now pin `search_path`
(`2a707bcc8`, reproduced on PostgreSQL 18.7 before and after). The qualification's
Redis sampler authenticated as the disabled `default` user and recorded a failure on
every sample (`4597eaf50`). A share of 1 or 2 sessions refused every automatic start
of a 720p or larger video (`52b6118af`). A raw `/` in a `REDIS_URL` password was
refused (`adb9526fa`).

Verification checkpoints: the combined backend run over the Phase 2 work found four
stale test expectations (`40bdba4e1`), and the whole-tree checks after Phase 2 found
five more, among them the interleave test's 10-second handshake under host load
(`372dc91da`). After Phase 3, `InitialSchemaMigrationTest` met the first irreversible
migration (`2698fb77e`). The preset qualification has not run on this work: the code
review found that its Redis sampler would have reported a failure on every sample
(`4597eaf50`). The worker load proof with
8 video and 12 audio jobs needs a live deployment and has not run. The Playwright spec
for path encoding parses but has not run.

Final verification, on `715da1832` (after the PHP 8.5 pass and the import pass): every
changed PHP file lints, PHPStan is clean on the whole tree with the application and the
tooling configurations, Deptrac reports 0 violations, `check:tooling-format` and
`check:tooling-source-policy` pass, and the web suite (3,911 tests), type check and lint
passed on `1d3c15c0d` plus the two web test fixes in `372dc91da`. The last full backend
run that was read, on `1d3c15c0d`, passed stack lane 1 (2,454 tests); its other failures
were the stale migration list fixed in `2698fb77e`, the stale test double fixed in
`372dc91da`, and three timing tests that pass alone. A full backend run and the full
tooling suite were started on `715da1832` on 2026-11-25 at 19:57 and never read. The
full runs on `8661c86ad` cover this work: the native lane, both stack lanes and the
tooling suite passed, and the ordinary unit lane passed on `3935fa941` (see the
pipe-operator entry).

### Decisions confirmed by the owner

- 2026-11-24: DPoP refuses RSA proofs. URL wrappers are off in every image. The
  passkey attestation formats follow the setting. A dependency update takes every
  package inside its range, and Yarn installs no version younger than three days.
- 2026-11-25: A signed original opened as a page downloads. Only super admins clear
  rate limiters. Member content caps as the plan lists them, with 20 push
  subscriptions per user. Six encode sessions per user, admins included. A start that
  finds no free video slot may end another user's session only after 70 minutes
  without a playback report. The radio station list returns at most 500 rows per
  request, and the country catalogue is cached for six hours. Sign-in block records
  are kept 30 days with fields of at most 255 characters. The unused `unplugin-info`
  plugin leaves the desktop build. The operator guide documents one installation, the
  preset. An unknown `APP_ENV` refuses to boot. Redis and PostgreSQL passwords need at
  least 16 bytes, checked when the preset renders and when the application boots in
  `prod`. The desktop preload loses the channel that reads a stored credential and
  keeps the ones that store and clear it. Discovery accepts `http:` only for a private
  or loopback address literal or a `.local` name. The preset's services log through
  Docker's `local` driver, five files of 50 MB, and per-boot log directories older than
  14 days are pruned. Fork pull requests on Forgejo run no job on the runner that holds
  the Docker socket. Committed test secrets are refused by the preset, not yet at boot.
  Copy starts count toward the same per-user session share as encode starts
  (`4045721e4`). An unknown, expired or other server's pairing code answers one `404`,
  so a caller cannot tell whether a code is live for another server (`67b925944`).
- 2026-11-25, after the code review: a low "Video sessions per user" setting makes an
  automatic start ask for fewer quality tiers, at most the share, rather than raising
  the setting's minimum to 3. A share of 2 gives 360p and 720p, a share of 1 gives
  360p (`52b6118af`).

### Open

Residual risks, recorded in the threat models:

- Signed envelopes carry no expiry or nonce, so a process that can write to Redis
  can replay a captured one; the impact per message type was not assessed. Cached
  values are authenticated without their key, so such a process can move a valid
  value to another key, and it can still change rate-limiter state and WebSocket
  tickets.
- Sessions use `RedisSessionHandler`, which `AuthenticatedMarshaller` does not cover.
  No route starts a session today; a feature that does should wrap the handler in a
  `MarshallingSessionHandler` over the authenticated marshaller.
- An undecodable envelope is dropped without a failure-transport row, and a signed
  envelope the codec refuses is retried and dropped with one critical log line.
  Rotating `APP_SECRET` drops the jobs queued at that moment; the credential rotation
  page says to drain the queues first.
- The Redis ACL's command list rests on a source search. It grants `EVAL` but not
  `EVALSHA`, `SCRIPT`, `MULTI` or `EXEC`, and a missing command would show as
  `NOPERM` at the first preset start.
- Container inspect through the Docker proxy returns every container's environment,
  `APP_SECRET` included.
- PostgreSQL runs an extension's install script with `search_path` set to `public`,
  which the application role owns; an extension added to the preset's list later
  would run its script there as the superuser.
- Forgejo fork routing is not a hard barrier: a fork that edits the workflow files
  can name the socket runner, so fork runs must stay behind Forgejo's approval.
  Routing keys on `head.repo.full_name`, so an AGit-flow pull request routes as a
  same-repository one; whether the approval hold covers AGit pull requests is not
  verified.
- A 6to4 or Teredo answer whose carried IPv4 addresses are public is admitted, as the
  plan decided.
- The deferred part of L8: the committed test values are refused by the preset only.
  A boot check would break the 56 test files that boot production kernels on them.
- The registry's static OpenSSL is built without `-fcf-protection`, so CET is not
  enforced at runtime.
- Pairing completion reads and saves the session without a lock; a race between two
  completions of one code is unproven.
- A coroutine started inside a request finds no token; no such coroutine reads
  security state today, by a source search. Likewise by source search only: every
  FFmpeg and ffprobe input goes through the input clause (a filter that opens a file
  itself, such as `subtitles=` or `movie=`, would not show), and no audio rendition
  takes a video file as its source under the audio-only whitelist.

Behaviour gaps that remain (the roadmap open items entry of 2026-11-26 closes the rest):

- No server-side LAN discovery responder exists. The desktop client accepts an
  announcement whose fields are at most 256 characters, `http:` only for a loopback
  or private IP literal or a `.local` name, stores the origin only, keeps at most 64
  servers, and shows the responder's address with a mark for unencrypted servers; a
  responder and the native apps should follow the same rules.
- `App\Media\Infrastructure\Converter\ImageConverter` is deprecated and has no callers;
  its removal is the owner's decision, after reading its history.
- A member's recommendation `POST` that races a generation run on the same key can
  still answer `500`; see the 2026-11-26 entry.

Preset and operations:

- The preset cannot yet rotate the OAuth keys offline, rotate the database password,
  rotate `APP_SECRET` with webhook delivery held, or run maintenance with PostgreSQL
  up and web and workers stopped; the maintenance pages say so. Its 35-second
  `stop_grace_period` ignores `WEB_DRAIN_SECONDS`, and worker readiness under the
  preset has not been observed.
- `DB_USERNAME=postgres` passes preset validation but fails at start, after the
  volumes are created, with a message that names internal variables.
- Worker containers that `DeploymentContainerRecipe` creates keep the Docker daemon's
  default log driver. `REDIS_PASSWORD` is still rendered into the web and worker
  environments, although `REDIS_URL` carries it.
- The development stack's Redis keeps the `default` user with `requirepass`, and its
  `docker-compose.yml` needs eight log-collector variables (since `1575b1ea7`) that no
  command writes; `compose-stack.md` documents the gap.
- The 64 MiB cap on the IPRD catalogue was not checked against the live catalogue.

Build and supply chain: closed by the roadmap open items entry of 2026-11-26.

Tests and gates:

- Not run: the preset qualification and the worker load proof with 8 video and 12
  audio jobs. The 2026-11-26 entry records the Playwright path-encoding spec and the
  original-media browser check.
- The 2026-11-26 entry closes the missing tests, the skipped-test item and the two
  PostgreSQL findings.

## Security review findings

The [security review findings plan](docs/plans/2026-11-22-1418-fix-security-review-findings-plan.md)
resolves every finding of the 2026-11-20 authentication and authorization review,
re-verified on `905d7da1e`. The review document is retired; read it with
`git show 905d7da1e:SECURITY_FINDINGS.md`. Each finding and its resolution, with the
commits that deliver it:

1. **First-party client through the public pushed request and code grant, with silent
   consent.** The pushed authorization, token and device endpoints refuse the
   first-party client with `unauthorized_client`, and the consent page with
   `invalid_client` and no redirect. Consent is always explicit, and personal access
   clients are no longer first-party. Each access token records its sign-in grant, and
   first-party authority needs a password or passkey sign-in (`a0ffc1c41`,
   `306227154`, `dcae94bc0`).
2. **Delegated token without `library` fetching original media.** The original track
   and video routes need `library` for a presented token; a signed URL fetch without
   a token still works. A unit check fails when a public route reads the caller's
   identity (`97428933b`).
3. **Preset deployment putting every client in one rate-limit bucket.** Gateway and
   web share a dual-stack edge network, web trusts exactly the gateway's pinned
   addresses, the gateway writes every forwarding header itself, and client addresses
   pass through only from declared front proxies or the host's bridge on a loopback
   listener (`cd225b952`, `46430bc42`, `a598c25b2`).
4. **Percent-encoded paths bypassing the auth and API rate limits.** Every limiter keys
   on the matched route, and an inventory test holds the limited routes to the
   compiled ones (`cdd1e368c`).
5. **TOTP turned off without the password, with no attempt limit.** Turning TOTP off
   needs step-up, the current password and a current code, under the per-account
   step-up limit (`e7bf7630c`).
6. **Password change as an unthrottled password check that skips TOTP.** Password
   change needs step-up with the TOTP code when TOTP is on; every mismatch answers
   `step_up_failed` (`e7bf7630c`).
7. **Party host pausing and seeking another user's transcode session.** A party
   carries only a job its host owns and loses it when the owner leaves or is revoked;
   non-admin job listings show only the caller's jobs; a play after a pause resumes
   the encoder (`d4c35e50d`).
8. **Pre-registered account keeping the registrant's TOTP and passkeys.** A
   verification link confirms an address only in a signed-in session of the account
   it was sent for, so the owner's password reset claims the account (`46bf3bafa`).

The low-severity items:

- **Open self-registration with full access before verification.** Self-registration is
  the `auth.registration_open` server setting, closed by default. Both open outcomes
  answer no sooner than 500 ms after the hash, so the timing does not reveal taken
  addresses, and the configuration check warns while registration is open on a
  `null://` mailer. An unverified account reaches only what confirming its
  address needs (`36ce7b428`, `dcc7b5577`).
- **WebAuthn origins accepting any subdomain and port.** Ceremonies accept only the
  public origins on the relying party, the host of `APP_URL`, matched exactly; sign-in
  is discoverable only; passkey endpoints answer fixed reasons, and the check endpoint
  runs the sign-in checks (`fb80263f9`).
- **Sessions surviving sign-in method changes; passkey removal without step-up.**
  Turning TOTP on or off, adding or removing a passkey, changing the password and
  confirming a new address end every other session and close its sockets through one
  fenced service; passkey removal needs step-up and records `PasskeyDeleted`
  (`34381444b`, `e7bf7630c`, `46bf3bafa`).
- **Role change and user deletion leaving sockets open.** Deleting a user closes their
  sockets after the commit (`df3281c40`). Closing sockets on a role change was
  considered and not built: the live runtime re-reads roles within about a second.
- **Live HLS and DASH URLs serving after access is revoked.** Live delivery re-checks
  the job owner's access on every request, as original and artifact delivery do
  (`b6e9e7151`).
- **Committed VAPID key pair, Redis and database passwords, and a tracked dev TLS
  key.** Production refuses the committed credentials, and the development key,
  certificate, `cert.ini` and CA certificate are deleted (`b7206492e`).
- **`app:dev:create-users` without an environment guard.** Both development commands
  run only under the `dev` kernel or with `BAANDER_DEVELOPMENT_INSTANCE=1`, which
  only the development lifecycle sets (`103dc0391`).
- **No guard for the last super admin.** Deleting, disabling or demoting the only
  enabled super admin answers `409` `last_super_admin`, or exit 1 on the console,
  under a transaction-scoped advisory lock (`df3281c40`).
- **Device and authorization codes stored unhashed.** Both are stored as SHA-256
  digests; user codes stay plain (`57a4601c1`).
- **Transcode delivery firewall patterns wider than the routes.** The rows and the
  live routes match public IDs, `s<index>` subtitle keys and `full.vtt`
  (`b6e9e7151`).
- **Activity history naming items from libraries the user lost.** Activity names only
  items inside the viewer's current access (`a1c5c5b57`).
- **Absolute server paths returned to members.** Only administrators read library,
  song and video paths (`a1c5c5b57`).
- **The remaining items.** Public `/health`, `/ready` and `/live` answer status only,
  both shipped gateways answer `/metrics` with 404, and the full report is the admin
  route `GET /api/debug/health` (`7fefe1b45`). The web app shell, its CSP and absolute
  API URLs render a configured public origin, never a forged `Host` (`1c5d762fd`).
  Images are served with their stored, allowlisted type and a sandboxing CSP
  (`a6998f170`). The discovery QR payload is a `POST` under the discovery limit, and
  creating a pairing session deletes expired ones (`c52d808e0`). Discogs and Last.fm
  searches spend a per-user limit and accept 1 to 100 results (`c52d808e0`). Passkey
  endpoints no longer return exception text or list a user's credentials
  (`fb80263f9`).

Also delivered on the branch: every `/api` client error answers in the error envelope
with a translated message (`9fc97fadd`, `71dd0b5ce`).

Code review (twelve reviewers plus a second security pass, verdict ready with fixes)
added: a whitespace-only registration name no longer tells a taken address from a free
one, and a registration that fails after the hash still waits out the response floor;
TOTP enable closes the other sessions' sockets before it forgets the pending secret
(`e24d7fb29`); a password reset that races an operator's disable or demotion no longer
reverts it, shown first by a two-process race test (`1f1531443`); the super admin race
test covers demotion, disable and deletion (`6d4b69643`); a refused party join answers
in the request's language like an unknown party (`3d5066477`); the register page reads
the registration state with a direct request (`2a20cde13`). Failures inherited from
master and fixed here: Person and TV capture coverage and cascades (`8e2aace42`,
`5c99c6273`), movie public ID routes (`ef74d1c2f`), the swoole-bundle inventory
(`795f191b4`), five unit failures (`d2a5e6f6b` to `53598d4f0`) and four tooling
failures (`b4f0b1b60` to `63727ec5c`).

Verification checkpoint, 2026-11-22, branch head `29ba8790f`: `make ci` on `6d4b69643`
passed Composer validation, PHPStan (no errors) and Deptrac (0 violations); its backend
suite selected 14,503 tests in 1,318 files, the unit lane failed 8 party tests and the
native lane 1 production-kernel test (both fixed in `29ba8790f`, party unit suite 213
tests OK), and both stack lanes hit the runner's one-hour Docker timeout under host load,
so a full rerun was still in progress at the merge. Merged batches in the CI image: 3,182
tests in 243 files (auth, controllers, OAuth integration), 3,907 in 331 files (after the
simplify pass), 441, 148 and 76, all green after their fixes. Web: 3,876 tests in 317
files, typecheck clean; the OpenAPI drift check and `generate:check` match. Tooling: 1,735
tests with 6 failures inherited from master, fixed and rechecked (75 tests OK);
`analyse:tooling` no errors; `check:tooling-architecture` exits 0. Browser, against a fresh
preview: the auth, settings and admin e2e specs passed 35 of 41 at `6d4b69643`, with the
register specs failing until `2a20cde13` and three admin specs failing on a stale route
(see Open); at `63727ec5c` auth and settings passed 18 of 20, with one timing-sensitive
volume spec failing under concurrent CI load after passing in the first run. The preset
qualification was not run.

Open:

- The WebSocket handshake still accepts only `APP_URL`'s origin (or the explicit
  `app.websocket.allowed_origins` list), so a page served on an `AUTH_EXTRA_ORIGINS`
  origin gets a CSP socket for that origin but its upgrade is refused. Deriving the
  allowlist from the public origins needs the swoole-bundle `WithWebSocketHandler`
  configurator to take the policy instead of an array, because its container
  expression is evaluated at compile time, where the `csv:` placeholder of
  `AUTH_EXTRA_ORIGINS` is still a string.
- Gates to finish: the full `make ci` backend suite on a quiet host, the preset
  qualification, and a quiet rerun of
  `tests/e2e/settings/sign-in-conflict.spec.ts`.
- `tests/e2e/admin/admin.spec.ts` (from the initial commit) still targets
  `/admin/monitor` and a "Job Monitor" heading; the app redirects that route to
  `/admin?tab=jobs`, so three of its tests fail on master and here.
- Review residual risks: credential-change transactions take the user row lock without
  the issuance lock timeout; `VerifyEmailHandler` reads `isDisabled()` before the lock, so
  a confirmation racing an operator disable is untested; members' song file paths are
  stripped by each caller rather than once; TOTP enable attempts are bounded only by the
  generic per-account API limiter.
- The preview of the main checkout (`preview-fd790a887b6899cb`) is blocked on a migration
  history the dev environment will not reconcile; its database was preserved.
- Deferred by the plan, each separate work: binding personal access pushes to a
  secret held by the client's owner, so nobody else can push as that client; a
  sessions list where users see and end their devices and app grants; a TOTP settings
  screen in the web app, where TOTP setup and disable remain API-only.

## Typed roles, permissions and scopes

Decided 2026-11-22, to be planned with `ce-plan` as its own refactor after the
security review findings branch (`fix/security-review-findings`) merges. Replace
the string references with enums for roles (`ROLE_USER`, `ROLE_ADMIN`,
`ROLE_SUPER_ADMIN`; 151 uses in 68 source files and 74 test files on that
branch), the voters' permission attributes, the OAuth scopes and the
authentication attributes such as `IS_AUTHENTICATED_FULLY`, and add builders for
permission paths. `security.yaml` keeps its YAML strings, held to the enums by a
test. What the permission-path builders cover is to be settled when the plan
starts.

## Person credits and catalog follow-ups

The current Person profile lists credits for cataloged works. TV file scanning,
playback and catalog-wide management remain separate from TV credit registration.
Concerts need Catalog targets before they can use Person credit relationships.
Provider-wide filmographies need their own visibility rules. Importing more
MusicBrainz recording, release or work relationships needs mapping and
precedence rules. [People and credits guide](docs-book/part-1-operator-guide/media/library-management.md#people-and-credits).

## Frontend build warnings

At `fc8d9305`, the production build measured 2322.71 kB for the minified app,
578.15 kB for HLS and 742.80 kB for Three. Vite still warns about their sizes.
The full-feature HLS entry is a single module. Three loads when the visualizer
opens. App/vendor or route splitting needs a measured benefit and smoke coverage
of the built application.

## Admin and terminal log viewers

Physical remote-host TLS deployment remains unqualified. The recorded two-node drill
passed eight checks for authentication, partial coverage during an outage and recovery,
but both nodes ran on one Docker host. See the
[operator guide](docs-book/part-1-operator-guide/operations/log-viewer.md) for the current contract.

## Forge-neutral CI

Open items:

- Dual-forge acceptance is partial: no `master` commit has yet run every gate green
  on both forges, the gate workflows have not run through a pull request on each,
  and no scheduled nightly has run on each.
- Forgejo acceptance is deferred (user decision, 2026-11-05). Every job of the oldest
  queued run that ran inside the Forgejo registry's `baander-runner:latest` (built
  2026-10-15) failed within a minute, and Forgejo 15's API serves no job logs or
  cancellation. Resuming needs: cancel the stale runs in the web UI, read one failed
  job's log there, and rebuild the Forgejo runner image from the current tree.
- Unverified on GitHub: whether Forgejo 15 scopes its workflow token by
  `permissions`; whether socket-launched test containers bind-mount workspace paths
  on the hosted runner; and whether the backend gate fits a hosted runner's memory
  and disk.
- The frame observer's lagged tolerance measures the snapshotted frame's duration,
  which equals the callback frame's for the constant-rate fixtures; a variable-rate
  source would misclassify. The lagged rate has no ceiling of its own beyond the 60
  percent coverage thresholds.
- The copy's lead after the first throttle pause is unmeasured. FFmpeg's read-rate
  clock keeps running while the producer is stopped, so a resumed copy may publish
  its accrued allowance in one burst; measure the published end across a pause and
  resume cycle before changing the rate.
- A native pause reaches the playback session only with the next presence
  observation, up to ten seconds later, so a reload inside that window recovers an
  older cursor.
- The held-frame transcode fixtures publish the frames generated after the source's
  last packet about ten seconds late, so playback stalls for about ten seconds in
  every held-frame run; the runs pass.
- `uniq_transcode_jobs_owner_user_id_video_id_quality_tier_name` names three of its
  five columns.
- `ui/web/src/features/session/live-coverage.ts` lists only `listening_sessions` and
  `devices` as the sources of `session.sessions`, although the snapshot also reads
  the four playback tables; whether that capture should stay is undecided.
- A cancelled Forgejo run may skip the step that removes its per-run BuildKit builder
  and leave that builder's container on the shared daemon.
- `PublicationWorkflowTest` is about 1,400 lines, including a workflow-expression
  evaluator and a tree linter that could move into test-support classes.
- The coverage comment steps still interpolate the stored baseline into `node -e` as
  code.
- `PackageStore` error paths without tests: restore into a non-empty target,
  constructor validation, and saving a missing directory.

## System overview pages

Open items:

- The Mermaid helper's own tests (`ui/site/scripts/render-blocks.test.mjs`) render
  only the three original flowchart pages, so the book's first sequence diagram is
  checked by the website build and the browser spec but not by the helper's font,
  marker and no-foreignObject assertions.
- The browser spec's diagram page list is kept by hand; nothing asserts that every
  docs-book page with a Mermaid fence is listed.
- Historical `docs/solutions` entries describe the removed task transport and a
  combined consumer; the docs-book follow-ups deliberately retained them as history.

## Interactive manual diagrams

Open items:

- No built page carries two diagram figures, so the shared document listener, the
  hint ids and cross-figure pin release are untested in a browser; the spec also
  lacks an OS-level theme change without the toggle and a wrapped (`<br/>`) sequence
  message.
- The render helper's `describeDiagram` option exists only so a test can force an
  unmatched edge; it should not grow further test-only hooks.
- The inlined SVG carries its own embedded font subset, so page font slicing never
  sees diagram labels; a diagram with non-Latin labels has not been checked.
- The diagram fade uses the fast duration token with the browser's default easing;
  `ui/DESIGN.md` states ease-out for the app shell and is silent for the static site.

## Small-host household deployment

The user confirmed the
[small-host household deployment plan](docs/plans/2026-11-02-1819-feat-small-host-household-deployment-plan.md).
It defines a separate profile for 2,000 songs, 50 movies and 250 TV episodes, with 1–2 simultaneous streams alongside normal scanning and metadata updates.
[System Requirements](docs-book/part-1-operator-guide/getting-started/system-requirements.md) publishes the measured figures and their limits.

The [capability and size deployment settings plan](docs/plans/2026-11-07-1804-feat-capability-and-size-deployment-settings-plan.md)
retains the 4 GB qualification. No host size is qualified; the user deferred that
run on 2026-11-12. The 4 GB `remux` starting point keeps its starting values until
that run measures them.

Open items:

- The 4 GB `remux` qualification run (U9) has not run; the user deferred it on
  2026-11-12 so that U1–U8 could land first. A first attempt on 2026-11-07 was
  stopped during setup. It reuses the QEMU guest in `~/.cache/baander-household-vm/`
  (start it with `-m 4096`, no libvirtd needed). The images `baander-app:local`,
  `baander-worker:local` and `baander-operator:local` were built from fa240b3d0,
  before the merge with `master`; rebuild all three from the current checkout first.
  It needs three comparable repetitions of the one-player scenarios, restart,
  recovery and deployment replacement on 4 GiB with swap off, then the
  insufficient-memory run on 3 GiB; KTD8 governs any size reduction. Afterwards,
  publish the values in `deploy/preset/example-4gb-remux.json` and the measured host
  size in System Requirements. Until then no host size is qualified, and the
  published measurements are those of the previous household profile from
  3 November 2026.
- At the last qualification attempt, the local `baander-database:latest` image lacked the
  `baander-pooler-credentials` executable the preset runs. Rebuild the database image from `docker/postgres/Dockerfile` before the preset can start.
- The 8 and 16 GB starting points are unqualified guidance; their qualification is
  deferred. ARM64 images and Raspberry Pi hardware are outside this plan.
- Qualification harness follow-ups from review: the deployment-replacement scenario
  does not check fixture borrowers; recovery fails if web or worker processes restart
  during the PostgreSQL outage; `Qualification.php` grew to about 1,900 lines and its
  recovery and replacement logic could move into its own class.
- `docker-compose.yml` now loads the rendered 16 GB PostgreSQL file, so the source
  installation's `max_connections` is 35 instead of PostgreSQL's default 100; check
  that host tools connecting directly still fit the four direct connections.
- `make docs` is shadowed by the `docs/` directory and needs a running preview;
  declare it `.PHONY` or document `php scripts/build-website.php`.
- The web control socket twice closed an `app:server:stats` request without answering under load (household repeats 1 and 2, 2026-11-03); the cause is unknown. The harness now retries and records such samples.
- After a PostgreSQL crash the worker pair stops ("deployment reconciliation is required") and stays stopped until an operator intervenes.
- Affected browser playback specs and the worker runtime container suites have not run against the new profile.

## Configurable media capacity

Open items:

- At the default 8 software video slots, the reference host keeps about two 1080p
  `libx264` encodes in real time, not eight, so operators who convert video in
  software must lower the slots. The default was the user's choice and is unchanged.
- Not run: the controls drill at 8 + 12 (the default drills keep the 3 + 1 fixture),
  and NVENC, QSV and AMF on real hardware. The NVENC device-request shape that
  containment verification compares was not checked against a Docker NVIDIA runtime.
- The Debian 13 database change required existing volumes to run `REINDEX` and
  `ALTER DATABASE … REFRESH COLLATION VERSION`. The later PGroonga move checked
  the persistent instance without finding collation or index errors; other existing
  volumes have no recorded acceptance.
- Transcode execution ownership renewal runs on the application pool, where a client
  can wait up to PgBouncer's default 120 s; the ownership lease is 15 s. A burst of
  long application transactions could delay renewals past the lease.
- The control pool fails a media authority query that waits more than one second;
  slot counts above 8 + 12 are unproven against it.
- The pooler's `admin_users` is the application role, so web and worker processes
  could pause or stop the pooler; the development checks need only `stats_users`.
- When a progress report races the write that records a failed encode, that write is
  refused and the failure stays unrecorded on that path.
- The governor's warm-up encodes predict about 40% of the host per 1080p stream,
  against about 21% measured for full encodes.
- Test gaps: the functional suite connects to PostgreSQL directly, so
  transaction-pooling incompatibilities surface only in the worker drills, and
  `HostCpus` and the boot-row fingerprint check constraint have no direct tests.
- The development `docker-compose.yml` sets no `oom_score_adj`.

## Playback destinations and notifications

The user confirmed the
[playback destinations and notifications plan](docs/plans/2026-11-01-1840-feat-playback-destinations-and-notifications-plan.md).
It covers player-owned destination selection, sidebar notifications, music and video transfer, and AirPlay, Chromecast and DLNA alongside Bånder devices.
Local and remotely hosted servers are included, with a local casting companion where needed.

Receiver grants, the companion, protocol adapters and physical receiver qualification remain open,
as do Cast registration and hosting.

Available hardware is now confirmed as an iPhone 13 and Apple TV 4K, alongside
Linux, with no Android device. AirPlay hardware qualification remains outstanding; physical Chromecast
and DLNA receiver checks remain open because suitable receivers have not been identified.

Queue authorization currently checks tracks individually; the receiver
authorization work will assess a bounded Catalog batch contract without weakening
per-item access checks.

Safari automatic local recovery remains unavailable; ordinary playback is retained.

The browser owns its local music/video
execution independently of companion presence, pairing or connectivity. The companion
plan explicitly prohibits claiming or acknowledging browser endpoints.

The first reload-recovery run retained the correct duration but did not resume
after Play. One instrumented rerun passed reload, tab-close and renderer-crash
recovery with the duration assertions. The first failure remains an unexplained
intermittent resume issue; the rerun does not establish its cause.

## Bånder Audio Core in the server transcoder

Open items:

- Decide whether `ffmpeg-baander-static` stays public. The image is built with
  fdk-aac under `--enable-nonfree`, which FFmpeg marks as not redistributable, and
  GHCR serves it anonymously. The user allowed the public package (2026-11-07) before
  this was raised. The alternatives are making the package private again (CI pulls it
  with its workflow token) or dropping fdk-aac, which removes the `fdk_aac` position
  of the setting.
- Check that `ffmpeg.yaml` ran and published on Forgejo. Forgejo CI reads
  `ffmpeg-baander-static:latest` from the Forgejo registry and fails at
  "Build CI image" without it.
- NVENC and QSV were not run on hardware; the capability gate only proves their
  components are compiled in.
- Not run: the stream-copy pacing re-check that
  `docs/solutions/performance-issues/pace-a-stream-copy-with-a-read-rate-burst-counted-from-the-seek-boundary.md`
  asks for at the next FFmpeg major. The testing guide's statement that `-shortest`
  buffers up to ten seconds is unverified on FFmpeg 8.
- Found and not fixed: the NVENC HDR path emits `tonemap_cuda`, a filter neither
  Debian's FFmpeg nor this build has;
  `MediaWorkerRunnerTest::testSerialAudioJobsReuseOneSlotWithIncreasingLaunchGenerations`
  failed once under CPU load and passes alone; GitHub reports 178 Dependabot alerts
  on the default branch (3 critical, 78 high).
- Review advisories left for a decision: a rendition whose source codec ffprobe
  cannot report decodes with FFmpeg's default decoder instead of the selected one;
  the libbac decoder labels 7- and 8-channel output with an unspecified layout unless
  the container gives one; it neither trims nor drains decoder delay, which matters
  only for MPEG Surround sources; the unused `FFmpegAdapter` per-segment encoders read
  the live setting rather than a session's.

## Learning and plan maintenance

The shared durable-consumer heartbeat can mask an individual consumer's outage;
that limitation is recorded for follow-up, with no runtime change in the 2026-10-31 learnings refresh.

## OAuth pushed authorization requests and auth hardening

The [pushed authorization requests plan](docs/plans/2026-11-19-1148-feat-oauth-pushed-authorization-requests-plan.md)
and [auth-hardening plan](docs/plans/2026-11-19-2119-fix-auth-security-hardening-plan.md)
retain final-revision acceptance gates. Their code is implemented; the required
verification on the final revision remains incomplete.

Open:

- The CSP, step-up passkey registration and web TOTP login were not checked in a
  browser: the preview could not start from this worktree.
- `/oauth/revoke` is a first-party route, so a delegated token cannot revoke itself;
  RFC 7009 revocation for third-party clients is separate work.

At `3473a20c3`, the stack lanes passed 3,950 tests without coverage after fixture corrections, but
coverage did not complete within the 3,600-second budget. Its current fit remains
unverified; see the [coverage budget investigation](docs/solutions/developer-experience/coverage-runs-push-the-stack-lanes-past-the-backend-budget.md).

## Web dependency refresh

The 2026-10-31 dependency checkpoint retains these limits. TypeScript 6.0.3 remains in the
web and shared packages because typescript-eslint requires a compiler below 6.1;
the site and embedded player use 7.0.2.

The [maintenance guide](docs-book/part-2-developer-guide/contributing/contributing.md#javascript-dependency-maintenance)
documents offline ownership checks and explicit online dependency, advisory and
patch inspection. Nine exact site resolutions reduce the Vercel dependency tree
from 44 advisory records to 22, removing its critical finding. Remaining fixes
require incompatible transitive releases. The web lint tree retains one moderate
minimatch advisory requiring version 11; its compatible Babel security update is
applied. These findings remain visible in online output. Yarn still reports three
upstream dash.js peer requirements in the `@svta/cml-*` packages; the real DASH
playback check passes for the tested path.

## PHP dependency and runtime refresh

Implementation is retained at the user's wrap-up checkpoint on 2026-10-31.
Qualification is partial; the remaining checks below are recorded follow-up work,
not authorization to continue expanding this upgrade.

Swoole bundle reconciliation: unconfigured session defects are recorded as
deferred defects; readonly proxy support remains unselected.

The local dependency checker returned the same seven Swoole fork inventory
findings and one Redis rate limiter validation evidence gap before and after
Composer normalization. Review the changed fork files and the checker's
interpretation of Composer output.
[Fork inventory](packages/swoole-bundle/upstream-review.json),
[Composer evidence collector](packages/baander-dev-dependencies/src/Php/ComposerEvidence.php).

Five root packages remain behind stable because the latest reli-prof 0.12.0 pins
their shared dependencies: Monolog, Webmozart Assert, Amp, Parallel and
MonologBundle. Exact versions, upstream references and solver blockers are
recorded in the
[dependency guide](docs-book/part-2-developer-guide/getting-started/php-dependencies.md) and tooling
policy.

TSDuck native qualification is blocked, as explicitly accepted by the user on
2026-10-31. Its required PHP 8.5/FFI/PHPUnit 13 probe fails during bootstrap because
the bindings declare `tspyphp*` polling bridge functions absent from upstream
TSDuck v3.43-4549 and the Docker build. Repository history contains no bridge
implementation to recover. Native qualification and automatic CI wiring remain
open for separately scoped C++ bridge work. The probe fails rather than skipping;
no native callback or lifetime pass is claimed.

Six operator-dependent tooling cases and the remaining registry modes still
require their separate qualification. The registry release and sanitizer gates
pass on the final qualification derivative; the thread gate was interrupted at
the user's request.

The complete backend result of 10,966 tests and 120,760 assertions applies to its
recorded earlier tree. No final full backend or reduced-tooling rerun is claimed.
The remaining media matrix and registry thread/fuzz/container/backup gates were
not completed at the wrap-up checkpoint. GitHub `master` at `7aa0172fb` was merged
into the upgrade branch; no full application or AAC suite was rerun for this merge.

## Cohesive live updates

All nine units and the five independently confirmed review fixes were delivered
on 2026-10-31; the recorded qualification leaves these limits open.
Memory reclamation was observed; warm byte counts exceeded cold baselines. This
finite workload does not establish indefinite memory stability. Remote metadata
conflict/deletion and preference conflicts use component/coordinator and hook
proofs; these are not browser conflict tests. The 10,000-album Timeline fixture
has no covers, so it does not measure image fanout. The earlier exited
scan-worker pair's cause remains unidentified; fresh worker health and the
subsequent real scan pass are the recorded qualification.

## Quiet live-update status

The watcher without freshness tracking has only helper-level coverage, and the
label-absence check covers only the connecting phase.

Open:

- The stalled-projection scenario is unproven here. In one branch run the outage
  started and the toast showed, but live status did not return to current within
  30 seconds of the release; the old label check would have passed while
  "Updating…" lingered. A preview where the stall degrades reliably is needed to
  tell a lingering refresh from an environment fault.
- The record-play and owned-audio scenarios fail on `master` too; their cause was
  not investigated.
- The admin bell keeps its own relative-time helper, which has no date fallback
  after 30 days, beside the shared one; consolidating it changes old admin rows.

## Website feature pages

Still to do:

- Check the `Public website` and `CI` workflow runs of `464fad883` on both forges.
- The user has not reviewed the pictures; the plan's Definition of Done requires it.
  They are live on `https://baander.app/` and `https://baander.app/features/`. The
  contact sheet in `var/site-capture/` shows the last capture run, not the
  committed pictures.
- No lyrics page. `README.md:46` and
  `docs-book/part-1-operator-guide/media/library-management.md:64` say lyrics come
  from `.lrc` files beside the tracks, and the scan stores them on the song. The
  lyrics panel reads only lyrics fetched from LRCLIB
  (`src/Lyrics/Interface/Controller/LyricsController.php:71-87`), so `.lrc` lyrics
  never reach it. User decision, 2026-11-18: the panel is to show the stored lyrics
  too, and the two documents stay as they are. Next: plan that application change,
  then add the lyrics feature page with its scene and pictures.
- Application defects seen while taking the pictures, none fixed here:
  - Player: while music plays, the player bar has no shuffle or repeat button; the S
    and R keys work and show no state. Crossfade is a stored preference with no
    control. The player bar's cover request is answered 403, so it shows a
    placeholder.
  - Playlists: the web application cannot remove a song or change the order. An
    album's "Add to Playlist" adds only the first track. Rows are numbered from 0.
    After a create on the Playlists page, the list stays blank until the page is
    opened again. Collaborators are listed by public id, not name. The share
    dialog's switch thumb overhangs its track when on.
  - Library: the album details panel lists tracks out of order for 5 of the 15
    sample albums. The home page's "Recently Added" row is sorted by title, not
    date. A double-click on an album card fails while the side panel is closed.
  - Destinations: the menu names a browser only as it registers itself, and "Rename
    device" does not change that name.
  - Administration: the AAC select in the Media settings group is cut off, job rows
    have seven cells in a six-column grid, and the job panel prints durations such as
    `0.208213ms`. The Add Library form offers types whose scanning is not
    implemented.
- Documents and the application disagree:
  - Passkey sign-in: `docs-book/part-1-operator-guide/administration/user-management.md:83,88`
    state it, and the web login form has no control for it. The overview no longer
    mentions passkeys.
  - The homepage says movies move between devices like music. A test shows it
    (`ui/web/tests/e2e/playback-destinations/video-transfer.spec.ts`), and no
    maintained document states it.
  - `app:radio:sync` syncs only countries a user subscribes to, and the web
    application never calls the refresh route.
  - `library-management.md:131-139` presents cover extraction as a step after each
    scan, but ingestion already queues it.
- Capture tooling: sign-in occasionally times out waiting for the login form; a
  second run gets past it. The playlists scene's create branch has not run in its
  final form, because the preview already holds its playlists. The destinations scene expects
  the names "Chrome on Linux" and "Firefox on Linux", and WebKit does not start on
  this host. The preview checks run through `make exec`, which honours `INSTANCE`,
  while the browser follows `--base-url`; nothing ties the two together.
- The homepage's font-arrival stability test at 1440 px failed its own precondition
  (Geist had not arrived) once in eleven local runs.
- The plan still says ten pages (R2, U7); the lyrics page is the difference.
- Smaller copy follow-ups from the claim and writing checks:
  - The overview's Internet radio entry could say that stations come from the
    countries listeners subscribe to (`commands/app-radio-sync.md:3,39`).
  - Two "More in Bånder" links point at pages that state only part of their entry.
    For Internet radio, `/docs/developer-guide/contexts/radio/` states all of it;
    for accounts, `/docs/operator-guide/commands/app-library-member-grant/` states
    who grants library access.
  - The homepage's fourth equalizer slide speaks of device profiles, which its new
    picture does not show; the first slide's picture shows them.

## Website reference pages and fold-out menu

The package inventory page remains excluded. It needs a per-package snapshot
refreshed with shipped images and a rule for Debian license declarations that
contain copyright text instead of license names.

The Strategy and Concepts pages publish their source documents as they stand,
including the competitor table in `STRATEGY.md` and the maintenance note at the top
of `CONCEPTS.md`. Other intermittent layout-shift failures remain unexplained;
the header first-paint defect was corrected and passed 25 targeted checks and
the 219-case browser suite.

## Presentation register and homepage hero

Open:

- The hero's copies in the homepage template, the search description and the social
  card are paraphrases kept in step by hand; no test compares their content.
- The Bånder Audio Core lead and the docs-book introductions can use the register
  on request; the website feature pages already use it.

## docs-book rewrite

- Four Prometheus gauges are never emitted; `app:album:duplicates` takes a library
  UUID that no command prints.
- Domain events mapped to notification categories (`AlbumCreated`,
  `TranscodeJobCompleted`, `TranscodeJobFailed`) are never raised.
- Policy questions the workers left open: the playback snapshot exposes UUID
  primary keys although public payloads use public IDs; the Electron tests run on Node
  24 in CI while pages say 26; the database rotation step that reruns
  `pooler-credentials` is derived from the script and has not been drilled.

## docs-book rewrite follow-ups

- The transcode steer runs before the broadcast; if it throws, the party state is saved
  but the room receives no `party.playback_state`.
- `PlaybackStateChanged` is a domain event, so every host play, pause and seek also
  writes an outbox row that no durable consumer reads.
- `HW_BITRATE_MULTIPLIER` reaches `SegmentEncoder` and the hardware diagnostics, but
  `SegmentEncoder::applyBitrateMultiplier()` has no caller, so encodes never apply it.

## Party invitations

Not built, by the plan: a library-access check on invite or join, a way for an invited
user to see or decline invitations, a notice to a revoked socket, a cap on invitations
per party, invitation events and a console command.

Open:

- An uninvited join takes the party's row lock and runs two more queries before it is
  refused, while an unknown ID is refused before dispatch. The answers match; the
  response times differ, and a holder of the ID can briefly queue on the lock.
- A worker that dies between a revoke's or a leave's commit and its event leaves the
  user's sockets on other workers in the room until they close. The member row is
  gone either way, so the user cannot re-enter.
- When the room listener fails after the commit, the caller gets an error although
  the change is stored; the listener closes the whole room first.
- Ending a party reads the host without the row lock, so a host demoted during the
  same request can still end it.
- Deleting the user who hosts a party deletes the party by cascade without
  `PartySessionEnded` and without closing its room. The fix belongs to the user
  deletion path in Auth and needs a decision on whether the party ends or passes to
  another member.
- Nothing prunes the rows of ended parties, and no retention rule says when they
  should go.
- An invite whose user is deleted between the lookup and the insert fails on the
  foreign key and answers 500.
- `handlePartyJoin` treats any `NotFoundException` from the join handler as a hidden
  party, not only `PartyNotFoundException`: Deptrac forbids the WebSocket controller
  that Party class. The join handler throws no other not-found outcome today.
- The plan lists as a risk that a long-lived WebSocket worker can hold party entities
  from an earlier message. The review traced that each socket message runs in its own
  coroutine whose entity manager is cleared on release, so the exposure is one
  message, not the worker's lifetime. That trace was read from the code, not run.
- `AudioTranscodeStreamTest::testEachSupportedFormatProducesItsCodec` fails on
  `master` in the historical local CI run; its cause was not investigated there.

## Notification pane well

Open:

- The warm mood's one-level differences at rest are unexplained. The computed
  declarations are the same as before. Most of the differences follow an animated mood
  switch and vary from run to run, which points at how the browser rasterises partial
  repaints; 11 pixels differ without one. The plan made a changed rest state a reason
  to stop and ask, and the user accepted this difference on 2026-11-17.
- No CI job runs `tests/e2e/styling`, and its specs now all need the preview's fixture
  executor.

## Styling specs and sign-in settings conflict

Open:

- On a narrow screen, a playing track that arrives after sign-in opens the context
  panel as an overlay, which takes focus and closes an open popover such as the
  notification pane. This predates the work; the user chose to record it as a product
  question.
- After a volume drag, the footer slider shows the previous value until the listening
  session reports the new one. The spec now waits for the value; how long the snap-back
  shows was not measured.
- Both `visualizer-calibration.spec.ts` tests fail before and after this work; their
  cause was not investigated.
- `tests/e2e/live-updates/recovery.spec.ts` was not usable as a check on this preview:
  its managed-restart case restarted the preview, whose release preparation then
  failed, and every later test failed against it until `make preview-up`. Its delivery
  outage case did start an outage through the repaired `interruptDelivery`.
- `controls.spec.ts` "admin library tabs" and parts of `appearance-matrix.spec.ts` fail
  on the preview's admin account before and after this work; they were not attributed.
- If a preference family's first read never runs in a session, for example while live
  updates never become ready, the playing track keeps the panel's tab and volume
  changes are not saved for that session.
- WebKit could not launch on this host: Playwright reports missing `libicu74`,
  `libxml2` and `libflite1`. The handoff spec did not run in WebKit, and no container
  route exists for preview-backed specs.

## Cohesive operator manual

Qualification limits remain explicit: its verification did not deploy a new
instance, scan user media, rotate secrets, replace workers or restore data. The
current worker credentials contract cannot provision private Discogs tokens for
queued enrichment; documentation now identifies that runtime gap. Coordinated
application restore, actual registry destination/RPO/RTO and whole-host capacity
remain unqualified. The revised source-build procedure is source-verified, without
a new bootstrap run.

## Local development workflow

Open: in a linked worktree the PHP tooling suite fails up to three
`ApplicationInventoryTest` cases (the two `testInterruptedCollection…` cases always,
`testShellRecoversUncertainCreationWhenCollectorIsInterrupted` sometimes) because a
marker does not appear within its window. `RepositoryConfiguration::local` reads the
primary checkout's `dev.local.json`, which slows configuration loading there. All
three pass in a standalone clone and with `CI=1`.

Open: automatic updates run the controller tooling installed by the last
`make dev-up`, which can be older than the images they deploy. On 2026-11-13 the
persistent instance's update to `60fc26b63` (the capability and size merge) started
the new application container, then failed while resolving the worker profile: the
installed tooling, from 2026-11-09, passed the settings to `DeploymentProfile` in the
constructor's old argument order, and the image rejected them ("Argument #2
($webWorkers) must be of type int, null given"). No database change had started.
The instance stayed blocked, refusing every later update, until `make dev-recover`
and `make dev-up` ran from a current checkout. A contract change between the tooling
and the image needs either a controller generation that updates with `master` or a
versioned profile request the image can refuse with a reason.

## Playback investigation and evidence limits

An admission request can allocate a producer before a lost response or a server
error leaves the browser without the session identifier. That cleanup case still
needs server-side reconciliation using the Playback client-session identity. The
cancellation must identify the former owner's admission so it cannot cancel its
successor's producer.
[Playback controls](docs-book/part-2-developer-guide/development/video-playback-controls.md#audio-tracks-and-timing).

**Frame capture:** the historical native timestamp of 24.64 seconds versus a
24.60-second callback remains unexplained. Failure diagnostics are delivered and
the mismatch did not recur in the prescribed runs; these results do not prove its
cause or universal capture stability. The
[sparse playback guide](docs-book/part-2-developer-guide/testing/adaptive-playback.md#sparse-movie-playback)
retains the contract and evidence. No tolerance change or further implementation
is selected by this historical failure.

**Qualification boundaries:** bounded adaptation and endpoint proofs do not
establish uninterrupted full-source playback or universal format compatibility.
Keep these limits separate from selected work:

- Safari/native HLS, DASH browser playback and subtitles, full live FFmpeg DASH
  EOF, formal CMAF conformance, interactive audio switching, native caption-menu
  interaction and caption pixels remain unqualified. The
  [testing guide](docs-book/part-2-developer-guide/testing/media-and-playback.md) distinguishes tested
  HLS/Chromium/Firefox workflows from these paths. The separate native pressure
  observer still expects the old HLS muxer output and is not qualified for the
  current demuxed output.
- General variable-rate gaps, timestamp discontinuities, additional origins,
  arbitrary containers, hardware and untested browser/format combinations remain
  outside the measured cases. A [Selected video endpoint](docs-book/part-2-developer-guide/testing/movie-endpoint-playback.md#selected-video-endpoint-playback)
  is qualified only for its calibrated metadata paths; missing or stale metadata
  still uses an estimate. Existing checks do not establish general cache or
  performance qualification.
- Software colour checks qualify BT.2020 SDR handling and PQ/HLG tone mapping;
  they do not establish general HDR browser acceptance, complete reference-vector
  coverage, display-gamut certification or Dolby Vision support. See the
  [testing guide](docs-book/part-2-developer-guide/testing/media-and-playback.md) for the measured cases.
- Native codec acceptance remains conditional on the selected release path.
  [AAC reference coverage](packages/baander-audio-core/docs/README.md) does not
  exercise the 34-band and phase decoder paths; listening tests and the HE-AAC
  IPC/bridge suites were not run in that checkpoint. The original 5% HE-AAC CBR
  target was not established by the delivered 6% acceptance gate. These limits
  do not select unused codec work or reopen intentional encoder design decisions.
- Other existing limits belong in the [scan guide](docs-book/part-1-operator-guide/commands/app-library-scan.md),
  [Transcode context](docs-book/part-2-developer-guide/contexts/transcode.md) and
  [messaging guide](docs-book/part-2-developer-guide/development/cqrs-and-messaging.md).
  Delivered directory publication does not complete the broader event-producer
  transaction-boundary audit. None of these boundaries selects a new task here.

## Registry release acceptance

The [Registry guide](relay/README.md) documents implemented contracts, deployment,
encrypted backup and recovery, packaged dependency inventory, and existing
qualification runners. Its [release acceptance](relay/README.md#release-acceptance)
section retains the full whole-host budgets and workload thresholds.

- **Constrained deployment:** qualify the agreed five-host optimized profile,
  including snapshots, compaction, catch-up and backup upload. Each whole host has
  1 CPU and 512 MiB; API-container limits and short local load smoke do not establish
  whole-host acceptance. Supply target hosts or an explicitly agreed equivalent
  measurement environment before selecting this work. Sanitizer workers may be larger.
- **Performance baseline:** select a stable runner and accepted baseline, then add
  comparison enforcing confirmed regressions above 10%. Existing bounded smoke
  measurements do not establish that baseline.
- **Long soak:** reserve a 24-hour workload window on the intended deployment
  profile. After warm-up, compare equivalent quiescent windows; fail sustained RSS
  growth over 1 MiB/hour or 10% of baseline.
- **Destination recovery:** qualify encrypted, versioned five-minute S3-compatible
  backups at the actual destination and rehearse isolated restoration with a
  five-minute RPO and 30-minute RTO. Disposable TLS S3 upload and offline restore
  are covered; actual-destination access is unavailable.
- **Regional deployment:** obtain access and inventory for `de`, `ca`, `sg`, `au`
  and `fi` (unavailable, user confirmed 2026-10-04). Validate the actual five-voter
  topology, rolling upgrades preserving volumes and bootstrap guards, no
  acknowledged-data loss after two-voter failure, and leader recovery within
  30 seconds. Region names alone do not supply deployment access.

The nightly workflow is on the default branch and locally verified. Hosted
scheduler activation and a scheduled run are not verified by the evidence here.
Use the documented
[nightly procedure](relay/README.md#nightly-qualification-and-retained-diagnostics)
when checking that deployment decision.

## Application release acceptance

- **Recovery:** rehearse application backup restoration before migration rollout.
  Follow the [upgrade guide](docs-book/part-1-operator-guide/maintenance/upgrading.md); this
  prerequisite does not reopen covered fresh-install or migration behavior.
- **Redistribution:** resolve the application's observed notice, provenance and
  embedded-dependency gaps before qualifying a release image. The
  [application review record](third_party/inventory/application-review.md) retains
  the inventory evidence and verification checkpoint; follow the
  [collection procedure](dev-docs/application-packaged-inventory.md) for each new
  artifact. Preserve the boundaries in [THIRD_PARTY.md](THIRD_PARTY.md).
  The separate Registry inventory is outside this remainder.
- **Selected native media path:** when a release explicitly includes an active
  codec/DSP path, resolve its remaining reproducibility and reference-vector
  acceptance before claiming certification. Use the
  [DSP qualification guide](packages/dsp/README.md) to identify the current evidence
  and limits. Browser graph checks alone do not certify native codec behavior;
  an unwired module's incomplete qualification does not create a release task.
- **First production release:** record acceptance evidence before cutting it.
  There are no production deployments. Remove the temporary pre-release policy in
  [AGENTS.md](AGENTS.md) only when the user explicitly announces the first release;
  a tag, build or deployment preparation does not make that announcement.

## Deferred scope

React Native work, including the Android and Android TV targets in `ui/rn`,
remains deferred at the user's request (2026-10-05). The separate `ui/android`
application was removed at the user's request on 2026-10-26. Resume feature, API
and tooling reconciliation for `ui/rn` only when the user resumes that work.
Historical mobile failures do not select a task or a new CI lane.
Essentia remains out of scope under [AGENTS.md](AGENTS.md); this cleanup does not resume it.

## Earlier acceptance and limits

### Geist and Noto typography

Open:

- The later styling checkpoint covered 105 cases, of which 91 passed; its remaining
  failures are recorded under [Styling specs and sign-in settings conflict](#styling-specs-and-sign-in-settings-conflict).
  A first-paint measurement remains unrecorded. The stylesheet measurement was
  456 kB raw and 157 kB compressed, including about 412 kB of `@font-face` rules.
- Considered and not done: song resources still split the album title and language
  into two maps; radio genre chips render genre names without a content language;
  `input` and `textarea` use a 2.0 line-height inside the fixed 2rem field height,
  which WebKit may render with the caret off-centre.

### Website typography page

The Korean samples need a native-speaker check, and the header's `↗` renders from Noto Sans SC because Geist's
Latin slice lacks it.

### Website layout stability

One scaling factor per weight range cannot match every
line, so a long page dense with inline code still wraps a few paragraphs differently
in the fallback, and headings that fill their column to the pixel can wrap in the
bold fallback; the medium weight renders in the regular face and runs 1.5 to 2.4
percent narrow. Linux Chromium with full font hinting can still gain a line in the
fallback on the swap. Diagram pages are not in the shift check, and Firefox is not
measured.

### Source-installation documentation

The recorded source-installation check used a named checkout volume for the remote Docker daemon,
a supported controller container for host PHP extensions, an accepted disposable
self-signed certificate and internal networks. It did not qualify the literal
host-PHP procedure, external metadata providers, other media workflows or host
hardware. The [source-installation guide](docs-book/part-1-operator-guide/getting-started/installation.md)
contains the current procedure; the pinned history above retains detailed evidence,
suite counts and environment adaptations.

### AAC codec gap closure against the fdk-aac upstream

These items remain open:

- **Low delay and dynamics:** the LD-SBR windows and frame grid, the RVLC escape
  codebook and the HCR reordering rules were written without the standard's
  tables and round-trip within the codec only; the DRC metadata path is not
  available for the low-delay types. Not run: interop for ELD with LD-SBR and
  for the RVLC and HCR tools.
- **USAC decoder:** the harmonic transposer, PVC and inter-TES are implemented
  but unverified, since the FDK encoder emits none and no stream at hand uses
  them; stereoConfigIndex 2 is verified by construction only.
- **MPEG Surround decoder:** TTT prediction mode 0 and the centre-subtraction
  modes 2 and 3 are verified by construction only, since libxaac's own output
  breaks there; energy modes whose two level differences exceed about 48 dB
  cannot be matched against libxaac's fixed-point weights; the object type 30
  form is checked against the in-band form only (40 dB or better); SBR input
  (HE-AAC plus surround) is wired but exercised by no stream; LD-SBR with the
  spatial payload, temporal shaping in the low-delay path and ELD frame lengths
  other than 512 are not covered; the 7.1 trees, the HRTF and binaural modes
  and the 3D mode are refused. Streams from a third-party MPEG Surround encoder
  were not run; none exists as free software.
- **xHE-AAC encoder:** the LPD core, the live loudness leveler, the 8:3 and 4:1
  SBR ratios, residual and phase coding in MPS 212 and surround input to the
  USAC encoder are out of scope as planned; the one-pass figures cannot enter
  the stream they were measured from, since a configuration cannot change
  mid-stream; the meter takes up to six channels, so 6.1 and 7.1 input has no
  in-encoder measurement; FFmpeg master's LATM path refuses USAC, so its gate
  runs on the MP4 form.
- **Player-grade controls:** the night set's gain codes stop at -31.875 dB, so
  a programme anchored near -47 LUFS or lower cannot hold -1 dBTP; the FDK
  decoder's front end still loses frames where a chunk boundary lands just
  after a configuration (its own fault, fed frame by frame it decodes whole);
  on corrupt speech frames the stabilised synthesis filters keep the output
  finite, but it can still peak near 1e13. Not run: the ten-minute fuzz runs
  per harness under libFuzzer (no clang runtime here).
- **Encoder quality and speed:** encode speed with the afterburner on (36×
  realtime against FDK's 83× and Apple's 79× in the same run), xHE-AAC at 24 to
  32 kbit/s, Apple's true VBR (0.17 to 0.19 PEAQ ahead at 128 to 160 kbit/s;
  true VBR on sparse tonal items such as glockenspiel), the TVBR quality scale
  (quality 50 gives about 230 kbit/s), bandwidth at 192 kbit/s and up (PEAQ and
  ViSQOL disagree), Apple's coupled stereo SBR and additional sinusoids,
  xHE-AAC rate accounting and the louder low band of low-rate xHE-AAC decodes,
  and the decoder's loudness default. The
  [codec comparison](packages/baander-audio-core/docs/codec-comparison.md) keeps the
  prioritized list and the [Apple probe note](packages/baander-audio-core/docs/apple-aac-probe.md)
  the remaining gaps.
- **Not run across the tiers:** listening tests, the ISO conformance suite, and
  speech, 44.1 kHz, multichannel and low-delay codec comparisons.

Figures and limits are in the
[reference comparison](packages/baander-audio-core/docs/reference-comparison.md) and the
[codec README](packages/baander-audio-core/docs/README.md).

### Development controller image identities

Open:

- **Instances that need a data reset:** the preflight refuses a changed applied
  migration; the preview `preview-fd790a887b6899cb` had applied five migrations that
  `de7942d73` later rewrote. An instance that was running when its release was pruned
  also stops at a named refusal, because only the lost operator image could retire
  its worker registration. Both need a data reset, which needs the owner's approval.
- **No reset command:** the tooling has none. The preview's reset on 2026-11-11,
  approved by the owner, was done with `docker volume rm`, `docker network rm` and by
  removing the instance's directory under the controller's state root.

### Movie playback on growing presentations

Open, known limits:

- **Stop, close, play elsewhere:** a closing tab now reports its close and the next
  tab plays at once. A tab that crashed, was killed or lost its network sends
  nothing: the session stays held until that tab's 30-second registration lapses,
  and then "Play here anyway" ends it.
- **Join lead:** a sibling that encodes slower than playback is not offered, so on a
  host where 1080p encodes at about real time it never appears; each join still
  replaces the attachment once.
- **Ended-session reason:** it is inferred from job, execution and session state. A
  cancel that leaves an in-progress job still referenced by another session reads as
  expired, and the player restarts it.
- **End-of-stream landing:** the restore holds 250 ms on the landing and keeps a
  one-second backstop, because the hook cannot see hls.js end the stream.
- **Registration proof in Chromium:** local recovery releases the runner before
  registration, so only the Firefox run binds the proof to the server change. The
  page identity now also releases a reloaded page's runner in the registration
  itself, in every engine.
- **Command disclosure by another incarnation:** a WebSocket transfer or registration
  payload carrying another of the same user's incarnation ids is returned that
  incarnation's commands and marks them shown. It can only block a settlement.
- **Player hook size:** `useBaanderPlayer.ts` passed 1,100 lines; its session restart
  resets about twenty closure variables by hand.
- **Finished presentations:** these keep their sessions and are reclaimed by the
  cache sweep's 24-hour TTL, not by job cleanup.

### Releasing a browser runner that went away

Open, known limits:

- **Stale close-report proof:** the prepared proof is renewed with each presence
  report; a hidden tab whose timers are throttled to about once a minute can close
  with a proof older than the server's 60 seconds, which is refused, and the session
  stays held until "Play here anyway".
- **Crash, killed browser, discarded mobile page:** no close report is sent; the
  override after the 30-second lapse is the way back.
- **Browser proof:** the close report and the override are proved in Chromium only.
  In Firefox, 2 of the 5 cases pass (closing a tab released the session in 74 ms);
  after navigating away, the session was not released within 8 seconds, and whether
  Firefox did not send the keepalive report or the request was lost is not
  established (the next step is to log it on the server). No browser spec drives a
  duplicated tab or a back/forward-cache restore; the unit and PostgreSQL tests
  cover them.

### bAAC exact arithmetic

Open:

- CI writes the committed streams and checks against them with the same compiler; a
  second compiler in the Linux job is the requirement that catches a regression.
- The published speed tables and per-stream decoder figures in the package's
  documents were measured with the fast-math build; FDK and libxaac are not on this
  host to measure again.
- MSVC and toolchains other than GCC and Clang get none of the options, and other C
  libraries are unverified.

### Master CI repair

The Composer normalization fix passed validation, the normalization dry run,
PHPStan and Deptrac in the CI image. The backend coverage run selected 14,195
tests across four lanes. It was stopped during the ordinary lane, before any
lane reported a result. Full coverage acceptance remains open.
[CI target](Makefile#L141).

The last recorded complete quality gate passed on `44e315ee3` (13,028 tests in
2,276 seconds); that run then failed the obsolete private-image check, removed
in `9e7d164bd`. A later all-green hosted CI run is not recorded here. The separate
coverage-budget gap remains under OAuth acceptance above. Each run selects a
new test-order seed, so order-dependent failures may still surface.

The registry release job once failed on a load-smoke loop; the recorded retry
passed. Its cause remains unestablished. Dual-forge acceptance remains under
[Forge-neutral CI](#forge-neutral-ci).

### Playback session entities

Open:

- **Unreviewed baseline:** the eleven tables still in `schema_filter` and the
  existing stores that run DBAL statements have not been reviewed against the new
  rule, which neither approves them nor requires converting them.
- **Query cache:** the measurement's entity manager uses an in-memory query cache;
  production configures `cache.system`. The cost of a DQL statement there is
  unmeasured.
- **Control cost:** a control that issues a command costs two more statements than
  the SQL store and 1.26 times its time, inside the bound of 1.5.
- **Associations with a database cascade, audited 2026-11-11 by reading:** 51 to-one
  join columns carry `onDelete` (39 `CASCADE`, 12 `SET NULL`) in 33 entity files; the
  earlier count of 29 files left out four that carry only `SET NULL`. No schema
  listener adds one on an association column, and no mapping uses `cascade` or
  `orphanRemoval`. None is reachable today: the failure needs managed children, the
  parent removed through the ORM, and a later flush with something to write, and for
  every pair at least one is missing. Three places are one change away and have no
  test that would catch it:
  - `PruneMissingImagesHandler` removes images in a loop with a flush each, and is
    safe only while the owning albums and artists are uninitialized references;
  - `AlbumRepository::delete` detaches songs without skipping uninitialized ones, and
    covers neither credits, genre links nor activities (`MovieRepository::delete` has
    the same gaps);
  - `UserRepository::delete` has eleven child classes, and an administrator deleting
    their own account already holds managed token entities, so any write added after
    the delete in that request would fail.
  Not covered: `tests/`, and the long-lived runners were not traced line by line.
- **Not run:** the media-control gate, whose code this branch does not touch.
- **Store preconditions:** `register()`, `saveTransfer()` and `insertCommand()` look
  a row up and then insert it, which is safe only under the account lock that
  `lockSession()` takes inside the transaction port. The coordinator is their only
  caller. The store states this in docblocks and does not assert it: the contract
  tests call the three outside a transaction, and an open transaction would not prove
  the lock.
- **A job another run removed:** when two cleanup runs overlap and the other one
  deleted a whole job first, this run still counts that job as cleaned.

### bAAC lossless mode

Known limits:

- **Failed gate:** in the recorded run (run 6, 2026-11-12, AMD Ryzen 7 5800X3D, GCC
  16.2.1, `flac` 1.4.3, FFmpeg 8.1.3) 37 of 38 gates passed and every stream is
  bit-exact. The failed gate is the held-out size gate on the 96 kHz 24-bit piano
  recording: 9,821,712 bytes against 9,795,735 for `flac -5`, 0.27 % larger. By the
  user's decision of 2026-11-12 the mode lands with the failure disclosed in the
  results, the comparison and the website (KD12).
- **Frame search behind damage:** the decoder's search has a work budget, which on
  input crafted to overdraw it may pass over a frame right behind the damage (KD10).

Open:

- Better compression of material like the failed held-out recording is its own plan
  (KD12). It counts as tuning in answer to the third set, so it ends with a fourth
  fresh held-out set and a new recorded run.
- The server's lossless renditions and a FLAC codec in the FFmpeg wrapper are their
  own plans (KD3). The wrapper plan decides how the C structs are versioned.
- No speed was measured on ARM or with MSVC, whose build writes Rice codes with the
  shared loop and not the AVX2 kernel.
- FLAC in fragmented MP4, the Electron bridge and a browser playback matrix belong to
  the feature that delivers FLAC to clients.
- The harness selftest runs in no CI job; it needs numpy on the runner.
- Until `audio-core-v0.2.0` is published, installs build the binding from source;
  the older `aac-native-v0.2.0` tag remains held.

### Bånder Audio Core rename

Open:

- **Remote:** the release's four prebuilds and `SHA256SUMS`, a clean desktop install
  that fetches its prebuild, and Forgejo `ci.yaml` after Forgejo's FFmpeg image
  publishes (the user set Forgejo CI aside).
- **Not run anywhere before the landing:** the macOS and NEON kernel step, the
  Windows MSVC binding and the first CI run after the cache keys changed. Only the
  binding workflow covers them, and it first runs on `master`.
- **Desktop:** after the final binding change only the immutable install was rerun;
  the earlier `tsc`, test and packaging passes do not qualify that final change.
- The release did not exist at the last recorded check on 2026-11-14. Three Linux
  x64, Windows x64 and macOS x64 prebuild attempts passed, but GitHub never assigned
  a macOS arm64 runner in run 43478502093 and skipped publication. Recheck remote
  state before rerunning the failed job.
- Move the benchmark data from `packages/baander-aac/bench/work/` in
  `/home/martin/dev/baander-baac-lossless` to `packages/baander-audio-core/bench/`.
  Copy `work/heldout/runs.json` first, then run `./rename_ledger.py work/heldout/runs.json`
  and `./setup-refs.sh` without `--lossless-only` to replace the old absolute paths.
  Do not read the fourth held-out set before this move and ledger rewrite.
- Website visual checks were not repeated manually after the rename. Later
  artifact browser checks cover search, themes and narrow layouts, without
  establishing manual live-site visual acceptance.

### Scan artist credits, song lengths and lyrics outcomes

Open:

- A single artist value that names several artists stays whole in the scan (R7);
  matching the album splits it. On 2026-11-14 the user chose matching over splitting
  on an album-artist prefix in the scan.
- Deferred, each separate work:
  - album identity across directories: albums have no uniqueness on title, and
    duplicate detection with `app:album:merge` covers the outcome until album identity
    is planned;
  - a file's song found by hash across libraries (`findByHash` is catalog-wide): a
    file shared by two libraries needs its own identity design;
  - genre slugs collapsing non-ASCII names, and Unicode (NFC and NFD) spellings of one
    name becoming two artists: both predate the branch, and no real library has shown
    them yet;
  - a forced-sync rename followed by a wrong ID: no code path reaches it today.
- From review, not fixed: the import handler's size and its `PreparedSong` array shape
  (a split into a tag-credit reader and a rescan repair was suggested); genre creation
  by the metadata enrichers takes no import lock, so an enricher that creates a genre
  while a running import creates the same slug fails on `uniq_genres_slug`, and the
  album enricher logs that genre failure and goes on (locking it needs a transaction
  around the enricher's flush, which is separate work); a retag is any byte change at a
  song's path, so a library-wide ReplayGain pass replaces hand-made credits on the next
  scan (the settled retag decision).
- Housekeeping: the postgres-remediation `doctrine.md` version list is stale.

### Matching follow-ups

Open:

- On an instance that updates: a lyrics fetch queued before the update carries no
  queued flag and fails to decode, and its song stays marked for a day. The
  persistent instance updates itself from `master`, so a bulk run in flight at that
  moment loses its remaining fetches until a later run. A match request a run held at
  the update keeps its due time and is matched within the hour.
- A metadata sync does not request again an album that is on the review list with an
  unchanged song count. "Ten Thousand Fists" therefore keeps its `no_candidates`
  review on the persistent instance until an admin opens its match dialog, which now
  lists candidates, and applies one. Several 14-track editions tie at score 100.
- The pause has met no real 429. A `Retry-After` in date form takes the fallback of 5
  and then 15 seconds, so the request could be sent again inside the time LRCLIB
  named; the warning logged with the first real pause shows which form LRCLIB sends.
  A stored pause ends early only when its Redis key `lyrics:lrclib:pause` is deleted.
- The claim's lease is not renewed. A match that takes longer than 15 minutes lets a
  second run take the album: MusicBrainz is asked twice, and the apply's row lock and
  release recheck keep a second write out.
- Not run on the preview: an album merge, because its five albums hold no pair to
  merge. `AlbumMergeTest` (14 tests) runs a merge through the real matcher and apply.
- Waits that a worker's keepalive alarm can still cut short: the webhook delivery
  back-off (`WebhookDeliveryService`), and the `Retry-After` waits of the Discogs,
  Spotify and TMDB adapters, which no worker reaches today.
- From the code review, not built: a test that fails when the MusicBrainz adapter
  goes back to the interruptible sleep. The other test leftovers were delivered by
  [Matching leftovers](#matching-leftovers).
- From the simplification pass, left as they are: the same User-Agent string in the
  LRCLIB, MusicBrainz and Cover Art Archive adapters; the ranking of candidates
  written twice, in the dialog's search handler and the matcher (predates the branch);
  `nextRequestDueAt()` reads every requested row since it counts a claimed row from
  its claim's expiry; `LrclibClient` takes a sleep closure where the Transcode context
  uses `SleeperInterface`.
- Qualification: the 4 GB run is still to do. Its cold start gets its album matches
  from the admin sync, since automatic matching is off by default. An album the queue
  leaves for review keeps the count below 100 until the two-hour maintenance deadline,
  and that failure names no album.

### Matching leftovers

Open:

- Writes beside a merge: a play read before the merge and saved after it can
  overwrite the folded play count (`RecordPlayHandler` reads and writes without a
  lock), and an existing play saved after the merge loses its album
  (`MediaActivityRepository::syncToEntity` looks up the deleted source). A favorite of
  the target added after the merge's statement waits and then fails with 23505, which
  the add maps to nothing.
- Artist sync:
  - an artist whose only empty detail fields are locked is queued by every admin sync;
    it makes no request but takes a two-second step. The six fields are listed three
    times (`SyncArtistHandler::DETAILS`, its `missesDetails()` and the query in
    `ArtistRepository::identifiedWithMissingDetailsAfter()`);
  - an album artist that only an import's album credit creates is filled by the next
    admin sync, not by the import;
  - one outage moves the shared due time of every later fill by its pause of 5, 15 or
    60 minutes, and a 301 to anything but another artist lookup counts as an outage,
    tried again three times at every admin sync;
  - after a cancel, a new admin sync queues none of the cancelled run's artists until
    their cancelled fills fall due;
  - a fill whose requeue after an outage could not be sent keeps its queued mark for
    up to a day once its retries are spent;
  - `InMemoryQueuedArtistSyncs` shares no contract cases with
    `RedisQueuedArtistSyncs`;
  - release and recording lookups of a merged ID still answer as outages; the adapter
    can report them the same way.
- Deferred by the plan, each separate work: the other hand edits that save a copy read
  before a concurrent commit (`UpdateAlbumHandler`, `SetCoverHandler`,
  `RemoveCoverHandler`); the import's repair of a known song without a row lock;
  deleting an artist leaves the `credits` mark on the songs it uncredits; one failed
  match request ends the other requests of the same import; favorites of an album or
  song deleted outright dangle; `MovieRepository::delete` has the gaps the album
  delete no longer has.
- From the simplification passes, left as they are: the credit lock order in a static
  method of `ArtistCreditInput` with eight parameters; the release loop in each of the
  four album merge adapters; the job-run records of the artist sync store and the
  lyrics store; `ArtistMatchWriter::text()` and `ApplyMatchHandler::text()`.
- Point 2 of
  `docs/solutions/architecture-patterns/concurrent-imports-find-or-create-identities-under-sorted-advisory-locks.md`
  cites the removed assigner; it is due at the next learnings refresh.
- `dev-docs/worker-runtime-inventory.md` holds 15 links that were broken before this
  historical branch.

### PGroonga cleanup race

Open:

- From the code review: `PgroongaLeftovers::clear()` sets no `lock_timeout`, so a
  lock holder that PostgreSQL does not cancel (an anti-wraparound autovacuum, or a
  connection of the same test process holding uncommitted DDL on a PGroonga table)
  would hang the lane until the step's budget instead of failing at once; and the
  guard exempts a whole file once it calls the helper, so a later cleanup in that file
  after new leftovers would pass it.
- `pgroonga_list_broken_indexes()` was not tried against a broken index on 4.1.0;
  on 4.0.5 it raises "object isn't found" instead of listing the index.
- Deferred by the plan, each separate work: a template database instead of about 500
  migration replays per run; the test stacks start PostgreSQL with its defaults, not
  `docker/postgres/conf/postgresql.conf`; nothing checks that the local default test
  image `baander-database:latest` is current, and on the development host it was a
  Debian 12 build until 2026-11-16; an upstream report of the cleanup collision.

### Lyrics effect toggle and song browser panes

Open: the pane fix has no automated test, because jsdom computes no layout; a
Playwright check of the songs view would cover it. Not run: `yarn build`, and Firefox
or Safari.

## Completed work and evidence

The roadmap and plans before this cleanup are preserved at
`707dd55c24e073ea902fe42a92c830d77b777b37`. Read the delivery narratives and suite results with
`git show 707dd55c24e073ea902fe42a92c830d77b777b37:ROADMAP.md`.
List the plans with `git ls-tree -r --name-only 707dd55c24e073ea902fe42a92c830d77b777b37 docs/plans/`,
then read one with `git show 707dd55c24e073ea902fe42a92c830d77b777b37:<path>`. Earlier retirements
remain available at the revisions named in that roadmap.

The 2026-11-22 cleanup retired 13 delivered plans. The 12 retained plans match
the pinned revision. All 45 local roadmap links and six incoming fragment links
resolve.
