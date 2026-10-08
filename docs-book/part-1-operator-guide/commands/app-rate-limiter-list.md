# app:rate-limiter:list

List every configured rate limiter with its effective configuration. The command shows what `GET /api/monitor/rate-limiters` returns in the admin API and what the admin Rate Limits tab displays.

## Quick start

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
| Cache pool | The pool that holds only this limiter's state, such as `cache.rate_limiter.auth_login_ip` |
| Description | What the limiter protects, or empty |

The list shows configuration only. It does not show which clients are currently limited.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | List printed |
