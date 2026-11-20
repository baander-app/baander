# Redis rate limiter

`baander/redis-rate-limiter` gives Baander's Symfony rate limiters a shared Redis
state and administration API. It is an internal package in the
`Baander\RedisRateLimiter` namespace. It requires PHP 8.5, `ext-redis` and the
Symfony components listed in [composer.json](composer.json).

Register [`BaanderRedisRateLimiterBundle`](src/Bridge/Symfony/BaanderRedisRateLimiterBundle.php)
and provide a [`RedisRateLimiterStore`](src/Storage/RedisRateLimiterStore.php). The store
accepts a borrowing closure with the signature
`Closure(callable(\Redis): mixed): mixed`; the application supplies its Redis pool
through [RedisRateLimiterStoreFactory](../../src/Shared/Infrastructure/Redis/RedisRateLimiterStoreFactory.php).
The bundle's [compiler pass](src/Bridge/Symfony/RateLimiterCatalogPass.php) replaces configured
Symfony factories for `fixed_window`, `sliding_window` and `token_bucket` policies.
The bundle also registers the administration service and its interface alias.
Application limiter names, capacities and intervals stay in application configuration.

[`RedisRateLimiter`](src/Limiter/RedisRateLimiter.php) exposes Symfony's consume, reserve
and reset operations. Redis scripts read and commit versioned snapshots; failed
storage access raises `RateLimiterUnavailableException`.
[`RedisRateLimiterAdministration`](src/Administration/RedisRateLimiterAdministration.php) lists
definitions and clears limiter generations. Application routes, console commands
and error responses remain outside the package. See the current
[service wiring](../../config/services_packages.yaml) and [bundle registration](../../config/bundles.php).

The source groups [limiter policies](src/Limiter/), [storage](src/Storage/),
[administration](src/Administration/) and [exceptions](src/Exception/).
[Symfony integration](src/Bridge/Symfony/) contains the bundle and compiler pass.
[Package tests](tests/) follow those groups. Application pool-failure mapping,
provider settings, HTTP responses, commands and concurrency integration remain
covered in the application's test tree.

The root [Unit suite](../../phpunit.xml.dist) discovers the package's tests.
Use the existing [backend runners](../../docs-book/part-2-developer-guide/testing/README.md)
from the repository root.

Production embedding belongs to the application's
[TypePHP configuration](../../tools/typephp/application.yml). The package has no
separate executable or build step.
