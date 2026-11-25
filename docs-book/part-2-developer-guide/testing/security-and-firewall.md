# Security, Firewall, and Network Suites

This page covers the integration and functional suites for token rotation and reuse grace, DPoP store outages, signed video delivery, catalog and playlist mutation firewalls, delegated token scopes and sign-in grants, step-up, credential changes and sign-in limits, verification and registration, atomic rate-limit storage, and outbound destination policies.

## Session, Token, and Firewall Matrices

Five suites cover the session and token-refresh lifecycle and run through the
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
endpoint and the quality ladder.

The contract the suite records follows the order the firewall works in. A valid signed URL is a bearer capability and serves any holder
without a credential. A credential that is presented must be valid: an expired,
revoked, foreign-audience, malformed or fingerprint-mismatched token answers `401`
even with a valid signature. The DPoP proof is never read on a delivery route, so a
missing, foreign-key or reused proof answers `200` there and records no replay key.
A signature fault answers `403` on every delivery route before the streaming port
is called, the segment index included. On the signing endpoint and the ladder, a
missing proof, a foreign key, a reused proof and an unbound token answer `403`, a
bad token `401`, and a video of another library `403`. A DPoP-bound token
presented with the Bearer scheme answers `401` everywhere the `api` firewall
authenticates.

Every refusal row also asserts which component refused. The API answers every
`403` with one generic body, so the suite registers a kernel-exception probe that
records the refusing exception's message; the `401` rows read the authenticator's
code from the body. One assertion scans every response body and every log file of
the kernel for the tokens, proofs and signatures the matrix used. That signature
scan found the request URL, signature included, in the web processor's `url` field
and the access log, and `StreamSignatureRedactionProcessor` now redacts it.
`tests/Unit/Shared/Infrastructure/Security/DeliveryRouteInventoryTest.php` keeps
the delivery route set equal across the security configuration, the binding
listener's exemption list and the rate limiter's exemption list, and the suite
derives its unsigned-request loop from that inventory. It substitutes sample public
IDs into every delivery route and checks that paths no delivery route serves, such
as `/api/transcode/jobs/master.m3u8`, are not public.

A signed live URL serves only while the owner of the job it names may still play the
job's video. The suite signs live URLs as a member and then removes the member's
library access, disables the owner, or signs for a job whose owner belongs to another
library; each next manifest or segment request answers `409`.
`tests/Unit/Transcode/Infrastructure/Transcode/LiveDeliveryAccessAdapterTest.php`
covers the owner check itself, a missing job included.

The catalog, library and playlist mutations have their own matrices.
`tests/Integration/CatalogMutationFirewallTest.php` crosses thirty routes (albums,
songs, movies, genres, covers, cover extraction, the admin deletes and previews, the
admin duplicates listing and the library routes) with anonymous, member, unrelated
member and administrator. Every denied row asserts the role voter's refusal, an
unchanged catalog snapshot, empty in-memory transports, no storage call and no Redis
growth. Every administrator row asserts the change and the port calls it makes;
scan-all, file deletion on a real library root, album deletion with a cover,
cross-library merge and path validation have rows of their own. Its kernel,
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
These suites act as the web app, so their tokens are minted on the first-party client
(`tests/Fixtures/Auth/FirstPartyOAuthClient.php`). `FirstPartyOAuthClient::accessToken()`
takes the sign-in grant the token records, without a default, because only a password or
passkey grant gives a first-party token its authority. `createTestUser()` in
`tests/Functional/TestCase.php` creates a verified user; a test of an unverified account
passes `verified: false`.

## Delegated tokens, step-up and sign-in limits

- `tests/Unit/Auth/Infrastructure/Security/OAuth/ScopeRouteInventoryTest.php` holds
  `ScopeRouteMap` to the routes in `config/routes.yaml`: every route is public,
  first-party only or reachable with named scopes, and no route a delegated token may
  reach requires an admin role. A new route fails it until it is classified. It loads
  the routes through the routing component, without compiling the container.
