# app:oauth:generate-keys

Initialize an RSA key pair (2048-bit) for OAuth2 JWT signing when neither configured key file exists. Both files are created with mode `0600`.

## Quick start

```bash
make exec cmd="php bin/console app:oauth:generate-keys"
```

## Details

The command has no arguments or options. Key paths come from the container parameters `oauth_keys.private_key_path` and `oauth_keys.public_key_path`. Missing parent directories are created for both targets.

The command refuses either existing target, including a symlink, and does not prompt or overwrite in interactive or non-interactive mode. It also refuses identical private and public paths. To replace a key pair, use the [staged offline rotation procedure](app-auth-rotate-secrets.md). Pass `--skip-keys` to `app:dev:setup` when reusing initialized keys.

The pair is generated and checked before either file is written. Each target is created exclusively with mode `0600`. If a write fails, the command removes only files it can identify as created by that invocation; inspect the paths before retrying.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | New key pair generated |
| 1 | Existing or unsafe target, key generation or validation failure, or file write failure |

## Tips

- Run this once during initial setup. The keys are reused across restarts.
- Never commit the generated private key to version control.
- Re-running this command fails without changing existing keys. Use `app:auth:rotate-secrets` for replacement.
