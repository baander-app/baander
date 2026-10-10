# Auth

The Auth context signs users in, issues their tokens, and validates those tokens on every request. Baander's own apps get tokens from password login (with optional TOTP), passkey login (WebAuthn), and refresh. Other clients use the OAuth 2.0 authorization server: the authorization code grant with mandatory S256 PKCE, the device authorization grant (RFC 8628), and the refresh token grant at `POST /api/oauth/token`. Every token pair, whichever path issues it, is bound to the client's DPoP key, and refresh tokens rotate. Auth implements the authorization server itself; it uses League OAuth2 Server only to validate access tokens, behind an anti-corruption layer. There is no client credentials grant.

## Domain Models

### Aggregate Roots

| Model | Purpose |
|-------|---------|
| `User` | User account |
| `Client` | OAuth 2.0 client application |
| `AccessToken` | Issued access token |
| `RefreshToken` | Issued refresh token |
| `AuthCode` | Single-use authorization code, bound to its redirect URI and S256 code challenge |
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
| `DpopValidationResult` | Result of DPoP header validation |

## Commands & Handlers

Commands and handlers are organized into feature namespaces under `Application/Command/` and `Application/CommandHandler/`: `OAuth/`, `Passkey/`, `Totp/`, and `User/`. Password login is checked by `PasswordAuthenticator` on the API firewall, not by a command. Five application services under `Application/Service/` are shared by the OAuth handlers: `TokenPairIssuer` mints every new token pair, `OAuthClientAuthenticator` identifies and authenticates the client of an OAuth request, `PendingDeviceCodeFinder` resolves a user code as the user typed it, `AuthorizationRequestValidator` checks an authorization request for the signed-in user, and `ClientRevoker` revokes a client together with its tokens.

| Command | Handler | Purpose |
|---------|---------|---------|
| `RegisterUserCommand` | `RegisterUserHandler` | Self-registration; seeds the email language from the browser (User/) |
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
| `CreateAuthorizationCodeCommand` | `CreateAuthorizationCodeHandler` | Record the signed-in user's decision on an authorization request: a code on approval, `access_denied` on denial (OAuth/) |
| `ExchangeAuthorizationCodeCommand` | `ExchangeAuthorizationCodeHandler` | Authorization code grant at the token endpoint (OAuth/) |
| `RequestDeviceAuthorizationCommand` | `RequestDeviceAuthorizationHandler` | Start the device flow for a device client (OAuth/) |
| `ExchangeDeviceCodeCommand` | `ExchangeDeviceCodeHandler` | Device code grant: answer a poll or issue the token pair (OAuth/) |
| `ApproveDeviceCodeCommand` | `ApproveDeviceCodeHandler` | The signed-in user approves a device request (OAuth/) |
| `DenyDeviceCodeCommand` | `DenyDeviceCodeHandler` | The signed-in user denies a device request (OAuth/) |
| `ExchangeRefreshTokenCommand` | `ExchangeRefreshTokenHandler` | Refresh token grant at the token endpoint, through `RefreshTokenHandler` (OAuth/) |
| `CreatePersonalAccessClientCommand` | `CreatePersonalAccessClientHandler` | Create a personal access client for the current user (OAuth/) |
| `RevokeClientCommand` | `RevokeClientHandler` | Revoke the user's personal access client and every token issued to it (OAuth/) |
| `RegisterClientCommand` | `RegisterClientHandler` | Register a device, public or confidential client for the admin API and `app:oauth:client:create` (OAuth/) |
| `RotateClientSecretCommand` | `RotateClientSecretHandler` | Give a confidential client a new secret for the admin API and `app:oauth:client:rotate-secret` (OAuth/) |
| `RevokeRegisteredClientCommand` | `RevokeRegisteredClientHandler` | Revoke a device, public or confidential client and its tokens for the admin API and `app:oauth:client:revoke` (OAuth/) |
| `RegisterPasskeyCommand` | `RegisterPasskeyHandler` | Add a WebAuthn passkey (Passkey/) |
| `AuthenticatePasskeyCommand` | `AuthenticatePasskeyHandler` | Verify a WebAuthn assertion during passkey login (Passkey/) |
| `VerifyEmailCommand` | `VerifyEmailHandler` | Redeem a verification token and mark the address verified (User/) |
| `ResendEmailVerificationCommand` | `ResendEmailVerificationHandler` | Send a signed-in user a new verification link (User/) |
| `ChangeEmailCommand` | `ChangeEmailHandler` | Every email change: the user's own, the admin panel's and `app:user:change-email` (User/) |
| `EnableTotpCommand` | `EnableTotpHandler` | Enable TOTP 2FA (Totp/) |
| `DisableTotpCommand` | `DisableTotpHandler` | Disable TOTP 2FA (Totp/) |

