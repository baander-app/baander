# Auth

The Auth context implements a full OAuth 2.0 authorization server using the League library behind an anti-corruption layer. It supports password grants, passkeys (WebAuthn), TOTP two-factor authentication, device authorization flow, DPoP token binding, and refresh token rotation.

## Domain Models

### Aggregate Roots

| Model | Purpose |
|-------|---------|
| `User` | User account |
| `Client` | OAuth 2.0 client application |
| `AccessToken` | Issued access token |
| `RefreshToken` | Issued refresh token |
| `AuthCode` | Authorization code |
| `DeviceCode` | Device authorization code |
| `Passkey` | WebAuthn passkey credential |
| `ThirdPartyCredential` | External provider credential |

### Value Objects

| Model | Purpose |
|-------|---------|
| `ChainId` | DPoP proof-of-possession chain identifier |
| `ClientFingerprint` | Client application fingerprint |
| `Scope` | OAuth 2.0 scope |
| `DpopValidationResult` | Result of DPoP header validation |

## Commands & Handlers

| Command | Handler | Purpose |
|---------|---------|---------|
| `RegisterUserCommand` | `RegisterUserHandler` | Self-registration |
| `CreateUserCommand` | `CreateUserHandler` | Operator-created users |
| `LoginUserCommand` | `LoginUserHandler` | Password-based login |
| `IssueTokenCommand` | `IssueTokenHandler` | Central OAuth token issuance (all grant types) |
| `RefreshTokenCommand` | `RefreshTokenHandler` | Refresh token flow |
| `RevokeTokenCommand` | `RevokeTokenHandler` | Token revocation |
| `RegisterPasskeyCommand` | `RegisterPasskeyHandler` | Add a WebAuthn passkey |
| `AuthenticatePasskeyCommand` | `AuthenticatePasskeyHandler` | Passkey-based login |
| `EnableTotpCommand` | `EnableTotpHandler` | Enable TOTP 2FA |
| `DisableTotpCommand` | `DisableTotpHandler` | Disable TOTP 2FA |
| `ApproveDeviceCodeCommand` | `ApproveDeviceCodeHandler` | Device authorization approval |
| `RequestPasswordResetCommand` | `RequestPasswordResetHandler` | Password reset initiation |

## Ports

| Port | Purpose |
|------|---------|
| `JwtGeneratorInterface` | JWT token generation |
| `PasskeyVerifierInterface` | WebAuthn verification |
| `PasswordHasherInterface` | Password hashing |
| `TotpVerifierInterface` | TOTP code verification |
| `UserPortInterface` | User operations |
| `PasswordResetTokenRepositoryInterface` | Password reset token storage |
| `DpopJtiCacheInterface` | DPoP replay protection |

## Domain Events

| Event | Trigger |
|-------|---------|
| `UserRegistered` | Self-registration completed |
| `UserCreatedByOperator` | Operator created a user |
| `PasswordChanged` | Password updated |
| `TokenIssued` | OAuth token issued |
| `TokenRevoked` | Token revoked |
| `PasskeyRegistered` | Passkey added |
| `PasskeyDeleted` | Passkey removed |
| `EmailVerified` | Email verification completed |
| `DeviceCodeApproved` | Device code approved by user |

## API Endpoints

All endpoints are prefixed with `/api`.

| Method | Path | Purpose |
|--------|------|---------|
| POST | `/api/auth/register` | Self-registration |
| POST | `/api/auth/login` | Password login |
| POST | `/api/auth/login/passkey` | Passkey login |
| POST | `/api/oauth/authorize` | Authorization endpoint |
| POST | `/api/oauth/token` | Token endpoint |
| POST | `/api/oauth/revoke` | Token revocation |
| POST | `/api/oauth/introspect` | Token introspection |
| POST | `/api/oauth/device/authorize` | Device authorization |
| GET | `/api/oauth/device/verify` | Device code polling |
| POST | `/api/oauth/device/approve` | Device code approval |
| POST | `/api/auth/totp/setup` | Enable TOTP |
| POST | `/api/auth/totp/verify` | Verify TOTP code |
| DELETE | `/api/auth/totp` | Disable TOTP |
| POST | `/api/auth/passkey/register/options` | WebAuthn registration challenge |
| POST | `/api/auth/passkey/register` | Complete passkey registration |
| DELETE | `/api/auth/passkey/{publicId}` | Delete a passkey |
| GET | `/api/.well-known/oauth-authorization-server` | Server metadata discovery |
| GET | `/api/.well-known/jwks.json` | JSON Web Key Set |

## Cross-Context Relationships

| Direction | Context | Details |
|-----------|---------|---------|
| Depends on | Shared | `Uuid`, `PublicId`, `Email` |
| Depended on by | All contexts | Every authenticated endpoint depends on Auth |

## Infrastructure

| Component | Type | Purpose |
|-----------|------|---------|
| League OAuth adapters | Anti-corruption layer | League interfaces aliased to internal adapters in `services.yaml` |
| Cached token repositories | Doctrine repository | Cached implementations for access tokens and refresh tokens |
| Doctrine entities | ORM | Persistence for all aggregates |
| Doctrine repositories | ORM | Repository implementations for all aggregates |
| Voter classes | Security | Authorization checks for protected resources |

See the [Architecture](../architecture.md#anti-corruption-layer) page for details on the League anti-corruption layer.
