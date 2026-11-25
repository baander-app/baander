# Outbound provider security

Use this reference when diagnosing a provider request that is refused, times out or follows different redirect rules from another integration. It records the scope and limits of the transport checks; provider availability and setup are covered in [External APIs](../media/external-apis.md).

## Outbound provider requests

Metadata lookups, Spotify token acquisition, IPRD catalog downloads and browser-push
delivery use approved HTTPS providers on port 443. Each request validates the host
and every DNS answer, rejects non-public destinations, and pins one approved
address while preserving hostname verification. An IPv6 answer that carries an
IPv4 address, such as a NAT64 (`64:ff9b::/96`) or 6to4 address, is judged by that
IPv4 address. Proxies and automatic redirects are disabled, and each request uses
a fresh connection. No LAN exception applies.

An answer is read only up to a size limit: 16 MiB for metadata answers, 10 MiB
for a Cover Art Archive image and 64 MiB for the IPRD catalog. A longer answer
fails the request, as an unreachable provider does. A cover's image type is read
from its bytes, not from the type the archive sends.

Cover Art Archive is the only redirect exception: it may follow at most three GET
redirects within `coverartarchive.org`, `archive.org` or their label-boundary
subdomains. Every hop repeats the destination checks and drops credentials and
cookies when crossing origins. Other metadata and IPRD redirects are refused.

Push delivery revalidates endpoints already stored in subscriptions, not just new
subscription input. A 3xx is a failed delivery, while 404/410 retain the existing
expired-subscription cleanup. Minishlink still encrypts the payload and signs VAPID;
the transport does not automatically replay push requests. Delivery logs omit
provider API-key query strings, endpoint credentials and raw transport failures.

DNS resolution and HTTP share one monotonic request budget, capped at thirty seconds, across a CAA redirect chain. Push delivery likewise spends one thirty-second budget on DNS and sending, with connection time capped at five seconds or the remaining budget. A fresh PHP helper performs the complete native A/AAAA lookup while the calling coroutine waits without blocking its worker. Timeout, cancellation, invalid replies and exhausted helper capacity stop delivery.

Cleanup and operating-system scheduling can extend the observed return time; existing provider retries can also extend the overall operation. These controls do not promise a hard real-time or whole-operation deadline. Webhooks, operator-configured lyrics, mail, tracing and browser playback have separate destination policies. The [application threat model](../../part-2-developer-guide/architecture/application-threat-model.md#provider-transport-boundaries) records the scope and qualification limits.
