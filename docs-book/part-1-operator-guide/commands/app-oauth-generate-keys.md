# app:oauth:generate-keys

Generate an RSA key pair (2048-bit) for OAuth2 JWT signing. Writes the private and public keys to the configured paths and restricts both files to mode `0600`.

## Quick start

```bash
make exec cmd="php bin/console app:oauth:generate-keys"
```

## Details

The command has no arguments or options. Key paths come from the container parameters `oauth_keys.private_key_path` and `oauth_keys.public_key_path`; the private key's parent directory is created if it does not exist.

If the private key already exists, the command prompts for confirmation before overwriting. Answering no aborts with success and leaves the existing keys untouched. In non-interactive mode (e.g., when invoked by `app:dev:setup`), the overwrite prompt is skipped.

Both the private and public key files are written with permission mode `0600`.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Keys generated (or overwrite declined / aborted by user) |
| 1 | Failure — private key directory could not be created, key generation failed, or a key file could not be written |

## Tips

- Run this once during initial setup. The keys are reused across restarts.
- Never commit the generated private key to version control.
- Re-running in interactive mode asks before overwriting; in non-interactive mode it overwrites without prompting.
