# Security audit: findings and action points

Audit date: 2026-11-24. Commit: `9524a6fd9` on `master`. Scope: the application
(`src/`, `packages/`, `config/`), the web client and desktop shell (`ui/web`,
`ui/shared`, `ui/electron`, `ui/site`), the registry (`relay/`), the deployment
preset, the images and the CI workflows. `ui/rn` and Essentia are out of scope.

The audit had two halves. Eight reviewers read the code, one area each. Then every
finding that could be attacked was attacked on a disposable instance, together with
a set of attacks that depend on no finding: route sweeps, token forgery, path tricks
and a browser crawl over hostile media tags.

One finding is critical. A request that carries no credentials at all runs as the
last user the web worker authenticated. On the test instance such a request created
a super admin account. Read that finding first; the rest of the document assumes it
is fixed, because while it is open most other access controls can be walked around.

## How to read the evidence

Each finding carries one of three evidence levels.

- **Live**: reproduced on the running instance. The observed answers are quoted.
- **Read**: traced through the code, not run. The files and lines are given.
- **Suspected**: depends on library behaviour that was not verified.

The instance was a worktree preview of `9524a6fd9` with its own database, Redis and
keys, holding two fixture libraries of generated media. It ran in the `dev`
environment behind the development gateway. Where the environment matters, the same
test was repeated against a second server started inside the application container
with `app:serve --env=prod --no-debug`, which the startup table reported as
`env prod`, `debug false`. Those cases say "production environment" below.

## Findings at a glance

| ID | Severity | Finding | Evidence |
| --- | --- | --- | --- |
| C1 | Critical | A request without credentials runs as the last authenticated user | Live, both environments |
| H1 | High | One unauthenticated request with a `PS256` DPoP proof holds a web worker for minutes | Live, production environment |
| H2 | High | A DASH manifest named like a media file plays media from outside the library | Live |
| H3 | High | A library file is served from the application origin as `text/xml` and runs script | Live, two browsers |
| H4 | High | One failed `GET /ready` turns the registry off for everyone | Read (lead re-read) |
| H5 | High | 38 open advisories in `composer.lock`, several on reachable paths | Live (`composer audit`) |
| M1 | Medium | One file with a large tag stops the worker deployment pair | Live |
| M2 | Medium | A symlinked video is transcoded without the library containment check | Live |
| M3 | Medium | An admin `ftp://` path makes the server connect to internal hosts | Live |
| M4 | Medium | Failed sign-ins store megabytes of attacker data, with no retention | Live |
| M5 | Medium | Per-IP limits key on the full IPv6 address | Read |
| M6 | Medium | Any admin can clear every brute-force limiter | Live |
| M7 | Medium | A member can register unlimited push subscriptions | Read |
| M8 | Medium | The operator guide's source installation is the development stack | Read |
| M9 | Medium | Three single-barrier trust boundaries in the preset | Read |
| M10 | Medium | Images, the desktop binding and CI trust unverified inputs | Read |
| M11 | Medium | Member-writable fields have no size bound | Live in part |
| M12 | Medium | Desktop renderer hardening gaps | Read |
| M13 | Medium | Registry: unbounded read buffer; one CA for clients and Raft peers | Suspected |
| M14 | Medium | Production logs at debug level, unrotated, grown by request values | Read |
| L1 to L14 | Low | See "Low findings" | Mixed |

## Action points

Ordered by what to do first. Each names the findings it closes.

**Before any instance is reachable by someone you do not trust**

1. Give every request its own security token and clear it when the request ends
   (C1). Make `security.untracked_token_storage` a per-coroutine service, and make the
   bundle run every `kernel.reset` method of a pooled service, not the first.
2. Add a real-server regression test for C1: an authenticated request, then a request
   without credentials, repeated on every worker, expecting `401`. Add a second test
   that interleaves two users across a coroutine switch.
3. List every service whose second or later reset method the bundle currently drops,
   and judge each for the same class of leak (C1).
4. Run `composer update` inside the current constraints, which reaches every fixed
   version, and add a scheduled `composer audit --locked` gate (H5, H1).
5. Bound DPoP RSA keys: modulus at most 4096 bits, exponent 65537, and a length cap
   on the `DPoP` header. Install `ext-gmp` in the images (H1).

**Next**

6. Pass `-format_whitelist` and `-protocol_whitelist file` on every ffmpeg and ffprobe
   input, and refuse at import any probed format outside the real containers (H2).
7. Resolve every transcode source through `LibraryMediaFileResolver`, and skip
   symlinks that leave the library root at scan (M2).
8. Serve original media with a type mapped from the probed container, as an
   attachment, with `Content-Security-Policy: default-src 'none'; sandbox`. Give
   every non-HTML response a restrictive default policy (H3).
