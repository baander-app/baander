# Auth Implementation Inventory

Use this reference to locate Auth models, handlers, ports and entry points. The [Auth overview](auth.md) links the explanations of how they work.

## Domain Models

### Aggregate Roots

`User` (`UserState`) is the domain aggregate root. `Client`, `AccessToken`, `RefreshToken`, `AuthCode`, `PushedAuthorizationRequest`, `DeviceCode`, `Passkey`, `ThirdPartyCredential`, and `LoginBlock` are auth and OAuth domain models (each with a `*State` object) managed through their repositories.

| Model | Purpose |
|-------|---------|
| `User` | User account (`UserState`; aggregate root) |
| `Client` | OAuth 2.0 client application, with its authentication method and, for `private_key_jwt`, its key source |
| `AccessToken` | Issued access token |
| `RefreshToken` | Issued refresh token |
| `AuthCode` | Single-use authorization code, bound to its redirect URI, S256 code challenge and DPoP key thumbprint |
| `PushedAuthorizationRequest` | Authorization request a client pushed (RFC 9126), with its parameters and DPoP key thumbprint; expires after `auth.par.request_uri_ttl` and is used up by one decision |
| `DeviceCode` | Device authorization request with its device code, user code, polling interval, and approval state |
| `Passkey` | WebAuthn passkey credential |
| `ThirdPartyCredential` | External provider credential |
| `LoginBlock` | Honeypot login block record |

### Value Objects

| Model | Purpose |
|-------|---------|
| `ChainId` | Refresh rotation chain; replay detection revokes the whole chain |
| `ClientFingerprint` | Client fingerprint a token can be bound to |
| `Scope` | OAuth 2.0 scope |
| `ClientSecret` | A confidential client's plain-text secret, held only between generation and the one response that shows it; stored as its SHA-256 digest |
| `ClientType` | Enum of client kinds: `first_party`, `personal_access`, `device`, `public`, `confidential`; `isAdministered()` is true for the last three |
| `ClientAuthMethod` | Enum of client authentication methods, named after the RFC 7591 values: `none`, `client_secret_post` (which also covers the secret sent in HTTP Basic, `client_secret_basic`), `private_key_jwt` |
| `ClientKeySource` | A `private_key_jwt` client's inline JWK Set or `https` `jwks_uri`, never both |
| `DpopValidationResult` | Result of DPoP header validation |
| `TokenId` | URL-safe random token identifier for access tokens, refresh tokens, authorization codes, and pushed request URIs |
| `TokenMetadata` | Issuance environment and IP history (`oauth_token_metadata`) for an access token |

### Domain Services

| Service | Purpose |
|---------|---------|
| `TokenChainValidator` | Checks refresh token rotation integrity against the predecessor token (`TokenChainViolationException`) |

## Commands & Handlers

Commands and handlers are organized into feature namespaces under `Application/Command/` and `Application/CommandHandler/`: `LoginBlock/`, `OAuth/`, `Passkey/`, `Totp/`, and `User/`. Password login is checked by `PasswordAuthenticator` on the API firewall, not by a command. Eight application services under `Application/Service/` are shared by the OAuth handlers: `TokenPairIssuer` mints every new token pair, `TokenChainRevoker` revokes a token chain and closes its WebSocket connections after the commit, `OAuthClientAuthenticator` identifies and authenticates the client of an OAuth request, `PendingDeviceCodeFinder` resolves a user code as the user typed it, `AuthorizationRequestValidator` checks a pushed authorization request when it is pushed and again for the signed-in user, `ClientRevoker` revokes a client together with its tokens, `ChainConnectionCloser` closes the WebSocket connections of a revoked chain, and `IssuanceLockTimeout` bounds the lock waits of a token-issuing transaction. The account handlers share four more: `PasswordChanger` sets a password, `CredentialChangeRevoker` ends the other sessions of a change to a user's sign-in methods ([Credential Changes](auth-account-security.md#credential-changes)), `StepUpVerifier` checks the current password and code, and `SuperAdminContinuity` refuses to delete, disable or demote the only enabled super admin under a transaction-scoped advisory lock.

