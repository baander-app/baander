# Sessions and access

Use this reference to establish what an account disable, logout or credential change actually ends. In particular, an admitted media response and a signed video URL have different lifetimes from an OAuth session. For account commands, see [User management](user-management.md); for global changes, see [Rotate credentials](../maintenance/credential-rotation.md).

## OAuth clients and cross-origin access

Register clients through [OAuth client administration](oauth-clients.md). For token binding, scope checks and permitted origins, see [the access reference](../../part-2-developer-guide/development/session-and-access-behavior.md#oauth-clients-and-cross-origin-access).

## Disabled accounts

Disabling a user, from the admin panel or with [app:user:disable](../commands/app-user-disable.md), revokes the user's access and refresh tokens in the transaction that saves the disable. Every authenticator then refuses the account: API requests, token refresh and new WebSocket handshakes. After the commit, the web server closes the user's open WebSocket connections on every worker. The command reaches the server through its control socket, so run it in the web container.

In a container without a web server, it disables the account and logs a warning that it could not reach the open connections; the socket reconciliation (see [Sessions, logout and a Redis outage](#sessions-logout-and-a-redis-outage)) closes them within a minute.

Two kinds of media access do not end with the tokens:

### A response already being sent

A track file, or a transcode that is still encoding, keeps streaming until that one response ends. Every later request authenticates again and is refused.

### Signed video stream URLs

HLS and DASH URLs carry an HMAC signature, not the user's token, so revoking the tokens does not end them. Every manifest, segment and subtitle request on one checks instead that the owner of the transcode job it names may still play the video, as original-file and completed-video URLs do. Once the owner is disabled, loses the video's library or the job is gone, the next request answers `409 Conflict` (`Playback media is unavailable.`), and the player keeps only what it has already buffered.

While the owner keeps access, a URL works until it expires. A signed manifest URL lives at most 24 hours, and every URL a manifest lists keeps that manifest URL's expiry. To end every signed URL at once, for every user, set a new `STREAM_URL_HMAC_SECRET` and restart the web server.

A signed URL is a bearer capability: it is not bound to a user or session, and it serves whoever holds it, with no session, until it expires or its owner loses access. A member of another library or a signed-out browser is served alike. A request that does present a token on a signed URL must present a valid one: an expired, revoked, foreign-audience or malformed token, or a fingerprint-bound token without its header, answers `401` even with a valid signature, because the firewall validates any token it is shown before the signature is read.

The DPoP proof is not read on delivery routes, so no replay key is recorded there. The signature value is redacted from every log record, the access log included, so a logged URL cannot be replayed. A DPoP-bound token presented with the `Bearer` scheme instead of `DPoP` answers `401` on every route the API firewall authenticates; WebSocket upgrades accept only a single-use handshake ticket, not an access token.

## Sign-in method changes

A user who changes their password, turns TOTP on or off, adds or removes a passkey or confirms a new email address ends every other session of their account at once, paired devices, personal access tools and third-party apps included, and the web server closes those sessions' WebSocket connections. The session that made the change stays signed in. An operator's password reset or TOTP reset ends every session of the user; an operator's email change ends none. Signed video URLs are not sessions and follow the rules above. See [session behavior](../../part-2-developer-guide/development/session-and-access-behavior.md#sign-in-method-changes) for the exact responses.

## Who can change the catalog, and who can share a playlist

Catalog and library changes require an administrator. Library membership governs reads; playlist owners control collaboration. See [the access rules](../../part-2-developer-guide/development/session-and-access-behavior.md#who-can-change-the-catalog-and-who-can-share-a-playlist) for their full scope and regression evidence.

## Sessions, logout and a Redis outage

A logout ends the authenticated session’s chain of token pairs. The web app keeps a pending logout until the server confirms it or refuses its refresh token. Redis outages can delay authentication work without invalidating the session. For exact response codes, retry behavior and the narrow logout/revocation exceptions, see [session behavior](../../part-2-developer-guide/development/session-and-access-behavior.md#sessions-logout-and-a-redis-outage). Check [Redis connectivity](../operations/troubleshooting.md#common-issues) before treating an outage as an invalid credential.
