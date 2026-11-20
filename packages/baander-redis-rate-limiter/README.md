# Redis rate limiter

`baander/redis-rate-limiter` gives Baander's Symfony rate limiters a shared Redis
state and administration API. It is an internal package in the
`Baander\RedisRateLimiter` namespace. It requires PHP 8.5, `ext-redis` and the
Symfony components listed in [composer.json](composer.json).

Register [`BaanderRedisRateLimiterBundle`](src/BaanderRedisRateLimiterBundle.php)
and provide a [`RedisRateLimiterStore`](src/RedisRateLimiterStore.php). The store
accepts a borrowing closure with the signature
`Closure(callable(\Redis): mixed): mixed`; the application supplies its Redis pool
through [RedisRateLimiterStoreFactory](../../src/Shared/Infrastructure/Redis/RedisRateLimiterStoreFactory.php).
The bundle's [compiler pass](src/RateLimiterCatalogPass.php) replaces configured
Symfony factories for `fixed_window`, `sliding_window` and `token_bucket` policies.
Application limiter names, capacities and intervals stay in application configuration.

[`RedisRateLimiter`](src/RedisRateLimiter.php) exposes Symfony's consume, reserve
and reset operations. Redis scripts read and commit versioned snapshots; failed
storage access raises `RateLimiterUnavailableException`.
[`RedisRateLimiterAdministration`](src/RedisRateLimiterAdministration.php) lists
definitions and clears limiter generations. Application routes, console commands
and error responses remain outside the package. See the current
[service wiring](../../config/services.yaml) and [bundle registration](../../config/bundles.php).

Production embedding belongs to the application's
[TypePHP configuration](../../tools/typephp/application.yml). The package has no
separate executable or build step.