Four queries serve the OAuth endpoints. `GetAuthorizationRequestQuery` (`GetAuthorizationRequestHandler`) checks an authorization request and describes it for the consent page. `GetDeviceAuthorizationQuery` (`GetDeviceAuthorizationHandler`) shows the user which client a user code belongs to. `ListPersonalAccessClientsQuery` (`ListPersonalAccessClientsHandler`) lists the current user's personal access clients, and `ListRegisteredClientsQuery` (`ListRegisteredClientsHandler`) lists every client except personal access clients for the admin API and `app:oauth:client:list`.

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
| `PasswordResetDeliveryInterface` | Emails the reset link after the response is sent; never persists or logs the token |
| `DpopJtiCacheInterface` | DPoP replay protection |
| `AuthenticatedUserIdentityInterface` | Identity of the user a login authenticator verified |
| `EmailVerificationTokenRepositoryInterface` | Each user's single outstanding verification token, stored as a SHA-256 hash with the address it verifies and redeemed once |
| `EmailVerificationDeliveryInterface` | Emails the verification link after the response is sent; never persists or logs the token |
| `EmailVerificationResendThrottleInterface` | Per-user limit on verification resend requests |
| `OAuthSecretBundleInterface` | Prepare and validate key bundles for `app:auth:rotate-secrets` |
| `OAuthTokenInvalidatorInterface` | Delete all token rows during offline key rotation |

## Password Reset and Password Changes

`password_reset_tokens` holds at most one token per user, keyed by `user_id` with `ON DELETE CASCADE`. Only the SHA-256 hash of the 256-bit token is stored. `UserRepository::save` removes the token whenever the stored email address or password hash changes, so a token never outlives the credentials it was issued for or carries over to a later account with the same address. The lifetime comes from `PASSWORD_RESET_EXPIRE` (minutes).

`RequestPasswordResetHandler` charges the per-account limiter before looking up the address and issues a token only for an existing account; the endpoint answers the same way in every case. After issuing the token, the handler passes it to the `PasswordResetDeliveryInterface` port. The raw token exists only in the handler's memory and in that delivery. Do not put it in a domain event, because events are stored in the outbox.

`MailerPasswordResetDelivery` implements the port. It builds the link `APP_URL/reset-password#token=…` and queues the email on `AfterResponseMailer`, which waits for `kernel.terminate`, which the Swoole server and PHP-FPM both dispatch after the response has gone out. Sending inside the request would make the request slower for real accounts than for unknown addresses, and that difference would reveal which addresses have accounts. Pending emails are held in a `WeakMap` keyed by the main request, so concurrent coroutines in one worker do not send each other's mail. Without a current request (a console command or a worker), it sends at once.

Sending is shared with the verification email through `AfterResponseMailer`, which queues a `CredentialEmail` and sends it. The `CredentialEmail` carries the user ID, not a language. When `AfterResponseMailer` sends the email, it asks `UserSettingsContractInterface::resolveLanguage()` for the recipient's [email language](#email-language) and passes that locale explicitly to the translator and the templates; it never changes the shared translator's locale. Resolving the language at send time keeps the per-account lookup out of the request, so a reset request for a real account still takes as long as one for an unknown address. If the lookup fails, the email is sent in English and the failure is logged with the exception class only.

The mailer calls the mailer transport directly rather than `MailerInterface`. `MailerInterface` dispatches a `SendEmailMessage` on the message bus, and a later routing change could put the token into Redis or the `failed_messages` table. Notification email (`SendEmailCommand` on `swoole_task`) can be retried from those stores; a reset email cannot, and does not need to be, because the user can ask for a new link. A failed send is logged with the user ID and exception class only, since transport errors can quote the recipient. The templates are `templates/email/auth/password_reset.{txt,html}.twig`, translated through the `password_reset_email` keys in the `auth` domain.

