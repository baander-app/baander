# Application threat model and security regressions

## Scope and acceptance

Deliver an application threat model, focused security regressions and fixes for
reproduced gaps, supporting direct clients and explicitly trusted reverse proxies.
Each threat has source evidence, test references, prerequisites, impact, controls,
coverage limits and unresolved findings. Historical passing tests are not current
verification. Registry internals, quorum downgrade, regional qualification and
mobile work remain pending; the combined roadmap item stays open.

## Work sequence

1. Independently review this plan before implementation on
   `plan/application-threat-model`. Use the roadmap's manual workflow fallback
   because the compound-engineering skills are unavailable.
2. Inventory assets, actors, entry points, trust boundaries and data flows across
   browser/service worker, HTTP/WebSocket, workers, PostgreSQL, Redis, media storage
   and external services. Distinguish PHP Discovery registration/pairing from the
   registry service using source evidence.
3. Extend credential leakage/replay and real-firewall authorization evidence for
   users, libraries, playlists, pairing and WebSockets, including revocation and
   alternate methods. Preserve intentional credential-return endpoints.
4. Reproduce proxy identity and rate-limit gaps before fixing them. Trust no
   forwarding proxies by default and align Swoole and Symfony explicit trust.
   Exercise concurrency and Redis failure semantics.
5. Exercise malformed JSON/types, oversized requests and malformed wire HTTP in
   the real runtime, proving bounded rejection and subsequent responsiveness.
   Add a bounded runtime security drill as a blocking Forgejo gate.
6. Inventory outbound paths and exercise forbidden destinations, DNS changes,
   redirects, TLS trust, hostname mismatch and downgrade with disposable fixtures.
   Record larger redesigns with concrete impact and reproduction as open findings.
7. Write the developer threat model and link it from the security guide. Apply
   prose-fix. Simplify at phase boundaries; obtain independent code review and
   apply justified findings before local commits. Push only on request.

## Ownership and integration

Independent workers may own proxy/rate-limit implementation, authorization and
credential regressions, and outbound/TLS evidence. The lead owns plan, final threat
model, runtime drill integration, CI/shared configuration, roadmap and commits.
Agree additional ownership before overlapping edits. Workers do not stage or commit.

## Verification

Use disposable PostgreSQL, Redis and local HTTP/TLS services with `baander.app`
hostnames; preserve the development database and prevent external fixture traffic.
Run focused regressions before and after fixes, then CI-image unit, functional,
integration, PHPStan, Deptrac, container lint and OpenAPI checks. Run browser-auth
checks and web gates when browser code changes. Record actual commands, counts,
runtime details and remaining gaps in ROADMAP.md. No API/schema redesign is planned;
a public-contract change requires OpenAPI and generated-client updates together.
Scanner canaries establish scanner behavior only.

## Plan review

Independent review completed before implementation on 2026-10-14 with no blocking
findings. Verification includes StaticAnalysisRules. Wire rejection must be followed
by a healthy connection; proxy cases include direct spoofing, trusted chains, IPv6,
malformed values and DPoP URL identity. The combined roadmap item remains open.
