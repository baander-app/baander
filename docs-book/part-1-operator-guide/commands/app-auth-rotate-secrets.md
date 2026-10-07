# app:auth:rotate-secrets

Prepare a new OAuth secret bundle and invalidate existing grants during an offline
cutover. This is a one-shot operator command. It does not stop services, install
configuration, or replace active key files.

## Prepare and inspect

Run the command as the account that will own the secret files. Use a new absolute
bundle directory under an existing, canonical parent owned by that account and
not writable by its group or others. Paths may contain ASCII letters, digits,
slashes, underscores, dots, and hyphens. Symlink paths are rejected.

```bash
php bin/console app:auth:rotate-secrets prepare --directory=/srv/baander/secrets/oauth-2026-10 --key-size=2048
php bin/console app:auth:rotate-secrets validate --directory=/srv/baander/secrets/oauth-2026-10
```

The supported RSA sizes are 2048 (default) and 4096 bits. The command creates a
private directory containing `private.key`, `public.key`, `oauth.env`, and a
versioned checksum manifest. Validation checks file ownership, permissions, file
types, checksums, the RSA pair, and the exact configuration entries. It prints the configuration path, never secret values.
The bundle is never overwritten by this command; a failed preparation requires a
new directory. Keep any incomplete bundle private while investigating.

`oauth.env` contains `OAUTH_PRIVATE_KEY_PATH` and `OAUTH_PUBLIC_KEY_PATH`.
Provision these values through the deployment's secret
provider. The paths must resolve to the same validated files in every application
instance; container deployments must mount them at those paths. Preserve the old
configuration and files securely before changing anything.

## Offline cutover

1. Drain incoming traffic and stop **all** token issuers, resource servers, and
   background workers across every instance. Keep them stopped through database
   invalidation, any retries, and configuration replacement.
2. Run the operator command with PostgreSQL and the configured token cache
   reachable:

   ```bash
   php bin/console app:auth:rotate-secrets invalidate --directory=/srv/baander/secrets/oauth-2026-10 --offline
   ```

   `--offline` is your assertion that all application processes are stopped. The
   command cannot detect or fence another running instance. It validates the
   bundle, deletes access tokens, refresh tokens, authorization codes, device
   codes, and token metadata in one database transaction, then invalidates the
   token cache. The reported count includes code and metadata rows. Clients, scopes, and
   users are preserved.
3. After success, install both values from `oauth.env` while applications
   remain stopped. Deploy the same bundle to every instance.
4. Restart every instance, verify fresh authentication and protected API access,
   then resume traffic. Existing clients must authenticate again. Never repeat
   `invalidate` after service resumes: it would delete newly issued grants.

This procedure requires downtime. Database deletion, cache cleanup, and installing
configuration are separate operations; there is no distributed atomic cutover.
Replacing a signing key alone does not preserve tokens signed by the old key.

## Failure and recovery

| Result | Operator action |
|---|---|
| Bundle preparation or validation fails | No token invalidation was attempted. Inspect permissions and files; use a new directory for another preparation. |
| Token invalidation is unconfirmed | Keep all instances offline. A lost commit acknowledgement may mean deletion committed. Restore database connectivity and repeat invalidation with the same validated bundle. |
| Database committed, cache cleanup unconfirmed | Keep all instances offline. Restore cache connectivity and repeat invalidation with the same bundle. A zero-row retry still clears the token cache. |
| Configuration installation or restart fails | Keep traffic drained. Correct the configuration or restore the retained old configuration and key files consistently across all instances. Deleted grants cannot be recovered by restoring keys; clients still need fresh authentication. |

Offline retries are safe only while no process can issue new grants. Do not switch
configuration or resume service after an unconfirmed invalidation. Retain the old
bundle according to your secret-retention policy after verifying recovery.

Exit status is `0` for a confirmed successful action, `1` for an operational
failure, and `2` for invalid action/options. `--offline` is required only for
`invalidate`; `--key-size` applies only to `prepare`.