- `tests/Unit/Auth/Infrastructure/Security/OAuth/PublicRouteIdentityTest.php` reflects
  the controller method of every public `/api/` route, follows the same-class helpers
  it calls, and fails when one reads the caller's identity, through a current-user
  parameter included. It is a deny-list of known identity reads.
- `tests/Integration/OAuthScopeEnforcementTest.php` sends delegated tokens through the
  production firewall, PostgreSQL and Redis: a token of any client other than the
  first-party one reaches only the routes its scopes cover and never carries its
  user's admin authority, while the first-party token keeps both.
- `tests/Integration/OAuthGrantPathAcceptanceTest.php` refuses an anonymous push that
  names the first-party client and stores nothing, and refuses the first-party client
  at the token endpoint for every grant. `tests/Integration/OAuthFirewallAcceptanceTest.php`
  checks that only a password or passkey sign-in of the first-party client carries the
  administrator's role, and that a first-party token from an authorization code keeps
  no scopes. `tests/Integration/OAuthAccessTokenGrantMigrationTest.php` and
  `tests/Integration/OAuthPersonalAccessClientMigrationTest.php` cover the stored grant
  column and the personal access clients' first-party flag.
- `tests/Functional/Controller/StreamControllerAccessTest.php` serves the original media
  to a delegated token only with the `library` scope, and a signed original fetch
  without a token still works.
- `tests/Integration/TotpLoginReplayTest.php` signs in with a TOTP code through the
  production firewall: the web client's nonce challenge and retry with the same code,
  single use of each code, the encrypted secret at rest, and two logins racing with
  one code (the second in a child process, `tests/Fixtures/Auth/totp-login-race.php`).
- `tests/Functional/Auth/StepUpTest.php` covers the email change, the password change,
  TOTP setup and disable, and passkey registration options and removal: each needs the
  current password, plus a code when TOTP is on, every mismatch gets one answer, a
  refusal that costs no attempt comes first, and attempts are limited per account.
- `tests/Integration/PasswordChangeSessionRevocationTest.php` proves that turning TOTP
  on or off, adding or removing a passkey, changing the password and confirming a new
  address keep the requesting session and end every other one, a paired device's
  included, and close their sockets when the change commits. Two-process races
  (`tests/Fixtures/Auth/session-request-race.php`) show that a change from a session
  another change ended is refused and stores nothing, while two changes from one
  session both succeed. A password reset racing an operator's disable or demotion leaves the
  operator's change in place: the disabled account's reset is refused, and the demoted admin
  stays demoted.
  `tests/Unit/Auth/Application/Service/CredentialChangeRevokerTest.php` covers the fence alone.
- `tests/Functional/Auth/PasswordResetClaimTest.php` opens a verification link signed
  out and checks that it verifies nothing and that the owner's later reset claims the
  account; `tests/Functional/Auth/EmailVerificationDeliveryTest.php` covers redemption
  in the account's own session and another account's link.
- `tests/Functional/Auth/UnverifiedAccountGateTest.php` refuses an unverified account
  outside its verification routes, delegated tokens included, and lets it read its
  profile, resend its link and change its address. `ScopeRouteInventoryTest` holds
  that allowlist to the compiled routes.
- `tests/Functional/Auth/PasskeyEndpointsTest.php` covers the passkey check endpoint and
  discoverable registration; `tests/Unit/Auth/Infrastructure/Security/Passkey/PasskeyServiceTest.php`
  checks that the relying party is the host of `APP_URL`, that only the configured
  origins on it may register, and that sign-in options list no credential.
- `tests/Integration/SuperAdminRemovalRaceTest.php` holds one super admin's demotion of the
  other open while a second process demotes, disables or deletes the first: that change waits
  on the advisory lock and gets `last_super_admin`.
