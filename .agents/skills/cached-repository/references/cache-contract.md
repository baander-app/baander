# Repository cache contract

## Discovery and strategy

Record the cached methods, full result dependencies, key encoding, TTL, tag scope,
and allowed staleness. Different finders and filters need separate keys even when
they share an entity identifier. Preserve case-sensitive identifiers and use cache
adapter-safe encodings; lowercase prefixes do not mean lowercase identifier data.
Include pagination/sort/tenant/permission inputs when they influence results.

Object caching must account for serialization and detached mutable objects. Verify
that consumers cannot corrupt a cached aggregate shared with other operations and
that rehydration preserves domain invariants. Decide whether absent results are
cached and for how long; do not assume every `find*`/`get*` method is safe to cache.

For status caching, define a truth table for each status and its effect on the
consumer. The OAuth reference uses `true = revoked`, `null = consult database`;
`false` does not supply an active AccessToken. Do not reuse that boolean as generic
`true = active` or authorize a request from a cached status without its required
expiry/ownership checks. Treat security staleness as a contract decision.

## Cache operations and failure boundaries

Use `TagAwareCacheInterface` when invalidating tags and `ItemInterface` in callbacks.
Tag entries with the actual entity/result scope and only active global tags. Choose
TTL and stampede handling for the workload; the existing `beta: 1.5` read is an
example, not a correctness guarantee.

Cache `get(key, callback)` computes a miss; it is not an unconditional overwrite.
A callback returning a new revoked status may never run when an entry already
exists. Design explicit replacement/invalidation behavior for the chosen adapter
and test pre-existing entries. Do not call proactive writes TOCTOU-safe without
showing the race and how the implementation prevents it.

Separate cache failures from repository/domain failures, including exceptions
thrown inside a cache callback. Preserve the original database error; do not retry
a failed repository operation merely because a broad cache catch intercepted it.
For optional cache availability, log the cache failure and fall back to the database
according to the specified contract. Logging must not turn a cache fallback into a
new failure accidentally. Define invalidation failure behavior deliberately rather
than imposing universal swallowing or universal propagation.

Write methods delegate to the inner contract first. Invalidate affected item and
query/tag entries according to actual method effects, including bulk operations.
A Doctrine flush may still be inside an outer transaction: publishing cache state
before commit can expose aborted writes. Use an established after-commit mechanism
or a cache policy that cannot publish uncommitted state; do not invent a nonexistent
transaction callback API. Database rollback does not undo Redis writes.

## Wiring and evidence

Select the decorator through actual aliases/decorates configuration and preserve
inner adapter signatures, including optional flush flags. Do not infer a service ID
from a mandatory `Doctrine/Repository` path. Existing OAuth adapter:
`src/Auth/Infrastructure/Repository/OAuth/AccessTokenRepository.php`; decorator:
`src/Auth/Infrastructure/Cache/CachedAccessTokenRepository.php`.

Test observable results and inner call counts for hits, misses, absence, exceptions,
write/bulk invalidation, and collision-sensitive argument changes. Status tests must
cover an existing cached entry and the exact consumer meaning. Mutable aggregate
fixtures must not leak state through static reuse. Use a disposable database and a
second connection for transaction-visibility claims; apply the
[PostgreSQL skill](../../postgres-remediation/SKILL.md) when persistence is affected.
