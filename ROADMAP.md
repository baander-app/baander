# Baander roadmap

## Resume here

Choose work from the [retained plans](docs/plans/) and the open items below.
The [November backlog](#roadmap-open-items-2026-11-26) covers selected repairs.
[Registry acceptance](#registry-release-acceptance) and
[application acceptance](#application-release-acceptance) describe the evidence
needed for release. [Deferred scope](#deferred-scope) records work the owner
has set aside.

The [testing guide](docs-book/part-2-developer-guide/testing/README.md) covers
verification, [CONCEPTS.md](CONCEPTS.md) defines project terms, and
[AGENTS.md](AGENTS.md) records standing decisions. The
[historical checkpoint](#completed-work-and-evidence) contains the full delivery
records and suite counts.

## TV series libraries, checkpoint 2026-11-28

TV and movie libraries scan shared media files, match titles through TMDB,
TheTVDB or local metadata, and expose available files to members. An admin can
review matches, choose an episode ordering and assign files. Members can resume
individual files and manage watched marks across browsers. The server setting
`activity.progress_clock_skew_seconds` defaults to zero. The
[published operator guide](https://baander.app/docs/operator-guide/media/video-matching/)
covers matching and playback progress.

### Verification

The merged release at `9fe714410` passed all 16,003 backend tests with 170,703
assertions, Composer validation and normalization, PHPStan, Deptrac, imports and
the tooling gates. The web suite passed 4,025 tests in 342 files. All six
Chromium and Firefox playback and admin cases passed with retries disabled.
The fresh database applied 62 migrations, and both fresh and upgraded preview
databases passed schema validation.

The owner accepted the focused checks and merged release for U4, U5, U12–U16,
U22 and U23. The nine historical replays were cancelled. U19 and U11 did run.
Their failures were in tests corrected in `cb6ba9ba1` and verified on the
merged release. The public site served the guide from `b67344508` after all 378
website Chromium checks, 76 design-token tests and 35 renderer tests passed.
[Website CI](https://github.com/baander-app/baander/actions/runs/46170300921)
and [published deployment](https://vercel.com/martin-juuls-projects/baander/DSrgTPnhb93TSo3CkaEHT6mYYpFJ).

## Website design system section, 2026-11-27

The [public website](https://baander.app) publishes ten design-system pages from `ui/DESIGN.md`,
`ui/ICON-GUIDE.md` and generated tokens. One reader supplies the type roles to
both `/typography/` and `/design-system/type/`; the build rejects missing borrowed
sections, inconsistent roles and insufficient accent contrast. The section has
shared navigation and search indexing, and the site publishes all three SVG marks
with `favicon.svg` as its icon. Delivery and review span `3dac4f5`–`e18fbf6`.

Owner decisions: the application’s English strings follow the new Writing section;
a menu has at most three icons, including destructive actions; `/typography/`
stays. The adopted defaults retain all four duration tokens, name Lucide icons
without drawing them and keep both type presentations reading one source.

The reviewed tooling passed 218 tests with 1,990 assertions. The published
site passed the [November 28 website checks](#verification).

Open:

- `--duration-normal` and `--duration-slow` remain unused; removing them needs the
  owner’s consent.
- Five English errors in `ui/web/src/shared/i18n/locales/en/translation.json` need
  the Writing rule’s explanation and next action: `common.error`,
  `auth.stepUp.failed` (also missing its period), `auth.passwordMismatch`,
  `player.compatibility.audio_stream_unavailable` and `auth.consent.unsafeRedirect`.
  The file also mixes straight and curly apostrophes.
- `ui/ICON-GUIDE.md` still has `--` dashes, Title Case headings and rule 5’s “Check
  this guide”; its published text needs a prose pass.
- SVG marks inside `<img>` follow the theme’s `color-scheme` in Chromium. Firefox
  and Safari remain unchecked.

## Roadmap open items, 2026-11-26

The owner selected the Symfony and pipe-check leftovers and the security audit’s
behaviour, build and verification gaps. Delivery spans `61a1f0a37`–`db3abcdaa`;
individual commits retain their failing-first test evidence.

The November 26 work covered member input bounds, recommendation and playback
responses, session accounting, terminal output and pinned build inputs. The owner
confirmed the Forgejo runner at `f46c6148a` and FFmpeg at `7aa5172fb`. The
[plan](docs/plans/2026-11-26-2023-fix-roadmap-open-items-plan.md) and the
[historical checkpoint](#completed-work-and-evidence) record each change and
its verification.

Verification: affected CI-image suites and static gates passed after integration;
initial-schema checks passed with 51 migrations and development/registry tooling
passed 429 tests with seven deliberate skips. Browser checks at `f2ca7146c` passed
encoded paths in Chromium and signed-original downloads in Chromium and Firefox.
They used the development gateway, not the preset gateway; signed originals became
attachments with a sandboxing CSP, left `about:blank` and ran no script.
Registry stop tests failed against both mutated server builds and passed with the
fix. The live collection suite passed 20 repeated, 40 shuffled and ten single-core
runs, followed by all 3,930 web tests. The merged release later passed all 16,003
backend tests and static gates; that result supersedes the pending backend run at
`bc19054f8`, not the unperformed deployment qualifications below.

Closed follow-ups: the two `LogAgentTest` archive failures used fixed November 20
fixtures that aged past retention. `c6c934ef8` supplies current timestamps; the later
merged backend suite and 122-test progress/log/publication checkpoint pass. The
Symfony limiter codec, hls.js repeated quality assignment, self-signed website
certificate, jsdom CSS injection, missing dev proxy volume, and disposable-drill
Compose/secrets defects have fixes recorded in `9e22148f9`, `f46c6148a`,
`79b1aa032`, `b7ce66a45`, `1500050d2` and `56b1d0ab4` respectively. A later complete
pass of the disposable database drill is still not recorded here.

Open:

- Verify the disposable database drill after `56b1d0ab4`; its subsequent runner
  failure was not diagnosed from the retained log.
- Read the failed Docker Hub login in manual Dependency advisories run
  `46038060179` (`bc19054f8`), with `DOCKERHUB_TOKEN` secret and
  `DOCKERHUB_USERNAME` repository variable. Rotate the token exposed in chat.
- Run the 8-video/12-audio worker load proof on a live deployment and the preset
  qualification in the libvirt guest when those environments are available.
- A member recommendation `POST` racing generation for the same user and key can
  still hit the unique index during ORM flush and answer `500`.
- Console show output using `horizontalTable()`, `definitionList()` and `text()`
  still needs escaping, including metadata match apply, song/album delete and user
  setting show. `TerminalText::literal()` covers it.
- OpenAPI operations other than `PATCH /api/playlists/{publicId}` omit the possible
  `409` `member_input_bound` for a stored over-bound row before repair.
- Registry load smoke failed twice in three full runner-image release runs with
  `lookup failed for load-057: HTTP 503`, but passed four isolated runs on both
  trees. The cause remains unknown.
- Development `down()` saves `quiescing` before `compose stop`; a failed stop can
  leave the preview requiring `recover`.
- `personal-state.test.tsx`’s idle-initial-session case fails with
  `vitest run tests/live-updates --sequence.shuffle --sequence.seed=4` on both
  trees; CI is unshuffled. Frontend coverage run `45653910522` also had four
  unexplained `waitFor` failures in three files. `LiveQueryCoordinator` still uses
  `Date.now()` for `lastPass`, `retryAt` and `wakeAt`; backward-clock refresh delay
  remains a suspected defect. The fixed HTTP admission clock issue does not prove
  those timers correct.

## Pipe-operator inspection check, 2026-11-26

Two PHPStan rules match PhpStorm’s three-to-ten-level nested calls and consecutive
assignments, and run in both configurations. The owner chose exact inspection
parity and named locals/helpers for multi-argument steps, never the quick fix.
The [Pipe Operator guide](docs-book/part-2-developer-guide/development/coding-conventions.md#pipe-operator)
records the fix and upgrade parity procedure.

At `d49e4d5c6`, PhpStorm 2026.3.0.1 (263.7307.352) and PHPStan agreed on 643 nested
calls in 402 files and one assignment chain. Conversion reached zero findings,
planted chains failed both configurations, and an AST round trip verified the
rewrites except the explicitly reviewed helpers. At `3935fa941`, ordinary/native
unit lanes passed 9,868/975 tests and the rules passed 25; the earlier stack and
tooling runs passed 2,454, 1,943 and 1,831 tests (nine tooling skips). Static gates
and review passed. `f1ed3352e` now tests that both configurations retain the tagged
rules extension.

Open: rerun parity after a PhpStorm or PHPStan upgrade. One ordinary lane at
`8661c86ad`, seed 138315351, crashed in `DiscoveryRegisterCommandTest`’s Symfony
`stty` probe with a Swoole hook active outside a coroutine. The rerun passed; the
earlier test leaving the hook active remains unidentified.

## Source installation and documentation

The bounded [installation record](docs-book/part-1-operator-guide/getting-started/installation.md#verified)
replaced the obsolete claim that the preset had never run. The opening’s library,
playback, queue, playlist, account and operator claims came from the baseline and
[From files to playback](docs-book/part-1-operator-guide/getting-started/understanding-baander.md#from-files-to-playback),
[user management](docs-book/part-1-operator-guide/administration/user-management.md)
and [installation prerequisites](docs-book/part-1-operator-guide/getting-started/installation.md#prerequisites).
The album-rediscovery opening is reader framing. The unchanged library screenshot
shows the web app; cgroup v2, PHP 8.5 and the IPv4-only qualification limit retain
the cited guide’s meaning.

## Security audit and review, November 2026

The November 20 authentication review and November 24 repository audit are
implemented. The [application threat model](docs-book/part-2-developer-guide/architecture/application-threat-model.md)
and [registry threat model](docs-book/part-2-developer-guide/architecture/registry-threat-model.md)
record the controls and their proving tests. The complete delivery, review and
owner-decision record is retained in
`git show 455808baa:ROADMAP.md`, under “Security audit, 2026-11-24” and
“Security review findings”. The original reports remain available with
`git show adb9526fa:SECURITY_FINDINGS.md` and
`git show 905d7da1e:SECURITY_FINDINGS.md`.

The [merged release](#verification) passed the full backend and static gates.
The encoded-path and original-download browser checks ran on the development
gateway at `f2ca7146c`. The [November backlog](#roadmap-open-items-2026-11-26)
tracks the deployment qualifications.

Owner decisions remain in force: DPoP refuses RSA proofs; passkey attestation
formats follow the setting; dependency updates stay within their ranges and Yarn
waits three days before admitting a release. Originals opened as pages download.
Only super admins clear rate limiters. Member-input caps, the 20-subscription limit,
the default six-session share and the 70-minute silent-session takeover threshold
remain as recorded. Copy starts consume the same share, and a low share reduces
automatic quality tiers rather than raising the setting's minimum. The preset is
the documented operator installation; production refuses unknown environments and
short Redis or PostgreSQL passwords. Discovery admits cleartext only for private or
loopback address literals or `.local` names. Refused pairing codes share one `404`.
The full numerical and deployment decisions are in the immutable record above.

### Remaining implementation and qualification

- **Deployment:** the preset qualification in the libvirt guest and the worker load
  proof with 8 video and 12 audio jobs remain unrun. The latter requires a live
  deployment. The owner skipped the preset run on November 26; preview browser
  results do not qualify its gateway, readiness or shutdown behaviour.
- **Preset operations:** offline OAuth-key rotation, database-password rotation,
  `APP_SECRET` rotation with webhook delivery held, and maintenance with PostgreSQL
  running while web and workers are stopped remain unavailable. Its 35-second
  stop grace ignores `WEB_DRAIN_SECONDS`. `DB_USERNAME=postgres` passes validation
  but fails after volume creation with internal variable names in the error.
  Worker containers created by `DeploymentContainerRecipe` retain the daemon's
  default log driver; web and worker environments repeat `REDIS_PASSWORD` although
  `REDIS_URL` carries it. See the
  [rotation guide](docs-book/part-1-operator-guide/maintenance/credential-rotation.md).
- **Development stack:** Redis still uses the `default` user with `requirepass`,
  and no command writes the eight log-collector variables required by its Compose
  file. The [Compose guide](docs-book/part-2-developer-guide/getting-started/compose-stack.md)
  records the gap. The checkout preview later passed schema validation and both browser engines
  during the [TV verification](#verification).
- **Browser checks:** the quiet rerun of
  `tests/e2e/settings/sign-in-conflict.spec.ts` remains unrecorded. The three admin
  cases in `tests/e2e/admin/admin.spec.ts` still target `/admin/monitor` and
  “Job Monitor”, while the app redirects to `/admin?tab=jobs`. The full backend
  release does not close these browser gaps.
- **Additional public origins:** the WebSocket handshake accepts `APP_URL` or
  `app.websocket.allowed_origins`, but does not derive its allowlist from
  `AUTH_EXTRA_ORIGINS`. The bundle configurator needs a runtime origin policy;
  its compile-time expression still receives the unresolved `csv:` placeholder.
- **Feature work:** no server-side LAN discovery responder exists. A responder and
  native clients should retain the desktop discovery bounds and origin rules.
  Binding personal-access pushes to an owner-held secret, a member-visible list
  of devices and app grants with revocation, and a web TOTP settings screen remain
  separate work. TOTP setup and disable are API-only.
- **Removal decision:** the deprecated `App\Media\Infrastructure\Converter\ImageConverter`
  has no callers. Read its history and obtain the owner's consent before removing it.
- **Other qualifications:** the 64 MiB IPRD catalogue cap has not been checked against
  the live catalogue. Recommendation creation racing generation can still return
  `500`; the [open-items entry](#roadmap-open-items-2026-11-26) retains that race.

### Residual risks

These are the remaining boundaries recorded by the audits, not new acceptance
claims. The immutable record above retains the original observations; the threat
models hold the current control descriptions.

- **Redis:** signed envelopes have no nonce or expiry; a Redis writer can replay
  them, move an authenticated cached value to another key, or alter limiter state
  and WebSocket tickets. Replay impact by message type is unassessed.
  `RedisSessionHandler` is outside the authenticated marshaller; no route starts a
  session today. A feature that does should wrap it in `MarshallingSessionHandler`.
  Undecodable envelopes lack failure-transport rows; codec-refused signed envelopes
  are retried then dropped with a critical log. Rotate `APP_SECRET` only after
  draining queues. The ACL command list is source-checked, not preset-qualified:
  it allows `EVAL`, but not `EVALSHA`, `SCRIPT`, `MULTI` or `EXEC`.
- **Operator and build privileges:** Docker-proxy container inspection exposes
  environments, including `APP_SECRET`. A future PostgreSQL extension install
  runs its script with `public` in `search_path`, and the application owns that
  schema. Forgejo fork routing depends on approval because edited workflows can
  select the socket runner; AGit approval coverage is unverified. The preset
  refuses committed test secrets, but the boot check does not yet do so. The
  registry's static OpenSSL lacks `-fcf-protection`, so CET is not enforced there.
- **Authentication races and policy:** pairing completion has no lock, and the
  same-code completion race is unproven. Credential changes take the user row lock
  without the issuance timeout. `VerifyEmailHandler` checks `isDisabled()` before
  locking; confirmation racing disable remains untested. TOTP enable attempts
  have only the generic per-account API limit. Role changes deliberately rely on
  the runtime's roughly one-second role refresh rather than closing sockets.
- **Source-only boundaries:** request-created coroutines find no security token,
  and none currently reads security state. FFmpeg and ffprobe input calls were
  found to use the input clause, but filter-opened files are outside that search.
  No audio rendition currently takes a video source under the audio-only whitelist.
  Member song paths are stripped by callers rather than centrally. Public 6to4 and
  Teredo answers remain admitted when their embedded IPv4 address is public, as
  the owner decided.

## Typed roles, permissions and scopes

Owner decision, 2026-11-22: plan a separate refactor of role, permission, OAuth-scope
and authentication-attribute strings into enums, with permission-path builders.
Settle the builders' scope in that plan. Keep `security.yaml` strings and test
that they agree with the enums.

## Person credits and catalog follow-ups

Concerts need Catalog targets before Person credits. Provider-wide filmographies
need visibility rules; further MusicBrainz recording, release and work relationships
need mapping and precedence rules. See [People and credits](docs-book/part-1-operator-guide/media/library-management.md#people-and-credits).
TV scanning and playback are delivered in the November 28 checkpoint above.

## Frontend build warnings

The app, HLS and Three bundles still warn about size. At `fc8d9305` they measured
2322.71, 578.15 and 742.80 kB minified respectively. HLS is one full-feature module;
Three loads with the visualizer. Splitting needs a measured benefit and smoke
coverage of the built application.

## Admin and terminal log viewers

Physical remote-host TLS deployment is unqualified. The two-node drill passed
eight authentication, outage and recovery checks on one Docker host; it does not
establish physical-host acceptance. See the
[operator guide](docs-book/part-1-operator-guide/operations/log-viewer.md).

## Forge-neutral CI

- Dual-forge acceptance requires all gates green on the same `master` revision,
  a pull request through each forge and a scheduled nightly on each.
- Forgejo acceptance remains deferred by the owner (2026-11-05). Inspect and cancel
  stale runs through the web UI; Forgejo 15's API exposes neither job logs nor
  cancellation. The owner confirmed current runner and FFmpeg images on 2026-11-26
  in the cleanup checkpoint above; the former instruction to rebuild the October
  runner is superseded.
- Unverified: Forgejo 15 workflow-token `permissions`, socket-launched containers'
  workspace mounts on hosted runners, and hosted backend memory and disk capacity.
- Media evidence limits: the frame observer's lag tolerance assumes the constant
  frame duration of its fixtures, has no separate lagged-rate ceiling beyond 60%
  coverage, and can misclassify variable-rate input. Copy lead after a throttle
  pause is unmeasured; FFmpeg's continuing read-rate clock may release a burst on
  resume. Native pause reaches the session on the next presence report, up to ten
  seconds later. Held-frame fixtures stall about ten seconds after the last packet.
- `uniq_transcode_jobs_owner_user_id_video_id_quality_tier_name` names three of its
  five columns. Whether `session.sessions` should retain capture from only
  `listening_sessions` and `devices`, despite reading four playback tables, is undecided.
- A cancelled Forgejo run may leave its BuildKit container. Coverage comments
  interpolate the stored baseline into `node -e` as code.
- Refactoring candidates: the 1,400-line `PublicationWorkflowTest` evaluator and
  tree linter. Missing `PackageStore` tests: constructor validation, restore into
  a nonempty target and save of a missing directory.

## System overview pages

- Mermaid helper tests cover the three original flowcharts; sequence-diagram font,
  marker and no-foreignObject assertions are absent. The manually maintained
  browser page list is not checked against every Mermaid page.
- Historical solutions retain the removed task transport and combined consumer.

## Interactive manual diagrams

- Browser coverage lacks two figures on one page (shared listener, hint IDs and pin
  release), an OS theme change without the toggle and wrapped sequence messages.
- `describeDiagram` is a test-only unmatched-edge hook; do not add further hooks.
- Diagram SVG embeds its own font subset, outside page font slicing; non-Latin
  labels are untested. The fast-duration fade uses browser-default easing; the
  design specifies ease-out for the app shell but no static-site rule.

## Small-host household deployment

The [superseded small-host plan](https://github.com/baander-app/baander/blob/455808baa77b566085aebcd40b00c511413be954/docs/plans/2026-11-02-1819-feat-small-host-household-deployment-plan.md)
and [capability plan](docs/plans/2026-11-07-1804-feat-capability-and-size-deployment-settings-plan.md)
retain the 4 GB qualification. No host size is qualified. The owner deferred the
run on 2026-11-12; [System Requirements](docs-book/part-1-operator-guide/getting-started/system-requirements.md)
still carries the November 3 household-profile measurements.

- Rebuild application, worker, operator and database images from the current tree;
  the last database image lacked `baander-pooler-credentials`. The stopped attempt's
  images at `fa240b3d0` predate the merge. The existing QEMU guest is at
  `~/.cache/baander-household-vm/`; use `-m 4096` without libvirtd.
- Run three comparable repetitions of one-player, restart, recovery and replacement
  scenarios on 4 GiB with swap off, then the insufficient-memory case on 3 GiB.
  Follow KTD8 before reducing sizes; publish measured values in
  `deploy/preset/example-4gb-remux.json` and System Requirements. The agreed profile
  is 2,000 songs, 50 movies, 250 TV episodes and 1–2 streams with normal background work.
- 8 and 16 GB qualification is deferred. ARM64 and Raspberry Pi are outside the plan.
- Harness gaps: replacement does not check fixture borrowers; recovery fails if web
  or workers restart during the database outage. `Qualification.php` recovery and
  replacement logic is a refactoring candidate.
- Source installation now uses the rendered 16 GB PostgreSQL profile with 35
  connections; verify direct host tools fit its four direct connections.
- `make docs` is shadowed by `docs/` and needs a running preview; declare it phony
  or document `php scripts/build-website.php`.
- Two unanswered stats requests under load remain unexplained; the harness retries
  and records them. After a PostgreSQL crash workers stay stopped until operator
  reconciliation. Browser playback and worker-container suites have not qualified
  the profile.

## Configurable media capacity

- At the owner-selected eight software slots the reference host sustains about two
  1080p libx264 encodes. Operators using software video must lower the slots.
- The 8-video/12-audio drill and hardware NVENC, QSV and AMF checks are unrun;
  NVIDIA runtime acceptance of the device-request shape is untested.
- Existing Debian 13 volumes need `REINDEX` and collation-version refresh. Later
  PGroonga checks covered the persistent instance only.
- Execution ownership renews on the application pool (up to 120-second wait) against
  a 15-second lease; long transactions can outlast it. The control pool's one-second
  authority timeout is unproven above 8 + 12 slots.
- The application role is a pooler `admin_users` member and can pause or stop it;
  development checks need only `stats_users`.
- A racing progress write can prevent recording an encode failure. Warm-up predicts
  about 40% host cost per 1080p stream against about 21% measured for full encodes.
- Functional tests bypass transaction pooling; only worker drills expose those
  incompatibilities. `HostCpus` and the boot-row fingerprint constraint lack direct
  tests. Development compose sets no `oom_score_adj`.

## Playback destinations and notifications

The [confirmed plan](docs/plans/2026-11-01-1840-feat-playback-destinations-and-notifications-plan.md)
retains receiver grants, the local companion, AirPlay/Chromecast/DLNA adapters,
physical qualification, Cast registration and hosting for local and remote servers.
The companion must not claim or acknowledge browser endpoints: browser music and
video execution is independent of it.

Available hardware is Linux, iPhone 13 and Apple TV 4K; no Android device or suitable
Chromecast/DLNA receiver is identified. AirPlay hardware acceptance is outstanding.
Queue authorization checks tracks individually; assess a bounded Catalog batch
contract without weakening per-item checks. Safari automatic local recovery is
unavailable; ordinary playback remains. A first reload failed to resume despite
correct duration; the instrumented reload/tab-close/crash rerun passed but did not
explain the intermittent failure.

## Bånder Audio Core in the server transcoder

- Decide public distribution of `ffmpeg-baander-static`: fdk-aac requires
  `--enable-nonfree`, and FFmpeg marks that build nonredistributable. The owner
  allowed public GHCR on 2026-11-07 before this was raised. Making it private uses
  workflow-token pulls; dropping fdk-aac removes the `fdk_aac` setting position.
- Hardware NVENC/QSV remains unqualified; compiled components alone are insufficient.
  Recheck stream-copy pacing at the FFmpeg major change and the unverified claim
  that FFmpeg 8 `-shortest` buffers ten seconds; see the
  [pacing solution](docs/solutions/performance-issues/pace-a-stream-copy-with-a-read-rate-burst-counted-from-the-seek-boundary.md).
- Recorded findings: NVENC HDR emits unavailable `tonemap_cuda`; the serial-audio
  slot test failed once under load but passes alone; the dated default-branch
  Dependabot count was 178 (3 critical, 78 high), requiring a fresh inventory.
- Decisions remain on default decoding when ffprobe cannot identify the source,
  libbac's unspecified 7/8-channel layout without container metadata, MPEG Surround
  decoder-delay trimming/draining, and unused per-segment encoders reading the live
  setting rather than the session's. The November 26 owner confirmation supersedes
  the former request to check whether Forgejo's FFmpeg image exists.

## Learning and plan maintenance

A shared durable-consumer heartbeat can hide one consumer's outage; the October 31
learnings refresh recorded this limitation without changing runtime behavior.

## OAuth pushed authorization requests and auth hardening

The [PAR plan](docs/plans/2026-11-19-1148-feat-oauth-pushed-authorization-requests-plan.md)
and [hardening plan](docs/plans/2026-11-19-2119-fix-auth-security-hardening-plan.md)
retain final-revision acceptance: browser CSP, step-up registration and web TOTP
login were untested when the preview could not start. A delegated token cannot
revoke itself through first-party `/oauth/revoke`; third-party RFC 7009 revocation
is separate work. Coverage did not finish within 3,600 seconds, despite the
3,950-test noncoverage stack pass at `3473a20c3`; see the
[coverage investigation](docs/solutions/developer-experience/coverage-runs-push-the-stack-lanes-past-the-backend-budget.md).
The November 28 full release pass does not establish coverage-budget acceptance.

## Web dependency refresh

The [maintenance guide](docs-book/part-2-developer-guide/contributing/contributing.md#javascript-dependency-maintenance)
requires explicit online advisory and patch inspection. The October 31 checkpoint
kept TypeScript 6.0.3 in web/shared for typescript-eslint's below-6.1 requirement;
site/player use 7.0.2. Nine site resolutions reduced 44 advisories to 22 and removed
the critical finding; further fixes need incompatible transitive releases. Web lint
retained moderate minimatch (fix requires v11). Three upstream dash.js peer warnings
remain, with the tested DASH path passing. These are dated inventory results.

## PHP dependency and runtime refresh

The owner ended the upgrade on 2026-10-31 with partial qualification; these limits
are not authorization to expand it. Unconfigured-session defects remain deferred,
and readonly proxy support unselected. The unchanged checker reported seven Swoole
fork findings and one Redis limiter evidence gap; review the fork and Composer
interpretation in [the inventory](packages/swoole-bundle/upstream-review.json) and
[collector](packages/baander-dev-dependencies/src/Php/ComposerEvidence.php).

- reli-prof 0.12.0 blocks newer Monolog, Webmozart Assert, Amp, Parallel and
  MonologBundle; versions and solver evidence are in the
  [dependency guide](docs-book/part-2-developer-guide/getting-started/php-dependencies.md).
- TSDuck qualification is owner-accepted blocked work: declared `tspyphp*` bridge
  functions exist neither in upstream v3.43-4549, the Docker build nor repository
  history. The PHP 8.5/FFI/PHPUnit 13 probe fails, not skips. A C++ bridge and CI
  integration require separately scoped work; no native callback/lifetime pass exists.
- Six operator-dependent tooling cases and registry thread/fuzz/container/backup
  modes remain separately unqualified. Registry release/sanitizer passed on the
  recorded derivative; the owner interrupted the thread gate.
- Historical 10,966-test results and the merge at `7aa0172fb` are not final upgrade
  acceptance. The current November 28 backend result is above; it does not imply
  AAC, media-matrix or operator/registry qualification.

## Cohesive live updates

Recorded limits: warm memory exceeded cold baselines despite reclamation; the
finite run proves no indefinite stability. Metadata conflict/deletion and
preference conflicts have component/hook proofs, not browser conflict tests. The
10,000-album Timeline has no covers and measures no image fanout. An earlier
scan-worker pair's exit remains unexplained despite later healthy workers and scan.

## Quiet live-update status

The watcher without freshness tracking has helper coverage only; label absence was
checked only while connecting. A stalled projection failed to return to current
within 30 seconds, even though the old label assertion would pass. Use a preview
with reliable stall degradation to distinguish stale refresh from environment
failure. Record-play and owned-audio cases also failed on `master`, without a cause.
The admin bell's separate relative-time helper lacks a date fallback after 30 days;
consolidating it changes old rows.

## Website feature pages

The feature-page plan's picture review by the owner is outstanding at
[the site](https://baander.app/features/). The November 28 website release supersedes
the request to check old GitHub revision `464fad883`; Forgejo acceptance remains
separate. The capture contact sheet is not evidence of the committed pictures.

- The lyrics panel reads LRCLIB output, not scanned `.lrc` lyrics. The owner decided
  on 2026-11-18 to expose stored lyrics, keeping README and library documentation:
  plan the application change, then the lyrics page, scene and pictures.
- Recorded player gaps: no shuffle/repeat controls or state despite S/R keys;
  crossfade preference has no control; player cover request answers 403.
- Playlist gaps: no remove/reorder; album add takes only its first track; numbering
  starts at zero; creation does not refresh the page; collaborators display public
  IDs; the share switch thumb overhangs.
- Library gaps: five of fifteen sample albums list tracks out of order; Recently
  Added sorts by title; album double-click fails with the side panel closed.
- Destination rename does not change the registered browser name. Admin gaps:
  clipped AAC select, seven cells in six columns, overprecise job durations and
  library types offered without implemented scanners. TV scanning is now delivered.
- Documentation mismatches: login has no passkey control; video device transfer
  lacks maintained documentation despite its browser test; radio sync uses only
  subscribed countries and the app never calls refresh; ingestion already queues
  the cover extraction described as a separate post-scan step.
- Capture gaps: intermittent login timeout; playlist-create scene untested in final
  form; hardcoded browser names; unavailable WebKit; `INSTANCE` fixture execution
  is not tied to browser `--base-url`. The font-arrival precondition once failed in
  eleven runs. The old plan's ten-page count omits its lyrics follow-up.
- Copy follow-ups: describe radio subscriptions; link radio/account entries to
  documents supporting their full claims; move or replace the fourth equalizer
  slide's device-profile claim, shown only by the first slide's picture.

## Website reference pages and fold-out menu

The package inventory page is excluded until a per-package snapshot is refreshed
with shipped images and Debian copyright-text license declarations have a rule.
Strategy and Concepts publish their source, including the competitor table and
maintenance note. Other intermittent layout shifts remain unexplained; the header
first-paint defect passed its recorded correction checks.

## Presentation register and homepage hero

Hero text, search description and social-card paraphrases are synchronized by hand
without a comparison test. Bånder Audio Core and docs-book introductions may receive
the register on request; feature pages already use it.

## docs-book rewrite

Four Prometheus gauges and the notification events `AlbumCreated`,
`TranscodeJobCompleted` and `TranscodeJobFailed` are never emitted.
`app:album:duplicates` requires a library UUID no command prints. Open policy and
verification questions: playback snapshots expose UUIDs despite public-ID payloads;
Electron CI uses Node 24 while pages say 26; the database-rotation
`pooler-credentials` procedure is source-derived and undrilled.

## docs-book rewrite follow-ups

A failing transcode steer after the party commit prevents its broadcast.
`PlaybackStateChanged` writes an outbox row on each host control, with no durable
consumer. `HW_BITRATE_MULTIPLIER` reaches diagnostics and `SegmentEncoder`, but the
uncalled `applyBitrateMultiplier()` never applies it.

## Party invitations

The plan excludes invite/join library checks, invitation discovery/decline, revoked
socket notices, invitation caps/events and a console command.

- Uninvited joins take a lock and two queries, unlike unknown IDs; equal answers
  have distinguishable timing and a known ID can briefly queue on the lock.
- Worker death after revoke/leave commit can leave other workers' sockets in the
  room; deleted membership still prevents re-entry. A failed room listener closes
  the room and returns an error after the committed change.
- End reads its host unlocked; a concurrently demoted host can end the party.
  Host-user deletion cascades without an end event or room close: decide whether
  deletion ends the party or transfers it before fixing the Auth path.
- Ended-party retention is undefined. Deleting an invitee between lookup and insert
  produces a foreign-key 500. Join maps all `NotFoundException` instances to hidden
  party; none other than the party exception is currently thrown.
- Source tracing bounds stale ORM state to one coroutine/message, not a worker's
  lifetime; this was not run. A historical `AudioTranscodeStreamTest` format case
  failed on `master` without investigation.

## Notification pane well

The owner accepted rest-state pixel differences on 2026-11-17: mood-switch partial
repaints explain most variation, but 11 pixels differ without a switch and the cause
is unknown. No CI job runs the preview-dependent `tests/e2e/styling` specs.

## Styling specs and sign-in settings conflict

- The owner retained as a product question the narrow-screen playing-track overlay
  that steals focus and closes a popover after sign-in.
- Volume snaps back until the session reports the new value; duration is unmeasured.
  Visualizer calibration, admin tabs and appearance-matrix failures predate the work
  and lack attribution.
- Recovery's managed restart broke the preview's release preparation until
  `make preview-up`; its delivery-outage case did start the repaired interruption.
- If preferences never receive an initial read, for example while live updates stay
  unready, the track retains the panel tab and volume changes are unsaved.
- WebKit lacked `libicu74`, `libxml2` and `libflite1`; no handoff pass or
  preview-backed container route is recorded.

## Cohesive operator manual

Documentation checks did not deploy, scan user media, rotate secrets, replace
workers or restore data. Worker credentials cannot provision private Discogs tokens
for queued enrichment. Coordinated app restoration, actual registry backup
RPO/RTO and whole-host capacity remain unqualified. The rewritten source bootstrap
is source-verified only.

## Local development workflow

In linked worktrees, up to three `ApplicationInventoryTest` cases fail when a
marker misses its window. `RepositoryConfiguration::local` reads the primary
checkout's `dev.local.json`, slowing configuration loading. Both
`testInterruptedCollection…` cases fail consistently;
`testShellRecoversUncertainCreationWhenCollectorIsInterrupted` fails intermittently.
All three pass in a standalone clone and with `CI=1`.
Controller tooling installed by the last `make dev-up` can lag deployed images:
the November 13 update to `60fc26b63` used an old `DeploymentProfile` argument order
and wedged the instance before migrations. Recovery required `make dev-recover`
and current `make dev-up`. A controller generation that updates with `master`, or
versioned profile requests rejected with a reason, remains needed.

## Playback investigation and evidence limits

A lost admission response can strand an allocated producer without a browser
session ID. Reconcile by Playback client-session identity; cancellation must target
the former admission and preserve a successor. See
[playback controls](docs-book/part-2-developer-guide/development/video-playback-controls.md#audio-tracks-and-timing).

The historical 24.64-second native frame versus 24.60 callback mismatch did not
recur with diagnostics and remains unexplained; it selects no tolerance change or
new implementation. [Sparse playback](docs-book/part-2-developer-guide/testing/adaptive-playback.md#sparse-movie-playback)
retains the evidence. Qualification boundaries, not automatically selected work:

- Safari/native HLS, DASH browser/subtitle playback, full live FFmpeg DASH EOF,
  formal CMAF, interactive audio switching and native caption-menu/pixel behavior
  are unqualified. The native pressure observer still assumes the old HLS muxer.
- Variable-rate gaps, discontinuities, other origins, containers, hardware and
  untested browser/format combinations are outside measured cases. A
  [selected endpoint](docs-book/part-2-developer-guide/testing/movie-endpoint-playback.md#selected-video-endpoint-playback)
  is qualified only for calibrated metadata; missing/stale metadata is estimated.
  Existing checks do not establish general cache or performance acceptance.
- BT.2020 SDR and PQ/HLG software checks do not certify general HDR playback,
  complete vectors, display gamut or Dolby Vision. See the
  [media testing guide](docs-book/part-2-developer-guide/testing/media-and-playback.md).
- Native codec acceptance depends on the release path: reference tests omit
  34-band/phase decoding, listening and HE-AAC IPC/bridge acceptance. The 6% HE-AAC
  CBR gate did not establish the original 5% target. These gaps do not select unused
  work or reopen intentional codec design decisions.
- Broader scan, transcode and producer transaction boundaries remain in their
  guides; directory publication does not complete the event-producer audit.

## Registry release acceptance

[Registry release acceptance](relay/README.md#release-acceptance) retains whole-host
budgets; container limits or brief local smoke cannot satisfy them.

- Supply five target hosts or an agreed equivalent for the optimized 1-CPU/512-MiB
  whole-host profile, including snapshots, compaction, catch-up and backup upload.
  Sanitizer workers may be larger.
- Choose a stable runner and accepted performance baseline before enforcing
  regressions above 10%.
- Reserve a 24-hour workload; compare equivalent post-warmup quiescent windows and
  fail sustained RSS growth above 1 MiB/hour or 10% of baseline.
- Obtain the actual S3-compatible destination; qualify encrypted/versioned
  five-minute backups and isolated restoration with five-minute RPO and 30-minute
  RTO. Disposable TLS upload and offline restore do not qualify that destination.
- Access to `de`, `ca`, `sg`, `au`, `fi` is unavailable (owner confirmed 2026-10-04).
  Qualify five voters, volume-preserving rolling upgrades/bootstrap guards, no
  acknowledged-data loss after two-voter failure and leader recovery in 30 seconds.
- The default-branch nightly is locally verified, but hosted activation and a
  scheduled run remain unverified. Follow the
  [nightly procedure](relay/README.md#nightly-qualification-and-retained-diagnostics).

## Application release acceptance

- Rehearse backup restoration before migration rollout under the
  [upgrade guide](docs-book/part-1-operator-guide/maintenance/upgrading.md); this does
  not reopen qualified fresh-install/migration behavior.
- Resolve notice, provenance and embedded-dependency gaps before release-image
  redistribution. Keep evidence in the
  [application review](third_party/inventory/application-review.md), follow the
  [collection procedure](dev-docs/application-packaged-inventory.md) per artifact
  and preserve [THIRD_PARTY.md](THIRD_PARTY.md) boundaries. Registry inventory is separate.
- Qualify reproducibility and reference vectors only for the active codec/DSP paths
  selected for release. [DSP guidance](packages/dsp/README.md) defines evidence;
  browser graphs certify no native codec and unused modules create no release task.
- Record first-production-release evidence. There are no production deployments;
  remove the temporary [AGENTS.md](AGENTS.md) policy only on the owner's explicit
  first-release announcement, not a tag, build or preparation.

## Deferred scope

`ui/rn`, including Android/Android TV, remains owner-deferred (2026-10-05); no
feature/API/tooling reconciliation or CI lane resumes without the owner's decision.
The separate `ui/android` app was owner-removed on 2026-10-26. Essentia stays outside
scope under [AGENTS.md](AGENTS.md).

## Earlier acceptance and limits

These are recorded constraints and potential follow-ups, not blanket authorization
to resume old work. Later checkpoints take precedence over their dated failures.

### Geist and Noto typography

First-paint measurement is missing; stylesheet measurement was 456 kB raw/157 kB
compressed, including about 412 kB of font rules. Styling failures are collected
[above](#styling-specs-and-sign-in-settings-conflict). Considered but not selected:
combine song title/language maps; add content language to radio genre chips; address
possible WebKit caret centering with 2.0 line-height inside a 2rem field.

### Website typography page

Korean samples need native-speaker review. Geist's Latin slice lacks `↗`, so the
header takes it from Noto Sans SC.

### Website layout stability

Fallback scaling cannot match every line: inline-code prose and full-width headings
can wrap differently; medium fallback is 1.5–2.4% narrow. Linux Chromium full hinting
can gain a line. Diagram pages and Firefox are outside the shift measurements.

### Source-installation documentation

The recorded check used a named checkout volume, controller container for PHP,
disposable self-signed TLS and internal networks. It did not qualify literal host
PHP, external providers, other media workflows or hardware. The
[installation guide](docs-book/part-1-operator-guide/getting-started/installation.md)
has the current procedure; history retains adaptations and counts.

### AAC codec gap closure against the fdk-aac upstream

The [codec comparison](packages/baander-audio-core/docs/codec-comparison.md),
[Apple probe](packages/baander-audio-core/docs/apple-aac-probe.md),
[reference comparison](packages/baander-audio-core/docs/reference-comparison.md) and
[codec README](packages/baander-audio-core/docs/README.md) retain detailed figures.

- LD-SBR windows/grid, RVLC escape tables and HCR rules were written without
  standards tables and only round-trip internally; low-delay DRC is absent.
  ELD/LD-SBR, RVLC and HCR interoperability is unrun.
- USAC harmonic transposition, PVC and inter-TES lack reference streams;
  stereoConfigIndex 2 is construction-verified only.
- MPEG Surround TTT mode 0 and centre-subtraction 2/3 are construction-only because
  libxaac breaks there. Differences above about 48 dB cannot match its fixed-point
  weights. Object type 30 has only in-band comparison (at least 40 dB); SBR input,
  low-delay spatial/temporal paths and ELD lengths other than 512 lack streams.
  7.1 trees, HRTF/binaural and 3D are refused. No third-party encoder stream was run.
- Planned xHE-AAC exclusions: LPD, live loudness leveling, 8:3/4:1 SBR, MPS 212
  residual/phase coding and USAC surround input. One-pass figures cannot enter their
  own fixed-configuration stream. Metering handles six channels, not 6.1/7.1.
  FFmpeg LATM rejects USAC; the gate uses MP4.
- Night gain stops at -31.875 dB and cannot hold -1 dBTP near/below -47 LUFS. FDK
  loses configuration-boundary frames in chunked input but decodes frame-by-frame.
  Corrupt-speech synthesis stays finite but can peak near 1e13. Ten-minute libFuzzer
  runs per harness lack the local clang runtime.
- Quality/speed follow-ups: afterburner speed (36× versus FDK 83×/Apple 79×),
  xHE-AAC at 24–32 kbit/s, Apple TVBR/sparse tonal quality (0.17–0.19 PEAQ ahead at
  128–160 kbit/s), TVBR scale (quality 50 about 230 kbit/s), conflicting PEAQ/ViSQOL
  above 192 kbit/s, Apple coupled-stereo SBR/additional sinusoids, xHE-AAC rate
  accounting/low band and decoder loudness default.
- Listening, ISO conformance and speech/44.1-kHz/multichannel/low-delay comparisons
  remain unrun across tiers.

### Development controller image identities

Changed applied migrations and pruned operator images can require a data reset,
with owner approval. The old `preview-fd790a887b6899cb` had five rewritten migrations.
There is no reset command; the owner-approved November 11 reset removed volumes,
networks and the instance state directory manually.

### Movie playback on growing presentations

- A crash/offline tab retains ownership until 30-second registration expiry and
  “Play here anyway.” Siblings encoding below playback speed are not offered;
  each join replaces one attachment.
- Inferred end reasons can label a cancelled, still-shared job expired and restart
  it. EOF restoration holds 250 ms with a one-second backstop because hls.js EOF is
  not visible to the hook.
- Historical Chromium proof released before registration; Firefox bound the proof
  to the change. Registration now also releases the reloaded page in all engines.
- Same-user incarnation IDs can disclose/mark that incarnation's commands shown,
  blocking settlement. The player hook has over 1,100 lines and about twenty manual
  restart resets. Finished sessions wait for the 24-hour cache sweep, not job cleanup.

### Releasing a browser runner that went away

Hidden-tab throttling can age a close proof past 60 seconds, requiring the override
following 30-second expiry. Crashes/kills/discarded mobile pages send no report.
Chromium covers close and override; only two of five Firefox cases passed (close
released in 74 ms; navigation did not within eight seconds). Server logging is the recorded next step to
distinguish an unsent from a lost keepalive request. Duplicated tabs and BFCache have
unit/PostgreSQL coverage but no browser spec.

### bAAC exact arithmetic

Same-compiler stream checks need a second Linux compiler to detect regressions.
Published speed/decoder figures used fast-math; missing FDK/libxaac prevented a
rerun. MSVC/other toolchains lack these flags; other C libraries are unverified.

### Master CI repair

The November 28 full release pass supersedes the earlier unreported full-backend
result. Coverage-budget acceptance remains under [OAuth](#oauth-pushed-authorization-requests-and-auth-hardening),
registry load-smoke intermittency under the November 26 cleanup, and dual-forge
acceptance under [Forge-neutral CI](#forge-neutral-ci). Random test-order seeds can
still expose order dependencies.

### Playback session entities

- Eleven `schema_filter` tables and existing DBAL stores remain unaudited against
  the ORM rule; this neither approves them nor selects conversion. DQL cost with
  production `cache.system` is unmeasured; the in-memory-cache control benchmark
  costs two more statements and 1.26× time, within its 1.5 bound.
- The November 11 source-only cascade audit found 51 joins (39 CASCADE, 12 SET NULL)
  in 33 files, no mapping cascade/orphanRemoval or listener-added association.
  No reachable failure had all three conditions: managed child, ORM parent delete,
  later writing flush. Three near-boundaries lack regression coverage: image pruning
  depends on uninitialized owners; album/movie deletion omits some child detachment;
  self-deleting users already hold managed tokens. Tests and long-running workers
  were not fully traced. Reconcile later album-merge fixes before selecting changes.
- The media-control gate was unrun at that checkpoint. `register()`,
  `saveTransfer()` and `insertCommand()` require `lockSession()`'s account lock;
  only the coordinator calls them, docblocks state it, and tests do not enforce it.
  Overlapping cleanup can count a job another run deleted.

### bAAC lossless mode

The owner accepted the November 12 size-gate failure: 37/38 gates, all streams
bit-exact; 96-kHz/24-bit piano is 9,821,712 bytes versus FLAC's 9,795,735 (0.27%
larger). Damaged-frame search can spend its budget and skip the next frame.

- Better compression needs its own plan, a fourth fresh held-out set and new run.
  Server lossless rendition and wrapper FLAC support are separate plans; the wrapper
  decides C-struct versioning. FLAC fragmented MP4, Electron bridge and browser
  acceptance belong to the delivery feature.
- ARM/MSVC speed is unmeasured; MSVC writes Rice codes without AVX2. Harness
  selftests lack CI/numpy. Until `audio-core-v0.2.0` publishes, installs build from
  source and `aac-native-v0.2.0` remains held.

### Bånder Audio Core rename

- Recheck release/prebuild remote state before rerunning: on November 14 the release
  was absent after Linux/Windows/macOS x64 success and unassigned macOS ARM64 in
  run 43478502093. Four prebuilds, SHA256SUMS and clean desktop fetch remain unqualified.
- macOS/NEON, Windows binding and changed-cache-key CI acceptance were not recorded;
  only immutable install ran after the final binding change. Forgejo remains deferred.
- Move benchmark work from `/home/martin/dev/baander-baac-lossless` into
  `packages/baander-audio-core/bench/`: copy `work/heldout/runs.json`, run
  `./rename_ledger.py work/heldout/runs.json` then `./setup-refs.sh` without
  `--lossless-only`. Do not inspect the fourth set first.
- Later artifact checks cover search/themes/narrow layouts, not manual visual
  acceptance after the rename.

### Scan artist credits, song lengths and lyrics outcomes

Owner decision, November 14: keep a multi-artist tag whole during scanning and split
it during matching. A byte-change retag, including ReplayGain, replaces hand credits.

- Separate deferred work: album identity across directories (`app:album:merge`
  handles duplicates); catalog-wide hash identity for files in multiple libraries;
  non-ASCII genre slugs and Unicode-normalization artist duplicates (none seen in
  real libraries); forced-sync rename/wrong-ID path (unreachable today).
- Import-handler/`PreparedSong` size is a refactoring candidate. Enrichers do not
  share import genre locks; concurrent slug creation can fail and album enrichment
  logs/continues. Locking needs a separately scoped transaction around its flush.
- Recheck the postgres-remediation Doctrine reference's dated version list.

### Matching follow-ups

- Old queued lyrics jobs without the queued flag fail decoding on upgrade and hold
  songs a day; running bulk fetches are lost until later. Held match requests keep
  due times and run within an hour.
- Unchanged-count review albums are not resubmitted: “Ten Thousand Fists” needs
  manual match selection among tied 14-track editions. The LRCLIB pause has seen no
  real 429; HTTP-date Retry-After falls back to 5/15 seconds and may retry early.
  Clearing `lyrics:lrclib:pause` is the only early release.
- Match leases are not renewed: after 15 minutes a second worker repeats provider
  requests, although locking/recheck prevents another write. No preview album merge
  was possible; real-matcher `AlbumMergeTest` covered fourteen tests.
- Worker keepalive can interrupt webhook backoff and Discogs/Spotify/TMDB waits;
  those adapter paths were unreachable by workers in the recorded tree. A regression
  test against MusicBrainz's former interruptible sleep is missing.
- Refactoring candidates: shared provider User-Agent, duplicated candidate ranking,
  `nextRequestDueAt()`'s requested-row scan and LRCLIB sleep closure versus
  `SleeperInterface`. 4 GB acceptance remains above; review-bound albums can keep
  matches below 100 at the two-hour deadline without naming the album.

### Matching leftovers

- A pre-merge play write can overwrite the folded count or lose the source album;
  a concurrent target favorite can fail with unmapped 23505.
- Artist sync requeues fully locked empty fields unnecessarily; six fields are
  repeated in handler/helper/query. Import-only credited artists fill on the next
  admin sync. An outage postpones all later fills by 5/15/60 minutes; nonlookup
  redirects count as outages and retry three times. Cancelled fills await their due
  times; unsent requeues can remain marked a day. In-memory and Redis stores lack
  shared contract cases. Merged release/recording IDs still report as outages.
- Separate deferred work: stale-copy album/cover edits, unlocked repair of known
  songs, stale `credits` marks on artist deletion, one failed request ending an
  import's other matches, dangling favorites after outright deletion, and movie
  deletion gaps formerly shared by album deletion.
- Refactoring candidates: eight-argument `ArtistCreditInput` lock helper, four merge
  adapter loops, artist/lyrics job records and the two match-writer text helpers.
- The concurrent-import solution's point 2 cites a removed assigner; refresh it with
  learnings. The worker runtime inventory had fifteen broken links before this work.

### PGroonga cleanup race

`PgroongaLeftovers::clear()` lacks `lock_timeout`; uncancellable lock holders can
stall to the lane budget. Its guard exempts an entire file after one helper call,
including later cleanup. `pgroonga_list_broken_indexes()` is untested on broken 4.1.0
indexes; 4.0.5 raised “object isn't found.” Deferred separately: template databases
instead of about 500 migrations/run, production PostgreSQL settings in test stacks,
a current-image check for local `baander-database:latest`, and upstream collision
reporting.

### Lyrics effect toggle and song browser panes

The pane fix lacks a layout-capable browser test; jsdom cannot cover it. Its dated
checkpoint ran neither web build nor Firefox/Safari.

## Completed work and evidence

The [November 28 roadmap](https://github.com/baander-app/baander/blob/455808baa77b566085aebcd40b00c511413be954/ROADMAP.md)
preserves every delivery, suite count, exception and earlier archive reference.
The commands below open the roadmap, list the plans and read the TV series plan.

```sh
git show 455808baa77b566085aebcd40b00c511413be954:ROADMAP.md
git ls-tree -r --name-only 455808baa77b566085aebcd40b00c511413be954 docs/plans/
git show 455808baa77b566085aebcd40b00c511413be954:docs/plans/2026-11-27-1827-feat-tv-series-libraries-plan.md
```

The cleanup retired the security audit, pipe-operator inspection, TV series
libraries and website design system plans. The older two-command worker design
and small-host household profile were superseded. The worker qualification
limits and the 4 GB household qualification remain open. Twelve plans remain
in `docs/plans/`, including both deferred React Native plans. All six archived
blobs are readable at the pinned revision. The 59 local roadmap links and six
incoming fragment links resolve.

The same revision records the Symfony 8.1.12 update and ownership drill, the
VAPID fix and the README introduction.