- `tests/Functional/Auth/LoginAccountThrottleTest.php` limits failed logins per
  account across client addresses. The test environment raises every limit to 10000,
  so each test spends the bucket down to the failures it then makes over HTTP.
- `tests/Integration/LoginFailureReservationRaceTest.php` releases 50 wrong-password
  sign-ins for one account at once from a file barrier, over the disposable Redis, and
  holds the password checks to the limit of 10.
- `tests/Functional/Auth/SignInStorageBoundsTest.php` refuses a sign-in body over 64 KiB
  with `413` before anything decodes it. It also checks that a login block stores at most
  255 characters of valid UTF-8 per field, and that storing one deletes those older than
  30 days.
- `tests/Functional/Auth/AuthEndpointRateLimitTest.php` charges a percent-encoded
  registration path to the registration limit.
  `tests/Unit/Shared/Infrastructure/Security/LimitedRouteInventoryTest.php` holds every
  route a limiter names to its method and path, and fails when a listener declares a
  route the inventory does not list or when a route the auth limits charge, other than
  the pushed authorization request endpoint, has no request body cap.

## Exposure and hardening

- `tests/Functional/Controller/HealthCheckControllerTest.php` checks that `/health`,
  `/ready` and `/live` show each component's status and no detail, and that the full
  report is refused to an anonymous caller. `tests/Integration/Docker/NginxTemplateTest.php`
  and `packages/baander-deployment/tests/PresetDeploymentTest.php` check that the
  production nginx and the preset gateway answer `/metrics` themselves.
- `tests/Functional/Media/Interface/Controller/ImageFileResponseTest.php` serves an
  allowed stored type even when the bytes sniff as markup, and any other stored type as
  an attachment.
- `tests/Unit/Shared/Infrastructure/Security/ProductionSecretsTest.php` refuses empty or
  committed service credentials and committed VAPID keys, reading only tracked
  environment files.
- `tests/Functional/Shared/ApiClientErrorEnvelopeTest.php` checks that framework client
  errors on `/api` paths answer in the error envelope.

## Atomic rate-limit storage

Configured rate limiters run Symfony's installed fixed/sliding-window policies, or
Baander's continuous token bucket for `token_bucket`, against operation-local
snapshots, and commit state through Redis compare-and-set.
The focused regressions must distinguish an absent subject from failed or corrupt
storage, preserve ordinary exhaustion responses, and refuse protected effects when
state cannot be established. All 27 configured names and budgets remain in force.
Container compilation rejects policies other than fixed windows, sliding windows and token buckets.

Storage cases cover policy parity, negative token counts, state identity and TTLs,
conflicting writers, uncertain writes without replay, per-subject reset and named
generation changes. Redis checks snapshot age against its own clock, accepting no
negative age or age over two seconds. Empty reads and resets retain ten-second
revision tombstones so expiry cannot make a paused writer's old snapshot writable
again. Conflict retries stop after eight attempts or a two-second monotonic budget;
an in-flight bounded Redis call can finish after that budget expires.

Corruption cases distinguish recovery from a budget reset. Named clear replaces a
valid old-generation hash on its next read, including extra fields, but wrong-type
subject keys and missing/invalid hash generation fields still fail closed. They need
expiry or targeted repair; the internal per-subject reset replaces the record.
Configuration changes start fresh subject budgets because the effective
configuration contributes to the subject digest. This proves configuration isolation,
not a shared budget across a rolling configuration change.

Concurrency checks need independent PHP processes and connections sharing a
barrier on disposable Redis. Fixed and sliding windows must admit exactly the
remaining budget; namespace, subject and effective-configuration isolation and
fresh-process observations of resets need separate assertions. Refused and paused
Redis cases must fail within a bound instead of treating the outage as an empty
bucket. HTTP checks cover generic API and pre-auth limits, password-reset/resend,
cover extraction, Discovery and configuration validation before their effects.