9. In the registry, latch "not ready" only on an authoritative incompatible schema
   answer, and re-run the startup probe while unready. Cap both read buffers (H4, M13).
10. On native output overflow, fail that one attempt. Stop probing with tags
    included, and bound the keyframe listing (M1).
11. Confine `POST /api/metadata/extract` and `/match` to resolved paths inside a
    library, and set `allow_url_fopen=Off` in the images (M3).
12. Cap the stored sign-in fields at 255 characters, cap the body size on auth
    routes, and purge `login_blocks` on a schedule (M4).
13. Key IPv6 clients on their /64 in both limiter listeners (M5). Require super admin
    to clear `auth_*` and `oauth_*` limiters, and log who cleared them (M6).
14. Cap push subscriptions per user and stop retrying a whole batch for one failed
    endpoint (M7). Add length and count constraints to member-writable fields and
    paginate the playlist list (M11).
15. Give source installations a production target, or point the operator guide at
    the preset (M8).

**Then**

16. Preset: sign Messenger envelopes and use a Redis ACL user, connect as a
    `NOSUPERUSER` role, and put a read-only socket proxy in front of the log agent (M9).
17. Supply chain: pin base images by digest, verify downloaded build inputs, commit
    hashes for the desktop binding, and keep fork pull requests off runners that
    hold the Docker socket (M10).
18. Desktop: drop `allow-file-access-from-files`, set `sandbox: true`, ship a static
    CSP, remove the credential bridge, and gate DevTools on `!app.isPackaged` (M12, L11).
19. Work through the low findings and the hardening notes.

## Critical

### C1. A request without credentials runs as the last authenticated user

**Who:** anyone who can send an HTTP request to the instance. No account is needed.
The precondition is that some user has used the application since the web worker
started, and workers never recycle (`worker_max_request: 0` in
`config/packages/swoole.yaml`).

**What was observed.** On the fresh instance, before anyone had signed in, 40
requests without credentials to `GET /api/admin/users` answered `401` and 40 to
`GET /api/auth/me` answered `404`. That is the correct behaviour.

Then an admin signed in and made 120 ordinary requests. After that, requests with no
`Authorization`, `DPoP` or `Cookie` header, each on a new connection, answered:

| Request | Answer |
| --- | --- |
| `GET /api/admin/users` | `200` on 40 of 40, with the user list and email addresses |
| `GET /api/auth/me` | `200` on 40 of 40, as "Admin User" |
| `POST /api/admin/users` with `"roles": ["ROLE_SUPER_ADMIN"]` | `201` |
| `GET /api/admin/oauth/clients` | `200` |
| `POST /api/webhooks/` | `201`, returning the signing secret |
| `GET /api/stream/original/track?id=…` without a signature | `200` |

The account created by the third row signed in normally and read
`GET /api/admin/settings`.

The identity follows the last authenticated request each worker handled. After a
member's requests, the same anonymous calls returned the member's profile and `403`
on admin routes. After the admin signed out, the admin's own token answered `401`
while anonymous requests still answered `200` on 30 of 30.

The production environment behaves the same: after admin requests spread over 12
connections, 12 of 12 fresh anonymous connections read `/api/admin/users`. A first
attempt there showed `401` throughout, because fixed dispatch (`dispatch_mode: 2`)
sends one connection to one worker and the admin had used a single connection. A
test for this must spread both sides across workers.

Two things stayed closed. `POST /api/ws/tickets` answered `401`, because it needs a
presented token. Password change and TOTP setup answered `422` `step_up_failed`,
because step-up still needs the real password.

**Mechanism.** Symfony tags `security.token_storage` with two reset methods,
`disableUsageTracking` and then `setToken`
(`vendor/symfony/security-bundle/Resources/config/security.php:73-84`). The second
one is what clears the token between requests in a long-running process. The bundle's
`StatefulServicesPass::getServiceResetters()` keeps only the first reset method of
each service
(`packages/swoole-bundle/src/Bridge/Symfony/Bundle/DependencyInjection/CompilerPass/StatefulServicesPass.php:296-303`),
so the pool resets the service with `disableUsageTracking` and `setToken(null)` never
runs. Every pooled `security.token_storage` also wraps the same
`security.untracked_token_storage`, one object per worker.

`OAuth2Authenticator::supports()` returns false when a request has no
`Authorization` header
(`src/Auth/Infrastructure/Security/OAuth/OAuth2Authenticator.php:76-82`). Nothing
then overwrites the token, and the access checks read the previous request's.

`git blame` dates the reset-method code to the initial commit of 2026-08-01, so this
is not a recent regression.

