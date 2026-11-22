# Auth

Authentication and token issuance. Password login (with optional TOTP), passkey login, and refresh issue DPoP-bound token pairs to Baander's own apps. Other clients get the same DPoP-bound pairs from Auth's OAuth 2.0 authorization server: the authorization code grant on pushed authorization requests (RFC 9126) with mandatory S256 PKCE, the device authorization grant, and the refresh token grant. Confidential clients authenticate with a secret, in the body or in HTTP Basic, or with `private_key_jwt` assertions signed by their own keys. The OAuth endpoints refuse the first-party client, and every authorization request asks the user. Each access token records the sign-in grant that started its chain. Tokens of the first-party client whose chain began with a password or passkey sign-in act with the user's full authority; every other token is delegated: its user counts as `ROLE_USER` alone, and `ScopeEnforcementListener` answers `403` `insufficient_scope` on any route `ScopeRouteMap` does not grant its scopes (`profile`, `email`, `library`, `playlist`). League OAuth2 Server is used only to validate access tokens, behind an anti-corruption layer (`services.yaml` aliases League's access token and refresh token repository interfaces to internal adapters). The `User` aggregate is the sole domain root.

## Concepts

The context is organized into four feature areas within each layer:

- **User** — self-registration (closed until an operator opens it with the `auth.registration_open` setting; while open, answered the same, and no sooner than `auth.registration.response_floor_ms` after the hash, whether or not the address is taken), login (the same answer for unknown, disabled and wrong-password accounts, and a per-account failure limit), profile management, password reset, self-service email changes held as pending until the link is used, verification links redeemed only in a signed-in session of their own account, `UnverifiedAccountListener` confining an unverified account to confirming its address, operator email verification, step-up (`StepUpVerifier`: the current password, plus a TOTP code when TOTP is on) before an email or password change, TOTP setup or disable, or passkey registration or removal, and `SuperAdminContinuity`, which refuses to delete, disable or demote the only enabled super admin. Every change to a sign-in method, a confirmed email change included, runs through `CredentialChangeRevoker`, which ends the user's other sessions and keeps the one that made the change
- **OAuth** — token lifecycle: issue at password or passkey login and through the authorization code and device code grants, rotate at refresh, revoke. Every authorization request is pushed first to `POST /api/oauth/par`, and its code is bound to the DPoP key the push named. Authorization codes and device codes are stored only as SHA-256 hashes. Every token pair comes from `TokenPairIssuer`, and both refresh paths rotate through `RefreshTokenHandler`; `POST /api/auth/refresh` takes only first-party refresh tokens. Refresh tokens are stored as SHA-256 hashes, and a chain ends at `auth.refresh.max_chain_lifetime` after its sign-in however often it rotates. A replayed authorization code revokes the chain its redemption started. Every request that issues tokens needs a DPoP proof with a server-issued nonce, and tokens are bound to the proof key. An optional `X-Baander-Client-Fingerprint` header on the token request also binds the access token to that fingerprint. A consumed refresh token presented again with its own key inside `auth.refresh.reuse_grace_seconds`, while its replacement is unused, gets a fresh pair (the lost-response grace); any other reuse revokes the chain. Logout revokes the chain of the presenting access token and closes its WebSocket connections. While the nonce or jti store in Redis is unavailable, every DPoP edge answers `503` with `Retry-After` (`DpopStoreUnavailableException`) instead of a verdict on the proof. The OAuth area also covers the RFC 8414 metadata document, personal access clients, and the registration, secret rotation, key replacement and revocation of device, public and confidential clients through the admin API and the `app:oauth:client:*` commands.
- **Passkey** — WebAuthn registration and authentication via `web-auth/webauthn-lib`; the relying party ID is the host of `APP_URL`, ceremonies accept only the public origins on it, sign-in is discoverable only, single-use challenges live in Redis only, user verification is required for accounts with TOTP, and failures answer fixed reasons
- **Totp** — time-based one-time password setup and verification via `otphp`; each code's time step is spent once, secrets are sealed under `AUTH_TOTP_ENCRYPTION_KEY`, and operators reset a user's TOTP with `app:user:reset-totp`

