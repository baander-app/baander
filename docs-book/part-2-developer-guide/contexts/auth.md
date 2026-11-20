# Auth

The Auth context signs users in, issues their tokens, and validates those tokens on every request.

Baander's own apps get tokens from password login (with optional TOTP), passkey login (WebAuthn), and refresh.

Other clients use the OAuth 2.0 authorization server: the authorization code grant on pushed authorization requests (RFC 9126) with mandatory S256 PKCE, the device authorization grant (RFC 8628), and the refresh token grant at `POST /api/oauth/token`. Confidential clients authenticate with a secret or with JWT assertions signed by their own keys (`private_key_jwt`).

Every token pair, whichever path issues it, is bound to the client's DPoP key, and refresh tokens rotate.

Tokens of the first-party client act with the user's full authority; every other client's tokens are delegated and reach only the routes their scopes cover (see [Scope Enforcement](auth-tokens.md#scope-enforcement)).

Auth implements the authorization server itself; it uses League OAuth2 Server only to validate access tokens, behind an anti-corruption layer. There is no client credentials grant.

## Development guides

- [Implementation inventory](auth-inventory.md): models, commands, handlers, ports, events and API entry points.
- [Account security and credential emails](auth-account-security.md): passwords, verification, passkeys, TOTP and delivery after the response.
- [Tokens and request validation](auth-tokens.md): issuance, rotation, revocation, DPoP checks and scope enforcement.
- [OAuth authorization server](auth-oauth-server.md): pushed requests, consent, code and device grants, and expiry cleanup.
- [Client authentication and registration](auth-client-authentication.md): secrets, assertions, key retrieval and administration.

For request examples when building a client, start with [API Authentication](../development/api-authentication.md).