Unavailable consumption answers a generic `503` with `Retry-After: 1` and
`Cache-Control: no-store`; ordinary exhausted budgets keep their existing outcomes.
Logout and token revocation keep the generic API limiter's narrow availability
exception and all remaining authentication/proof checks. Administration checks
exercise the actual consumed namespace through HTTP and CLI, including unknown
names, unavailable clears and clear-all partial failure. Run these checks through
the disposable backend coordinator with the command below; do not pause or flush the
development Redis. The integration selection also observes resets through fresh
processes; its paused-Redis fault affects only that lane's disposable service.

```bash
bash scripts/test-backend-container.sh \
  tests/Integration/RateLimiterConcurrencyTest.php \
  tests/Integration/RateLimiterHttpAvailabilityTest.php \
  tests/Functional/Console/RateLimiterCommandsTest.php \
  tests/Functional/Controller/RateLimiterMonitorControllerTest.php
```

The unit regressions under `tests/Unit/Shared/Infrastructure/RateLimiter/` cover
failure semantics, installed-policy parity, state decoding and Redis protocol
handling in `RateLimiterFailureSemanticsTest`, `RedisRateLimiterFactoryTest`,
`RateLimiterStateCodecTest` and `RedisRateLimiterStoreTest`. The narrow revocation
exception is covered by
`tests/Unit/Auth/Infrastructure/Security/ApiRateLimitAvailabilityTest.php`.
Current qualification results are recorded in the roadmap.

## Provider destination policy

The provider tests use injected DNS answers and disposable TLS peers; they never
contact metadata, radio-catalog or push providers. Run the focused selection in the
CI image through the existing unit coordinator:

```bash
bash scripts/test-unit-container.sh \
  packages/baander-http-client/tests \
  tests/Unit/Shared/Infrastructure/Http/PublicHttpTransportTest.php \
  tests/Unit/Metadata/Infrastructure/Api \
  tests/Unit/Radio/Infrastructure/Sync/IprdStationSyncAdapterTest.php \
  tests/Unit/Lyrics/Infrastructure/Api/LrclibClientTest.php \
  tests/Unit/Catalog/Application/CommandHandler/MusicBrainzMatch/MatchCoversTest.php \
  tests/Unit/Notification/Infrastructure/Push \
  tests/Unit/Notification/Infrastructure/Webhook/WebhookDestinationPolicyTest.php
```

Policy cases cover HTTPS port 443, exact and suffix host boundaries, every DNS
answer and mixed public/forbidden answers. An IPv6 answer that carries an IPv4
address, in IPv4-mapped, IPv4-translated, IPv4-compatible, NAT64, 6to4 or Teredo
form, is refused when that address is private and accepted when it is public.
Site-local `fec0::/10` and the local-use NAT64 prefix `64:ff9b:1::/48`
are always refused. The webhook policy cases keep their literal IP URLs:
`http://[64:ff9b::a00:5]:8080/` and `http://[::7f00:1]:9200/`, which reach private
addresses through NAT64 and the IPv4-compatible form, are refused, and a
`10.0.0.0/8` LAN allowlist entry admits `64:ff9b::a00:5`.
`tests/Functional/Notification/Interface/Console/WebhookCommandsTest.php` refuses
both URLs through the API and the console.

Body cases check that `PublicHttpClient::content()` stops at the chunk that would
cross its limit, raises `ResponseTooLargeException` and cancels the response, and
that every metadata provider, the IPRD catalog and LRCLIB answer a padded body
over their limit as a failure. A match cover from the Cover Art Archive takes its
type from its bytes: one served as `image/png` whose bytes are HTML is refused and
not stored.
Transport cases check connection pinning, forced peer/name verification,
proxy exclusion, fresh HTTP/1.1 connections, protected options and sanitized errors.
CAA redirect cases cover approved and relative locations, credential removal on
cross-origin hops, changed DNS, downgrade/private/unapproved targets and the
three-redirect bound. Other provider requests refuse redirects.

