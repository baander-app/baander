# Auth

The Auth context signs users in to Baander's own clients and validates their tokens. Password login (with optional TOTP), passkey login (WebAuthn), and refresh are the only ways to get tokens. Every token pair is bound to the client's DPoP key, and refresh tokens rotate. Auth uses League OAuth2 Server only to validate access tokens, behind an anti-corruption layer; it does not offer authorization code, device, or client credentials flows.

## Domain Models

### Aggregate Roots

| Model | Purpose |
|-------|---------|
| `User` | User account |
| `Client` | OAuth 2.0 client application |
| `AccessToken` | Issued access token |
| `RefreshToken` | Issued refresh token |
| `Passkey` | WebAuthn passkey credential |
| `ThirdPartyCredential` | External provider credential |
| `LoginBlock` | Honeypot login block record |

### Value Objects

| Model | Purpose |
|-------|---------|
| `ChainId` | Refresh rotation chain; replay detection revokes the whole chain |
| `ClientFingerprint` | Client fingerprint a token can be bound to |
| `Scope` | OAuth 2.0 scope |
| `DpopValidationResult` | Result of DPoP header validation |

## Commands & Handlers

Commands and handlers are organized into feature namespaces under `Application/Command/` and `Application/CommandHandler/`: `OAuth/`, `Passkey/`, `Totp/`, and `User/`. Password login is checked by `PasswordAuthenticator` on the API firewall, not by a command.

| Command | Handler | Purpose |
|---------|---------|---------|
| `RegisterUserCommand` | `RegisterUserHandler` | Self-registration (User/) |
| `CreateUserCommand` | `CreateUserHandler` | Operator-created users (User/) |
| `DisableUserCommand` | `DisableUserHandler` | Disable a user account (User/) |
| `EnableUserCommand` | `EnableUserHandler` | Re-enable a disabled account (User/) |
| `RequestPasswordResetCommand` | `RequestPasswordResetHandler` | Issue a password reset token for the account with that email (User/) |
| `ResetPasswordCommand` | `ResetPasswordHandler` | Redeem a reset token and set a new password (User/) |
| `SetUserPasswordCommand` | `SetUserPasswordHandler` | Operator password reset from the admin panel or `app:user:reset-password` (User/) |
| `ChangePasswordCommand` | `ChangePasswordHandler` | A user changes their own password (User/) |
| `IssueTokenCommand` | `IssueTokenHandler` | Issue a DPoP-bound token pair after password or passkey login (OAuth/) |
| `RefreshTokenCommand` | `RefreshTokenHandler` | Rotate a refresh token for a new token pair (OAuth/) |
| `RevokeTokenCommand` | `RevokeTokenHandler` | Token revocation (OAuth/) |
| `RegisterPasskeyCommand` | `RegisterPasskeyHandler` | Add a WebAuthn passkey (Passkey/) |
| `AuthenticatePasskeyCommand` | `AuthenticatePasskeyHandler` | Verify a WebAuthn assertion during passkey login (Passkey/) |
| `VerifyEmailCommand` | `VerifyEmailHandler` | Email address verification (User/) |
| `EnableTotpCommand` | `EnableTotpHandler` | Enable TOTP 2FA (Totp/) |
| `DisableTotpCommand` | `DisableTotpHandler` | Disable TOTP 2FA (Totp/) |

## Ports

| Port | Purpose |
|------|---------|
| `JwtGeneratorInterface` | JWT token generation |
| `PasskeyVerifierInterface` | WebAuthn verification |
| `PasswordHasherInterface` | Password hashing |
| `TotpVerifierInterface` | TOTP code verification |
| `UserPortInterface` | User operations |
| `PasswordResetTokenRepositoryInterface` | Each user's single outstanding reset token, stored as a SHA-256 hash and redeemed once |
| `PasswordResetRequestThrottleInterface` | Per-account limit on password reset requests, keyed by normalized email |
| `DpopJtiCacheInterface` | DPoP replay protection |
| `AuthenticatedUserIdentityInterface` | Identity of the user a login authenticator verified |
| `EmailVerificationTokenRepositoryInterface` | Email verification token storage |
| `OAuthSecretBundleInterface` | Prepare and validate key bundles for `app:auth:rotate-secrets` |
| `OAuthTokenInvalidatorInterface` | Delete all token rows during offline key rotation |