| Command | Handler | Purpose |
|---------|---------|---------|
| `RegisterUserCommand` | `RegisterUserHandler` | Self-registration while `auth.registration_open` is on; seeds the email language from the browser (User/) |
| `CreateUserCommand` | `CreateUserHandler` | Operator-created users (User/) |
| `RenameUserCommand` | `RenameUserHandler` | Update a user's display name from the admin API or `app:user:rename` (User/) |
| `DeleteUserCommand` | `DeleteUserHandler` | Delete a user account from the admin API or `app:user:delete`, except the only enabled super admin, and close the user's WebSocket connections after the commit (User/) |
| `SetUserRolesCommand` | `SetUserRolesHandler` | Replace a user's roles from the admin API or `app:user:roles`, except a demotion of the only enabled super admin (User/) |
| `DisableUserCommand` | `DisableUserHandler` | Disable a user account, except the only enabled super admin (User/) |
| `EnableUserCommand` | `EnableUserHandler` | Re-enable a disabled account (User/) |
| `RequestPasswordResetCommand` | `RequestPasswordResetHandler` | Issue a password reset token for the account with that email (User/) |
| `ResetPasswordCommand` | `ResetPasswordHandler` | Redeem a reset token and set a new password (User/) |
| `SetUserPasswordCommand` | `SetUserPasswordHandler` | Operator password reset from the admin panel or `app:user:reset-password` (User/) |
| `ChangePasswordCommand` | `ChangePasswordHandler` | A user changes their own password after step-up; every other session ends (User/) |
| `IssueTokenCommand` | `IssueTokenHandler` | Issue a DPoP-bound token pair after password or passkey login (OAuth/) |
| `RefreshTokenCommand` | `RefreshTokenHandler` | Rotate a refresh token for a new token pair (OAuth/) |
| `RevokeTokenCommand` | `RevokeTokenHandler` | Token revocation: a value that is a JWT signed with Baander's key is found as an access token by its `jti`; any other value as a refresh token, then as an access token id (OAuth/) |
| `PushAuthorizationRequestCommand` | `PushAuthorizationRequestHandler` | Authenticate the client, check and store a pushed authorization request with its key binding, and answer its request URI (OAuth/) |
| `CreateAuthorizationCodeCommand` | `CreateAuthorizationCodeHandler` | Record the signed-in user's decision on a pushed request, using it up: a key-bound code on approval, `access_denied` on denial (OAuth/) |
| `ExchangeAuthorizationCodeCommand` | `ExchangeAuthorizationCodeHandler` | Authorization code grant at the token endpoint (OAuth/) |
| `RequestDeviceAuthorizationCommand` | `RequestDeviceAuthorizationHandler` | Start the device flow for a device client (OAuth/) |
| `ExchangeDeviceCodeCommand` | `ExchangeDeviceCodeHandler` | Device code grant: answer a poll or issue the token pair (OAuth/) |
| `ApproveDeviceCodeCommand` | `ApproveDeviceCodeHandler` | The signed-in user approves a device request (OAuth/) |
| `DenyDeviceCodeCommand` | `DenyDeviceCodeHandler` | The signed-in user denies a device request (OAuth/) |
| `ExchangeRefreshTokenCommand` | `ExchangeRefreshTokenHandler` | Refresh token grant at the token endpoint, through `RefreshTokenHandler` (OAuth/) |
| `CreatePersonalAccessClientCommand` | `CreatePersonalAccessClientHandler` | Create a personal access client for the current user (OAuth/) |
| `RevokeClientCommand` | `RevokeClientHandler` | Revoke the user's personal access client and every token issued to it (OAuth/) |
| `RegisterClientCommand` | `RegisterClientHandler` | Register a device, public or confidential client, a confidential one with a secret or with keys, for the admin API and `app:oauth:client:create` (OAuth/) |
| `RotateClientSecretCommand` | `RotateClientSecretHandler` | Give a `client_secret_post` client a new secret for the admin API and `app:oauth:client:rotate-secret` (OAuth/) |
| `ReplaceClientKeysCommand` | `ReplaceClientKeysHandler` | Replace a `private_key_jwt` client's inline JWK Set or `jwks_uri` for the admin API and `app:oauth:client:replace-keys` (OAuth/) |
| `RevokeRegisteredClientCommand` | `RevokeRegisteredClientHandler` | Revoke a device, public or confidential client and its tokens for the admin API and `app:oauth:client:revoke` (OAuth/) |
| `PurgeExpiredOAuthCodesCommand` | `PurgeExpiredOAuthCodesHandler` | Delete authorization codes, device codes and pushed authorization requests expired more than one hour ago (`app:oauth:purge-codes` and daily schedule; OAuth/) |
| `RegisterPasskeyCommand` | `RegisterPasskeyHandler` | Add a WebAuthn passkey; every other session ends (Passkey/) |
| `DeletePasskeyCommand` | `DeletePasskeyHandler` | Remove one of the user's passkeys after step-up and record `PasskeyDeleted`; every other session ends (Passkey/) |
| `AuthenticatePasskeyCommand` | `AuthenticatePasskeyHandler` | Verify a WebAuthn assertion during passkey login and for the passkey check endpoint (Passkey/) |
| `VerifyEmailCommand` | `VerifyEmailHandler` | Redeem a verification token for the signed-in account it was sent for and mark the address verified; confirming a pending email change ends every other session (User/) |
| `ResendEmailVerificationCommand` | `ResendEmailVerificationHandler` | Send a signed-in user a new verification link (User/) |
| `ChangeOwnEmailCommand` | `ChangeOwnEmailHandler` | A user asks to change their own email: step-up, then the new address is held as pending and sent a link (User/) |
| `ChangeEmailCommand` | `ChangeEmailHandler` | An operator change from the admin panel or `app:user:change-email`, verified at once (User/) |
| `MarkEmailVerifiedCommand` | `MarkEmailVerifiedHandler` | Mark a user's current address verified on an operator's word, from the admin API or `app:user:mark-email-verified` (User/) |
| `ResetUserTotpCommand` | `ResetUserTotpHandler` | Drop a user's TOTP secret without reading it and revoke all of their tokens, from the admin API or `app:user:reset-totp` (User/) |
| `StartTotpSetupCommand` | `StartTotpSetupHandler` | Generate a pending TOTP secret after step-up, held for 10 minutes until enable confirms it (Totp/) |
| `EnableTotpCommand` | `EnableTotpHandler` | Enable TOTP 2FA; every other session ends (Totp/) |
| `DisableTotpCommand` | `DisableTotpHandler` | Disable TOTP 2FA after step-up; every other session ends (Totp/) |
| `DeleteLoginBlockCommand` | `DeleteLoginBlockHandler` | Delete one honeypot login block by `PublicId` (LoginBlock/) |
| `DeleteAllLoginBlocksCommand` | `DeleteAllLoginBlocksHandler` | Delete all honeypot login blocks (LoginBlock/) |