**A second consequence, read but not shown live.** Two requests that interleave at a
coroutine switch share that one token. The web workers switch at curl and process
calls (`src/Kernel.php:22`), so a request that waits on a provider or on ffprobe can
resume under another user's identity.

**Fix.** See action points 1 to 3. A listener that clears the token at the start of
each request is a useful second layer, but it is not the fix: with one shared token
it would also wipe the token of a request that is waiting.

## High

### H1. One unauthenticated request with a `PS256` proof holds a web worker for minutes

**Who:** anyone who can reach `POST /api/oauth/token`. The same proof check guards
login, refresh and pushed authorization requests.

**What was observed** on the production-environment server. A request without a proof
answers `400` and hands out a `DPoP-Nonce`. The next request carried a proof with
`"alg": "PS256"` and a `jwk` holding random bytes as an RSA key:

| Modulus | Exponent | Time to the `400` answer |
| --- | --- | --- |
| 2048 bits | 24 bits | 2.8 s |
| 2048 bits | 256 bits | 25.2 s |
| 2048 bits | 2048 bits (1,620-byte header) | 206.6 s |

During the last one the worker sat at 100 % CPU, and 3 of 16 simultaneous
connections to `/live` timed out after 5 seconds: the ones dispatched to that worker.
The instance runs four web workers, so four such requests stop the web tier.

The ordinary-size key is already enough. The token endpoint allows 120 requests per
minute per address (`config/packages/auth.yaml:246-247`). At 2.8 s each that is 336
worker-seconds a minute, and four workers supply 240.

**Mechanism.** `JwkPublicKeyConverter` enforces a 2048-bit floor on the modulus and
nothing else (`src/Auth/Infrastructure/Security/OAuth/JwkPublicKeyConverter.php:71-82`),
and `DpopProofValidator` verifies with the key from the proof's own header (line 173).
`web-token/jwt-library` 4.2.4 puts no bound on the exponentiation (advisory
`GHSA-96f8-m4jr-72xj`, fixed in 4.2.6). The image has neither `gmp` nor `bcmath`
(checked with `php -m` in the container), so `brick/math` computes in pure PHP.

**Fix.** Action points 4 and 5. The web client signs with ES256 only
(`ui/shared/dpop/proof.ts`); if no supported client needs RSA proofs, refusing RSA
algorithms for DPoP removes the path altogether.

### H2. A DASH manifest named like a media file plays media from outside the library

**Who:** someone who can place a file in a library and holds an account with access
to that library. No victim has to do anything.

**What was observed.** Two 500-byte DASH manifests were placed in the fixture
libraries, one named `Holiday Clip (2021).mkv` and one named
`01 - Borrowed Track.mp3`. Each has a `<BaseURL>` that names a file under the other
library's mount.

- The scan imported both. The "movie" was recorded with the private file's
  properties: 640x360, 6 seconds.
- A member granted only the movie library called `POST /api/stream/sign` for the
  manifest's video and fetched the returned playlist, init file and segment. The
  result was 144 frames of H.264 at 640x360. A decoded frame shows the test pattern
  of the private film, which exists only under the music library's mount.
- A member granted only the music library, who could see zero movies, called
  `GET /api/stream/track?id=…&mode=copy&format=aac&container=mp4&index=0`. The answer
  was 6.02 seconds of AAC at about 882 Hz: the private 880 Hz tone under the movie
  library's mount. Every other audio fixture is 440 or 330 Hz.

**Mechanism.** No ffmpeg or ffprobe input carries `-f`, `-format_whitelist` or
`-protocol_whitelist` (`FFprobeArguments.php:12-21`, `SegmentEncoder.php:238-239,276`,
`CopySegmentArguments.php:49-50`, `AudioRenditionExecutor.php:142-147`,
`VideoTimelinePreview.php:139-140`). FFmpeg therefore picks the demuxer from the
content, finds a DASH manifest, and opens the file it names. The manifest itself is a
regular file inside the library, so the containment check passes. Import never checks
the probed format (`FilesDiscoveredHandler.php:399,1144`), and `Id3Reader.php:25-41`
returns empty metadata for a tagless file instead of refusing it.

The reviewer tested offline that FFmpeg refuses `http` targets for a local input, so
this reads local files only. The target's name must end in `aac`, `m4a`, `m4s`, `m4v`,
`mov`, `mp4`, `webm` or `ts`.

**Fix.** Action point 6.

### H3. A library file is served from the application origin as `text/xml` and runs script

**Who:** someone who can place a file in a movie library and holds an account with
access to it, which is needed to mint the link. The victim opens a link to their own
server while signed in.

**What was observed.** The file is an XML prolog, a script element in the XHTML
namespace, a comment opener, about 81 KB of text padding, and then an MPEG-TS video.
It was named `.mkv`.

