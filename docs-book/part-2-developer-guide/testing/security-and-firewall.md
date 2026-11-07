# Security, Firewall, and Network Suites

Integration and functional suites covering token rotation and reuse grace, DPoP store outages, signed video delivery, catalog and playlist mutation firewalls, atomic rate-limit storage, and outbound destination policies.

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

## Atomic rate-limit storage

Configured rate limiters execute Symfony's installed fixed/sliding-window policies
against operation-local snapshots and commit state through Redis compare-and-set.
The focused regressions must distinguish an absent subject from failed or corrupt
storage, preserve ordinary exhaustion responses, and refuse protected effects when
state cannot be established. All 19 configured names and budgets remain in force.
Container compilation rejects policies other than fixed and sliding windows.

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
the disposable backend coordinator described below; do not pause or flush the
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
  tests/Unit/Shared/Infrastructure/Http/PublicDestinationPolicyTest.php \
  tests/Unit/Shared/Infrastructure/Http/PublicHttpClientTest.php \
  tests/Unit/Shared/Infrastructure/Http/PublicHttpTransportTest.php \
  tests/Unit/Metadata/Infrastructure/Api \
  tests/Unit/Radio/Infrastructure/Sync/IprdStationSyncAdapterTest.php \
  tests/Unit/Notification/Infrastructure/Push
```

Policy cases cover HTTPS port 443, exact and suffix host boundaries, every DNS
answer, mixed public/forbidden answers, mapped IPv4 and IPv6 translation/transition
ranges. Transport cases check connection pinning, forced peer/name verification,
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
or private-address exception. The historical `MetadataStreamRedirectTest` still
reproduces raw PHP stream behavior; adapter tests establish the new boundary.

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
