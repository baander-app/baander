# Atomic rate limits

## Problem and scope

The configured Symfony cache storage hides failed reads and writes, and concurrent
consumers can accept the same remaining token. All 19 configured limiters must
reject protected work when their state cannot be established. Existing budgets,
identity keys, fixed/sliding window calculations, ordinary 429 responses, development
bypasses and media exemptions remain in force. Logout and token revocation retain
their narrow Redis-outage availability contract and all authentication/proof checks.

No dependency, database schema or deployment changes are required. Test services
are disposable; development PostgreSQL and Redis are outside this work.

## Implementation

Replace configured factories centrally with a Redis-backed factory implementing
Symfony's existing factory interface. Execute Symfony's installed policy against an
operation-local snapshot storage, then commit the state with an atomic Redis
compare-and-set. This retains window rounding, TTLs, multi-token requests, zero-token
inspection and reservation semantics without duplicating Symfony's algorithms.
Reject negative token counts. Only fixed and sliding windows are supported by this
adapter; container compilation rejects other policies rather than silently weakening
protection. No token-bucket limiter is currently configured.

Each limiter has a persistent generation key and subject state keyed by a digest,
with an opaque revision and expiring serialized policy state. Atomic snapshot reads
return generation, revision, payload and Redis time. A read of an absent bucket
creates an empty revision tombstone with a ten-second TTL. A per-subject reset
writes a new empty revision tombstone with the same TTL, including when no bucket
exists. Compare-and-set rejects snapshots older than two seconds using Redis time;
the tombstone outlives every writable snapshot, preventing absence/expiry ABA even
if a process pauses before committing. Compare-and-set checks both generation and
revision before writing with the policy's relative TTL. Absent state is distinct
from corrupt state; only the expected policy state class can be decoded, and the
stored identifier must match. Read-only and denied decisions linearize at the
snapshot read. Successful writes linearize at compare-and-set. Retry only definite
comparison conflicts, at most eight attempts and within two seconds measured by a
monotonic clock (an in-flight bounded Redis call may finish after that deadline); transport errors, refused writes,
malformed replies, stale snapshots and retry exhaustion fail closed. Do not replay uncertain writes.
Use the existing pooled Redis factory and its bounded connection/read timeouts.

Per-subject reset replaces the revision atomically. Named administration
clear advances the limiter generation; old states expire naturally. An old writer
must retry against the new generation. Clear-all remains sequential and reports
partial failures through its existing application contract. Do not scan Redis or
share state between limiter namespaces. The administration catalog must describe
and clear this actual storage; replace the obsolete cachePool field with
storageNamespace across DTO, API, clients, tests and documentation, and remove
unused dedicated limiter cache pools.

A dedicated application availability exception maps to a generic 503 response with
Retry-After and Cache-Control: no-store, including Messenger-wrapped exceptions.
Preserve existing exhausted-budget outcomes. Only the generic API limiter catches
this availability exception for auth_logout and oauth_revoke; it still enforces
confirmed exhaustion and leaves every subsequent authentication/DPoP check intact.
Other consumers propagate the exception before protected side effects. Errors and
logs must not expose subjects, Redis endpoints or serialized state.

## Verification

- Unit regressions cover strict storage errors, corrupt state, conflict retries,
  ambiguous writes without replay, invalid inputs, TTL handling, state identity,
  independent operation snapshots and generation/reset races. Policy parity checks
  compare this adapter with the installed Symfony policies.
- Disposable Redis integration checks use independent PHP processes/connections
  with a shared barrier: fixed and sliding windows admit exactly the remaining
  budget. Verify namespace/subject isolation and fresh-process observations of
  per-subject and named resets. Verify refused/paused Redis fails within a bound.
- HTTP regressions cover anonymous/authenticated API and pre-auth throttles,
  password-reset/resend paths, admin cover extraction, Discovery and config-check.
  Assert unavailable stores prevent effects and report 503, while actual exhausted
  budgets retain their semantics. Keep logout/revoke outage and invalid-proof tests.
- Verify HTTP and CLI administration clear the consumed namespace, preserve unknown
  names and partial failures, and report unavailable clears as failures.
- Run focused suites, authoritative backend suites and static gates in the CI image;
  regenerate OpenAPI/client types for the catalog field and run web checks. Run
  production security and web runtime drills serially against disposable services.
  Record actual results, counts, elapsed times and limitations in ROADMAP.md.

## Delivery

Follow the documented manual workflow because the compound-engineering skills are
unavailable: independent plan review before implementation, local commits by unit,
simplification, independent code review and applied findings before delivery.
Update the threat model, testing/administration documentation and shared vocabulary
where required; book pages describe behavior without planning references. Apply
prose-fix and inspect its audit. Commit locally; publishing requires a later request.

## Review checkpoint

Independent review approved the plan on 2026-10-15 after adding server-checked
snapshot expiry and revision tombstones. Implementation must reject negative Redis
snapshot ages as well as ages over two seconds, and test paused writers and expired
tombstones explicitly.
