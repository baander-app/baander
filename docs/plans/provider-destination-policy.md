# Provider destination policy

## Scope

Metadata, radio-catalog and push delivery must contact only their approved HTTPS
providers at public addresses. Existing fixed URLs and subscription host checks do
not constrain DNS answers or PHP/Guzzle redirects. The current stream regression
also demonstrates credential forwarding on the CI image. This change closes those
paths without changing provider payloads, database schemas or dependencies.

The implementation covers the seven metadata adapters, Spotify token acquisition,
IPRD catalog requests and persisted web-push subscriptions at delivery. Webhook,
operator-configured lyrics, mail, tracing and browser playback remain separate
policies. Existing notification Application-to-Infrastructure imports are recorded
debt; this task must not add new such dependencies.

## Mechanism

A Shared Infrastructure destination policy validates HTTPS URLs with port 443,
no userinfo or fragment, and canonical ASCII hostnames. Callers supply exact host
allowlists or explicit domain suffixes with label boundaries. The policy resolves
A/AAAA records, rejects an empty answer or any non-global address, checks mapped
IPv6 addresses, rejects multicast and IPv6 translation/transition ranges, and
returns approved addresses for connection pinning.
No LAN exception applies. Failure messages omit URLs and credentials.

The Shared HTTP transport uses the existing Symfony HTTP client contract with a
dedicated unlogged cURL client. Fresh HTTP/1.1 connections avoid connection reuse,
coalescing and the installed client's HTTP/2 retry path. It pins
an approved address while retaining the original hostname for TLS verification,
forces peer/name verification, disables proxies and automatic redirects, and sets
bounded connection/request options. Each request gets its own validated options;
caller options are restricted to headers, body and time budgets and cannot weaken
security settings. DNS lookup uses the existing native
resolver mechanism; its system resolver timeout is outside the HTTP deadline and
must be documented rather than claimed as a total operation bound.

Ordinary metadata and IPRD requests reject redirects. Cover Art Archive alone may
follow at most three GET redirects across HTTPS coverartarchive.org and archive.org
or their label-boundary subdomains. Each hop repeats validation and pinning, closes
the previous response, and shares the HTTP time budget. Relative locations are
resolved against the current URL. Cross-origin hops forward only explicitly safe
public headers; credentials, request bodies and cookies are not forwarded.
The provider's [redirect implementation](https://github.com/metabrainz/artwork-redirect)
documents the archive.org transition. No live provider requests are used in tests.

Metadata adapters retain their result mapping and documented failure behavior while
replacing PHP stream HTTP requests. API key query strings and transport exceptions
must not enter logs. Provider retry logic must not retry destination-policy failures
or introduce automatic replay of push requests or token POSTs.

WebPush retains its installed Minishlink encryption, VAPID and result handling. An
explicit Guzzle cURL handler validates the actual outgoing endpoint immediately
before transport, including subscriptions already persisted. It pins DNS with
CURLOPT_RESOLVE, forces HTTPS/TLS verification, bypasses proxies and disables
redirects. It explicitly rejects 3xx responses, which Minishlink otherwise treats
as successful fulfilled requests. Fresh connections avoid reused transports; the
installed Guzzle rewind retry path is disabled and covered by a regression.
Existing provider host/suffix rules remain enforced at delivery. Rejected
requests produce failure rather than success or subscription expiration; other
subscriptions can still be attempted. No fallback stream handler may bypass pinning.

## Acceptance and checks

- Reject loopback, private, link-local, metadata, multicast, reserved, mixed public
  and forbidden answers, malformed URLs and DNS failures before a connection.
- Test exact/suffix boundaries, supported public IPv4/IPv6, DNS answer changes,
  connection pinning and attempts to override protected options.
- Test approved CAA redirects, relative locations, cross-origin credential removal,
  downgrade/private/unapproved redirects, loops and cleanup on rejection.
- Test each metadata provider and Spotify token acquisition, including successful
  request mapping, failure behavior and credential-free logs. Retain the historical
  raw-stream reproduction as evidence; add adapter-level protection regressions.
- Test radio success/error behavior and injected transport wiring. Test persisted
  push destinations, redirect refusal, VAPID/body preservation and normal expiry
  handling. Inspect installed dependencies for handler ordering and retries.
- Use disposable TLS peers for actual transport hostname/trust and redirect checks,
  with test-only transport routing. Production policy must not gain a private-address
  escape hatch. Tests use baander.app hostnames and never contact live providers.
- Run focused tests, then all four backend suites in the CI image and platform,
  PHPStan, Deptrac, container and OpenAPI gates. Run relevant production runtime
  drills serially. Preserve development PostgreSQL and Redis.

## Delivery

Follow the documented manual workflow because compound-engineering skills are not
available: independent plan review before implementation, local commits,
simplification and independent code review with justified findings applied.
Update the threat model, testing/operator guidance as needed, shared vocabulary and
ROADMAP.md with actual counts and limitations. Apply prose-fix and check links;
book pages must not reference plans. No push or merge is part of this request.

## Independent review

Reviewed before implementation on 2026-10-15. The review required explicit handling
of Minishlink redirect success, Guzzle rewind retries, connection reuse and IPv6
translation ranges. These requirements are included above. No remaining plan
blocker was identified.
