# app:auth:rotate-secrets

Rotate OAuth 2.0 keys, generate a new encryption key, and invalidate all existing tokens. Use this when you suspect a secret leak, when a team member with key access leaves, or as part of regular security maintenance.

## Quick start

```bash
make exec cmd="php bin/console app:auth:rotate-secrets"
```

With a larger key size:

```bash
make exec cmd="php bin/console app:auth:rotate-secrets --key-size=4096"
```

## Options

| Option | Default | Description |
|--------|---------|-------------|
| `--key-size` | `2048` | RSA key size in bits: `2048` or `4096` |

## What the command does

1. **Backs up** the existing private and public keys to `.old` files
2. **Generates** a new RSA key pair and replaces the key files
3. **Generates** a new encryption key (for authorization code encryption)
4. **Truncates** all OAuth token tables (`oauth_access_tokens`, `oauth_refresh_tokens`, `oauth_auth_codes`)
5. **Invalidates** the OAuth token cache
6. **Outputs** the new encryption key and next steps

## Action required after running

The command generates a new encryption key that you must add to your configuration. It will be printed in the output — add it to your `.env` file:

```
AUTH_ENCRYPTION_KEY=<printed_value>
```

Then restart the application:

```bash
make stop && make start
```

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Secrets rotated successfully |
| 1 | Invalid key size, missing key files, or generation failure |

## Tips

- All users will be logged out and must re-authenticate after running this command.
- The old keys are backed up to `<keyfile>.old` — remove them once you've confirmed everything works.
- For zero-downtime key rotation (where existing tokens remain valid), manual key replacement is required instead. See the [security guide](../security.md#rotating-oauth-20-keys).
- Running this in production will immediately invalidate every active API session.