The web pages are `/forgot-password` and `/reset-password` in `ui/web/src/features/auth/`. The reset page reads the token from the fragment, removes the fragment from the address bar, and clears the local session after a successful reset, because the server has revoked it.

All three password paths (`ResetPasswordHandler`, `SetUserPasswordHandler`, `ChangePasswordHandler`) call the application service `PasswordChanger`. It enforces the 8 to 255 character policy, hashes the password, and then, in one transaction, saves the user, revokes the user's access and refresh tokens and records `PasswordChanged`. A user's own change keeps the session that made it: the presenting access token and its refresh chain. The other two paths revoke everything. A reset token that is unknown, expired, already used, or belongs to a disabled account gets one generic `400`.

## Email Verification

`email_verification_tokens` follows the design of `password_reset_tokens`. It holds at most one token per user, keyed by `user_id` with `ON DELETE CASCADE`, and stores only the SHA-256 hash of the 256-bit token together with the `email` (`CITEXT`) the token was issued for. `UserRepository::save` removes the token when the stored email address changes. The lifetime is `auth.email_verification_token.ttl` (seconds).

Redemption is a single `DELETE … RETURNING`, so a token works once even under concurrent requests. `VerifyEmailHandler` runs that statement in the same transaction that marks the address verified and records `EmailVerified`; if the commit fails, the token stays redeemable. The handler accepts the token only while the account still has the address the token was issued for and is not disabled. Every other case, including an unknown, expired or used token, gets one generic `400`.

`EmailVerificationIssuer` is the single place tokens are issued. Its `issue()` stores the hash for the user's current address inside the caller's transaction and returns the raw token. It issues nothing for a verified address or a disabled account. Its `deliver()` hands the token to `EmailVerificationDeliveryInterface` after the commit, so a rolled-back registration or email change never emails a link. Three callers use it. `RegisterUserHandler` issues on registration. `ChangeEmailHandler` issues on every email change: `PUT /api/auth/me/email`, `PATCH /api/admin/users/{id}` and `app:user:change-email` all dispatch `ChangeEmailCommand`. `ResendEmailVerificationHandler` serves `POST /api/auth/me/email/verification`. The resend endpoint answers the same `200` whether it sent an email or not; over the per-user limit (`auth_email_verification_user`) it sends nothing. Redemption and resend requests share the per-IP limit `auth_email_verification_ip`, which answers `429` with `Retry-After`.

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

## API Endpoints

All endpoints except the two `/.well-known/` documents are under `/api`.

| Method | Path | Purpose |
|--------|------|---------|
| POST | `/api/auth/register` | Self-registration |
| POST | `/api/auth/login` | Password login (issues tokens) |
| POST | `/api/auth/login/passkey` | Passkey login (issues tokens) |
| POST | `/api/auth/refresh` | Refresh token rotation (issues tokens) |
| POST | `/api/auth/logout` | Revoke the current access token |
| POST | `/api/auth/password/reset-request` | Issue a password reset token; always answers `200` |
| POST | `/api/auth/password/reset` | Redeem a reset token and set a new password |
| POST | `/api/auth/email/verify` | Redeem a verification token; one generic `400` for every unusable token |
| POST | `/api/auth/me/email/verification` | Send the current user a new verification link; always answers `200` |
| GET, PUT | `/api/auth/me` | Read or update the current user |
| PUT | `/api/auth/me/email` | Change email; the new address is unverified and is sent a link |
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
| GET | `/api/oauth/authorize` | Check an authorization request for the consent page (authorization code with S256 PKCE) |
| POST | `/api/oauth/authorize` | Record the signed-in user's decision and answer the redirect URI |
| POST | `/api/oauth/token` | Token endpoint for the authorization code, device code, and refresh token grants (public; DPoP proof required) |
| POST | `/api/oauth/revoke` | Token revocation (RFC 7009) |
| POST | `/api/oauth/device/authorize` | Device authorization request (RFC 8628; public) |
| GET | `/api/oauth/device/verify` | Show the signed-in user the pending request behind a user code (device page) |
| POST | `/api/oauth/device/approve` | Approve or deny a device request |
| GET, POST | `/api/oauth/clients/` | List or create the current user's personal access clients |
| DELETE | `/api/oauth/clients/{publicId}` | Revoke one of the current user's clients and its tokens |
| GET | `/.well-known/oauth-authorization-server` | Authorization server metadata (RFC 8414) |
| GET | `/.well-known/jwks.json` | JSON Web Key Set for verifying access tokens |

