---
name: cached-repository
description: Add or change a Baander repository cache decorator with explicit keys, status semantics, invalidation, and transaction behavior. Use when repository caching is requested; do not cache all finders or authentication decisions by default.
---

# Cached repository

Read the complete repository contract, selected inner adapter, cache configuration,
existing decorators, and consumers. Discover nested feature paths and effective
service aliases. Run GitNexus impact before changing existing symbols.

Choose which operations benefit from caching and define freshness and failure
semantics before implementation. Read [cache contract](references/cache-contract.md)
for object/status strategies, invalidation, transactions, and verification. Scope
keys to every argument affecting a result; do not derive a cache design from method
names alone.

Use [repository rules](../../rules/ddd-repositories.md) and current
`src/Shared/Infrastructure/Cache/CacheTags.php`. Add only tags with real consumers.
Coordinate shared tags and services configuration with their assigned owner.

Implement the decorator against the existing signatures and select it deliberately
in effective service wiring. Preserve domain behavior and database exceptions;
cache fallback must not catch a repository error as though it were a cache outage.
Do not claim race freedom or atomic cache/database updates without evidence.

Test hits/misses, failures, complete keys, invalidation, status meaning, and rollback
behavior relevant to the chosen contract. Use independent mutable fixtures and
mocked cache/network services with `baander.app` test identities. Run focused checks;
a full suite is justified by impact, not required for every decorator.
