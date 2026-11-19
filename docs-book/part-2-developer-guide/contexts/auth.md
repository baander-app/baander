# Auth

The Auth context signs users in, issues their tokens, and validates those tokens on every request. Baander's own apps get tokens from password login (with optional TOTP), passkey login (WebAuthn), and refresh. Other clients use the OAuth 2.0 authorization server: the authorization code grant on pushed authorization requests (RFC 9126) with mandatory S256 PKCE, the device authorization grant (RFC 8628), and the refresh token grant at `POST /api/oauth/token`. Confidential clients authenticate with a secret or with JWT assertions signed by their own keys (`private_key_jwt`). Every token pair, whichever path issues it, is bound to the client's DPoP key, and refresh tokens rotate. Tokens of the first-party client act with the user's full authority; every other client's tokens are delegated and reach only the routes their scopes cover (see [Scope Enforcement](#scope-enforcement)). Auth implements the authorization server itself; it uses League OAuth2 Server only to validate access tokens, behind an anti-corruption layer. There is no client credentials grant.

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

Commands and handlers are organized into feature namespaces under `Application/Command/` and `Application/CommandHandler/`: `LoginBlock/`, `OAuth/`, `Passkey/`, `Totp/`, and `User/`. Password login is checked by `PasswordAuthenticator` on the API firewall, not by a command. Eight application services under `Application/Service/` are shared by the OAuth handlers: `TokenPairIssuer` mints every new token pair, `TokenChainRevoker` revokes a token chain and closes its WebSocket connections after the commit, `OAuthClientAuthenticator` identifies and authenticates the client of an OAuth request, `PendingDeviceCodeFinder` resolves a user code as the user typed it, `AuthorizationRequestValidator` checks a pushed authorization request when it is pushed and again for the signed-in user, `ClientRevoker` revokes a client together with its tokens, `ChainConnectionCloser` closes the WebSocket connections of a revoked chain, and `IssuanceLockTimeout` bounds the lock waits of a token-issuing transaction.

| Command | Handler | Purpose |
|---------|---------|---------|
| `RegisterUserCommand` | `RegisterUserHandler` | Self-registration; seeds the email language from the browser (User/) |
| `CreateUserCommand` | `CreateUserHandler` | Operator-created users (User/) |
| `RenameUserCommand` | `RenameUserHandler` | Update a user's display name from the admin API or `app:user:rename` (User/) |
| `DeleteUserCommand` | `DeleteUserHandler` | Delete a user account from the admin API or `app:user:delete` (User/) |
| `SetUserRolesCommand` | `SetUserRolesHandler` | Replace a user's roles from the admin API or `app:user:roles` (User/) |
| `DisableUserCommand` | `DisableUserHandler` | Disable a user account (User/) |
| `EnableUserCommand` | `EnableUserHandler` | Re-enable a disabled account (User/) |
| `RequestPasswordResetCommand` | `RequestPasswordResetHandler` | Issue a password reset token for the account with that email (User/) |
| `ResetPasswordCommand` | `ResetPasswordHandler` | Redeem a reset token and set a new password (User/) |
| `SetUserPasswordCommand` | `SetUserPasswordHandler` | Operator password reset from the admin panel or `app:user:reset-password` (User/) |
| `ChangePasswordCommand` | `ChangePasswordHandler` | A user changes their own password (User/) |
| `IssueTokenCommand` | `IssueTokenHandler` | Issue a DPoP-bound token pair after password or passkey login (OAuth/) |
| `RefreshTokenCommand` | `RefreshTokenHandler` | Rotate a refresh token for a new token pair (OAuth/) |
| `RevokeTokenCommand` | `RevokeTokenHandler` | Token revocation (OAuth/) |
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
| `RegisterPasskeyCommand` | `RegisterPasskeyHandler` | Add a WebAuthn passkey (Passkey/) |
| `AuthenticatePasskeyCommand` | `AuthenticatePasskeyHandler` | Verify a WebAuthn assertion during passkey login (Passkey/) |
| `VerifyEmailCommand` | `VerifyEmailHandler` | Redeem a verification token and mark the address verified (User/) |
| `ResendEmailVerificationCommand` | `ResendEmailVerificationHandler` | Send a signed-in user a new verification link (User/) |
| `ChangeOwnEmailCommand` | `ChangeOwnEmailHandler` | A user asks to change their own email: step-up, then the new address is held as pending and sent a link (User/) |
| `ChangeEmailCommand` | `ChangeEmailHandler` | An operator change from the admin panel or `app:user:change-email`, verified at once (User/) |
| `MarkEmailVerifiedCommand` | `MarkEmailVerifiedHandler` | Mark a user's current address verified on an operator's word, from the admin API or `app:user:mark-email-verified` (User/) |
| `ResetUserTotpCommand` | `ResetUserTotpHandler` | Drop a user's TOTP secret without reading it and revoke all of their tokens, from the admin API or `app:user:reset-totp` (User/) |
| `StartTotpSetupCommand` | `StartTotpSetupHandler` | Generate a pending TOTP secret after step-up, held for 10 minutes until enable confirms it (Totp/) |
| `EnableTotpCommand` | `EnableTotpHandler` | Enable TOTP 2FA (Totp/) |
| `DisableTotpCommand` | `DisableTotpHandler` | Disable TOTP 2FA (Totp/) |
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

## Password Reset and Password Changes

`password_reset_tokens` holds at most one token per user, keyed by `user_id` with `ON DELETE CASCADE`. Only the SHA-256 hash of the 256-bit token is stored. `UserRepository::save` removes the token whenever the stored email address or password hash changes, so a token never outlives the credentials it was issued for or carries over to a later account with the same address. The lifetime comes from `PASSWORD_RESET_EXPIRE` (minutes).

`RequestPasswordResetHandler` charges the per-account limiter before looking up the address and issues a token only for an existing account; the endpoint answers the same way in every case. After issuing the token, the handler passes it to the `PasswordResetDeliveryInterface` port. The raw token exists only in the handler's memory and in that delivery. Do not put it in a domain event, because events are stored in the outbox.

`MailerPasswordResetDelivery` implements the port. It builds the link `APP_URL/reset-password#token=…` and queues the email on `AfterResponseMailer`, which waits for `kernel.terminate`, which the Swoole server and PHP-FPM both dispatch after the response has gone out. Sending inside the request would make the request slower for real accounts than for unknown addresses, and that difference would reveal which addresses have accounts. Pending emails are held in a `WeakMap` keyed by the main request, so concurrent coroutines in one worker do not send each other's mail. Without a current request (a console command or a worker), it sends at once.

Sending is shared with the verification email through `AfterResponseMailer`, which queues a `CredentialEmail` and sends it. The `CredentialEmail` carries the user ID, not a language. When `AfterResponseMailer` sends the email, it asks `UserSettingsContractInterface::resolveLanguage()` for the recipient's [email language](#email-language) and passes that locale explicitly to the translator and the templates; it never changes the shared translator's locale. Resolving the language at send time keeps the per-account lookup out of the request, so a reset request for a real account still takes as long as one for an unknown address. If the lookup fails, the email is sent in English and the failure is logged with the exception class only.

The mailer calls the mailer transport directly rather than `MailerInterface`. `MailerInterface` dispatches a `SendEmailMessage` on the message bus, and a later routing change could put the token into Redis or the `failed_messages` table. Notification email (`SendEmailCommand` on `async`) can be retried from those stores; a reset email cannot, and does not need to be, because the user can ask for a new link. A failed send is logged with the user ID and exception class only, since transport errors can quote the recipient. The templates are `templates/email/auth/password_reset.{txt,html}.twig`, translated through the `password_reset_email` keys in the `auth` domain.

The web pages are `/forgot-password` and `/reset-password` in `ui/web/src/features/auth/`. The reset page reads the token from the fragment, removes the fragment from the address bar, and clears the local session after a successful reset, because the server has revoked it.

All three password paths (`ResetPasswordHandler`, `SetUserPasswordHandler`, `ChangePasswordHandler`) call the application service `PasswordChanger`. It enforces the 8 to 255 character policy, hashes the password, and then, in one transaction, saves the user, revokes the user's access and refresh tokens and records `PasswordChanged`. A user's own change keeps the session that made it: the presenting access token and its refresh chain. Every other session ends, including one whose tokens were issued while the change was running. The other two paths revoke everything. A reset token that is unknown, expired, already used, or belongs to a disabled account gets one generic `400`.

A redeemed reset token also settles the account's address. On a verified address, `ResetPasswordHandler` drops a pending email change. On an address that was never verified, someone else may have registered the account under it, so the reset claims the account for the address that just proved itself: it verifies the address and removes the account's TOTP secret, its spent TOTP step and its passkeys, in the transaction that changes the password and revokes the sessions.

## Email Verification

`email_verification_tokens` follows the design of `password_reset_tokens`. It holds at most one token per user, keyed by `user_id` with `ON DELETE CASCADE`, and stores only the SHA-256 hash of the 256-bit token together with the `email` (`CITEXT`) the token was issued for. `UserRepository::save` removes the token when the stored email address changes. The lifetime is `auth.email_verification_token.ttl` (seconds).

Redemption is a single `DELETE … RETURNING`, so a token works once even under concurrent requests. `VerifyEmailHandler` runs that statement in the same transaction that marks the address verified and records `EmailVerified`; if the commit fails, the token stays redeemable. The handler accepts the token only while the account still has the address the token was issued for and is not disabled. Every other case, including an unknown, expired or used token, gets one generic `400`.

`EmailVerificationIssuer` is the single place tokens are issued. Its `issue()` stores the hash, with the address the token verifies, inside the caller's transaction and returns the raw token: the pending address of a self-service email change, otherwise the current address while it is unverified. It issues nothing when no change is pending and the address is verified, or for a disabled account. Its `deliver()` hands the token to `EmailVerificationDeliveryInterface` after the commit, so a rolled-back registration or email change never emails a link. Three callers use it. `RegisterUserHandler` issues on registration. `ChangeOwnEmailHandler` serves `PUT /api/auth/me/email`: it stores the new address in `users.pending_email` and issues for it, answering `202` whether or not the address is taken. When the link is redeemed, `VerifyEmailHandler` moves the pending address into `email` unless another account uses it by then, and `EmailChangeNoticeInterface` tells the old address. Operator changes (`PATCH /api/admin/users/{publicId}`, `app:user:change-email`) do not use the issuer: they dispatch `ChangeEmailCommand`, which writes the new address as verified, drops a pending change and tells the old address. `MarkEmailVerifiedHandler` serves `POST /api/admin/users/{publicId}/verify-email` and `app:user:mark-email-verified`: it verifies the current address on the operator's word and leaves a pending change alone, which still takes effect only through its own link. The third caller, `ResendEmailVerificationHandler`, serves `POST /api/auth/me/email/verification`. The resend endpoint answers the same `200` whether it sent an email or not; over the per-user limit (`auth_email_verification_user`) it sends nothing. Redemption and resend requests share the per-IP limit `auth_email_verification_ip`, which answers `429` with `Retry-After`.

A verified address is a precondition for adding an authenticator: `POST /api/auth/totp/setup` and `POST /api/auth/passkey/options` answer an unverified address with `EmailUnverifiedException` (`422`, `details.reason` `email_unverified`) before step-up spends an attempt.

`MailerEmailVerificationDelivery` builds the link `APP_URL/verify-email#token=…` and queues the email on `AfterResponseMailer`. That is the reset email's path: it sends after the response and straight to the mailer transport, so a slow or failing mail server neither delays an HTTP response nor fails a registration. The templates are `templates/email/auth/email_verification.{txt,html}.twig`, translated through the `email_verification_email` keys in the `auth` domain. Both auth emails are written in the recipient's email language. `AfterResponseMailer` reads it through the UserPreference settings contract when it sends the email, after the response, so the request does no per-account work. If the lookup fails, the email is sent in English and the failure is logged without the address or the link.

The web page is `/verify-email` in `ui/web/src/features/auth/`. It reads the token from the fragment and removes the fragment from the address bar. The account card in **Settings** shows an unverified address and offers a new link.

## Email Language

The email language is a UserPreference user setting, `language`, that follows the system setting `i18n.default_language`. Auth reaches it only through `UserSettingsContractInterface`, in the `UserPreference Settings Contract` Deptrac layer (see [UserPreference](user-preference.md#the-settings-contract)).

At registration, `AuthController` passes the `Accept-Language` header to `AcceptLanguageMatcher` (`App\Shared\Application\Http`), which returns the supported language the browser ranks highest. It ignores `*` and entries with `q=0`, maps a regional tag such as `da-DK` to `da`, and returns `null` when nothing matches. `Request::getPreferredLanguage()` is not used, because it answers with the first supported language even when the browser asks for none of them. `RegisterUserCommand` carries the result, and `RegisterUserHandler` calls `seedLanguage()` inside the registration transaction. The contract stores the language only when it differs from the current server default, so a user whose browser asks for the default keeps following it when an administrator changes it. `CreateUserHandler`, behind `app:user:create` and the admin panel, stores no language.

The same setting localizes API messages. `ApiLanguageListener` (`Interface/EventListener/`) resolves each API request's language once, after the firewall: the user's saved choice (not the server default), else the request's `Accept-Language` through the same matcher, else English. It also runs on `kernel.exception` for a request the firewall refused first. It stores the result in the `_baander_locale` request attribute and never calls `Request::setLocale()`, because Symfony would copy that onto the translator, which is one instance shared by every coroutine of a worker. `ExceptionSubscriber`, `ValidationExceptionSubscriber` and `TranslatorTrait` pass the attribute's locale to each translation, so the shared translator stays English. The password, OAuth2 and passkey authenticators run inside the firewall, before the listener, and translate their failures from `Accept-Language` through `AuthenticationFailureMessage`.

The verification email that follows registration is sent in the stored language, because `AfterResponseMailer` resolves the language when it sends.

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

No code raises `PasskeyDeleted`: `DELETE /api/auth/passkey/{publicId}` removes the passkey through the repository without an event, although Notification maps the event to the Security category.

## API Endpoints

All endpoints except the two `/.well-known/` documents are under `/api`.

| Method | Path | Purpose |
|--------|------|---------|
| POST | `/api/auth/register` | Self-registration; answers `202` with the same body whether or not the address is taken, and mails the owner of a taken address |
| POST | `/api/auth/login` | Password login (issues tokens) |
| POST | `/api/auth/login/passkey` | Passkey login (issues tokens) |
| POST | `/api/auth/refresh` | Refresh token rotation for first-party tokens (issues tokens) |
| POST | `/api/auth/logout` | Revoke the chain of the access token that authenticated the request and close the WebSocket connections opened with any of its access tokens; accepted during a DPoP store outage without the replay check |
| POST | `/api/auth/password/reset-request` | Issue a password reset token; always answers `200` |
| POST | `/api/auth/password/reset` | Redeem a reset token and set a new password |
| POST | `/api/auth/email/verify` | Redeem a verification token; one generic `400` for every unusable token |
| POST | `/api/auth/me/email/verification` | Send the current user a new verification link; always answers `200` |
| GET, PUT | `/api/auth/me` | Read or update the current user |
| PUT | `/api/auth/me/email` | Ask to change email (step-up); the new address is held as pending and sent a link; `202` |
| PUT | `/api/auth/me/password` | Change password; other sessions are signed out |
| POST | `/api/auth/totp/setup` | Generate a TOTP secret and provisioning URI (verified address and current password; `409` while TOTP is on) |
| POST | `/api/auth/totp/enable` | Enable TOTP after verifying a code |
| POST | `/api/auth/totp/disable` | Disable TOTP (requires a current code) |
| GET | `/api/auth/passkey` | List the current user's passkeys |
| POST | `/api/auth/passkey/options` | WebAuthn registration options (verified address and step-up) |
| POST | `/api/auth/passkey/register` | Complete passkey registration |
| POST | `/api/auth/passkey/authenticate/options` | WebAuthn authentication options for passkey login |
| POST | `/api/auth/passkey/authenticate` | Verify an assertion without issuing tokens |
| DELETE | `/api/auth/passkey/{publicId}` | Delete a passkey |
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

All routes under `/api/admin/users` (controller `AdminUserController`, gated `ROLE_ADMIN`; `{publicId}` is validated as a 21-character `PublicId`). Listing needs `USER_MANAGEMENT_LIST` and creating needs `USER_MANAGEMENT_CREATE`; every other route requires `ROLE_SUPER_ADMIN`. `UserManagementVoter` grants both attributes to super administrators. It grants them to other administrators only while the system settings `admin.can_view_users` and `admin.can_create_users` are on, reading them on every vote, and grants creation only when every requested role is `ROLE_USER`. The console commands (`app:user:list`, `app:user:create`, `app:user:rename`, `app:user:change-email`, `app:user:delete`, `app:user:roles`, `app:user:reset-password`, `app:user:reset-totp`, `app:user:mark-email-verified`, `app:user:disable`, `app:user:enable`, and `app:dev:create-users`) are not gated by these settings. The admin API refuses with `403` to change the signed-in administrator's own email address, password or TOTP, which go through the self-service routes with step-up; the console has no such limit.

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

All routes under `/api/admin/oauth/clients` (controller `AdminOAuthClientController`, gated `ROLE_ADMIN`; mutating routes require `ROLE_SUPER_ADMIN`). Each has an `app:oauth:client:*` command that dispatches the same message (see [Client Registration](#client-registration)).

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

## Token Issuance

Four endpoints issue tokens. Baander's own apps use `POST /api/auth/login`, `POST /api/auth/login/passkey`, and `POST /api/auth/refresh`; other clients use `POST /api/oauth/token`. Login dispatches `IssueTokenCommand` for the SPA client named by `auth.spa_client_id`, and first-party refresh dispatches `RefreshTokenCommand`. The token endpoint dispatches one command per grant (see [Token Endpoint](#token-endpoint)).

Every new token pair comes from `TokenPairIssuer`, whether password login, passkey login, the authorization code grant, or the device code grant asked for it. It stores a DPoP-bound access token and a refresh token that starts a new rotation chain, with both rows written in one transaction. A grant can pass a further write that commits or rolls back with the tokens; the code grants use it to redeem their code. The refresh token is an opaque identifier, stored only as its SHA-256 hash; neither League encryption nor an `OAUTH_ENCRYPTION_KEY` is involved. The access token JWT carries `iss` (`auth.oauth.issuer`), the header type `at+jwt` and, as `kid`, the RFC 7638 thumbprint of the signing key, which `AccessTokenSigningKeyInterface` also gives the JWKS document; `DpopAwareBearerTokenValidator` refuses a token whose `iss` is missing or different. Login asks for no scopes, so first-party tokens carry none. The delegated grants take their scopes from `ScopeAllowlist::grantable()`: requested scopes outside `auth.scopes.user_grants` are dropped, and a request left with none fails with `invalid_scope` when it is pushed or when the device flow starts. There is no default scope.

Rotation happens in `RefreshTokenHandler` for both refresh paths. It consumes the old refresh token atomically, issues the new pair in the same chain, and carries the DPoP key and the client fingerprint forward. A replayed refresh token revokes its chain and closes the WebSocket connections opened with its access tokens, as a logout does, except within the lost-response grace: a consumed token presented again with its own key inside `auth.refresh.reuse_grace_seconds` (default and maximum 60, measured on the database clock, 0 disables), while its replacement is unused, gets a fresh pair and the replacement is revoked. The decision is made on rows read under the row lock inside the issuing transaction, so two simultaneous presentations leave exactly one usable pair. A presentation with another key revokes nothing. Tokens of a revoked client cannot be refreshed. A chain also ends at its absolute lifetime, `auth.refresh.max_chain_lifetime` (7,776,000 seconds, 90 days, by default), counted from the sign-in that started it, whatever the sliding expiry of its newest refresh token. `POST /api/auth/refresh` passes the first-party client into `RefreshTokenCommand`, so the handler's client check refuses there, with `401`, a refresh token issued to any other client. A server fault, such as a database restart, is answered `503` with `Retry-After`; it says nothing about the token.

Logout (`POST /api/auth/logout`) dispatches `RevokeTokenCommand` for the access token that authenticated the request, with its chain: the handler revokes the refresh rows, lists the chain's access token ids under those row locks, revokes the access rows, commits, and asks the live-connections port to close every WebSocket opened with an access token of the chain. The list is taken under the locks so that a replacement a concurrent rotation has just committed is closed with the rest. A revocation that did not commit answers `503` with `Retry-After` rather than `200`; a close the web server could not finish is logged with the chain id and the connection count, never a token id, and the request still answers `200`, because the chain is gone and `RevokedSocketReconciler` closes the connection on its next run. Only an unknown, foreign or already revoked token answers `200` without effect.

`RevokedSocketReconciler` (Auth Infrastructure, Swoole) is the backstop for every socket: a timer in HTTP worker 0 lists the distinct access token ids of the registered connections (`WebSocketConnectionRegistry::getConnectedAccessTokenIds()`), asks `AccessTokenRepositoryInterface::findTokenIdsOfLiveChains()` which of them belong to a chain that still has a stored, unrevoked and unexpired access token on the database clock, in chunks of 500, and closes the connections of the rest in-process through the live-connections port. The chain is the unit because a rotation revokes the access token a socket was opened with while the session goes on; rotation alone never closes a socket. It follows the health monitor's lifecycle (worker 0 only, pooled services released per tick, no overlap, skipped while draining) and implements the Shared port `WorkerTimerInterface`, through which `DrainWorkerExitSubscriber` stops every tagged timer without naming a context. Its logs count dead tokens and never carry a token id.

The binding listener (`DpopBindingListener`) lets the two revocation routes through a DPoP store outage: `DpopProofValidator::validate()` takes a flag that turns the replay store's failure into a `replayUnchecked` result after every other check ran, and the listener logs one warning with the route, the thumbprint and the user id. Every other route keeps the 503.

The first-party endpoints wrap `accessToken`, `tokenType` (`DPoP`), `expiresIn`, and `refreshToken` in `data`; login responses also include `user`. The token endpoint answers in the RFC 6749 format instead: a flat object with `access_token`, `token_type` (`DPoP`), `expires_in`, `refresh_token`, and `scope`, built by `OAuthTokenResource`.

### DPoP

All four endpoints require a `DPoP` header carrying a proof (RFC 9449) with a server-issued nonce. A proof without a valid nonce gets `400` with `{"error": "use_dpop_nonce"}` and a `DPoP-Nonce` header; the client retries with a new proof that carries that nonce. Each nonce works once, and `DpopNonceManager` hands one out only after Redis stored it. A successful response returns a fresh `DPoP-Nonce` for the client's next proof; at the first-party endpoints the next nonce is stored after the tokens are committed, and when that store fails the header is left out and the client's next refresh gets one challenge. Ordinary API responses carry no nonce. At the first-party endpoints a missing `DPoP` header returns a `400` `ApiError`, and an invalid proof gets the `use_dpop_nonce` answer.

At the token endpoint, `TokenEndpointDpopListener` checks the proof before any grant runs, through `DpopTokenRequestVerifier::inspect()`, and stores a valid one on the request as `VerifiedDpopProof`. A rejected proof comes back as a `DpopProofRejection`, which the listener answers in RFC 9449 terms: `400` `use_dpop_nonce` when the proof lacks a fresh nonce, and `400` `invalid_dpop_proof` when the proof is missing or invalid, each with `error_description`, a `DPoP-Nonce` header, `Cache-Control: no-store` and `Pragma: no-cache`. Because a polling device spends a nonce on every request, every answer the controller gives, OAuth errors included, carries the next `DPoP-Nonce`; only the `503` below carries none. The listener checks a proof sent to `POST /api/oauth/par` the same way, but a push without a `DPoP` header passes, since it may bind its key with `dpop_jkt` instead (see [Pushed Authorization Requests](#pushed-authorization-requests)).

Tokens are bound to the proof key: the access token JWT carries `cnf.jkt`, and the thumbprint is stored with the token (`dpop_jkt`). Refresh requires a proof signed by the same key, and the new pair keeps the binding. `DpopBindingListener` requires a matching proof on every API request made with the token, except signed stream delivery URLs, where the proof is not read and no `jti` is recorded. `OAuth2Authenticator` refuses a DPoP-bound token presented with the `Bearer` scheme on every route the `api` firewall authenticates (including `POST /api/ws/tickets`, which issues the single-use ticket that `/api/ws?ticket=...` redeems through `WsTicketAuthenticator`). Replay protection records each proof's `jti` through `DpopJtiCacheInterface::isReplay()`, one atomic set-if-absent command with an expiry one second longer than the proof acceptance window (the larger of `auth.dpop.nonce_ttl` and twice `auth.dpop.clock_skew`), because times are checked in whole seconds.

`DpopProofValidator` compares a proof's `htu` with the deployment's public origins followed by the request path, never with the scheme and `Host` the request arrived with: the origin of `APP_URL` and each origin in `auth.dpop.extra_origins` (`AUTH_DPOP_EXTRA_ORIGINS`, comma-separated, empty by default). The origins are normalized once when the service is built, and one that is not an absolute `http` or `https` URL fails container boot. The test environment lists `https://baander.app` and `http://localhost`. The shipped production nginx and the preset gateway pass the upstream proxy's `X-Forwarded-Proto` on and send their own scheme only when none arrived.

#### DPoP store outage

A nonce or jti that cannot be checked is not a verdict on the proof. `DpopNonceManager` and `RedisDpopJtiCache` translate `\RedisException` and pool exhaustion into `DpopStoreUnavailableException` (Application) and log the failure; `RedisClientFactory` in Shared keeps its own exceptions. Five edges catch it and answer `503` with `Retry-After: 5`, `Cache-Control: no-store`, no `DPoP-Nonce` and no `WWW-Authenticate`: `AuthController::refresh`, `LoginController`, `PasskeyAuthenticator`, `TokenEndpointDpopListener` and `DpopBindingListener` (the listeners through `DpopStoreUnavailableResponse`); on the logout and revoke routes `DpopBindingListener` instead accepts a proof whose replay check could not run, as described under the revocation routes above. `OAuthController::token` and `OAuthController::par` answer a server fault the same way by throwing Symfony's service-unavailable exception, whose headers `ExceptionSubscriber` keeps. The client assertion `jti` store and a `jwks_uri` key set that cannot be fetched raise the same exception, so the pushed request and token endpoints answer them with `503` and `Retry-After` too. A genuine nonce miss keeps its `400` `use_dpop_nonce` answer and a genuine replay its `400` or `403`. Every Redis connection has a 1 second connect and read timeout (`RedisClientFactory::TIMEOUT_SECONDS` and the DSNs in `config/packages/cache.yaml`), so a hung Redis is answered inside the web client's 10 second attempt. The health monitor alerts once per outage; the edges log nothing more.

### Token Endpoint

`POST /api/oauth/token` is public: the client and the grant identify the caller, not a user token. Parameters may be form-encoded (RFC 6749) or a JSON object. `grant_type` selects the command:

| `grant_type` | Command | Required parameters |
|--------------|---------|---------------------|
| `authorization_code` | `ExchangeAuthorizationCodeCommand` | `code`, `code_verifier`, and `redirect_uri` unless the client registered exactly one |
| `urn:ietf:params:oauth:grant-type:device_code` | `ExchangeDeviceCodeCommand` | `device_code` |
| `refresh_token` | `ExchangeRefreshTokenCommand` | `refresh_token` |

`OAuthClientAuthenticator` authenticates the client by `client_id` and its registered method (see [Client Authentication](#client-authentication)).

Errors follow RFC 6749: `{"error": "...", "error_description": "..."}`, with `400` except for `invalid_client`, which is `401`. Every controller answer, success or error, carries `Cache-Control: no-store`, `Pragma: no-cache`, and the next `DPoP-Nonce`; a server fault during the grant is `503` with `Retry-After` and no nonce (see [DPoP store outage](#dpop-store-outage)). At this endpoint a refresh token must belong to the authenticated client; `ExchangeRefreshTokenHandler` reports every refresh rejection as `invalid_grant` without saying why.

Each grant handler charges `OAuthClientThrottleInterface` right after the client authenticates, so a caller who merely names a client in `client_id` cannot spend its allowance. `RateLimiterOAuthClientThrottle` keys a client with a secret or keys by its public ID; a public client, which has no credential to prove, by its public ID and the caller's network, the `/24` of an IPv4 address or the `/64` of an IPv6 address. A client over its allowance gets `429` with `Retry-After`. Like the per-IP limits, the per-client limits are off in the `dev` environment.

### Client Authentication

The pushed authorization request endpoint, device authorization and all three token grants pass `ClientCredentials` to `OAuthClientAuthenticator::authenticate()`. The value object carries the required `client_id`, an optional `client_secret` from the body, an optional secret from an HTTP Basic `Authorization` header (`client_secret_basic`), and an optional `client_assertion` with its `client_assertion_type`; an empty body value counts as not sent, and a Basic header counts as sent even with an empty password. `OAuthController` reads Basic credentials as RFC 6749 section 2.3.1 defines them, each part form-urlencoded: the header names the client, a body `client_id` may only repeat it, and a malformed header is `invalid_client`. At the token and pushed authorization request endpoints, an `invalid_client` answer to a request with Basic credentials carries `WWW-Authenticate: Basic` (RFC 6749 section 5.2). The authenticator resolves the client by `client_id` and verifies it by the one method the client was registered with, `Client::getAuthMethod()`:

| `ClientAuthMethod` | Accepts |
|--------------------|---------|
| `none` | Neither a secret nor an assertion |
| `client_secret_post` | A secret in the body or in HTTP Basic, never both, compared in constant time; no assertion |
| `private_key_jwt` | A JWT bearer assertion (`urn:ietf:params:oauth:client-assertion-type:jwt-bearer`) that `ClientAssertionVerifierInterface` accepts; no secret |

Any other combination, including a second method alongside the registered one, is `401` `invalid_client` (RFC 6749 section 2.3). Unknown, malformed, and revoked clients fail the same way, so the answer does not reveal which it was. `identify()` resolves a client without authenticating it, for the consent page's front-channel calls.

`ClientAssertionVerifier` (Infrastructure, on `lcobucci/jwt`) implements the verifier port under the rules of RFC 7523 as updated by RFC 10070:

- `alg` is one of the asymmetric algorithms DPoP proofs use (`JwkPublicKeyConverter::SIGNING_ALGORITHMS`), which the metadata advertises as `token_endpoint_auth_signing_alg_values_supported`; a unit test keeps the two lists equal. `none` and HMAC are refused. `PS256` is verified through web-token's RSA-PSS implementation against the registered JWK, because `lcobucci/jwt` has no PSS signer; RSA keys need at least 2048 bits, and an `OKP` key must name `Ed25519`, for client assertions and DPoP proofs alike.
- A `crit` header is refused: no JWS extension is understood (RFC 7515 section 4.1.11).
- `typ` is absent or `client-authentication+jwt`, with an optional `application/` prefix, compared case-insensitively. The header is read from the encoded token, because the parser reports a missing `typ` as `JWT`.
- `iss` and `sub` equal the `client_id`. `aud` is exactly `auth.oauth.issuer`, as a string or a one-element array, without normalization. The token endpoint URL that OpenID Connect Core suggests is refused.
- `exp` is required, and `exp - now` may not exceed `auth.client_assertion.max_lifetime` (300 by default) plus the clock skew `auth.dpop.clock_skew`. A present `iat` may not lie in the future beyond the skew, and `exp - iat` may not exceed the maximum lifetime. `nbf` is honored.
- The signature verifies with one of the keys `ClientKeySetProviderInterface::signingKeys()` returns for the assertion's `kid`. A key whose own `alg` differs is skipped.
- `jti` is required. After every other check passes, `ClientAssertionJtiStoreInterface::isReplay()` records it. `RedisClientAssertionJtiStore` uses the key `oauth:assertion-jti:` followed by the SHA-256 of the `client_id` and `jti`, apart from DPoP proof jtis, with a TTL of the assertion's remaining lifetime plus the skew, plus one second because times are checked in whole seconds.

The verifier logs which check failed and answers only `false`. `JwkPublicKeyConverter` turns a JWK into a verification key for both this verifier and `DpopProofValidator`.

`ClientKeySetProvider` serves the keys from the client's `ClientKeySource`: an inline JWK Set from `oauth_clients.jwks`, or the set published at `oauth_clients.jwks_uri`. `ClientJwksFetcher` fetches a published set through `PublicHttpClient`. The allowlist is the URI's own host, the address must be public, redirects are not followed, the budget is 3 seconds, and the body is read only up to `ClientKeySource::MAX_JWKS_BYTES` (64 KB). The fetched set must pass the same `ClientKeySource` rules as an inline one: at most 10 public asymmetric keys, with distinct `kid` values when there are several. Keys with a `use` other than `sig` are left out.

A fetched set is cached in `cache.app` for `auth.client_jwks.cache_ttl` seconds (3600 by default). The cache key hashes the client ID with the client's `updated_at`, so replacing the keys makes older entries unreachable, and a fetch that was running during the replacement cannot bring old keys back. Every fetch first takes a Redis lock (`SET NX EX`). The first fetch of an uncached set uses `oauth:client-jwks-fetch:`, so one fetch per client runs at a time and a concurrent request gets `503`. An assertion naming a `kid` the cached set lacks refetches under `oauth:client-jwks-refetch:`, at most once per client per `auth.client_jwks.refetch_interval` seconds (60 by default); within the interval the cached set answers.

A transport failure, timeout, refused destination, non-`200` answer or oversized body raises `DpopStoreUnavailableException`, caches nothing and leaves an earlier entry as it was. So does an unavailable lock, so nothing is fetched while Redis is down. A malformed set, or one without the assertion's key after the refetch, gives `invalid_client`.

Each assertion is single use, so a client signs a new one for the token request that follows a push. `TokenEndpointDpopListener` answers a `use_dpop_nonce` challenge before the controller runs, so that retry does not use the assertion up. A retry after a `503` may find it already used.

### Authorization Code Grant

Every authorization request is pushed first (RFC 9126), and the authorization endpoint accepts nothing else. A browser redirect cannot carry the user's DPoP-bound access token, so the authorization endpoint is a page of the web app, not an API route. The RFC 8414 metadata advertises `auth.oauth.authorization_page_uri` (by default `APP_URL/oauth/authorize`) as `authorization_endpoint`, `POST /api/oauth/par` as `pushed_authorization_request_endpoint`, and `require_pushed_authorization_requests: true`.

#### Pushed Authorization Requests

`POST /api/oauth/par` (route `oauth_par`) is public, like the token endpoint: it has the same `security.yaml` access rule and the same CORS entry, and it accepts form-encoded or JSON parameters. `OAuthController::par` refuses a `request_uri` parameter with `invalid_request` (RFC 9126 section 2.1) and a `request` parameter with `request_not_supported`, requires `client_id`, and dispatches `PushAuthorizationRequestCommand`. `PushAuthorizationRequestHandler` then does three things:

1. It authenticates the client through `OAuthClientAuthenticator`, exactly as the token endpoint does (see [Client Authentication](#client-authentication)).
2. It runs `AuthorizationRequestValidator::validatePush()`, every check that needs no user. The `redirect_uri` must match a registered URI exactly; a loopback URI (`http` on `localhost`, `127.0.0.1`, or `[::1]`) also matches on any port, because native apps listen on an ephemeral one (RFC 8252 section 7.3). The parameter may be omitted only when the client registered exactly one URI. `response_type` must be `code`. Device clients are refused (`unauthorized_client`). PKCE is required for every client: `code_challenge` must be a base64url SHA-256 digest and `code_challenge_method` must be `S256`. Requested scopes are filtered against `auth.scopes.user_grants`.
3. It derives the key binding (RFC 9449 section 10.1). `TokenEndpointDpopListener` covers `oauth_par` as well as `oauth_token`. A push without a `DPoP` header passes the listener without a `VerifiedDpopProof`; a push with one gets the full token endpoint check, nonce challenge included. The handler then binds:
   - a verified proof alone: its `jkt`;
   - `dpop_jkt` alone: the parameter, which must be a 43-character base64url SHA-256 thumbprint or the push fails with `invalid_request`;
   - both: they must be equal under `hash_equals()`, or the push fails with `invalid_dpop_proof`;
   - neither: `invalid_request`.

The handler saves a `PushedAuthorizationRequest` and answers `201` with `{request_uri, expires_in}`. Errors answer the client directly in the RFC 6749 shape, `401` for `invalid_client` and `400` otherwise. Every answer carries `Cache-Control: no-store` and `Pragma: no-cache`, and an answer to a verified proof carries the next `DPoP-Nonce`. A server fault while storing the request is `503` with `Retry-After` and no nonce, as at the token endpoint. `OAuthController::par` answers a body larger than 64 KiB with `413` `invalid_request` before parsing it, and the handler refuses a `state` longer than 1024 bytes (`PushedAuthorizationRequest::MAX_STATE_BYTES`) with `invalid_request`; `chk_oauth_pushed_authorization_requests_state_length` holds the same bound in the database. The per-IP limiter `oauth_par_ip` runs before the request is read, and the per-client limiter `oauth_par_client` is charged through `OAuthClientThrottleInterface` once the client has authenticated, as at the token endpoint, so a public client cannot fill the table.

`oauth_pushed_authorization_requests` holds the client (`fk_oauth_pushed_authorization_requests_client_id`, `ON DELETE CASCADE`), the redirect URI, the scopes, `state`, the S256 challenge (`chk_oauth_pushed_authorization_requests_code_challenge_method`), the key thumbprint `dpop_jkt` (`NOT NULL`), `expires_at` and `consumed_at`. It has no user column, because the user exists only at decision time. The request URI is `urn:ietf:params:oauth:request_uri:` followed by a `TokenId`, stored as `request_id` under `uniq_oauth_pushed_authorization_requests_request_id`; a lookup fails as unknown on any other shape. The request lives for `auth.par.request_uri_ttl` seconds (300 by default), which leaves room for a sign-in with TOTP or a passkey before consent.

#### Consent

The client sends the user's browser to the consent page with only `client_id` and `request_uri`. The page passes both to two API calls, each made with the signed-in user's DPoP-bound access token. The calls read only `client_id`, `request_uri` and, for the decision, `decision`:

1. `GET /api/oauth/authorize?client_id=...&request_uri=...` looks the pushed request up without using it. `GetAuthorizationRequestHandler` answers flat JSON `{client_id, client_name, client_type, scopes, redirect_uri, consent_required}`. `scopes` are the scopes an approval grants: the pushed scopes Baander allows. `consent_required` is false only for clients with the first-party flag: the first-party client and the user's own personal access clients. The page may approve those without asking. Baander keeps no record of earlier consent, so every other client needs the user's approval each time.
2. `POST /api/oauth/authorize` with `client_id`, `request_uri` and `decision` (`approve` or `deny`), form-encoded or JSON, records the decision. `CreateAuthorizationCodeHandler` answers `{redirect_uri}`: the pushed redirect URI carrying `code`, the pushed `state`, and `iss` on approval, or `error=access_denied`, `error_description`, `state`, and `iss` on denial. The `iss` parameter (RFC 9207) lets the client detect a mix-up between authorization servers.

Neither call redirects. The page navigates to the returned `redirect_uri` itself, after checking that it keeps the scheme, host, port and path of the `redirect_uri` the lookup returned. Both calls answer with `Cache-Control: no-store` and `Pragma: no-cache`, and an unauthenticated call gets `401` `ApiError`. Like every API route other than the token, pushed authorization request and device authorization endpoints, `/api/oauth/authorize` accepts cross-origin requests only from `APP_URL`.

`AuthorizationRequestValidator` checks both calls in two stages:

1. **The pushed request.** `OAuthController` answers a missing `client_id` or `request_uri` with `invalid_request` before any handler runs. `resolvePushedRequest()` then identifies the client and loads the pushed request; an unknown or revoked client is `invalid_client`. A request URI that is malformed, unknown, pushed by another client, already used or expired is `invalid_request_uri`. These errors must not be sent to the client (RFC 6749 section 4.1.2.1). The answer is `400` with `{error, error_description}` and no `redirect_uri`, even for an unknown client, because `401` would read to the page as an expired session. The page shows the error to the user.
2. **The user.** `validate()` adds the checks that depend on the signed-in user. A personal access client serves only its owner, and anyone else gets `unauthorized_client`; a disabled or missing user gets `access_denied`. These errors answer `400` with `{error, error_description, redirect_uri}`, where `redirect_uri` is the pushed redirect URI carrying `error`, `error_description`, the pushed `state`, and `iss`; the page navigates there. They leave the pushed request unused: a reload repeats the same error redirect, and the request expires within minutes.

A denial runs both stages, as an approval does, so another user cannot use up a request for someone's personal access client by denying it: the request stays unused and the denial is redirected with `unauthorized_client`. Either decision uses the request up through `PushedAuthorizationRequestRepositoryInterface::consume()`, one conditional `UPDATE` that sets `consumed_at` only where the row is unconsumed, unexpired by `clock_timestamp()` and owned by the client. An approval runs it inside `TransactionPortInterface::run()` together with saving the code, so of two concurrent decisions one issues a code and the other gets `invalid_request_uri`.

The code is stored with its redirect URI, `code_challenge`, `code_challenge_method` and the pushed key thumbprint in `dpop_jkt` (`NOT NULL`). The schema defines `chk_oauth_auth_codes_code_challenge_method` (`code_challenge_method = 'S256'`). A code lives for `auth.auth_code.ttl` seconds (60 by default): it is created after consent, so it only has to survive the redirect and the token request.

At the token endpoint, `ExchangeAuthorizationCodeHandler` requires that the code belongs to the authenticated client, is unexpired and unused, was issued for the same redirect URI, and matches the `code_verifier`. After the PKCE check, `AuthCode::isBoundTo()` compares the token request's proof `jkt` with the stored thumbprint under `hash_equals()`, and a proof from another key gets `invalid_grant`. The handler redeems the code atomically in the transaction that stores the token pair, so concurrent redemptions yield one pair. The conditional update that uses the code up also records, in `oauth_auth_codes.chain_id`, the token chain the redemption started. When the client presents a redeemed code again, `TokenChainRevoker` revokes that chain and commits before the handler answers `invalid_grant`; the loser of a concurrent redemption revokes nothing.

### Device Authorization Grant

The device flow (RFC 8628) serves devices without a browser, such as TV apps. Only clients registered as device clients may use it.

1. The device posts `client_id` and a space-separated `scope`, form-encoded or JSON, to `POST /api/oauth/device/authorize`, which is public. The client authenticates through `OAuthClientAuthenticator::authenticate()`, with its credentials read as at the token endpoint. The RFC 8628 answer contains `device_code`, `user_code` (such as `BCDF-GHJK`), `verification_uri`, `verification_uri_complete`, `expires_in`, and `interval`, with `Cache-Control: no-store`. `verification_uri` is `auth.device.verification_uri`, by default the issuer followed by `/device`, the web app's device page; the complete URI adds `?user_code=`. A missing `client_id`, a client that is not a device client or a request with no grantable scope gets `400` (`invalid_request`, `unauthorized_client` or `invalid_scope`); an unknown or revoked client gets `401` `invalid_client`.
2. The device shows the user code and polls the token endpoint with the device code grant, no faster than `interval` seconds.
3. On the device page, the signed-in user's code is looked up with `GET /api/oauth/device/verify?user_code=...`, which answers `{data: {userCode, clientId, clientName, scopes, expiresAt}}`. `scopes` are the scopes an approval grants. The page then sends `POST /api/oauth/device/approve` with `{userCode, decision}`, where `decision` is `approve` or `deny`, and gets `{data: {decision, message}}` with `decision` `approved` or `denied`. Case, spaces, and dashes in the user code do not matter. Both endpoints answer an unusable code with a `400` `ApiError` whose `error.details.reason` is `user_code_required` (lookup only), `invalid_user_code`, or `device_already_processed`. `ApproveDeviceCodeHandler` approves with one conditional `UPDATE` that succeeds only while the code is undecided and unexpired, so of two concurrent approvals the second gets `device_already_processed` and raises no `DeviceCodeApproved`.

While the user has not decided, a poll answers `authorization_pending`. A poll sooner than the current interval answers `slow_down`, and that device code's interval grows by 5 seconds (RFC 8628 section 3.5). A denied request answers `access_denied` and an expired one `expired_token`. An approved code is redeemed once, in the transaction that stores the token pair; a second redemption gets `invalid_grant`.

Device codes live for `auth.device_code.ttl` seconds (900 by default), and the initial interval is `auth.device_code.interval` (5 seconds). Approval raises `DeviceCodeApproved`, which notifies the user as a Security event.

### Purging Expired Codes

`PurgeExpiredOAuthCodesCommand` deletes authorization codes, device codes and pushed authorization requests whose `expires_at` is more than one hour in the past, in any state. The hour keeps an expired device code long enough for a polling device to get `expired_token` instead of `invalid_grant`. Codes with a null `expires_at` never expire in the domain, so the purge keeps them; a pushed request always has an expiry. `PurgeExpiredOAuthCodesHandler` calls `deleteExpiredBefore()` on `AuthCodeRepositoryInterface`, `DeviceCodeRepositoryInterface` and `PushedAuthorizationRequestRepositoryInterface` and returns `PurgedOAuthCodesDTO` with the three counts and the cutoff. There is no index on `expires_at`: each table holds about a day of short-lived rows, so a daily scan costs less than maintaining an index on every insert.

The command implements the Scheduler's `SchedulableCommandInterface`. The initial migration seeds the daily schedule **Purge expired OAuth codes** (`30 3 * * *`, UTC), and `app:oauth:purge-codes` dispatches the same command. Because the purge keeps the tables small, the full unique index `uniq_oauth_device_codes_user_code` stays: a partial index cannot test expiry, since `now()` is not immutable, and lookups by user code rely on one row per code.

### Personal Access Clients

A signed-in user can create named clients for their own tools with `POST /api/oauth/clients/` and list them with `GET /api/oauth/clients/`; the list carries no secret. `Client::createPersonalAccess()` makes a public client owned by the user with the loopback redirect URI `http://localhost`. `DELETE /api/oauth/clients/{publicId}` works only for the owner; another user's client answers `404`. Revoking a client also revokes its access and refresh tokens, through `ClientRevoker`.

### Client Registration

Administrators manage device, public and confidential clients through the admin API (see [Admin — OAuth Clients](#admin--oauth-clients)) and the `app:oauth:client:*` console commands. Both dispatch the same messages, so the console has the same authority as a super administrator. `app:auth:setup-clients` still seeds the first-party SPA client, and users create their own personal access clients.

`ClientType` names what a client is registered for. `Client::getType()` derives it from the stored flags, in this order: `personal_access`, `first_party`, `device`, `confidential`, else `public`. `ClientType::isAdministered()` is true for `device`, `public`, and `confidential`. The rotate, key replacement and revoke handlers refuse any other client (`protected_client`). The client-management handlers raise the shared exceptions: `NotFoundException` for an unknown client (`404`), `ConflictException` (`409`) with reason `protected_client`, `no_secret`, `no_keys` or `revoked`, and `InvalidInputException` (`422`) with reason `invalid_registration` or `invalid_keys`. The reason reaches the admin API as `error.details.reason`. The list includes the first-party client and leaves out personal access clients.

`RegisterClientHandler` calls one factory per type. `Client::registerDevice()` takes a name only; a device client with redirect URIs is rejected. `Client::registerPublic()`, `Client::registerConfidential()` (with a secret) and `Client::registerConfidentialWithKeys()` (with a `ClientKeySource`) validate the redirect URIs in `Client::validRedirectUris()`:

- 1 to 10 URIs after trimming and removing duplicates, each at most 2000 characters, without whitespace or control characters;
- absolute, without a fragment, and without user name or password;
- `https` with a host; `http` only on a loopback host (`localhost`, `127.0.0.1`, `[::1]`; RFC 8252 section 7.3); or a private-use scheme in reverse domain form, containing a dot, such as `app.baander.tv:/callback` (RFC 8252 section 7.1). `javascript`, `data`, `file`, and `vbscript` are rejected.

A confidential client also has a `ClientAuthMethod`, fixed at registration: `client_secret_post` by default, or `private_key_jwt` with exactly one `ClientKeySource`. Switching between a secret and keys means registering a new client. `oauth_clients.token_endpoint_auth_method` stores the method, and the nullable `jwks` (`jsonb`) and `jwks_uri` (`text`) columns hold the key source. Check constraints tie them together: a client has a secret hash exactly when its method is `client_secret_post`, exactly one key source exactly when it is `private_key_jwt`, and is confidential exactly when it is not `none`. An inline set holds at most 10 public asymmetric keys in at most 64 KB, each with its own `kid` when there are several, and an `OKP` key names `Ed25519`. A `jwks_uri` passes the syntactic half of the outbound fetch policy when it is given: `https` on port 443 and a dotted DNS name, with no IP literal, single label, trailing dot, credentials, fragment, whitespace or backslash. Registration answers a failure with `invalid_registration` and key replacement with `invalid_keys`.

A client name is trimmed and at most 100 characters. A rejected registration raises `InvalidInputException` with reason `invalid_registration`, which the admin API answers with `422`.

`ClientSecret` generates a confidential client's secret: 32 random bytes, base64url-encoded without padding (43 characters). The client stores only the lower-case hex SHA-256 digest, in `oauth_clients.secret_hash`. `OAuthClientAuthenticator` checks a presented `client_secret` through `Client::authenticatesWith()`, which compares digests in constant time with `hash_equals()`. A fast hash is enough here: a secret with 256 random bits cannot be guessed, so a slow password hash would add no resistance, only CPU cost on every token request. The plain secret exists only in `RegisteredClientDTO`, between generation and the one response or command output that shows it; that response carries `Cache-Control: no-store`. `RotateClientSecretHandler` refuses a client without a secret (`no_secret`) or a revoked one (`revoked`), both `409` in the admin API. The new secret takes effect at once, and tokens already issued stay valid.

`ReplaceClientKeysHandler` gives a `private_key_jwt` client a new inline JWK Set or `jwks_uri`, and may switch between the two, since the method stays the same. It refuses a client without keys (`no_keys`) or a revoked one (`revoked`) with `409`, and invalid keys (`invalid_keys`) with `422`. `Client::replaceKeys()` also sets the client's `updated_at`, so `ClientKeySetProvider` stops using a cached set at once. Replacing a `jwks_uri` with the same URI is accepted, and it is how an operator withdraws a key at once: a key removed from the published set otherwise stays usable from the cache until `auth.client_jwks.cache_ttl` expires, because only an unknown `kid` triggers a refetch.

`ClientRevoker` revokes a client and every access and refresh token issued to it in one transaction, for both `RevokeRegisteredClientHandler` and the personal access `RevokeClientHandler`. The client flag alone is not enough, because the resource server checks each access token's own revocation flag. Issuance takes a shared lock on the client row, so revocation and issuance cannot interleave. Revoking a revoked client succeeds again. A revoked client fails `OAuthClientAuthenticator` like an unknown one.

### Passkey Login

1. `POST /api/auth/passkey/authenticate/options` returns `{challengeKey, options}`. Pass `userId` to restrict the allowed credentials.
2. The client runs `navigator.credentials.get()` with `options`.
3. `POST /api/auth/login/passkey` with `{challengeKey, response, userId?}` and a `DPoP` header.

`PasskeyAuthenticator` runs on the API firewall. It checks the DPoP proof with `DpopTokenRequestVerifier` before the WebAuthn assertion, so a nonce challenge leaves the assertion valid for a retry. Disabled accounts are rejected. On success it stores `VerifiedPasskeyLogin` (user, proof key thumbprint, next nonce) on the request, and `PasskeyLoginController` issues tokens only from it.

`PasskeyService` keeps ceremony options in `PasskeyChallengeStoreInterface` and takes them with `consumeRegistrationChallenge(user, key)` or `consumeLoginChallenge(key)`, so each challenge works once and only for the ceremony it was issued for. There is no in-memory fallback: while Redis is unavailable, the options and verification routes and `/api/auth/login/passkey` answer `503` with `Retry-After`. An account with TOTP enabled (`User::hasTotpEnabled()`) refuses an assertion without the user-verified flag, at login and at `POST /api/auth/passkey/authenticate`; the flag is checked after the signature verifies, so a refusal does not reveal the account's TOTP status. `auth.passkey.user_verification` stays `preferred`, so accounts without TOTP keep security keys that have no PIN. `AuthenticatePasskeyHandler` runs the clone check only when the stored or the presented signature counter is non-zero, so an authenticator that always reports 0 can sign in.

### Client Fingerprint Binding

Password login, passkey login, and every grant at the token endpoint accept an optional `X-Baander-Client-Fingerprint` header. When present, it is stored in `oauth_token_metadata` and binds the access token: `OAuth2Authenticator` rejects any request with that token whose header is missing or different (`401`, `AUTH_INVALID_TOKEN`). If the metadata lookup fails, the request is rejected. Refresh copies the binding to the new token. Tokens issued without the header are unbound and ignore it. No first-party client sends the header today; `POST /api/ws/tickets` runs under the `api` firewall (`OAuth2Authenticator` + `DpopBindingListener`), so client fingerprint checks apply before a WebSocket ticket is issued, and `/api/ws?ticket=...` redeems the single-use ticket through `WsTicketAuthenticator`.

## Scope Enforcement

`OAuth2Authenticator` sets three request attributes from the access token it accepts, named in `AccessTokenAttributes`: the client's public ID, a first-party marker and the token's scopes. A token is first-party only when its client is the client registered under `auth.spa_client_id` with `ClientType::FirstParty`; a personal access client, a `first_party` client under another ID and every other client are delegated. A delegated token authenticates its user with `ROLE_USER` alone, so admin checks in voters and controllers deny it, and a first-party token carries an empty scope list. A request without the first-party marker counts as delegated with no scopes.

`ScopeEnforcementListener` runs on the `api` firewall's login success, before access control, so a delegated token on an admin route is refused for its scope rather than its role. A first-party token passes. Any other authenticated request reaches only the routes `ScopeRouteMap` lists as public or maps to one of its scopes, and is otherwise answered `403` with `{"error": {"message": ..., "code": "insufficient_scope"}}` and `WWW-Authenticate: DPoP error="insufficient_scope"` (RFC 6750 section 3.1); neither access control nor the controller runs. `ScopeRouteMap` names every route in exactly one of three lists: public routes, first-party routes, and scoped routes with the scopes that reach them (`profile` and `email` for `GET /api/auth/me`; `library` for browsing, streaming, playback, transcoding, devices, favorites, history, recommendations and radio; `playlist` for playlists). `ScopeRouteInventoryTest` loads the routes without compiling the container and fails on an unclassified route, a stale or duplicate entry, an unknown scope, or a public or scoped route that requires an admin role.

`GET /api/auth/me` answers a delegated token through `UserResource::forDelegatedToken()`, without `roles`, `totpEnabled` and `pendingEmail`, and without `email` and `emailVerifiedAt` unless the token holds `email`. `LibraryReadScopeProvider::forUser()` uses the request token's admin authority when the request is the user's own, so an administrator's delegated `library` token cannot reach other libraries through delivery decisions or playback.

## Sign-in and Account Protection

**Password login.** `PasswordAuthenticator` checks the DPoP proof and settles the next nonce before reading credentials. An unknown address is checked against a dummy hash made once per worker with the configured hasher, so it costs what a real account costs, and a disabled account is reported only after its password matches; unknown, disabled and wrong-password accounts otherwise get the same `401` `AUTH_INVALID_CREDENTIALS`. Each rejected password or TOTP code is charged through `LoginFailureThrottleInterface` to the normalized address, whether or not an account holds it; nonce challenges, the TOTP prompt, a disabled account with the right password and a success are not. An address over its limit gets `429` with `Retry-After` before any account is looked up.

**TOTP.** `users.totp_secret_ciphertext` holds the secret sealed by `TotpSecretCipherInterface` with libsodium secretbox under `AUTH_TOTP_ENCRYPTION_KEY`, a dedicated 32-byte key, so rotating `APP_SECRET` leaves TOTP working. The verifier checks the current step and `auth.totp.window` steps on either side and returns the matched step. A step is spent by one conditional `UPDATE` of `users.totp_last_used_step` that succeeds only for a later step, so a code counts once, also under concurrent requests; password login spends it in the transaction that stores the tokens, and a lost race rolls the pair back. An empty `totpCode` counts as absent (`AUTH_TOTP_REQUIRED`). A secret that does not decrypt raises `TotpSecretUnavailableException`: login answers `AUTH_TOTP_UNAVAILABLE` and logs a warning, and it never counts as TOTP being off. `StartTotpSetupHandler` refuses while TOTP is on and holds the new secret in `PendingTotpSecrets` for 10 minutes, each setup replacing the last; enable spends the confirming code's step. `ResetUserTotpHandler` drops the stored secret without reading it, clears the spent step, and revokes every token of the user in one transaction.

**Step-up.** `StepUpVerifier` serves `ChangeOwnEmailHandler`, `StartTotpSetupHandler` and passkey registration options. It charges `StepUpAttemptThrottleInterface` first (`auth_step_up_user`, 10 per 15 minutes by default, per account from any address), then checks the current password and, when TOTP is on, spends the code's step; a wrong password never reaches the code check. Every mismatch raises `StepUpFailedException` (`422`, `details.reason` `step_up_failed`), and the limit raises `StepUpRateLimitedException` (`429` with `Retry-After`). TOTP setup asks for the password only, because TOTP is off whenever it runs.

**Registration.** `RegisterUserHandler` answers `202` with one message whether or not the address is taken. For a taken address it hashes the password as for a new one, creates nothing, and `RegistrationAttemptNoticeInterface` emails the owner after the response, with a link to the forgot-password page.

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
| `RateLimitListener` | Security | Per-IP, per-IP-and-email and per-refresh-token limits for the auth and OAuth endpoints, before authentication (see [Rate limiting](../../part-1-operator-guide/configuration/authentication-and-limits.md#rate-limiting)) |
| `ScopeEnforcementListener` | Security | Answers a delegated token on a route `ScopeRouteMap` does not grant it with `403` `insufficient_scope` |
| `RateLimiterLoginFailureThrottle`, `RateLimiterStepUpAttemptThrottle`, `RateLimiterOAuthClientThrottle` | Security | The per-account login failure, per-account step-up and per-client OAuth limits, charged from inside the authenticator and the handlers |
| `SodiumTotpSecretCipher` | Security | `TotpSecretCipherInterface` on libsodium secretbox under `AUTH_TOTP_ENCRYPTION_KEY` |
| `RedisPasskeyChallengeStore` | Cache | `PasskeyChallengeStoreInterface` on Redis: `SET NX EX 300` to store, `GETDEL` to take |
| Doctrine entities | ORM | Persistence for all models |
| Doctrine repositories | ORM | Repository implementations for all aggregates |
| Voter classes | Security | Authorization checks for protected resources; `UserManagementVoter` applies the user management settings |
| `AfterResponseMailer` | Mail | Sends credential emails after the response, in the recipient's email language |

See the [Architecture](../architecture/overview.md#anti-corruption-layer) page for details on the League anti-corruption layer.
