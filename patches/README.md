# Dependency patches

## Symfony Redis Messenger delayed promotion

`symfony-redis-messenger-recoverable-delays.patch` targets the locked
`symfony/redis-messenger` 8.0.8. Composer Patches 2.0.0 applies it; the reviewed
SHA-256 is recorded in `patches.lock.json`. Keep that lockfile available before
Composer installation, including in production image builds. The upstream package
retains its MIT license.

The upstream transport removes a due sorted-set member before adding it to the
stream. A rejected write or interruption can therefore lose an accepted retry.
The patch selects a due member without removing it, uses the existing transport
insertion path, then removes the exact member only after insertion succeeds. Body,
headers, serializer behavior and existing delayed-member formats are preserved.
Each poll promotes at most 100 messages.

This is recoverable, at-least-once promotion, not an atomic or exactly-once transfer.
Concurrent pollers or interruption after insertion can produce duplicate deliveries.
Consumer effects still need their existing stable operation IDs and idempotency.
Malformed queue members are retained and reported, not silently discarded. Poison
members and Redis resource exhaustion can block promotion; quarantine and explicit
admission/backpressure remain separate work. The patch does not prove Redis writes
survive a host crash.

After dependency upgrades, verify applicability against a clean Composer install
and run `bash scripts/test-messaging-container.sh`. The delayed-promotion tests
exercise rejected stream writes, interrupted delayed-member removal, future due
times and payload preservation against disposable Redis. Never skip a failed patch
or relax these regressions to complete an upgrade. Remove the patch only after an
upstream release provides the required recovery behavior and the tests pass.
