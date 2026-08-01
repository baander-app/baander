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
| `LoginBlock` | Honeypot login block record |

### Value Objects

| Model | Purpose |
|-------|---------|
| `ChainId` | DPoP proof-of-possession chain identifier |
| `ClientFingerprint` | Client application fingerprint |
| `Scope` | OAuth 2.0 scope |
| `DpopValidationResult` | Result of DPoP header validation |

## Commands & Handlers

Commands and handlers are organized into feature namespaces under `Application/Command/` and `Application/CommandHandler/`: `OAuth/`, `Passkey/`, `Totp/`, and `User/`.

| Command | Handler | Purpose |
|---------|---------|---------|
| `RegisterUserCommand` | `RegisterUserHandler` | Self-registration (User/) |
| `CreateUserCommand` | `CreateUserHandler` | Operator-created users (User/) |
| `LoginUserCommand` | `LoginUserHandler` | Password-based login (User/) |
| `DisableUserCommand` | `DisableUserHandler` | Disable a user account (User/) |
| `EnableUserCommand` | `EnableUserHandler` | Re-enable a disabled account (User/) |
| `RequestPasswordResetCommand` | `RequestPasswordResetHandler` | Password reset initiation (User/) |
| `IssueTokenCommand` | `IssueTokenHandler` | Central OAuth token issuance (all grant types) (OAuth/) |
| `RefreshTokenCommand` | `RefreshTokenHandler` | Refresh token flow (OAuth/) |
| `RevokeTokenCommand` | `RevokeTokenHandler` | Token revocation (OAuth/) |
| `ApproveDeviceCodeCommand` | `ApproveDeviceCodeHandler` | Device authorization approval (OAuth/) |
| `RegisterPasskeyCommand` | `RegisterPasskeyHandler` | Add a WebAuthn passkey (Passkey/) |
| `AuthenticatePasskeyCommand` | `AuthenticatePasskeyHandler` | Passkey-based login (Passkey/) |
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

### Admin — User Management

All routes under `/api/admin/users` (controller `AdminUserController`, gated `ROLE_ADMIN`; mutating routes require `ROLE_SUPER_ADMIN`).

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/api/admin/users` | List users (paginated, filter by role/disabled) |
| POST | `/api/admin/users` | Create a user |
| PATCH | `/api/admin/users/{id}` | Update a user |
| DELETE | `/api/admin/users/{id}` | Delete a user |
| POST | `/api/admin/users/{id}/roles` | Assign roles |
| POST | `/api/admin/users/{id}/reset-password` | Reset a user's password |
| POST | `/api/admin/users/{id}/disable` | Disable a user |
| POST | `/api/admin/users/{id}/enable` | Enable a user |

### Admin — Login Blocks

All routes under `/api/admin/login-blocks` (controller `AdminLoginBlockController`, gated `ROLE_ADMIN`; deletes require `ROLE_SUPER_ADMIN`).

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/api/admin/login-blocks` | List recent honeypot blocks (paginated) |
| DELETE | `/api/admin/login-blocks/{id}` | Delete a single block |
| DELETE | `/api/admin/login-blocks` | Delete all blocks |

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