## Password Reset and Password Changes

`password_reset_tokens` holds at most one token per user, keyed by `user_id` with `ON DELETE CASCADE`. Only the SHA-256 hash of the 256-bit token is stored. `UserRepository::save` removes the token whenever the stored email address or password hash changes, so a token never outlives the credentials it was issued for or carries over to a later account with the same address. The lifetime comes from `PASSWORD_RESET_EXPIRE` (minutes).

`RequestPasswordResetHandler` charges the per-account limiter before looking up the address and issues a token only for an existing account; the endpoint answers the same way in every case. The raw token exists only in that handler's memory, and nothing delivers it yet. Email delivery belongs in a port that the handler calls after issuing the token. Do not put the raw token in a domain event, because events are stored in the outbox.

All three password paths (`ResetPasswordHandler`, `SetUserPasswordHandler`, `ChangePasswordHandler`) call the application service `PasswordChanger`. It enforces the 8 to 255 character policy, hashes the password, and then, in one transaction, saves the user, revokes the user's access and refresh tokens and records `PasswordChanged`. A user's own change keeps the session that made it: the presenting access token and its refresh chain. The other two paths revoke everything. A reset token that is unknown, expired, already used, or belongs to a disabled account gets one generic `400`.

## Domain Events

| Event | Trigger |
|-------|---------|
| `UserRegistered` | Self-registration completed |
| `UserCreatedByOperator` | Operator created a user |
| `PasswordChanged` | Password updated through `PasswordChanger` |
| `TokenRevoked` | Token revoked |
| `PasskeyRegistered` | Passkey added |
| `PasskeyDeleted` | Passkey removed |
| `EmailVerified` | Email verification completed |

## API Endpoints

All endpoints except the JWKS document are under `/api`.

| Method | Path | Purpose |
|--------|------|---------|
| POST | `/api/auth/register` | Self-registration |
| POST | `/api/auth/login` | Password login (issues tokens) |
| POST | `/api/auth/login/passkey` | Passkey login (issues tokens) |
| POST | `/api/auth/refresh` | Refresh token rotation (issues tokens) |
| POST | `/api/auth/logout` | Revoke the current access token |
| POST | `/api/auth/password/reset-request` | Issue a password reset token; always answers `200` |
| POST | `/api/auth/password/reset` | Redeem a reset token and set a new password |
| POST | `/api/auth/email/verify` | Verify an email address |
| GET, PUT | `/api/auth/me` | Read or update the current user |
| PUT | `/api/auth/me/email` | Change email |
| PUT | `/api/auth/me/password` | Change password; other sessions are signed out |
| POST | `/api/auth/totp/setup` | Generate a TOTP secret and provisioning URI |
| POST | `/api/auth/totp/enable` | Enable TOTP after verifying a code |
| POST | `/api/auth/totp/disable` | Disable TOTP (requires a current code) |
| GET | `/api/auth/passkey` | List the current user's passkeys |
| POST | `/api/auth/passkey/options` | WebAuthn registration options |
| POST | `/api/auth/passkey/register` | Complete passkey registration |
| POST | `/api/auth/passkey/authenticate/options` | WebAuthn authentication options for passkey login |
| POST | `/api/auth/passkey/authenticate` | Verify an assertion without issuing tokens |
| DELETE | `/api/auth/passkey/{publicId}` | Delete a passkey |
| POST | `/api/oauth/revoke` | Token revocation (RFC 7009) |
| GET | `/.well-known/jwks.json` | JSON Web Key Set for verifying access tokens |

### Admin — User Management

