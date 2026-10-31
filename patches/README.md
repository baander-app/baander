# Dependency patches

## PHP 8.5 ZTS initialization

`swoole-bundle-z-engine-php85-zts-init.patch` initializes nullable typed static
properties and resolves Zend executor/compiler globals through thread-local
storage on ZTS builds. Stable upstream 8.5.2, revision
`5c06419c4a1aaf87fda0d8065dd050c7e3d5380e`, still rejects thread-safe PHP and
does not contain these changes. The patch remains necessary for Baander's ZTS
application image; its hash is recorded in `patches.lock.json`.

Clean patch application proves source compatibility only. Qualification must
initialize z-engine through FFI inside the rebuilt application image and boot the
generated container. Host NTS execution cannot establish that the ZTS globals
and native layouts are correct.

## Review inventory

The tooling [dependency policy](../packages/baander-tooling/resources/php-dependencies.json)
classifies Composer projects, runtime declarations, patches and forks. Reviewed
upstream references identify comparison points; a changed reference requires a
new review and does not establish that a patch is obsolete.

## Symfony Redis Messenger delayed promotion

`symfony-redis-messenger-recoverable-delays.patch` targets the locked
`symfony/redis-messenger` 8.1.10, revision
`484058b2e3055dc56be9b1463cf510e06228542c`. Composer Patches 2.0.0 applies it; the reviewed
SHA-256 is recorded in `patches.lock.json`. Keep that lockfile available before
Composer installation, including in production image builds. The upstream package
retains its MIT license.

Upstream 8.1.10 leases a due sorted-set member by moving its score forward before
inserting it into the stream. That improves recovery over the previous removal-first
algorithm, but does not provide Baander's bounded promotion and immediate retry
behavior. The retained patch selects a due member without removing it and runs a
Lua script that checks its score, inserts the stream entry, then removes the exact
member. A failed insertion retains the original due member. The payload uses the
native client's serializer, preserving bodies, headers and existing delayed-member
formats. Each poll promotes at most 100 messages.

This is recoverable, at-least-once promotion. Redis executes the script without
interleaving commands, but does not roll back writes after a script error. A failed
removal after insertion can therefore produce duplicate deliveries on retry.
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