Six queries serve the OAuth and admin endpoints. `GetAuthorizationRequestQuery` (`GetAuthorizationRequestHandler`) looks a pushed authorization request up for the signed-in user and describes it for the consent page, without using it up. `GetDeviceAuthorizationQuery` (`GetDeviceAuthorizationHandler`) shows the user which client a user code belongs to. `ListPersonalAccessClientsQuery` (`ListPersonalAccessClientsHandler`) lists the current user's personal access clients, and `ListRegisteredClientsQuery` (`ListRegisteredClientsHandler`) lists every client except personal access clients for the admin API and `app:oauth:client:list`. `ListUsersQuery` (`ListUsersHandler`) lists users for `GET /api/admin/users` and `app:user:list`, and `ListLoginBlocksQuery` (`ListLoginBlocksHandler`) lists honeypot login blocks for `GET /api/admin/login-blocks` and `app:login-block:list`.

## Ports

| Port | Purpose |
|------|---------|
| `JwtGeneratorInterface` | JWT token generation |
| `PasskeyVerifierInterface` | WebAuthn verification |
| `PasswordHasherInterface` | Password hashing |
| `TotpVerifierInterface` | TOTP code verification |
| `UserPortInterface` | User operations |
| `UserIdentifierResolverInterface` | Resolve a user's `Uuid` or `PublicId` from an email address or public ID (`UserLookup`) |
| `CurrentUserAuthorityReader` | Read current user authority (`CurrentUserAuthority`) for live-update authorization (`DoctrineCurrentUserAuthorityReader`) |
| `PasswordResetTokenRepositoryInterface` | Each user's single outstanding reset token, stored as a SHA-256 hash and redeemed once |
| `PasswordResetRequestThrottleInterface` | Per-account limit on password reset requests, keyed by normalized email |
| `PasswordResetDeliveryInterface` | Emails the reset link after the response is sent; never persists or logs the token |
| `DpopJtiCacheInterface` | DPoP replay protection: `isReplay()` records a proof's `jti` for the acceptance window and throws `DpopStoreUnavailableException` when the store cannot answer |
| `ClientAssertionVerifierInterface` | Verify a `private_key_jwt` client assertion against the client's registered keys (`ClientAssertionVerifier`) |
| `ClientKeySetProviderInterface` | Serve a `private_key_jwt` client's signing keys from its inline JWK Set or its fetched `jwks_uri` set (`ClientKeySetProvider`) |
| `ClientAssertionJtiStoreInterface` | Single use of client assertion `jti` values, per client and apart from DPoP proofs; throws `DpopStoreUnavailableException` when the store cannot answer (`RedisClientAssertionJtiStore`) |
| `AuthenticatedUserIdentityInterface` | Identity of the user a login authenticator verified |
| `EmailVerificationTokenRepositoryInterface` | Each user's single outstanding verification token, stored as a SHA-256 hash with the address it verifies and redeemed once |
| `EmailVerificationDeliveryInterface` | Emails the verification link after the response is sent; never persists or logs the token |
| `EmailChangeNoticeInterface` | Tells an account's previous address that its email changed, after the response is sent |
| `EmailVerificationResendThrottleInterface` | Per-user limit on verification resend requests |
| `RegistrationAttemptNoticeInterface` | Tells an account's owner, after the response is sent, that someone tried to register with its address |
| `SelfRegistrationInterface` | Whether self-registration is open now, read from the `auth.registration_open` system setting (`SelfRegistration`); the Shared health check reads it through this narrow contract |
| `LoginFailureThrottleInterface` | Per-account limit on failed password logins from all addresses, keyed by normalized email (`RateLimiterLoginFailureThrottle`) |
| `StepUpAttemptThrottleInterface` | Per-account limit on step-up attempts (`RateLimiterStepUpAttemptThrottle`) |
| `OAuthClientThrottleInterface` | Per-client limit on token and pushed authorization requests, charged after the client authenticates (`RateLimiterOAuthClientThrottle`) |
| `TotpSecretCipherInterface` | Seal and open TOTP secrets under `AUTH_TOTP_ENCRYPTION_KEY` (`SodiumTotpSecretCipher`); opening throws `TotpSecretUnavailableException` when the key does not fit |
| `PasskeyChallengeStoreInterface` | Single-use WebAuthn ceremony options bound to their ceremony, shared by every worker (`RedisPasskeyChallengeStore`) |
| `AccessTokenSigningKeyInterface` | The access token signing key as a JWK with its RFC 7638 thumbprint as `kid`, for the JWKS document and revocation by `jti` (`AccessTokenSigningKey`) |
| `OAuthSecretBundleInterface` | Prepare and validate key bundles for `app:auth:rotate-secrets` |
| `OAuthTokenInvalidatorInterface` | Delete all token rows during offline key rotation |
| `WebSocketTicketIssuerInterface` | Issue single-use 30-second WebSocket handshake tickets (`WebSocketTickets`) |
| `WebSocketTicketRedeemerInterface` | Redeem a single-use WebSocket handshake ticket (`WebSocketTickets`) |
| `WebSocketTicketStoreInterface` | Store and atomically consume WebSocket handshake tickets in Redis (`RedisWebSocketTicketStore`) |
| `WebSocketTokenStatusInterface` | Check whether the access token backing a WebSocket ticket is still live (`DoctrineWebSocketTokenStatus`) |

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
| `DeviceCodeApproved` | The user approved a device request; Notification maps it to the Security category (`device_code.approved`) |

