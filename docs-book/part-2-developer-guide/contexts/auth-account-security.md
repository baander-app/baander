# Account Security and Credential Emails

This page follows account changes through validation, persistence and credential delivery. For token issuance and session revocation, see [Auth Tokens](auth-tokens.md).

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

`EmailVerificationIssuer` is the single place tokens are issued. Its `issue()` stores the hash, with the address the token verifies, inside the caller's transaction and returns the raw token: the pending address of a self-service email change, otherwise the current address while it is unverified. It issues nothing when no change is pending and the address is verified, or for a disabled account. Its `deliver()` hands the token to `EmailVerificationDeliveryInterface` after the commit, so a rolled-back registration or email change never emails a link.

Three callers use it:

- `RegisterUserHandler` issues on registration.
- `ChangeOwnEmailHandler` serves `PUT /api/auth/me/email`. It stores the new address in `users.pending_email` and issues for it, answering `202` whether or not the address is taken. When the link is redeemed, `VerifyEmailHandler` moves the pending address into `email` unless another account uses it by then, and `EmailChangeNoticeInterface` tells the old address.
- `ResendEmailVerificationHandler` serves `POST /api/auth/me/email/verification`. The resend endpoint answers the same `200` whether it sent an email or not; over the per-user limit (`auth_email_verification_user`) it sends nothing.

Operator changes (`PATCH /api/admin/users/{publicId}`, `app:user:change-email`) do not use the issuer. They dispatch `ChangeEmailCommand`, which writes the new address as verified, drops a pending change and tells the old address.

`MarkEmailVerifiedHandler` serves `POST /api/admin/users/{publicId}/verify-email` and `app:user:mark-email-verified`. It verifies the current address on the operator's word and leaves a pending change alone; that change still takes effect only through its own link.

Redemption and resend requests share the per-IP limit `auth_email_verification_ip`, which answers `429` with `Retry-After`.

A verified address is a precondition for adding an authenticator: `POST /api/auth/totp/setup` and `POST /api/auth/passkey/options` answer an unverified address with `EmailUnverifiedException` (`422`, `details.reason` `email_unverified`) before step-up spends an attempt.

`MailerEmailVerificationDelivery` builds the link `APP_URL/verify-email#token=…` and queues the email on `AfterResponseMailer`. That is the reset email's path: it sends after the response and straight to the mailer transport, so a slow or failing mail server neither delays an HTTP response nor fails a registration. The templates are `templates/email/auth/email_verification.{txt,html}.twig`, translated through the `email_verification_email` keys in the `auth` domain. Both auth emails are written in the recipient's email language. `AfterResponseMailer` reads it through the UserPreference settings contract when it sends the email, after the response, so the request does no per-account work. If the lookup fails, the email is sent in English and the failure is logged without the address or the link.

The web page is `/verify-email` in `ui/web/src/features/auth/`. It reads the token from the fragment and removes the fragment from the address bar. The account card in **Settings** shows an unverified address and offers a new link.

## Email Language