- The scan imported it as a movie (ffprobe reports `mpegts`, H.264, AAC).
- A member called `POST /api/stream/delivery` declaring `mpegts`, `h264` and `aac`.
  The answer was `"kind": "original"` with a signed `/api/stream/video` URL.
- That URL, fetched with no credentials, answered `200` with
  `Content-Type: text/xml; charset=UTF-8`, no `Content-Security-Policy` and no
  `Content-Disposition`.
- Opened in headless Chromium and in Firefox, the script ran. Its dialog showed
  `document.domain` as the instance's own host name.

Script on the application origin can use the signed-in user's tokens and DPoP key
from IndexedDB. If the victim is an admin, that is an admin session.

**What did not work, and why it matters.** The reviewers' original test files, an SVG
prefix directly followed by the video, are served as `application/octet-stream` with
`nosniff`, and browsers download those. PHP's `finfo` in the image classifies them
differently from the host's `file` command. The text padding is what flips `finfo` to
a text type. An `.html`-typed variant is served as `text/html` but receives the nonce
policy, and Chromium blocked its inline script; that file still renders attacker HTML
on the origin, and the policy has no `form-action` directive.

**Mechanism.** `VideoService.php:50` takes the type from `finfo` at request time with
no allowlist. `OriginalMediaController.php:89-103,259-268` sends the file inline with
that type. `SecurityHeadersListener.php:75,112-117` adds a policy only to `text/html`
and untyped responses. The image routes were fixed for this class on 2026-11-22
(`a6998f170`); this route was not part of that fix.

**Fix.** Action point 8.

### H4. One failed `GET /ready` turns the registry off for everyone

**Who:** any client that can reach the registry. Not run; the registry was not built
for this audit. The lead re-read the code below after the reviewer reported it.

**Mechanism.** The `/ready` handler wraps its database probe in a `try` whose
`catch (const std::exception &)` sets `initialized = false` and rethrows
(`relay/cpp/server.cpp:99-137`). `Failure` derives from `std::runtime_error`
(`relay/include/registry/core.hpp:10`), so every failure lands there, including
"Database request budget exhausted." and "Database request capacity exhausted."
(`relay/cpp/transport.cpp:241-254`). Registration and lookup both refuse with `503`
while `initialized` is false. The only code that sets it true again is a later
successful `/ready`: the startup loop `initialize()` has already returned
(`server.cpp:166-185`).

The database budget is the session deadline minus 100 ms (`server.cpp:239-241`), and
the deadline starts at connect. A client that sends `GET /ready` slowly, finishing
about 1.95 s into the default 2-second deadline, makes the budget check fail before
the database is contacted. Eight concurrent lookups plus one `/ready` do the same
through the pool's capacity check.

The container health check probes `/health`, not `/ready`
(`relay/docker/docker-compose.yml:24-25`), so nothing restores the flag. A transient
rqlite fault during any `/ready` probe has the same lasting effect, which makes this a
reliability defect as well.

**Fix.** Action point 9. `relay/tests/Support/HttpContract.php:785-801` covers only
the malformed-schema case.

### H5. 38 open advisories in `composer.lock`

`composer audit --locked --no-plugins` on 2026-11-24 reports 38 advisories in 14
packages, all published between 2026-11-04 and 2026-11-17. The lead re-ran it and got
the same count.

| Package | Installed | Fixed in | Advisories | Reachable here |
| --- | --- | --- | --- | --- |
| `web-token/jwt-library` | 4.2.4 | 4.2.6 | 11 | Yes: `GHSA-96f8-m4jr-72xj` is H1. The rest need JWE or JSON serialization, which the application does not use |
| `web-auth/webauthn-lib` | 5.3.9 | 5.3.13 | 6 | Yes, by reading: `GHSA-rxc4-cg58-jrpw` (a CBOR length allocated before it is checked, on passkey sign-in without a session) and `GHSA-c9p6-r72h-rp3x` (PEM attestation certificates; `PasskeyService.php:115,118` registers those formats) |
| Symfony components | 8.1.10 | 8.1.11 | 18 | Not shown. `GET /index.php/metrics` answered the web shell, and `HEAD` on protected routes answered `401` on a clean server |
| `spomky-labs/otphp` | 11.5.0 | 11.5.1 | 3 | No: `TotpService` never parses a provisioning URI |

Reachability was judged from advisory titles and the code, not from advisory texts.
No workflow, Makefile target or Composer script checks advisories today.

**Fix.** Action point 4.

## Medium

### M1. One file with a large tag stops the worker deployment pair