`DeletePasskeyHandler` records `PasskeyDeleted` in the transaction that removes the passkey, and Notification maps it to the Security category.

## API Endpoints

All endpoints except the two `/.well-known/` documents are under `/api`.

| Method | Path | Purpose |
|--------|------|---------|
| POST | `/api/auth/register` | Self-registration; `403` with reason `registration_closed` while it is closed; otherwise answers `202` with the same body, and no sooner than the response floor, whether or not the address is taken, and mails the owner of a taken address |
| GET | `/api/auth/registration` | Whether self-registration is open (public) |
| POST | `/api/auth/login` | Password login (issues tokens) |
| POST | `/api/auth/login/passkey` | Passkey login (issues tokens) |
| POST | `/api/auth/refresh` | Refresh token rotation for first-party tokens (issues tokens) |
| POST | `/api/auth/logout` | Revoke the chain of the access token that authenticated the request and close the WebSocket connections opened with any of its access tokens; accepted during a DPoP store outage without the replay check |
| POST | `/api/auth/password/reset-request` | Issue a password reset token; always answers `200` |
| POST | `/api/auth/password/reset` | Redeem a reset token and set a new password |
| POST | `/api/auth/email/verify` | Redeem a verification token in a signed-in first-party session of the account it was sent for; `403` with reason `verification_link_other_account` for another account's valid token, which stays usable, and one generic `400` for every unusable token |
| POST | `/api/auth/me/email/verification` | Send the current user a new verification link; always answers `200` |
| GET, PUT | `/api/auth/me` | Read or update the current user |
| PUT | `/api/auth/me/email` | Ask to change email (step-up); the new address is held as pending and sent a link; `202` |
| PUT | `/api/auth/me/password` | Change password (step-up); other sessions are signed out |
| POST | `/api/auth/totp/setup` | Generate a TOTP secret and provisioning URI (verified address and current password; `409` while TOTP is on) |
| POST | `/api/auth/totp/enable` | Enable TOTP after verifying a code; other sessions are signed out |
| POST | `/api/auth/totp/disable` | Disable TOTP (step-up with the current password and a current code; `400` while TOTP is off); other sessions are signed out |
| GET | `/api/auth/passkey` | List the current user's passkeys |
| POST | `/api/auth/passkey/options` | WebAuthn registration options (verified address and step-up) |
| POST | `/api/auth/passkey/register` | Complete passkey registration; other sessions are signed out |
| POST | `/api/auth/passkey/authenticate/options` | WebAuthn authentication options for discoverable passkey login: no account, no credential list |
| POST | `/api/auth/passkey/authenticate` | Verify an assertion with the sign-in checks, without issuing tokens; `401` with reason `passkey_auth_failed` for every refusal |
| DELETE | `/api/auth/passkey/{publicId}` | Delete a passkey (step-up in the body); other sessions are signed out |
| POST | `/api/oauth/par` | Push an authorization request (RFC 9126; public; client authentication and a DPoP key binding required) and get its `request_uri` |
| GET | `/api/oauth/authorize` | Look up a pushed request by `client_id` and `request_uri` for the consent page |
| POST | `/api/oauth/authorize` | Record the signed-in user's decision on a pushed request and answer the redirect URI |
| POST | `/api/oauth/token` | Token endpoint for the authorization code, device code, and refresh token grants (public; DPoP proof required) |
| POST | `/api/oauth/revoke` | Token revocation (RFC 7009) of an access token, found by its `jti`, or a refresh token of the signed-in user, and its chain; first-party tokens only; a foreign or unknown token answers `200` alike, nothing revoked; accepted during a DPoP store outage without the replay check |
| POST | `/api/oauth/device/authorize` | Device authorization request (RFC 8628; public) |
| GET | `/api/oauth/device/verify` | Show the signed-in user the pending request behind a user code (device page) |
| POST | `/api/oauth/device/approve` | Approve or deny a device request |
| GET, POST | `/api/oauth/clients/` | List or create the current user's personal access clients |
| DELETE | `/api/oauth/clients/{publicId}` | Revoke one of the current user's clients and its tokens |
| POST | `/api/ws/tickets` | Issue a single-use 30-second WebSocket handshake ticket (`WebSocketTicketController`) |
| GET | `/api/live/recovery` | Recover current live-update authorization and health (`LiveRecoveryController`) |
| GET | `/.well-known/oauth-authorization-server` | Authorization server metadata (RFC 8414) |
| GET | `/.well-known/jwks.json` | JSON Web Key Set for verifying access tokens |

