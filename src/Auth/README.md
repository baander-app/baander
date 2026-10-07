# Auth

Authentication and token issuance. Password login (with optional TOTP), passkey login, and refresh issue DPoP-bound token pairs to Baander's own apps. Other clients get the same DPoP-bound pairs from Auth's OAuth 2.0 authorization server: the authorization code grant with mandatory S256 PKCE, the device authorization grant, and the refresh token grant. League OAuth2 Server is used only to validate access tokens, behind an anti-corruption layer (`services.yaml` aliases League's access token and refresh token repository interfaces to internal adapters). The `User` aggregate is the sole domain root.

## Concepts

The context is organized into four feature areas within each layer:

- **User** — registration, login, profile management, password reset
- **OAuth** — token lifecycle: issue at password or passkey login and through the authorization code and device code grants, rotate at refresh, revoke. Every token pair comes from `TokenPairIssuer`, and both refresh paths rotate through `RefreshTokenHandler`. Every request that issues tokens needs a DPoP proof with a server-issued nonce, and tokens are bound to the proof key. An optional `X-Baander-Client-Fingerprint` header on the token request also binds the access token to that fingerprint. The OAuth area also covers the RFC 8414 metadata document, personal access clients, and the registration, secret rotation and revocation of device, public and confidential clients through the admin API and the `app:oauth:client:*` commands.
- **Passkey** — WebAuthn registration and authentication via `web-auth/webauthn-lib`
- **Totp** — time-based one-time password setup and verification via `otphp`

The OAuth models AccessToken, AuthCode, Client, DeviceCode and RefreshToken each have a state object and a repository; TokenMetadata has a repository. The aggregate pattern is `User` only — the rest are OAuth primitives managed through their repositories. Deleting a user deletes their access tokens, and with them the refresh tokens and token metadata, as well as their authorization codes and device codes.

## Ports

| Port | Purpose |
|------|---------|
| `UserPortInterface` | User CRUD |
| `PasswordHasherInterface` | Password hashing |
| `JwtGeneratorInterface` | JWT token generation |
| `TotpVerifierInterface` | TOTP code verification |
| `PasskeyVerifierInterface` | WebAuthn passkey verification |
| `DpopJtiCacheInterface` | DPoP JTI replay protection (backed by Redis) |
| `PasswordResetTokenRepositoryInterface` | Each user's single outstanding password reset token (hashed, single use) |
| `PasswordResetDeliveryInterface` | Emails the reset link after the response is sent; never persists or logs the token |
| `EmailVerificationTokenRepositoryInterface` | Each user's single outstanding email verification token (hashed, bound to the address, single use) |
| `EmailVerificationDeliveryInterface` | Emails the verification link after the response is sent; never persists or logs the token |
| `EmailVerificationResendThrottleInterface` | Per-user limit on verification resend requests |
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
| `DeviceCodeApproved` | Security | Notification (through the `Auth Device Approval Event Contract` Deptrac layer) |
| `PasskeyRegistered` | Security | Notification |
| `PasskeyDeleted` | Security | Notification |
| `EmailVerified` | — | none |