**Live.** A 1.24 MB Matroska file with one 1.2 MB `COMMENT` tag was scanned into the
movie library. A member's transcode was running. When the tagged file was played
(`POST /api/stream/sign`), both worker containers exited with code 1 within seconds.
The execution unit logged `worker_supervisor_stopped` with reason `management_failed`
at `WorkerSupervisorRunner.php:117`. The control unit logged `worker_media_fatal` at
`NativeExecutionClient.php:146` and then `management_failed` at
`WorkerPeerHandshake.php:89`. A new transcode answered `503` "Transcode startup is
temporarily unavailable." The `async` and `scheduler` consumers stopped with the pair.

A minute later both containers were still down. The preview gives them no restart
policy; the preset renders `restart: unless-stopped`
(`packages/baander-deployment/src/Presets/Renderer.php:71`), so there the effect is an
interruption that anyone who may play the file can repeat.

The play was triggered as an admin. The probe that overflows runs for any account
allowed to play the file.

**Mechanism.** The play-time probe prints tags (`TranscodeProcessPool.php:44-49`). The
native daemon caps one command's output at 1 MiB (`NativeExecutionProtocol.php:13`),
and on overflow marks itself poisoned and kills every attempt
(`NativeExecutionDaemon.php:363-365,384-386,114`). The reviewer measured two more
triggers offline: an MP3 with randomised ID3 frames writes 9 MB of stderr on an audio
rendition, and about 10,080 keyframes fill the cap in the keyframe listing for copy
delivery, which a 2 h 48 min film with one-second keyframes reaches by accident.

**Fix.** Action point 10.

### M2. A symlinked video is transcoded without the library containment check

**Live.** A symlink in the movie library pointed at a film under the music library's
mount. The scan imported it. For a member with the movie library only,
`POST /api/stream/delivery` answered `409` "Playback media is unavailable.", so the
containment check holds on original delivery. `POST /api/stream/sign` for the same
video started a transcode and served 144 frames of the film.

**Mechanism.** `TranscodeSessionSubscriber.php:128-132` uses `Video::getPath()` after
only `is_file`. `DirectoryScanner.php:37-46` yields file symlinks. Original and copy
delivery resolve through `VideoService.php:37` and `LibraryMediaFileResolver.php:29`.

**Fix.** Action point 7.

### M3. An admin `ftp://` path makes the server connect to internal hosts

**Live.** As an admin, `POST /api/metadata/extract` with
`{"path": "ftp://172.21.0.1:2121/a.mp3"}` made the application container open two FTP
control connections to a listener on the Docker bridge and log in as `anonymous`. The
listener answered `PASV` with the Redis container's address and port. PHP then
connected its data channel there, and the endpoint answered `422` after 4.0 s.
Steered at a closed port, it answered after 0.0 s. That difference is an open-port
oracle for any address the container can reach.

**Mechanism.** The `path` field has only `NotBlank`
(`ExtractMetadataRequest.php:26-27`) and reaches `file_exists()` in
`MediaFileMetadata.php:52` and `fopen()` in
`packages/baander-media-metadata/src/FormatDetector.php:26-30`. `allow_url_fopen` is
on in the development and production images. The `ftp` wrapper implements `stat`, so
`file_exists()` connects; the `http`, `php` and `data` wrappers do not and stop there.
Library paths are validated (`src/Library/Application/PathValidator.php:45`); this
endpoint is not.

Each silent target also blocks a web worker for up to 60 seconds, because web workers
hook only curl and process calls. The two error texts distinguish an existing local
path from a missing one.

**Fix.** Action point 11.

### M4. Failed sign-ins store megabytes of attacker data

**Live.** Three failed `POST /api/auth/login` requests, each with a 2,000,000-byte
`username` field, each answered `401`. Afterwards `login_blocks` held 3 rows totalling
5,859 kB.

**Mechanism.** `PasswordAuthenticator.php:107-117` stores `email`, `username` and the
User-Agent unvalidated in unbounded `text` columns. Swoole accepts 16 MiB bodies.
Nothing purges the table, and the admin list returns the full value
(`LoginBlockResource.php:41`). The bound is the per-IP login limit, 20 per 5 minutes,
which M5 weakens for IPv6 clients.

**Fix.** Action point 12.

### M5. Per-IP limits key on the full IPv6 address

**Read.** `RateLimitListener.php:89-177` and `ApiRateLimitListener.php:135` key on
`getClientIp()` unchanged. One /64 holds 2^64 addresses, so a client with ordinary
IPv6 connectivity never meets a per-IP limit: sign-in spraying across accounts,
registration and reset-mail floods, device user-code guessing and M4.
`RateLimiterOAuthClientThrottle.php:74` already folds IPv6 to the /64. The per-account
failure limit is unaffected.

Not attacked live: the preview disables the per-IP limiters in `dev`, and the test
host has no IPv6 path to the instance.

### M6. Any admin can clear every brute-force limiter