### Admin — User Management

All routes under `/api/admin/users` (controller `AdminUserController`, gated `ROLE_ADMIN`; `{publicId}` is validated as a 21-character `PublicId`). Listing needs `USER_MANAGEMENT_LIST` and creating needs `USER_MANAGEMENT_CREATE`; every other route requires `ROLE_SUPER_ADMIN`. `UserManagementVoter` grants both attributes to super administrators. It grants them to other administrators only while the system settings `admin.can_view_users` and `admin.can_create_users` are on, reading them on every vote, and grants creation only when every requested role is `ROLE_USER`. The console commands (`app:user:list`, `app:user:create`, `app:user:rename`, `app:user:change-email`, `app:user:delete`, `app:user:roles`, `app:user:reset-password`, `app:user:reset-totp`, `app:user:mark-email-verified`, `app:user:disable`, `app:user:enable`, and `app:dev:create-users`) are not gated by these settings. The admin API refuses with `403` to change the signed-in administrator's own email address, password or TOTP, which go through the self-service routes with step-up; the console has no such limit. Deleting, disabling or demoting the only enabled super admin is refused by the admin API with `409` and reason `last_super_admin` and by the console commands with exit code 1; a disabled super admin does not count.

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/api/admin/users` | List users (paginated, filter by role/disabled; CLI: `app:user:list`) |
| POST | `/api/admin/users` | Create a user (CLI: `app:user:create`; dev seeding: `app:dev:create-users`) |
| PATCH | `/api/admin/users/{publicId}` | Update a user's name or email (CLI: `app:user:rename`, `app:user:change-email`) |
| DELETE | `/api/admin/users/{publicId}` | Delete a user (CLI: `app:user:delete`) |
| POST | `/api/admin/users/{publicId}/roles` | Assign roles (CLI: `app:user:roles`) |
| POST | `/api/admin/users/{publicId}/reset-password` | Reset a user's password and sign them out (CLI: `app:user:reset-password`) |
| POST | `/api/admin/users/{publicId}/reset-totp` | Turn off a user's TOTP and sign them out; `409` with reason `totp_not_enabled` without TOTP (CLI: `app:user:reset-totp`) |
| POST | `/api/admin/users/{publicId}/verify-email` | Mark the user's current address verified (CLI: `app:user:mark-email-verified`) |
| POST | `/api/admin/users/{publicId}/disable` | Disable a user (CLI: `app:user:disable`) |
| POST | `/api/admin/users/{publicId}/enable` | Enable a user (CLI: `app:user:enable`) |

### Admin — User Settings

Routes under `/api/admin/users/{publicId}/settings` (controller `AdminUserSettingsController`). `{publicId}` is the user's 21-character public ID; any other value (including a UUID) is `404`.

| Method | Path | Role | Purpose |
|--------|------|------|---------|
| GET | `/api/admin/users/{publicId}/settings` | `ROLE_ADMIN` + `USER_MANAGEMENT_LIST` | List the user's settings, with a stored value that is no longer allowed marked `storedValueValid: false` |
| PUT | `/api/admin/users/{publicId}/settings/{key}` | `ROLE_SUPER_ADMIN` | Set the user's choice (body `{"value": …}`), for every user setting, including ones users cannot change themselves; `422` for an invalid value |
| DELETE | `/api/admin/users/{publicId}/settings/{key}` | `ROLE_SUPER_ADMIN` | Remove the user's choice |

`app:user:setting get|set|reset <identifier> [key] [value]` is the console counterpart; it accepts an email address or public ID (not a UUID, per `UserLookup::byIdentifier()`). The endpoints and the command share the application service `AdminUserSettings`, which resolves the user with `UserLookup`, calls `UserSettingsContractInterface`, and logs every set and reset at info level with `actor_id` (the signed-in administrator, or `cli`), `target_id`, `key`, `old_value` and `new_value`. The logged values are the stored ones; `null` means no choice.

Deleting a user deletes their access tokens (`fk_oauth_access_tokens_user_id` is `ON DELETE CASCADE`), and the existing cascades from `oauth_access_tokens` then delete the matching refresh tokens and token metadata. The user's authorization codes and device codes are deleted with them as well (`fk_oauth_device_codes_user_id` uses `ON DELETE CASCADE`).

### Admin — OAuth Clients

All routes under `/api/admin/oauth/clients` (controller `AdminOAuthClientController`, gated `ROLE_ADMIN`; mutating routes require `ROLE_SUPER_ADMIN`). Each has an `app:oauth:client:*` command that dispatches the same message (see [Client Registration](auth-client-authentication.md#client-registration)).

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/api/admin/oauth/clients` | List every client except personal access clients, revoked ones included (CLI: `app:oauth:client:list`) |
| POST | `/api/admin/oauth/clients` | Register a device, public or confidential client; a `client_secret_post` client's secret is in this response only (CLI: `app:oauth:client:create`) |
| POST | `/api/admin/oauth/clients/{clientId}/rotate-secret` | Give a `client_secret_post` client a new secret (CLI: `app:oauth:client:rotate-secret`) |
| POST | `/api/admin/oauth/clients/{clientId}/keys` | Replace a `private_key_jwt` client's keys (CLI: `app:oauth:client:replace-keys`) |
| POST | `/api/admin/oauth/clients/{clientId}/revoke` | Revoke a client and its access and refresh tokens (CLI: `app:oauth:client:revoke`) |