The OAuth models AccessToken, AuthCode, Client, DeviceCode, PushedAuthorizationRequest and RefreshToken each have a state object and a repository; TokenMetadata has a repository. The aggregate pattern is `User` only — the rest are OAuth primitives managed through their repositories. Deleting a user deletes their access tokens, and with them the refresh tokens and token metadata, as well as their authorization codes and device codes. Deleting a client deletes its pushed authorization requests.

## Ports

| Port | Purpose |
|------|---------|
| `UserPortInterface` | User CRUD |
| `PasswordHasherInterface` | Password hashing |
| `JwtGeneratorInterface` | JWT token generation |
| `TotpVerifierInterface` | TOTP code verification |
| `PasskeyVerifierInterface` | WebAuthn passkey verification |
| `DpopJtiCacheInterface` | DPoP JTI replay protection (backed by Redis): `isReplay()` records a jti for the proof acceptance window plus one second and throws `DpopStoreUnavailableException` when the store cannot answer |
| `ClientAssertionVerifierInterface` | Verifies a `private_key_jwt` client assertion against the client's registered keys |
| `ClientKeySetProviderInterface` | A `private_key_jwt` client's signing keys, from its inline JWK Set or its fetched `jwks_uri` set; throws `DpopStoreUnavailableException` when the set cannot be fetched now |
| `ClientAssertionJtiStoreInterface` | Single use of client assertion `jti` values per client (backed by Redis); throws `DpopStoreUnavailableException` when the store cannot answer |
| `PasswordResetTokenRepositoryInterface` | Each user's single outstanding password reset token (hashed, single use) |
| `PasswordResetDeliveryInterface` | Emails the reset link after the response is sent; never persists or logs the token |
| `EmailVerificationTokenRepositoryInterface` | Each user's single outstanding email verification token (hashed, bound to the address, single use) |
| `EmailVerificationDeliveryInterface` | Emails the verification link after the response is sent; never persists or logs the token |
| `EmailChangeNoticeInterface` | Tells an account's previous address that its email changed, after the response is sent |
| `EmailVerificationResendThrottleInterface` | Per-user limit on verification resend requests |
| `AuthenticatedUserIdentityInterface` | Identity of the user a login authenticator verified |
| `RegistrationAttemptNoticeInterface` | Tells an account's owner that someone tried to register with its address |
| `SelfRegistrationInterface` | Whether self-registration is open now, read from the `auth.registration_open` setting; the Shared health check depends on it |
| `LoginFailureThrottleInterface` | Per-account limit on failed password logins, from all addresses |
| `StepUpAttemptThrottleInterface` | Per-account limit on step-up attempts |
| `OAuthClientThrottleInterface` | Per-client limit on token and pushed authorization requests, charged after client authentication |
| `TotpSecretCipherInterface` | Seals and opens TOTP secrets under `AUTH_TOTP_ENCRYPTION_KEY` |
| `PasskeyChallengeStoreInterface` | Single-use WebAuthn challenges bound to their ceremony (backed by Redis) |
| `AccessTokenSigningKeyInterface` | The access token signing key as a JWK with its thumbprint as `kid` |
| `OAuthSecretBundleInterface` | Key bundle preparation and validation for secret rotation |
| `OAuthTokenInvalidatorInterface` | Token deletion during offline secret rotation |

## Events

| Event | Category | Consumers |
|-------|----------|-----------|
| `UserRegistered` | Security | Notification |
| `UserCreatedByOperator` | — | none |
| `PasswordChanged` | Security | Notification |
| `TokenRevoked` | Security | Notification |
| `DeviceCodeApproved` | Security | Notification (through the `Auth Device Approval Event Contract` Deptrac layer) |
| `PasskeyRegistered` | Security | Notification |
| `PasskeyDeleted` | Security | Notification |
| `EmailVerified` | — | none |

`DeletePasskeyHandler` records `PasskeyDeleted` in the transaction that removes the passkey. `CreateUserHandler` raises `UserCreatedByOperator`, but Notification maps no category to it, so no notification follows.