### Admin — User Management

All routes under `/api/admin/users` (controller `AdminUserController`, gated `ROLE_ADMIN`). Listing needs `USER_MANAGEMENT_LIST` and creating needs `USER_MANAGEMENT_CREATE`; every other route requires `ROLE_SUPER_ADMIN`. `UserManagementVoter` grants both attributes to super administrators. It grants them to other administrators only while the system settings `admin.can_view_users` and `admin.can_create_users` are on, reading them on every vote, and grants creation only when every requested role is `ROLE_USER`. The console commands are not gated by these settings.

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/api/admin/users` | List users (paginated, filter by role/disabled) |
| POST | `/api/admin/users` | Create a user |
| PATCH | `/api/admin/users/{id}` | Update a user's name or email (email CLI: `app:user:change-email`) |
| DELETE | `/api/admin/users/{id}` | Delete a user |
| POST | `/api/admin/users/{id}/roles` | Assign roles |
| POST | `/api/admin/users/{id}/reset-password` | Reset a user's password and sign them out (CLI: `app:user:reset-password`) |
| POST | `/api/admin/users/{id}/disable` | Disable a user |
| POST | `/api/admin/users/{id}/enable` | Enable a user |

### Admin — User Settings

Routes under `/api/admin/users/{id}/settings` (controller `AdminUserSettingsController`). `{id}` is the user's UUID; any other value is `404`.

| Method | Path | Role | Purpose |
|--------|------|------|---------|
| GET | `/api/admin/users/{id}/settings` | `ROLE_ADMIN` | List the user's settings, with a stored value that is no longer allowed marked `storedValueValid: false` |
| PUT | `/api/admin/users/{id}/settings/{key}` | `ROLE_SUPER_ADMIN` | Set the user's choice (body `{"value": …}`), for every user setting, including ones users cannot change themselves; `422` for an invalid value |
| DELETE | `/api/admin/users/{id}/settings/{key}` | `ROLE_SUPER_ADMIN` | Remove the user's choice |

`app:user:setting get|set|reset <identifier> [key] [value]` is the console counterpart; it accepts an email address or UUID. The endpoints and the command share the application service `AdminUserSettings`, which resolves the user with `UserLookup`, calls `UserSettingsContractInterface`, and logs every set and reset at info level with `actor_id` (the signed-in administrator, or `cli`), `target_id`, `key`, `old_value` and `new_value`. The logged values are the stored ones; `null` means no choice.

Deleting a user deletes their access tokens (`fk_oauth_access_tokens_user_id` is `ON DELETE CASCADE`), and the existing cascades from `oauth_access_tokens` then delete the matching refresh tokens and token metadata. The user's authorization codes and device codes are deleted with them as well (`Version20261006300000` made `fk_oauth_device_codes_user_id` cascade; authorization codes always did).

### Admin — OAuth Clients

All routes under `/api/admin/oauth/clients` (controller `AdminOAuthClientController`, gated `ROLE_ADMIN`; mutating routes require `ROLE_SUPER_ADMIN`). Each has an `app:oauth:client:*` command that dispatches the same message (see [Client Registration](#client-registration)).

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/api/admin/oauth/clients` | List every client except personal access clients, revoked ones included (CLI: `app:oauth:client:list`) |
| POST | `/api/admin/oauth/clients` | Register a device, public or confidential client; a confidential client's secret is in this response only (CLI: `app:oauth:client:create`) |
| POST | `/api/admin/oauth/clients/{clientId}/rotate-secret` | Give a confidential client a new secret (CLI: `app:oauth:client:rotate-secret`) |
| POST | `/api/admin/oauth/clients/{clientId}/revoke` | Revoke a client and its access and refresh tokens (CLI: `app:oauth:client:revoke`) |

### Admin — Login Blocks

