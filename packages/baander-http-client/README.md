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

[`AddressClassifier`](src/AddressClassifier.php) decides whether an address is
public. An IPv6 address in an IPv4-mapped, IPv4-translated, IPv4-compatible,
NAT64 (`64:ff9b::/96`), 6to4 or Teredo form is judged by the IPv4 address it
carries; for Teredo, the server and the client address must both be public.
Site-local `fec0::/10` and the local-use NAT64 prefix `64:ff9b:1::/48` are never
public. The policy applies it to every DNS answer, and the application's webhook
policy applies it to webhook destinations.

Construct [`PublicHttpClient`](src/PublicHttpClient.php) with a Symfony HTTP client
and that policy. `request()` takes the method, URL and exact allowed initial hosts.
The policy requires HTTPS and public resolved addresses; the client pins the
validated address and disables automatic redirects. Optional redirect domains
permit bounded, revalidated redirects for bodyless GET requests. Request options
are limited to headers, body and time budgets. `content()` reads a response body
up to a byte limit, 16 MiB unless the caller names another; a longer body raises
[`ResponseTooLargeException`](src/ResponseTooLargeException.php) and cancels the
response, and no more than the limit is held. `stream()` exposes response chunks.
Provider credentials, rate limits and response interpretation remain in the application.

The source classes stay together in [src/](src/). [Package tests](tests/)
cover destination policy and client behavior. Application transport wiring,
provider integration and native DNS lifecycle tests stay in the application's
test tree.

The root [Unit suite](../../phpunit.xml.dist) discovers the package's tests.
Use the existing [backend runners](../../docs-book/part-2-developer-guide/testing/README.md)
from the repository root.

The application owns transport registration in
[services_packages.yaml](../../config/services_packages.yaml). The package has no
separate executable or build step.
