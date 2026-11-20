# Security findings: authentication and authorization

Review date: 2026-11-20. Scope: this repository's working tree on
`refactor/tooling-context-packages` (including the uncommitted tooling-packages work), read in
full by eight parallel reviewers and spot-checked by the lead. No request was sent to any host
outside this machine.

**Status of this document: phase 1 only, cut short by a usage limit.** Every finding below is
traced through the code. Reproduction tests are written for findings 1 to 5, 7 and 8 in a
snapshot worktree (`/home/martin/dev/baander-secfind`, detached at stash commit `d9871ca0f`); their
run had been started but its result had not been read when this file was written. Until that
result is read, treat every finding as "confirmed by reading, not yet by a failing test". Phase 2
(fixes) has not started; nothing in the repository was changed except this file.

No critical finding. No way was found to forge a token, skip DPoP, reach admin routes as a normal
user or with a delegated token, or read another user's data by changing an ID.

## How auth works (short map)

- One stateless firewall on `^/api/` with three authenticators (password login, passkey login,
  OAuth2 bearer). Everything outside `/api/` has `security: false`. No cookies or sessions are
  used, so there is no CSRF surface.
- Access tokens are RS256 JWTs bound to a DPoP key; DPoP is mandatory. Refresh tokens are stored
  hashed, rotate, and are bound to client and key. A per-user credential epoch fences tokens on
  password change and reset.
- Tokens of the first-party client (`auth.spa_client_id`) act with the user's full roles. Tokens
  of any other client are delegated: `ROLE_USER` only and limited by `ScopeRouteMap`, which
  denies unmapped routes.
- `access_control`: `/api/admin`, `/api/debug`, `/api/monitor` need `ROLE_ADMIN`; all of
  `/api/auth/` is `PUBLIC_ACCESS`, so those handlers check the user themselves (all do);
  stream and transcode delivery paths are public and authorized by an HMAC signature over path,
  query and expiry; everything else needs a full login. Non-admin mutations in catalog, library,
  metadata and radio carry their own `ROLE_ADMIN` checks. Reads are filtered by per-user library
  grants (`user_library_access`).
- WebSocket upgrade uses a 30-second single-use ticket bound to user, token, origin and path.
- Route counts: 371 route/method pairs; 102 admin, 33 public under `/api/`, 25 outside the
  firewall (17 of those are dev-only profiler routes), the rest login-required. The full
  per-route tables are in the reviewer reports, not reproduced here.

## Findings, by severity

### 1. First-party client usable through the public PAR and code grant, with silent consent

- **Severity: medium** (high when the instance origin is `http://localhost` or plain http). It
  yields a full first-party session without password, TOTP or passkey, but the attacker must read
  one URL from the victim's browser within 60 seconds.
- **Files:** `src/Auth/Application/Service/AuthorizationRequestValidator.php:62-80` (push refuses
  only device clients); `src/Auth/Application/QueryHandler/OAuth/GetAuthorizationRequestHandler.php:36`
  (`consentRequired: !isFirstParty()`); `ui/web/src/features/auth/components/OAuthConsent.tsx:139-148`
  (approves without asking); `src/Auth/Application/CommandHandler/OAuth/ExchangeAuthorizationCodeHandler.php:46-85`;
  `src/Auth/Infrastructure/Security/OAuth/OAuth2Authenticator.php:150-153,218-225` (first-party
  decided from the client ID alone); `src/Auth/Interface/Console/SetupClientsCommand.php` (public
  client, redirect = origin, http accepted).
- **Precondition:** anonymous attacker; a signed-in victim opens one link; the attacker can
  observe the landing URL at the instance origin (extension, history sync, shared screen, proxy
  or access log, or a local process when the origin is loopback, where any port is accepted).
- **Reproduce:**
  1. `POST /api/oauth/par` with `client_id=<spa client id>`, `response_type=code`,
     `redirect_uri=<origin>`, `scope=library`, an S256 challenge and `dpop_jkt` of the attacker's key. Answer 201.
  2. Victim opens `<APP_URL>/oauth/authorize?client_id=…&request_uri=…`. No prompt; browser lands
     on `<origin>?code=…`.
  3. `POST /api/oauth/token` with `grant_type=authorization_code`, the code, the verifier and a
     proof from the attacker's key. The pair is first-party: full roles, all routes, refreshable.
- **Impact:** account takeover of the victim, admin roles included.
- **Fix:** refuse the first-party client in `validatePush` and in the code exchange
  (`unauthorized_client`); derive first-party authority from how the token was issued (password
  or passkey login), not from the client ID; make the consent page's auto-approval impossible
  for a request pushed over the public endpoint. Personal access clients share the silent
  approval with lower impact (scoped tokens, loopback redirect) and should be reviewed with it.
- **Repro tests:** `tests/Integration/OAuthGrantPathAcceptanceTest.php`
  (`testAnAnonymousPushAsTheFirstPartyClientNeverYieldsAFullAuthoritySession`) and
  `tests/Functional/Auth/SecurityReviewReproTest.php` in the snapshot worktree.