All routes under `/api/admin/login-blocks` (controller `AdminLoginBlockController`, gated `ROLE_ADMIN`; deletes require `ROLE_SUPER_ADMIN`).

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/api/admin/login-blocks` | List recent honeypot blocks (paginated) |
| DELETE | `/api/admin/login-blocks/{id}` | Delete a single block |
| DELETE | `/api/admin/login-blocks` | Delete all blocks |

## Token Issuance

Four endpoints issue tokens. Baander's own apps use `POST /api/auth/login`, `POST /api/auth/login/passkey`, and `POST /api/auth/refresh`; other clients use `POST /api/oauth/token`. Login dispatches `IssueTokenCommand` for the SPA client named by `auth.spa_client_id`, and first-party refresh dispatches `RefreshTokenCommand`. The token endpoint dispatches one command per grant (see [Token Endpoint](#token-endpoint)).

Every new token pair comes from `TokenPairIssuer`, whether password login, passkey login, the authorization code grant, or the device code grant asked for it. It stores a DPoP-bound access token and a refresh token that starts a new rotation chain, with both rows written in one transaction. A grant can pass a further write that commits or rolls back with the tokens; the code grants use it to redeem their code. The refresh token is a plain opaque identifier; neither League encryption nor an `OAUTH_ENCRYPTION_KEY` is involved. Requested scopes are filtered against `auth.scopes.user_grants`, and a request for no scopes gets the default scopes.

Rotation happens in `RefreshTokenHandler` for both refresh paths. It consumes the old refresh token atomically, issues the new pair in the same chain, and carries the DPoP key and the client fingerprint forward. A replayed refresh token revokes its chain. Tokens of a revoked client cannot be refreshed.

The first-party endpoints wrap `accessToken`, `tokenType` (`DPoP`), `expiresIn`, and `refreshToken` in `data`; login responses also include `user`. The token endpoint answers in the RFC 6749 format instead: a flat object with `access_token`, `token_type` (`DPoP`), `expires_in`, `refresh_token`, and `scope`, built by `OAuthTokenResource`.

### DPoP

All four endpoints require a `DPoP` header carrying a proof (RFC 9449) with a server-issued nonce. A proof without a valid nonce gets `400` with `{"error": "use_dpop_nonce"}` and a `DPoP-Nonce` header; the client retries with a new proof that carries that nonce. Each nonce works once. A successful response returns a fresh `DPoP-Nonce` for the client's next proof. At the first-party endpoints a missing `DPoP` header returns a `400` `ApiError`, and an invalid proof gets the `use_dpop_nonce` answer.

At the token endpoint, `TokenEndpointDpopListener` checks the proof before any grant runs, through `DpopTokenRequestVerifier::inspect()`, and stores a valid one on the request as `VerifiedDpopProof`. A rejected proof comes back as a `DpopProofRejection`, which the listener answers in RFC 9449 terms: `400` `use_dpop_nonce` when the proof lacks a fresh nonce, and `400` `invalid_dpop_proof` when the proof is missing or invalid, each with `error_description`, a `DPoP-Nonce` header, `Cache-Control: no-store` and `Pragma: no-cache`. Because a polling device spends a nonce on every request, every answer the controller gives, OAuth errors included, carries the next `DPoP-Nonce`.

Tokens are bound to the proof key: the access token JWT carries `cnf.jkt`, and the thumbprint is stored with the token (`dpop_jkt`). Refresh requires a proof signed by the same key, and the new pair keeps the binding. `DpopBindingListener` requires a matching proof on every API request made with the token, except signed stream delivery URLs.

### Token Endpoint

`POST /api/oauth/token` is public: the client and the grant identify the caller, not a user token. Parameters may be form-encoded (RFC 6749) or a JSON object. `grant_type` selects the command:

| `grant_type` | Command | Required parameters |
|--------------|---------|---------------------|
| `authorization_code` | `ExchangeAuthorizationCodeCommand` | `code`, `code_verifier`, and `redirect_uri` unless the client registered exactly one |
| `urn:ietf:params:oauth:grant-type:device_code` | `ExchangeDeviceCodeCommand` | `device_code` |
| `refresh_token` | `ExchangeRefreshTokenCommand` | `refresh_token` |

`OAuthClientAuthenticator` authenticates the client by `client_id`. A public client sends only its ID (`none`); a confidential client also sends `client_secret` (`client_secret_post`). Unknown, malformed, and revoked clients all fail with `401` `invalid_client`, so the answer does not reveal which it was.

Errors follow RFC 6749: `{"error": "...", "error_description": "..."}`, with `400` except for `invalid_client`, which is `401`. Every controller answer, success or error, carries `Cache-Control: no-store`, `Pragma: no-cache`, and the next `DPoP-Nonce`. At this endpoint a refresh token must belong to the authenticated client; `ExchangeRefreshTokenHandler` reports every refresh rejection as `invalid_grant` without saying why.

### Authorization Code Grant

A browser redirect cannot carry the user's DPoP-bound access token, so the authorization endpoint is a page of the web app, not an API route. The RFC 8414 metadata advertises `auth.oauth.authorization_page_uri` (by default `APP_URL/oauth/authorize`) as `authorization_endpoint`. The client sends the user's browser there with the usual query parameters. The consent page passes them on to two API calls, each made with the signed-in user's DPoP-bound access token:

1. `GET /api/oauth/authorize` with the parameters in the query string checks the request and describes it. `GetAuthorizationRequestHandler` answers flat JSON `{client_id, client_name, client_type, scopes, redirect_uri, consent_required}`. `scopes` are the scopes an approval grants: the requested scopes Baander allows, or the default scopes when none are left. `consent_required` is false only for clients with the first-party flag: the first-party client and the user's own personal access clients. The page may approve those without asking. A personal access client serves only its owner; anyone else gets `unauthorized_client` at the redirect URI. Baander keeps no record of earlier consent, so every other client needs the user's approval each time.
2. `POST /api/oauth/authorize` with the same parameters plus `decision` (`approve` or `deny`), form-encoded or JSON, records the decision. `CreateAuthorizationCodeHandler` answers `{redirect_uri}`: the client's redirect URI carrying `code`, `state`, and `iss` on approval, or `error=access_denied`, `error_description`, `state`, and `iss` on denial. The `iss` parameter (RFC 9207) lets the client detect a mix-up between authorization servers. A denial checks only the client and redirect URI.

Neither call redirects; the page navigates to the returned `redirect_uri` itself. Both carry `Cache-Control: no-store` and `Pragma: no-cache`, and an unauthenticated call gets `401` `ApiError`. Like every API route other than the token and device authorization endpoints, `/api/oauth/authorize` accepts cross-origin requests only from `APP_URL`.

`AuthorizationRequestValidator` checks the request for both calls, in two stages:

1. **Client and redirect URI.** The client must exist and not be revoked. The `redirect_uri` must match a registered URI exactly; a loopback URI (`http` on `localhost`, `127.0.0.1`, or `[::1]`) also matches on any port, because native apps listen on an ephemeral one (RFC 8252 section 7.3). The parameter may be omitted only when the client registered exactly one URI. Until both are valid, an error must not be sent to the client (RFC 6749 section 4.1.2.1). The answer is `400` with `{error, error_description}` and no `redirect_uri`, even for an unknown client, because `401` would read to the page as an expired session. The page shows the error to the user.
2. **Everything else.** `response_type` must be `code`. Device clients are refused (`unauthorized_client`). PKCE is required for every client: `code_challenge` must be a base64url SHA-256 digest and `code_challenge_method` must be `S256`; `plain` and a missing method are rejected. A disabled or missing user gets `access_denied`. These errors answer `400` with `{error, error_description, redirect_uri}`, where `redirect_uri` is the client's redirect URI carrying `error`, `error_description`, `state`, and `iss`; the page navigates there.

The code is stored with its redirect URI, `code_challenge`, and `code_challenge_method`. `Version20261006350000` makes the challenge columns `NOT NULL` and adds `chk_oauth_auth_codes_code_challenge_method` (`code_challenge_method = 'S256'`). A code lives for `auth.auth_code.ttl` seconds (600 by default).

At the token endpoint, `ExchangeAuthorizationCodeHandler` requires that the code belongs to the authenticated client, is unexpired and unused, was issued for the same redirect URI, and matches the `code_verifier`. It redeems the code atomically in the transaction that stores the token pair, so concurrent redemptions yield one pair.

### Device Authorization Grant

The device flow (RFC 8628) serves devices without a browser, such as TV apps. Only clients registered as device clients may use it.

1. The device posts `client_id` and an optional space-separated `scope`, form-encoded or JSON, to `POST /api/oauth/device/authorize`, which is public. The RFC 8628 answer contains `device_code`, `user_code` (such as `BCDF-GHJK`), `verification_uri`, `verification_uri_complete`, `expires_in`, and `interval`, with `Cache-Control: no-store`. `verification_uri` is `auth.device.verification_uri`, by default the issuer followed by `/device`, the web app's device page; the complete URI adds `?user_code=`. A missing `client_id` or a client that is not a device client gets `400` (`invalid_request` or `unauthorized_client`); an unknown or revoked client gets `401` `invalid_client`.
2. The device shows the user code and polls the token endpoint with the device code grant, no faster than `interval` seconds.
3. On the device page, the signed-in user's code is looked up with `GET /api/oauth/device/verify?user_code=...`, which answers `{data: {userCode, clientId, clientName, scopes, expiresAt}}`. `scopes` are the scopes an approval grants, the default scopes when the request named none that Baander allows. The page then sends `POST /api/oauth/device/approve` with `{userCode, decision}`, where `decision` is `approve` or `deny`, and gets `{data: {decision, message}}` with `decision` `approved` or `denied`. Case, spaces, and dashes in the user code do not matter. Both endpoints answer an unusable code with a `400` `ApiError` whose `error.details.reason` is `user_code_required` (lookup only), `invalid_user_code`, or `device_already_processed`.

While the user has not decided, a poll answers `authorization_pending`. A poll sooner than the current interval answers `slow_down`, and that device code's interval grows by 5 seconds (RFC 8628 section 3.5). A denied request answers `access_denied` and an expired one `expired_token`. An approved code is redeemed once, in the transaction that stores the token pair; a second redemption gets `invalid_grant`.

Device codes live for `auth.device_code.ttl` seconds (900 by default), and the initial interval is `auth.device_code.interval` (5 seconds). Approval raises `DeviceCodeApproved`, which notifies the user as a Security event.

### Purging Expired Codes

`PurgeExpiredOAuthCodesCommand` deletes authorization codes and device codes whose `expires_at` is more than one hour in the past, in any state. The hour keeps an expired device code long enough for a polling device to get `expired_token` instead of `invalid_grant`. Codes with a null `expires_at` never expire in the domain, so the purge keeps them. `PurgeExpiredOAuthCodesHandler` calls `deleteExpiredBefore()` on `AuthCodeRepositoryInterface` and `DeviceCodeRepositoryInterface` and returns `PurgedOAuthCodesDTO` with both counts and the cutoff. There is no index on `expires_at`: each table holds about a day of short-lived rows, so a daily scan costs less than maintaining an index on every insert.

The command implements the Scheduler's `SchedulableCommandInterface`. `Version20261007100000` seeds the daily schedule **Purge expired OAuth codes** (`30 3 * * *`, UTC), and `app:oauth:purge-codes` dispatches the same command. Because the purge keeps the tables small, the full unique index `uniq_oauth_device_codes_user_code` stays: a partial index cannot test expiry, since `now()` is not immutable, and lookups by user code rely on one row per code.

### Personal Access Clients

A signed-in user can create named clients for their own tools with `POST /api/oauth/clients/` and list them with `GET /api/oauth/clients/`; the list carries no secret. `Client::createPersonalAccess()` makes a public client owned by the user with the loopback redirect URI `http://localhost`. `DELETE /api/oauth/clients/{publicId}` works only for the owner; another user's client answers `404`. Revoking a client also revokes its access and refresh tokens, through `ClientRevoker`.