**Live.** An account with `ROLE_ADMIN` only called
`DELETE /api/monitor/rate-limiters/clear?confirm=true`. It answered `200` and listed
every limiter, the sign-in and step-up ones included. An account that had been locked
out moments before then signed in. The same admin was refused (`403`) when removing a
login block, which needs `ROLE_SUPER_ADMIN`. The clear is not logged with its actor
(`RateLimiterMonitorController.php:18,158,247`).

### M7. A member can register unlimited push subscriptions

**Read.** `PushSubscriptionController.php:123-163` stores any number of rows per user.
`SendPushHandler.php:56-87` sends to each in turn and throws if any failed, so
Messenger repeats the whole batch three more times. Each send forks a DNS helper and
opens a new TLS connection. A member can trigger their own notifications. Not
replayed, because the endpoints are real push services.

### M8. The operator guide's source installation is the development stack

**Read.** `docs-book/part-1-operator-guide/getting-started/installation.md:243` builds
`--target dev`, and `Dockerfile:516` sets `APP_ENV=dev` there. In `dev` every limiter
returns early (`RateLimitListener.php:202`, `ApiRateLimitListener.php:140`) and the
production secrets check is skipped (`src/Kernel.php:44`). The compose file it uses
runs the application `privileged`, mounts the checkout read-write, and publishes
PostgreSQL on all interfaces with the fallback password `baander`
(`docker-compose.yml:34,72,124-128`). The guide says it is a development stack, but it
is the only documented source installation.

### M9. Three single-barrier trust boundaries in the preset

**Read.**

- **Redis.** Messenger envelopes are unsigned (`config/packages/messenger.php:163`)
  and cache values are unserialized with every class allowed. Whoever can write to
  Redis can run any registered handler. Preset Redis runs as root with its password
  in `argv` (`Renderer.php:103`).
- **PostgreSQL.** Web and workers connect as the cluster superuser
  (`Renderer.php:26,91`), so an SQL injection would reach `COPY … TO PROGRAM`. The
  audit found no SQL injection; see "What held".
- **Log agent.** It runs as root with the Docker socket
  (`packages/baander-deployment/src/Observability/LogCollection.php:68-74`). A
  read-only bind does not restrict the Docker API, so compromising the agent
  compromises the host.

### M10. Images, the desktop binding and CI trust unverified inputs

**Read.**

- `Dockerfile:29` pipes the NodeSource setup script into `bash` in an ancestor of the
  production stage. `docker/ffmpeg/Dockerfile:25` pipes rustup into `sh`, and lines
  39-41 take FFmpeg and codec sources through a build script selected by tag. None of
  the 10 external `FROM` lines uses a digest, and 8 float.
- The desktop audio binding is downloaded at install. `fetch-prebuilds.mjs:99-102`
  skips the hash check when the release has no `SHA256SUMS`, and the sums come from the
  same release. `audio-core.yml:324` uploads with `--clobber`.
- `.github/workflows/ci.yaml:7,30,32` runs pull-request code on a self-hosted runner
  with the Docker socket, and the contributing guide says fork pull requests run
  every gate. `ci.yaml:35` uses the mutable `baander-runner:latest`.

The 25 remote actions are pinned by commit SHA, every workflow sets
`permissions: contents: read`, and none uses `pull_request_target`.

### M11. Member-writable fields have no size bound

**Live in part.** A member's `POST /api/playlists/` with a 3,000,000-character
`description` answered `201` and echoed it back. By reading, `UpdatePlaylistRequest`
has no constraints at all, `AddCollaboratorHandler.php:34-58` adds a collaborator
without that user's consent, and `PlaylistRepository.php:109-121` lists owned and
collaborated playlists without a limit. One member can therefore put megabytes into
another member's playlist list. `PlayActivityRequest` (`platform`, `player`) and the
EQ profile `deviceId` follow the same pattern.

### M12. Desktop renderer hardening gaps

**Read.** No script injection sink was found in the web client, so these are a second
line of defence. `ui/electron/src/main/flags.ts:11` sets
`allow-file-access-from-files` on a `file://` renderer whose policy allows
`connect-src https:`. The main window runs with `sandbox: false`
(`main-window.ts:115`). When bootstrap fails the web client loads with no policy
(`renderer/index.tsx:62-65`). `config.getUser(username)` returns the keychain password
to page script (`preload/index.ts:24-32`, `config.ipc.ts:43-46`), and the web client
never calls it. `set-server-url` stores a raw string that is interpolated into the
policy (`config.ipc.ts:28-41`).

### M13. Registry: unbounded read buffer; one CA for clients and Raft peers

