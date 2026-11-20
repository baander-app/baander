# Public HTTP client

`baander/http-client` validates public provider destinations before making outbound
requests. It is an internal Baander package in the `Baander\HttpClient` namespace.
It requires PHP 8.5, `ext-curl`, Guzzle PSR-7 and the Symfony components listed in
[composer.json](composer.json).

Construct [`PublicDestinationPolicy`](src/PublicDestinationPolicy.php) with a DNS
closure, `Closure(string, float): list<string>`. Its arguments are the hostname
and an absolute deadline measured with `hrtime(true) / 1e9`. Baander supplies this
closure through [`NativeDnsResolver::resolver()`](../../src/Shared/Infrastructure/Http/NativeDnsResolver.php),
keeping process and coroutine lifecycle handling in the application.

Construct [`PublicHttpClient`](src/PublicHttpClient.php) with a Symfony HTTP client
and that policy. `request()` takes the method, URL and exact allowed initial hosts.
The policy requires HTTPS and public resolved addresses; the client pins the
validated address and disables automatic redirects. Optional redirect domains
permit bounded, revalidated redirects for bodyless GET requests. Request options
are limited to headers, body and time budgets. `stream()` exposes response chunks.
Provider credentials, rate limits and response interpretation remain in the application.

The three source classes stay together in [src/](src/). [Package tests](tests/)
cover destination policy and client behavior. Application transport wiring,
provider integration and native DNS lifecycle tests stay in the application's
test tree.

The root [Unit suite](../../phpunit.xml.dist) discovers the package's tests.
Use the existing [backend runners](../../docs-book/part-2-developer-guide/testing/README.md)
from the repository root.

The application owns transport registration in
[services_packages.yaml](../../config/services_packages.yaml) and production embedding in its
[TypePHP configuration](../../tools/typephp/application.yml). The package has no
separate executable or build step.