### 2. Delegated token without the `library` scope can fetch original tracks and videos

- **Severity: medium.** Scope bypass; limited by needing media IDs and by the user's own library
  access still applying.
- **Files:** `src/Auth/Infrastructure/Security/OAuth/ScopeRouteMap.php:30-38` (`stream_original_video`
  and `stream_original_track` in `PUBLIC_ROUTES`) and `:227-235`;
  `src/Media/Interface/Controller/OriginalMediaController.php:142-154` (falls back to the
  authenticated user when no signature is present).
- **Precondition:** holder of any delegated token (for example `profile` only) and its DPoP key.
- **Reproduce:** `GET /api/stream/original/track?id=<track public id>` with `Authorization: DPoP <token>`
  and a proof. Same for `/api/stream/video`.
- **Impact:** a client approved for profile data downloads the user's media.
- **Fix:** move both routes to the `library` scope. Signed anonymous fetches are unaffected,
  because the scope check runs only for authenticated requests. No route with an
  "authenticated user" fallback belongs in `PUBLIC_ROUTES`.

### 3. Preset deployment puts every client in one rate-limit bucket

- **Severity: medium.** Anonymous lockout of login, registration, reset and OAuth endpoints for
  the whole install; no data exposure.
- **Files:** `packages/baander-deployment/src/Presets/Renderer.php:22-34,169-213` (nginx gateway
  sets `X-Forwarded-For`; environment never sets `TRUSTED_PROXIES`); `config/packages/framework.yaml:3,41`
  and `config/packages/swoole.yaml:13` (default empty); consumers
  `src/Auth/Infrastructure/Security/RateLimitListener.php` and `ApiRateLimitListener.php`.
- **Precondition:** anonymous, against a deployment rendered from the preset.
- **Reproduce:** 20 `POST /api/auth/login` requests inside 5 minutes; every other client then gets
  429 on login. About 6 requests per second to any `/api/` path exhausts the anonymous API budget.
- **Fix:** give the gateway a fixed address or subnet and render `TRUSTED_PROXIES` to it; add a
  preset test asserting that web trusts exactly the gateway.
- **Not yet shown at runtime:** `getClientIp()` behind the rendered gateway.

### 4. Percent-encoded paths bypass the auth and anonymous API rate limits

- **Severity: medium.** Unlimited registration (Argon2id cost, verification mail to arbitrary
  addresses) and unthrottled reset, verify and OAuth endpoints. Login is not affected.
- **Files:** `src/Auth/Infrastructure/Security/RateLimitListener.php:181-186` and
  `ApiRateLimitListener.php:142` match the raw `getPathInfo()`; the router and firewall decode first.
- **Precondition:** anonymous.
- **Reproduce:** `POST /%61pi/auth/registe%72` with a normal register body.
- **Fix:** key both listeners on the matched `_route` (routing runs before them).
- **Open:** one runtime request to confirm the Swoole bridge passes the raw path through.

### 5. TOTP can be turned off from a session without the password, with no attempt limit

- **Severity: medium.** Removes the second factor; needs an already-stolen first-party session.
- **Files:** `src/Auth/Application/CommandHandler/Totp/DisableTotpHandler.php:27-45` (code only, no
  `StepUpVerifier`); no rule for `/api/auth/totp/*` in `RateLimitListener.php`.
- **Reproduce:** loop `POST /api/auth/totp/disable {"code":"NNNNNN"}`; three codes are valid at a
  time and the only budget is the general per-user API limit.
- **Fix:** route disable through `StepUpVerifier` (password plus code, 10 attempts per 15 minutes).
  This adds a required `currentPassword` field: a breaking API change for the web client.

### 6. Password change is an unthrottled password check and skips TOTP

- **Severity: medium to low.** Same precondition as 5; with 5 it gives permanent takeover (email
  change and passkey registration then pass step-up).
- **Files:** `src/Auth/Application/CommandHandler/User/ChangePasswordHandler.php:33-39`.
- **Reproduce:** loop `PUT /api/auth/me/password` with guesses; 422 means wrong.
- **Fix:** use `StepUpVerifier` there too, with `totpCode` in the request when TOTP is on.

### 7. Party host can pause and seek another user's transcode session

- **Severity: low to medium.** Cross-user disruption, no data exposure.
- **Files:** `src/Party/Infrastructure/PartyMediaAccess.php:24-44` (checks the job's video, not its
  owner); `src/Transcode/Infrastructure/Swoole/PlaybackPositionChangedListener.php:19-28` (no owner
  check); job IDs are listable by anyone with access to the video
  (`src/Transcode/Interface/Controller/TranscodeJobController.php`).
- **Reproduce:** `POST /api/party/sessions/` with the victim's `transcodeJobId`, then send
  `party.playback` pause or seek frames over the websocket.
