---
title: WebSocket handshake tickets
status: reviewed
date: 2026-10-15
---

# WebSocket handshake tickets

## Problem and scope

The Swoole upgrade callback accepts an OAuth access token from the query string
without the HTTP firewall's DPoP validation. Its Origin allowlist is not wired.
A stolen sender-constrained token can therefore open a socket without its key.
This change replaces that entrypoint and migrates the browser callers and live
fixtures. Native applications remain deferred. No schema or dependency changes
are required.

## Protocol

`POST /api/ws/tickets` passes through the existing OAuth and DPoP firewall. It
requires an exact allowed Origin and returns a no-store JSON response containing
`data.ticket` and `data.expiresAt` (an RFC 3339 timestamp). A ticket is 32 random
bytes encoded as 64 lowercase hexadecimal characters. Its lifetime is at most
30 seconds and cannot extend beyond the authorizing access token's expiry.

Redis stores the ticket under its SHA-256 digest in a dedicated namespace, with
its user ID, access-token ID, exact Origin, path `/api/ws` and expiry. Redemption
atomically verifies its Origin/path binding and consumes it once across workers.
A binding mismatch leaves the ticket available to its intended client. Missing, expired, malformed and reused
tickets fail closed. Store failures return a bounded 503 with Retry-After; no
process-local fallback is permitted. Persisted access-token revocation/expiry and
user enabled state are checked again on redemption. A ticket-specific scalar
status adapter reads fresh database state, bypassing ORM identity maps and caches;
it verifies that the token belongs to the ticket user at issuance and redemption.
The endpoint takes the token ID from the authenticated request attribute.

The upgrade accepts only GET `/api/ws?ticket=<ticket>`, a valid WebSocket key and
version, and an exact allowed Origin. Missing, `null`, foreign or malformed Origins
are rejected before ticket consumption. The default allowlist contains the serialized origin of `APP_URL`
(scheme, host and optional port);
an empty allowlist denies every Origin. Framing, path and query validation precede
consumption. Raw query syntax must contain exactly one canonical `ticket` field;
duplicates, encoded aliases, array fields and extra parameters are rejected.
The old `token` query parameter has no fallback. Origin binding is an
additional browser boundary, not proof of possession for arbitrary native clients.

The Auth Application layer owns ticket issuance and redemption contracts. Its
Redis adapter owns atomic storage. The Shared Swoole adapter depends on the Auth
Application contract, without adding cross-context repository access. The bundle
retains a generic authentication-unavailable error contract and does not import
application classes. Successful redemption supplies the existing user/token
identity to the connection registry, preserving immediate logout closure and
refresh-chain reconciliation.

The ticket remains a bearer capability during its short lifetime: theft before
redemption can race the intended client. Revocation concurrent with registration
retains the existing immediate-close/reconciliation bound. The work does not claim
per-message proof possession or eliminate that registration race.

## Clients and observability

The web session bus obtains a new ticket through the existing DPoP-aware Axios
client before each connection or reconnect. The WebSocket URL derives from the
configured API origin. Cancellation and generation checks prevent a late response
from opening a socket after logout, unmount or replacement. Issuance failures use
the existing reconnect backoff. Service-worker signing permissions stay unchanged.

The embedded player's transport uses a host-provided asynchronous ticket callback;
it never places an OAuth token in a WebSocket URL. Its existing party message
vocabulary mismatch is outside this authentication change and must remain an
explicit limitation. Native application feature work remains deferred.

Application URL redaction covers `ticket`, including encoded parameter names.
Review proxy access-log configuration and prevent ticket query strings from being
recorded in access or error logs there. External TLS proxies must apply the same
credential-log policy; local checks cannot qualify external configurations. Live shutdown and security fixtures issue tickets with real DPoP
and a configured `https://baander.app` Origin.

## Delivery and verification

1. Independently review this plan before implementation. Use the documented manual
   workflow because compound-engineering skills are unavailable.
2. Implement Auth contracts, store and endpoint; strict upgrade validation and
   redaction; browser and embedded transport lifecycle changes. Keep ownership
   separate across workers and agree the response/adapter contracts first.
3. Add focused tests for no-proof/wrong-proof/replayed-proof issuance; origin/path/
   framing rejection before consumption; expiry, revoke, disabled-user rejection;
   atomic one-use redemption; store outage; and client cancellation/retry behavior.
4. Exercise production upgrades in disposable infrastructure: valid browser-style
   flow, stolen raw-token denial, ticket reuse and concurrent redemption, Origin
   rejection, logout closure, bounded Redis outage/recovery and secret-free logs.
   Preserve shutdown/drain qualification. Run shared-state gates serially.
5. Regenerate OpenAPI and web API bindings. Run the CI-image backend suites and
   static gates, focused live security/shutdown gates, web tests/typecheck/lint and
   embedded tests/typecheck. Record actual counts, timings and limits rather than
   treating implementation as qualification.
6. Simplify, independently review code and apply justified findings. Update
   CONCEPTS.md, the current testing/security guides and ROADMAP.md with acceptance
   evidence and the next remaining item. Book pages must not link to this plan.
   Apply prose-fix, commit locally, and leave publishing for an explicit request.

## Acceptance

A sender-constrained access token alone cannot open a socket. A valid DPoP-bound
HTTP request can obtain a ticket that opens at most one socket, only for its bound
Origin and route while its authorizing identity remains valid. Invalid requests
cannot bypass the firewall through the raw upgrade callback. Browser reconnects
obtain fresh tickets and cancellation prevents late connections. Failures preserve
server liveness, cleanup and secret redaction. Evidence and remaining limits are
recorded before the roadmap marks this finding addressed.

## Plan review

Independent review approved implementation on 2026-10-15 after clarifying fresh
database reads, token ownership, atomic binding checks and strict raw query parsing.
Proxy error logs are part of the leakage check.
