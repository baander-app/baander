# Auth

First-party authentication: password login (with optional TOTP), passkey login, and refresh issue DPoP-bound token pairs to Baander's own clients. League OAuth2 Server is used only to validate access tokens, behind an anti-corruption layer (`services.yaml` aliases League's access token and refresh token repository interfaces to internal adapters). The `User` aggregate is the sole domain root.

## Concepts

The context is organized into four feature areas within each layer:

- **User** — registration, login, profile management, password reset
- **OAuth** — token lifecycle: issue at password or passkey login, rotate at refresh, revoke. Every request that issues tokens needs a DPoP proof with a server-issued nonce, and tokens are bound to the proof key. An optional `X-Baander-Client-Fingerprint` header at login also binds the access token to that fingerprint.
- **Passkey** — WebAuthn registration and authentication via `web-auth/webauthn-lib`
- **Totp** — time-based one-time password setup and verification via `otphp`

The OAuth models AccessToken, Client and RefreshToken each have a state object and a repository; TokenMetadata has a repository. The aggregate pattern is `User` only — the rest are OAuth primitives managed through their repositories. Deleting a user deletes their access tokens, and with them the refresh tokens and token metadata.

## Ports

| Port | Purpose |
|------|---------|
| `UserPortInterface` | User CRUD |
| `PasswordHasherInterface` | Password hashing |
| `JwtGeneratorInterface` | JWT token generation |
| `TotpVerifierInterface` | TOTP code verification |
| `PasskeyVerifierInterface` | WebAuthn passkey verification |
| `DpopJtiCacheInterface` | DPoP JTI replay protection (backed by Redis) |
| `PasswordResetTokenRepositoryInterface` | Password reset token persistence |
| `EmailVerificationTokenRepositoryInterface` | Email verification token persistence |
| `AuthenticatedUserIdentityInterface` | Identity of the user a login authenticator verified |
| `OAuthSecretBundleInterface` | Key bundle preparation and validation for secret rotation |
| `OAuthTokenInvalidatorInterface` | Token deletion during offline secret rotation |

## Events

| Event | Category | Consumers |
|-------|----------|-----------|
| `UserRegistered` | Security | Notification |
| `UserCreatedByOperator` | Security | Notification |
| `PasswordChanged` | Security | Notification |
| `TokenRevoked` | Security | Notification |
| `PasskeyRegistered` | Security | Notification |
| `PasskeyDeleted` | Security | Notification |
| `EmailVerified` | — | none |
