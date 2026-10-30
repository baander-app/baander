# app:rate-limiter:list

List every configured rate limiter with its effective configuration. The command shows what `GET /api/monitor/rate-limiters` returns in the admin API and what the admin Rate Limits tab displays.

## Quick start

The Make examples below target the checkout's development preview. For an
installed instance, use the `dc` function from
[Getting started](../getting-started.md#command-context) and replace
`make exec cmd="php bin/console …"` with `dc exec app php bin/console …`.

```bash
make exec cmd="php bin/console app:rate-limiter:list"
```

## Details

The command prints a table with one row per limiter configured under `framework.rate_limiter` in `config/packages/framework.yaml`:

| Column | Content |
|--------|---------|
| Name | The limiter name that [app:rate-limiter:clear](app-rate-limiter-clear.md) takes |
| Policy | The Symfony limiter policy, such as `fixed_window` or `sliding_window` |
| Limit | Requests allowed per interval |
| Interval | The window or refill interval, such as `60 seconds`, or empty when the policy has none |
| Storage namespace | The Redis namespace that holds this limiter's generation and subject states |
| Description | What the limiter protects, or empty |

The API names the storage field `storageNamespace`. Its format is
`baander:rate-limiter:{<SHA-256 of the limiter name>}`; the digest separates limiter
namespaces without exposing subject identities.

The list reads compiled configuration, not Redis. It remains available to the CLI
when Redis is down and does not show which clients are currently limited. An HTTP
catalog request still passes through the API's authentication and rate-limit checks.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | List printed |