- **Fix:** require that the job's session belongs to the host, in `assertHostCanPlay` or the listener.
- **Open:** end-to-end effect on the encoder was not run.

### 8. Pre-registered account keeps the registrant's TOTP and passkeys after the owner's reset

- **Severity: low.** Needs the victim to open a verification link for an account they did not create.
- **Files:** `src/Auth/Application/CommandHandler/User/VerifyEmailHandler.php:60-112` (anonymous
  verify keeps the registrant's password and sessions);
  `src/Auth/Application/CommandHandler/User/ResetPasswordHandler.php:64` (authenticators are
  removed only when the address is unverified).
- **Impact:** after the victim's password reset, the attacker's TOTP locks the victim out and the
  attacker's passkey still signs in.
- **Fix:** design decision needed: require a signed-in session to redeem a current-address
  verification, or drop authenticators enrolled before reset-proven ownership.

### Low severity, confirmed by reading only

- **Open self-registration with full `ROLE_USER` before email verification**
  (`AuthController.php:119`, `PasswordAuthenticator.php:213-235`); no registration switch exists.
  New users have no library grants, so the exposure is the non-library API surface. Product decision.
- **WebAuthn origin check accepts any subdomain and port of the RP ID**
  (`src/Auth/Infrastructure/Security/Passkey/PasskeyService.php:113-116`, no `setAllowedOrigins`).
- **Sessions survive TOTP enable/disable, passkey add/delete and own email change**; passkey
  delete has no step-up (`PasskeyController.php:435`). Role change and user deletion do not close
  open websockets (`SetUserRolesHandler.php`, `DeleteUserHandler.php`); whether a demoted admin's
  socket keeps admin event families was not run.
- **Live transcode HLS/DASH signed URLs work for up to 24 hours after access is revoked**
  (`StreamManifestController.php`, `StreamSegmentController.php`); documented as accepted in the
  operator guide, inconsistent with the original and artifact routes.
- **Committed VAPID key pair inherited by the production image** (`.env:49-50`, `Dockerfile:588`;
  not covered by `ProductionSecrets.php`). Redis and database passwords in `.env` are likewise
  outside that guard. `docker/dev/juul.localdomain.key` is a tracked dev TLS key. No OAuth keys
  are committed.
- **`app:dev:create-users` creates a super admin with a fixed password and has no environment
  guard** (`src/Auth/Interface/Console/CreateUsersCommand.php:24-43`); needs shell access.
- **No guard against deleting, demoting or disabling the last super admin**
  (`AdminUserController.php:199-234,314-325`).
- **Device codes and authorization codes are stored unhashed** (`DeviceCodeRepository.php:60`,
  `AuthCodeRepository.php:50`); a database reader can redeem an approved device code.
- **Firewall regexes for transcode delivery are wider than the routes** (`security.yaml:51-53`);
  not exploitable today, worst case an unauthenticated 500.
- **Activity history shows titles and covers of items in libraries the user lost access to**
  (`src/Activity/Infrastructure/ActivityEnrichmentService.php:57-101`).
- **Absolute server paths returned to non-admin members** (`LibraryResource.php:37`,
  `SongResource.php:62`, `VideoResource.php:31`).
- **Other:** `/metrics` and `/health` expose worker and pool counts anonymously; SPA shell derives
  the API origin from the Host header (CSP limits it); `/api/images/{id}/file` sniffs its
  Content-Type; discovery QR GET writes rows without a limit; Discogs and Last.fm searches are
  unthrottled with unbounded `limit`; passkey endpoints return exception text and list a user's
  credential IDs to anonymous callers who know the public ID; registration timing may reveal
  existing addresses (not measured).

## Checked and found sound

JWT verification (RS256 only, issuer and audience pinned, unknown `jti` refused); mandatory DPoP
with fail-closed replay storage; default-deny scopes; roles read from the database per request;
refresh rotation and reuse detection; reset and verification tokens (256-bit, hashed, single
use, expiring, bound to the address); login enumeration and 2FA sequencing; redirect URI
validation and PKCE; client authentication and JWKS fetching (no SSRF); object-level checks in
playlists, favorites, activity, preferences, radio, recommendations, notifications, push, party
membership, sessions and devices; admin escalation paths; SQL construction; process execution;
websocket tickets, origin checks and rooms; CORS; production error output; log redaction; the
test-only authenticator (wired in the test environment only).

## Not reviewed

- `ui/rn` and Essentia (out of scope by project rule); `ui/electron` and `ui/site` beyond a grep.
- Most of `packages/swoole-bundle` beyond handshake, static serving, proxy trust and API routing.
- The relay (C++ registry) beyond a skim.
- Admin-only controller internals (transport, job monitor, span debug, config check), cover
  upload validation, the library scan pipeline's symlink handling, webhook delivery DNS rebinding.
- Runtime confirmation of anything: no finding has a read test result yet (see the status note).