All routes under `/api/admin/users` (controller `AdminUserController`, gated `ROLE_ADMIN`; mutating routes require `ROLE_SUPER_ADMIN`).

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/api/admin/users` | List users (paginated, filter by role/disabled) |
| POST | `/api/admin/users` | Create a user |
| PATCH | `/api/admin/users/{id}` | Update a user |
| DELETE | `/api/admin/users/{id}` | Delete a user |
| POST | `/api/admin/users/{id}/roles` | Assign roles |
| POST | `/api/admin/users/{id}/reset-password` | Reset a user's password and sign them out (CLI: `app:user:reset-password`) |
| POST | `/api/admin/users/{id}/disable` | Disable a user |
| POST | `/api/admin/users/{id}/enable` | Enable a user |

Deleting a user deletes their access tokens (`fk_oauth_access_tokens_user_id` is `ON DELETE CASCADE`), and the existing cascades from `oauth_access_tokens` then delete the matching refresh tokens and token metadata.

### Admin — Login Blocks

All routes under `/api/admin/login-blocks` (controller `AdminLoginBlockController`, gated `ROLE_ADMIN`; deletes require `ROLE_SUPER_ADMIN`).

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/api/admin/login-blocks` | List recent honeypot blocks (paginated) |
| DELETE | `/api/admin/login-blocks/{id}` | Delete a single block |
| DELETE | `/api/admin/login-blocks` | Delete all blocks |

## Token Issuance

Only three endpoints issue tokens: `POST /api/auth/login`, `POST /api/auth/login/passkey`, and `POST /api/auth/refresh`. Login dispatches `IssueTokenCommand` for the SPA client named by `auth.spa_client_id`; refresh dispatches `RefreshTokenCommand`. `IssueTokenHandler` handles only this first-party issuance. The response wraps `accessToken`, `tokenType` (`DPoP`), `expiresIn`, and `refreshToken` in `data`; login responses also include `user`.

### DPoP

All three endpoints require a `DPoP` header carrying a proof (RFC 9449) with a server-issued nonce. A proof without a valid nonce gets `400` with `{"error": "use_dpop_nonce"}` and a `DPoP-Nonce` header; the client retries with a new proof that carries that nonce. Each nonce works once. A successful response returns a fresh `DPoP-Nonce` for the client's next proof. A missing `DPoP` header returns a `400` `ApiError`.

Tokens are bound to the proof key: the access token JWT carries `cnf.jkt`, and the thumbprint is stored with the token (`dpop_jkt`). Refresh requires a proof signed by the same key, and the new pair keeps the binding. `DpopBindingListener` requires a matching proof on every API request made with the token, except signed stream delivery URLs.

### Passkey Login

1. `POST /api/auth/passkey/authenticate/options` returns `{challengeKey, options}`. Pass `userId` to restrict the allowed credentials.
2. The client runs `navigator.credentials.get()` with `options`.
3. `POST /api/auth/login/passkey` with `{challengeKey, response, userId?}` and a `DPoP` header.

`PasskeyAuthenticator` runs on the API firewall. It checks the DPoP proof with `DpopTokenRequestVerifier` before the WebAuthn assertion, so a nonce challenge leaves the assertion valid for a retry. Disabled accounts are rejected. On success it stores `VerifiedPasskeyLogin` (user, proof key thumbprint, next nonce) on the request, and `PasskeyLoginController` issues tokens only from it.

### Client Fingerprint Binding

Password and passkey login accept an optional `X-Baander-Client-Fingerprint` header. When present, it is stored in `oauth_token_metadata` and binds the access token: `OAuth2Authenticator` rejects any request with that token whose header is missing or different (`401`, `AUTH_INVALID_TOKEN`). If the metadata lookup fails, the request is rejected. Refresh copies the binding to the new token. Tokens issued without the header are unbound and ignore it. No first-party client sends the header today, and the WebSocket query-token authenticator does not check it.

## Cross-Context Relationships

| Direction | Context | Details |
|-----------|---------|---------|
| Depends on | Shared | `Uuid`, `PublicId`, `Email` |
| Depended on by | All contexts | Every authenticated endpoint depends on Auth |

## Infrastructure

| Component | Type | Purpose |
|-----------|------|---------|
| League OAuth adapters | Anti-corruption layer | League access token and refresh token repository interfaces aliased to internal adapters in `services.yaml` |
| `ResourceServerFactory` | Security | Builds League's `ResourceServer` with `DpopAwareBearerTokenValidator` for access token validation |
| `CachedAccessTokenRepository` | Cache decorator | Caches access token lookups |
| Doctrine entities | ORM | Persistence for all models |
| Doctrine repositories | ORM | Repository implementations for all aggregates |
| Voter classes | Security | Authorization checks for protected resources |

See the [Architecture](../architecture.md#anti-corruption-layer) page for details on the League anti-corruption layer.