The email language is a UserPreference user setting, `language`, that follows the system setting `i18n.default_language`. Auth reaches it only through `UserSettingsContractInterface`, in the `UserPreference Settings Contract` Deptrac layer (see [UserPreference](user-preference.md#the-settings-contract)).

At registration, `AuthController` passes the `Accept-Language` header to `AcceptLanguageMatcher` (`App\Shared\Application\Http`), which returns the supported language the browser ranks highest. It ignores `*` and entries with `q=0`, maps a regional tag such as `da-DK` to `da`, and returns `null` when nothing matches. `Request::getPreferredLanguage()` is not used, because it answers with the first supported language even when the browser asks for none of them. `RegisterUserCommand` carries the result, and `RegisterUserHandler` calls `seedLanguage()` inside the registration transaction. The contract stores the language only when it differs from the current server default, so a user whose browser asks for the default keeps following it when an administrator changes it. `CreateUserHandler`, behind `app:user:create` and the admin panel, stores no language.

The same setting localizes API messages. `ApiLanguageListener` (`Interface/EventListener/`) resolves each API request's language once, after the firewall: the user's saved choice (not the server default), else the request's `Accept-Language` through the same matcher, else English. It also runs on `kernel.exception` for a request the firewall refused first. It stores the result in the `_baander_locale` request attribute and never calls `Request::setLocale()`, because Symfony would copy that onto the translator, which is one instance shared by every coroutine of a worker. `ExceptionSubscriber`, `ValidationExceptionSubscriber` and `TranslatorTrait` pass the attribute's locale to each translation, so the shared translator stays English. The password, OAuth2 and passkey authenticators run inside the firewall, before the listener, and translate their failures from `Accept-Language` through `AuthenticationFailureMessage`.

The verification email that follows registration is sent in the stored language, because `AfterResponseMailer` resolves the language when it sends.

## Passkey Login

1. `POST /api/auth/passkey/authenticate/options` returns `{challengeKey, options}`. Pass `userId` to restrict the allowed credentials.
2. The client runs `navigator.credentials.get()` with `options`.
3. `POST /api/auth/login/passkey` with `{challengeKey, response, userId?}` and a `DPoP` header.

`PasskeyAuthenticator` runs on the API firewall. It checks the DPoP proof with `DpopTokenRequestVerifier` before the WebAuthn assertion, so a nonce challenge leaves the assertion valid for a retry. Disabled accounts are rejected. On success it stores `VerifiedPasskeyLogin` (user, proof key thumbprint, next nonce) on the request, and `PasskeyLoginController` issues tokens only from it.

`PasskeyService` keeps ceremony options in `PasskeyChallengeStoreInterface` and takes them with `consumeRegistrationChallenge(user, key)` or `consumeLoginChallenge(key)`, so each challenge works once and only for the ceremony it was issued for. There is no in-memory fallback: while Redis is unavailable, the options and verification routes and `/api/auth/login/passkey` answer `503` with `Retry-After`. An account with TOTP enabled (`User::hasTotpEnabled()`) refuses an assertion without the user-verified flag, at login and at `POST /api/auth/passkey/authenticate`; the flag is checked after the signature verifies, so a refusal does not reveal the account's TOTP status. `auth.passkey.user_verification` stays `preferred`, so accounts without TOTP keep security keys that have no PIN. `AuthenticatePasskeyHandler` runs the clone check only when the stored or the presented signature counter is non-zero, so an authenticator that always reports 0 can sign in.

## Sign-in and Account Protection

**Password login.** `PasswordAuthenticator` checks the DPoP proof and settles the next nonce before reading credentials. An unknown address is checked against a dummy hash made once per worker with the configured hasher, so it costs what a real account costs, and a disabled account is reported only after its password matches; unknown, disabled and wrong-password accounts otherwise get the same `401` `AUTH_INVALID_CREDENTIALS`. Each rejected password or TOTP code is charged through `LoginFailureThrottleInterface` to the normalized address, whether or not an account holds it; nonce challenges, the TOTP prompt, a disabled account with the right password and a success are not. An address over its limit gets `429` with `Retry-After` before any account is looked up.

**TOTP.** `users.totp_secret_ciphertext` holds the secret sealed by `TotpSecretCipherInterface` with libsodium secretbox under `AUTH_TOTP_ENCRYPTION_KEY`, a dedicated 32-byte key, so rotating `APP_SECRET` leaves TOTP working. The verifier checks the current step and `auth.totp.window` steps on either side and returns the matched step. A step is spent by one conditional `UPDATE` of `users.totp_last_used_step` that succeeds only for a later step, so a code counts once, also under concurrent requests; password login spends it in the transaction that stores the tokens, and a lost race rolls the pair back.

An empty `totpCode` counts as absent (`AUTH_TOTP_REQUIRED`). A secret that does not decrypt raises `TotpSecretUnavailableException`: login answers `AUTH_TOTP_UNAVAILABLE` and logs a warning, and it never counts as TOTP being off. `StartTotpSetupHandler` refuses while TOTP is on and holds the new secret in `PendingTotpSecrets` for 10 minutes, each setup replacing the last; enable spends the confirming code's step. `ResetUserTotpHandler` drops the stored secret without reading it, clears the spent step, and revokes every token of the user in one transaction.

**Step-up.** `StepUpVerifier` serves `ChangeOwnEmailHandler`, `StartTotpSetupHandler` and passkey registration options. It charges `StepUpAttemptThrottleInterface` first (`auth_step_up_user`, 10 per 15 minutes by default, per account from any address), then checks the current password and, when TOTP is on, spends the code's step; a wrong password never reaches the code check. Every mismatch raises `StepUpFailedException` (`422`, `details.reason` `step_up_failed`), and the limit raises `StepUpRateLimitedException` (`429` with `Retry-After`). TOTP setup asks for the password only, because TOTP is off whenever it runs.

**Registration.** `RegisterUserHandler` answers `202` with one message whether or not the address is taken. For a taken address it hashes the password as for a new one, creates nothing, and `RegistrationAttemptNoticeInterface` emails the owner after the response, with a link to the forgot-password page.