Metadata adapter cases retain response mapping and failure behavior for all seven
providers and Spotify token acquisition; IPRD cases cover summary/catalog requests.
Push cases run the installed Minishlink/Guzzle path over persisted endpoints,
preserve encrypted bodies and VAPID signing, reject 3xx as failure, retain 404/410
expiry handling and prevent the installed cURL rewind path from replaying a push.
Logs must exclude API-key query strings and endpoint credentials.

`PublicHttpTransportTest` and `PushTransportTlsTest` route approved test names to
local TLS peers only in test transport wrappers, after inspecting production
pinning options. They check trusted success, untrusted certificates, hostname
mismatch and blocked redirect destinations. Production policy has no test-domain
or private-address exception. `MetadataStreamRedirectTest` checks that PHP's URL
stream wrappers are off in the image: a file function opens no `https://`, `http://`
or `ftp://` URL, and the local peer receives no request. Adapter tests establish
the outbound boundary.

DNS and HTTP share one absolute monotonic budget, capped at thirty seconds,
through approved CAA redirect hops. Push shares its thirty-second budget between
DNS and sending, with the connection timeout reduced to the remaining budget when
that is less than five seconds. Adapter tests inspect the deadline and transport
options after DNS, including exhausted budgets that must make no HTTP call.
Existing provider retries can extend the overall operation.

### Bounded native DNS

`NativeDnsResolver` runs the complete native A/AAAA lookup in a fresh PHP helper.
The caller waits through coroutine-aware polling; success still requires the
whole reply and the destination policy still classifies every address. The native
semantics distinguish definitive no-data from a failed family; a failed family
cannot turn the other family's public result into success.

Run helper lifecycle tests through the native lane and real system-resolver checks
through their dedicated disposable container:

```bash
bash scripts/test-unit-container.sh \
  tests/Unit/Shared/Infrastructure/Http/NativeDnsResolverTest.php \
  tests/Unit/Shared/Infrastructure/Http/NativeDnsResolverRuntimeTest.php
BAANDER_TEST_IMAGE=baander-ci-prewarm:local bash scripts/test-dns-runtime-container.sh
```

Both PHPUnit files belong to `scripts/backend-native-tests.json` because they
execute real process and signal lifecycles. They check malformed/oversized output,
nonzero exits, capacity shared across instances, timeout and cancellation cleanup,
repeated descriptor/capacity recovery, inherited process accounting and closed
parent descriptors. The fresh native lane runs as `www-data` with networking
disabled.

The DNS drill uses a privileged container, as the native lane does, with a
180-second outer timeout. Root changes only that disposable container's
`/etc/resolv.conf` and starts a loopback DNS fixture on port 53; probes
and production helpers run as `www-data`. The container has `--network none` and
is removed on exit. No host resolver, production DNS or development database is
used. The fixture covers both-family success, definitive AAAA no-data, family
SERVFAIL, a private answer beyond Swoole's address limit with native TCP fallback,
and stalled DNS with process hooks enabled and disabled. A heartbeat must advance
while lookup waits, no HTTP request may start after budget exhaustion, and cleanup
must leave no owned child. A subreaper fixture kills a lookup's parent and observes
the orphaned helper's finite lifetime.

The implementation admits at most eight owned children per PHP process, including
unreaped children, and accepts at most 64 KiB of output. It polls at five
milliseconds, shortening the sleep near the deadline with a one-millisecond
minimum. Timeout/cancellation kills the helper and allows up to 250 milliseconds
for cleanup; operating-system scheduling adds tolerance, and an unreaped child
keeps its slot. The helper alarm rounds remaining time up to whole seconds and
caps it at thirty seconds. These local checks do not certify live providers,
external trust stores, a hard real-time return bound or an overall retry deadline.
Current qualification results are recorded in the roadmap.