**Suspected.** `relay/cpp/server.cpp:220-226` reads into a default
`beast::flat_buffer`. The parser limits bound the header block and the decoded body,
but an unterminated chunk extension may grow the buffer for the 2-second deadline on
up to 64 sessions, against a 96 MB container limit. `beast::flat_buffer buffer{16384}`
bounds it whatever the parser does.

`relay/docker/rqlite-entrypoint.sh:60-64` passes the same `ca.crt` as the HTTP client
CA and the node CA. A holder of the API's or the backup worker's client key would
then pass Raft peer verification. What a rogue peer can do was not checked against
rqlite 10.5.1.

### M14. Production logs at debug level, unrotated, grown by request values

**Read.** `config/packages/monolog.yaml:74-91` logs at debug in production.
`WebProcessor` adds the URL and referrer to every record, and
`CorrelationIdProcessor.php:40-41` copies `X-Baander-Correlation-ID` unchecked. A
request with a long query, referrer and correlation ID writes about nine records
carrying all three. Byte rates were not measured.

## Low findings

| ID | Finding | Evidence |
| --- | --- | --- |
| L1 | `GET /api/radio/sources` returns `syncUrl` and `syncConfig` to every member. A member read a sync URL holding credentials and a config token an admin had stored (`RadioSourceController.php:80-89`) | Live |
| L2 | Recommendations are filtered per viewer after the SQL `LIMIT`. One member stored 100 rows with a high score and another member's list for that song went from 1 row to 0 (`RecommendationRepository.php:57-81`) | Live |
| L3 | The per-account failure limit is read before the password check and charged after it. 50 concurrent wrong passwords gave 13 evaluations against a limit of 10. Afterwards the right password answered `429` for 291 seconds, which `auth.yaml:202-207` documents as a trade-off | Live |
| L4 | The webhook destination policy accepted `http://[64:ff9b::a00:5]:8080/` (NAT64) and `http://[::7f00:1]:9200/` with `201`. It refused loopback, service names, `169.254.169.254`, the `::ffff:` form, and decimal and hex hosts. Admin only (`WebhookDestinationPolicy.php:48-49`) | Live |
| L5 | Route parameters reach API paths unencoded. Opening `/admin/library/..%2Fartists%2F<id>%3F` made the client call `GET /api/artists/<id>`; the page's delete button would send `DELETE` there | Live |
| L6 | `POST /api/recommendations/` with `{}` answers `500`. `GET /api/artists/?page=9223372036854775807&limit=100` answers `500` (`ArtistController.php:196`; same arithmetic in Album, Movie, MovieDirector, Favorites and Transport) | Live |
| L7 | `docker/prod/nginx.conf:39-40` logs `$request`, so signed delivery URLs reach the access log. Lines 12-15 forward a client's `X-Forwarded-Proto` | Read |
| L8 | The production secrets check accepts the committed `.env.test` values and has no minimum length for the Redis and database passwords (`ProductionSecrets.php:33,127-150`). The guards compare with the literal `prod`, so `APP_ENV=staging` on the production image boots on placeholder secrets (`Kernel.php:44`) | Read |
| L9 | A member can hold every video slot for 24 hours by signing and never cancelling (`StaleTranscodeSessionExpiry.php:35`). `GET /api/radio/countries` fetches the catalog on every call, and `GET /api/radio/stations` has no limit | Read |
| L10 | Registry: the image build sets no hardening flags (`relay/CMakeLists.txt:40,91,115`), OpenSSL is pinned to 3.5.3, and pairing completion returns a completed session before it checks expiry and server (`CompletePairingHandler.php:31-41`) | Read |
| L11 | Desktop: LAN discovery accepts any responder's name and URL, `http:` included (`discovery.service.ts:169-214`). `openDevTools()` runs in packaged builds (`ui/electron/src/main/index.ts:117`) | Read |
| L12 | The static handler serves `/index.php` as a file (282 bytes of front controller, no secrets) and `/.vite/manifest.json` | Live |
| L13 | A refused refresh answers `401` with the message "Internal server error." | Live |
| L14 | Git history keeps the deleted development TLS key (`docker/dev/juul.localdomain.key`, commit `1268df23c`) and earlier committed development secrets. None is a production credential | Live (`git log`) |

## Hardening notes

No attack path was shown for these.

- The web policy has no `form-action`, no `strict-dynamic`, and allows any `http:` and
  `https:` image and media source. HSTS has no `includeSubDomains`.
- `CspNonceListener.php:11-24` overwrites the nonce `SecurityHeadersListener` just set.
  Header and template still agree.
- Disabling a user revokes tokens but does not advance the credential epoch
  (`DisableUserHandler.php:62-68`). A sign-in verified just before the disable can
  store a token pair that works again once the account is re-enabled.
- A user cannot list or end a single session or third-party grant. Consent, device
  approval and personal access client creation need no step-up.