### Admin — Login Blocks

All routes under `/api/admin/login-blocks` (controller `AdminLoginBlockController`, gated `ROLE_ADMIN`; deletes require `ROLE_SUPER_ADMIN` and `{publicId}` is validated as a 21-character `PublicId`).

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/api/admin/login-blocks` | List recent honeypot blocks (paginated; CLI: `app:login-block:list`) |
| DELETE | `/api/admin/login-blocks/{publicId}` | Delete a single block (CLI: `app:login-block:delete <publicId>`) |
| DELETE | `/api/admin/login-blocks` | Delete all blocks (CLI: `app:login-block:delete --all`) |

## Cross-Context Relationships

| Direction | Context | Details |
|-----------|---------|---------|
| Depends on | Shared | `Uuid`, `PublicId`, `Email` |
| Depended on by | All contexts | Every authenticated endpoint depends on Auth |
| Depends on | UserPreference | Registration, credential emails and the admin user settings call `UserSettingsContractInterface` through the narrow `UserPreference Settings Contract` Deptrac layer |
| Depends on | Shared | `SystemSettingsPortInterface` for the user management settings, read by `UserManagementVoter` |
| Depended on by | Notification | Notification Domain maps `DeviceCodeApproved` to the Security category through the narrow `Auth Device Approval Event Contract` Deptrac layer |

## Infrastructure

| Component | Type | Purpose |
|-----------|------|---------|
| League OAuth adapters | Anti-corruption layer | League access token and refresh token repository interfaces aliased to internal adapters in `services.yaml` |
| `ResourceServerFactory` | Security | Builds League's `ResourceServer` with `DpopAwareBearerTokenValidator` for access token validation |
| `CachedAccessTokenRepository` | Cache decorator | Caches access token lookups |
| `TokenEndpointDpopListener` | Security | Requires a nonce-bearing DPoP proof at `POST /api/oauth/token` before the grant runs, and checks one sent to `POST /api/oauth/par` the same way; answers a rejected proof with `invalid_dpop_proof` or `use_dpop_nonce` |
| `ClientAssertionVerifier` | Security | `ClientAssertionVerifierInterface` on `lcobucci/jwt` |
| `ClientKeySetProvider`, `ClientJwksFetcher` | Security | `ClientKeySetProviderInterface`: inline key sets, and `jwks_uri` sets fetched through `PublicHttpClient`, cached in `cache.app` and coordinated by Redis locks |
| `JwkPublicKeyConverter` | Security | Converts a public JWK into a verification key for DPoP proofs and client assertions |
| `RedisClientAssertionJtiStore` | Cache | `ClientAssertionJtiStoreInterface` on Redis: one set-if-absent-with-expiry command per assertion |
| `DpopProofRejection` | Security | Why `DpopTokenRequestVerifier::inspect()` rejected a proof (`missing`, `nonce_required`, `invalid`) and the RFC 9449 error code for it |
| `DpopNonceManager` | Security | Issues and consumes the single-use nonces in Redis; a nonce is handed out only after it was stored, and an unreachable store raises `DpopStoreUnavailableException` |
| `RedisDpopJtiCache` | Cache | `DpopJtiCacheInterface` on Redis: one set-if-absent-with-expiry command per proof |
| `DpopBindingListener` | Security | Requires a proof from the token's key on every API request made with an access token; answers an unavailable store with `DpopStoreUnavailableResponse` (`503`) |
| `RateLimitListener` | Security | Per-IP, per-IP-and-email and per-refresh-token limits for the auth and OAuth endpoints, before authentication (see [Rate limiting](../../part-1-operator-guide/configuration/rate-limits.md#rate-limiting)) |
| `ScopeEnforcementListener` | Security | Answers a delegated token on a route `ScopeRouteMap` does not grant it with `403` `insufficient_scope` |
| `UnverifiedAccountListener` | Security | Answers an account whose address is unverified with `403` `email_unverified` on every route outside its allowlist ([Unverified Accounts](auth-account-security.md#unverified-accounts)) |
| `RateLimiterLoginFailureThrottle`, `RateLimiterStepUpAttemptThrottle`, `RateLimiterOAuthClientThrottle` | Security | The per-account login failure, per-account step-up and per-client OAuth limits, charged from inside the authenticator and the handlers |
| `SodiumTotpSecretCipher` | Security | `TotpSecretCipherInterface` on libsodium secretbox under `AUTH_TOTP_ENCRYPTION_KEY` |
| `RedisPasskeyChallengeStore` | Cache | `PasskeyChallengeStoreInterface` on Redis: `SET NX EX 300` to store, `GETDEL` to take |
| Doctrine entities | ORM | Persistence for all models |
| Doctrine repositories | ORM | Repository implementations for all aggregates |
| Voter classes | Security | Authorization checks for protected resources; `UserManagementVoter` applies the user management settings |
| `AfterResponseMailer` | Mail | Sends credential emails after the response, in the recipient's email language |

See the [Architecture](../architecture/overview.md#anti-corruption-layer) page for details on the League anti-corruption layer.