### Client Registration

Administrators manage device, public and confidential clients through the admin API (see [Admin — OAuth Clients](#admin--oauth-clients)) and the `app:oauth:client:*` console commands. Both dispatch the same messages, so the console has the same authority as a super administrator. `app:auth:setup-clients` still seeds the first-party SPA client, and users create their own personal access clients.

`ClientType` names what a client is registered for. `Client::getType()` derives it from the stored flags, in this order: `personal_access`, `first_party`, `device`, `confidential`, else `public`. `ClientType::isAdministered()` is true for `device`, `public`, and `confidential`. The rotate and revoke handlers refuse any other client with `ClientManagementException` reason `protected_client`, which the admin API answers with `409`. The list includes the first-party client and leaves out personal access clients.

`RegisterClientHandler` calls one factory per type. `Client::registerDevice()` takes a name only; a device client with redirect URIs is rejected. `Client::registerPublic()` and `Client::registerConfidential()` validate the redirect URIs in `Client::validRedirectUris()`:

- 1 to 10 URIs after trimming and removing duplicates, each at most 2000 characters, without whitespace or control characters;
- absolute, without a fragment, and without user name or password;
- `https` with a host; `http` only on a loopback host (`localhost`, `127.0.0.1`, `[::1]`; RFC 8252 section 7.3); or a private-use scheme in reverse domain form, containing a dot, such as `app.baander.tv:/callback` (RFC 8252 section 7.1). `javascript`, `data`, `file`, and `vbscript` are rejected.

A client name is trimmed and at most 100 characters. A rejected registration raises `ClientManagementException` reason `invalid_registration`, which the admin API answers with `422`.

`ClientSecret` generates a confidential client's secret: 32 random bytes, base64url-encoded without padding (43 characters). The client stores only the lower-case hex SHA-256 digest, in `oauth_clients.secret_hash`. `OAuthClientAuthenticator` checks a presented `client_secret` through `Client::authenticatesWith()`, which compares digests in constant time with `hash_equals()`. A fast hash is enough here: a secret with 256 random bits cannot be guessed, so a slow password hash would add no resistance, only CPU cost on every token request. The plain secret exists only in `RegisteredClientDTO`, between generation and the one response or command output that shows it; that response carries `Cache-Control: no-store`. `RotateClientSecretHandler` refuses a client without a secret (`no_secret`) or a revoked one (`revoked`), both `409` in the admin API. The new secret takes effect at once, and tokens already issued stay valid.

`ClientRevoker` revokes a client and every access and refresh token issued to it in one transaction, for both `RevokeRegisteredClientHandler` and the personal access `RevokeClientHandler`. The client flag alone is not enough, because the resource server checks each access token's own revocation flag. Issuance takes a shared lock on the client row, so revocation and issuance cannot interleave. Revoking a revoked client succeeds again. A revoked client fails `OAuthClientAuthenticator` like an unknown one.

### Passkey Login

1. `POST /api/auth/passkey/authenticate/options` returns `{challengeKey, options}`. Pass `userId` to restrict the allowed credentials.
2. The client runs `navigator.credentials.get()` with `options`.
3. `POST /api/auth/login/passkey` with `{challengeKey, response, userId?}` and a `DPoP` header.

`PasskeyAuthenticator` runs on the API firewall. It checks the DPoP proof with `DpopTokenRequestVerifier` before the WebAuthn assertion, so a nonce challenge leaves the assertion valid for a retry. Disabled accounts are rejected. On success it stores `VerifiedPasskeyLogin` (user, proof key thumbprint, next nonce) on the request, and `PasskeyLoginController` issues tokens only from it.

### Client Fingerprint Binding

Password login, passkey login, and every grant at the token endpoint accept an optional `X-Baander-Client-Fingerprint` header. When present, it is stored in `oauth_token_metadata` and binds the access token: `OAuth2Authenticator` rejects any request with that token whose header is missing or different (`401`, `AUTH_INVALID_TOKEN`). If the metadata lookup fails, the request is rejected. Refresh copies the binding to the new token. Tokens issued without the header are unbound and ignore it. No first-party client sends the header today, and the WebSocket query-token authenticator does not check it.

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
| `TokenEndpointDpopListener` | Security | Requires a nonce-bearing DPoP proof at `POST /api/oauth/token` before the grant runs; answers a rejected proof with `invalid_dpop_proof` or `use_dpop_nonce` |
| `DpopProofRejection` | Security | Why `DpopTokenRequestVerifier::inspect()` rejected a proof (`missing`, `nonce_required`, `invalid`) and the RFC 9449 error code for it |
| `RateLimitListener` | Security | Per-IP and per-client limits for the auth and OAuth endpoints (see [Rate limiting](../../part-1-operator-guide/configuration.md#rate-limiting)) |
| Doctrine entities | ORM | Persistence for all models |
| Doctrine repositories | ORM | Repository implementations for all aggregates |
| Voter classes | Security | Authorization checks for protected resources; `UserManagementVoter` applies the user management settings |
| `AfterResponseMailer` | Mail | Sends credential emails after the response, in the recipient's email language |

See the [Architecture](../architecture.md#anti-corruption-layer) page for details on the League anti-corruption layer.