- The pending TOTP secret sits unsealed in the cache for 10 minutes. There are no
  recovery codes.
- The Swoole management API on `127.0.0.1:9200` stops and reloads the server without
  authentication.
- GD decodes embedded covers with no pixel bound
  (`packages/baander-image-processing/src/GdImageConverter.php`). `FlacParser.php`
  keeps every metadata block. A sidecar `.lrc` follows symlinks with no size cap.
- Provider fetches have no body size cap apart from JWKS, and the remote cover type
  comes from the `Content-Type` header.
- The registry accepts 64 sessions of 2 seconds with no per-source cap, sets no cipher
  list, and writes no log at all.
- `composer.json` sets `minimum-stability: dev`. `.yarnrc.yml` enables install scripts
  with no minimum package age. `ui/electron` cannot be audited with the installed
  Yarn, and `packages/baander-audio-core` has no lockfile.
- A new host steers the previous host's encoder after a party handover, which
  `docs-book/part-2-developer-guide/contexts/party.md:58` documents.
- Webhook delivery ignores the per-user `webhook` preference and sends every user's
  notification text to all admin webhooks.

## What held

These attacks failed. They are listed so the next audit can start from them.

- **No access without an identity.** On a production-environment server nobody had
  signed in to, 374 route and method calls without credentials gave `401` on 328. The
  other 46 were the public endpoints answering as intended.
- **No privilege escalation by a member.** All 102 calls to `/api/admin`, `/api/debug`
  and `/api/monitor` routes answered `403`. A member's write to a server setting
  through the user settings route answered `403`. Extra `roles` and flag fields on
  the profile update were ignored.
- **Token forgery.** 30 cases were refused: `alg: none`, HS256 signed with the RSA
  public key in four encodings, an attacker `jku`, a traversal `kid`, an embedded
  attacker key, changed claims, and proofs with a wrong key, method, URL, time or
  token hash, or presented twice.
- **Refresh tokens.** Another DPoP key is refused and revokes nothing. Reuse after the
  replacement was used revokes the whole refresh token chain. Reuse inside the
  lost-response grace issues a new pair, as documented.
- **Library access.** A member without a grant sees no movies. Revoking access cut a
  signed delivery URL off at once.
- **Signed delivery URLs.** Eight tampered variants answered `403`: another video ID,
  a later expiry, no signature, another owner, an added parameter, a duplicated
  parameter, an upper-cased signature, and the query replayed on another route.
- **Path normalisation.** 24 spellings of `/api/admin/users` and two other admin
  routes, on a clean server, gave only `401` and `404`, apart from four that returned
  the web shell.
- **Dotfiles and Host.** `/.env`, `/.git/config` and `/composer.json` return the web
  shell. A forged `Host` and `X-Forwarded-Host` are not rendered.
- **Cross-site scripting in the web client.** A headless browser signed in as admin
  visited 58 screens while the library held tags, chapter titles, subtitle text and
  track names carrying markup. The values appeared as text on 22 screens. Nothing
  executed, no element was injected, and no policy violation was logged. The reviewer
  found no `dangerouslySetInnerHTML`, `innerHTML` or `eval` in the client.
- **Search.** 14 query-syntax probes on three endpoints produced no `500`.
- **By reading.** 513 database call sites bind every value. Every process is spawned
  from an argument array with a pinned executable. Provider requests go through one
  client that pins a public address and refuses redirects. Admin routes outside the
  admin prefixes carry their own role checks. The registry binds every SQL parameter
  and verifies enrollment with a constant-time comparison.

No way was found for an admin to run code on the host. An admin can register any
existing absolute directory as a library and read media files under it, which is the
design.

## Limits of this audit

- Per-IP rate limits were not attacked. The preview disables them in `dev`, and the
  production-environment server was used for C1, H1, the sweeps and the path tests.
- The registry was not built or run. H4 and M13 rest on reading.
- The desktop application and the live WebSocket protocol were not exercised.
- The preset was read, not deployed.
- No parser or decoder was fuzzed, and nothing was tested under sustained load.
- The lead re-read the code for C1 and H4. Other findings marked "Read" rest on one
  reviewer's trace.
- No attack was aimed at a host outside the test machine. One webhook validation
  looked up `hooks.baander.app`; the webhook was deleted before any delivery. The
  instance may have made its own catalog request on the radio screens.

## Notes from the test setup

- The primary checkout's preview was blocked before the audit: an interrupted
  activation with a started database migration and a pruned release image. It was left
  untouched. The audit ran on a separate worktree preview.
- A fresh worktree needs `.env`, which Git ignores. The development environment guide
  lists two prerequisites for a linked worktree and not this one. Without it the first
  start fails at `bin/console` and leaves the preview blocked with a started
  migration.
