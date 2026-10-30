# Encrypted Registry backups

For the registry's place in the installation and its separate recovery boundary,
see the [operator manual](../../docs-book/part-1-operator-guide/registry.md#backups-and-recovery).
Application database and media recovery are covered by
[Backup and Recovery](../../docs-book/part-1-operator-guide/backup-and-recovery.md).

The backup worker captures an authenticated rqlite snapshot at startup and every
300 seconds. It streams plaintext into age, uploads only completed ciphertext,
and retains each successful capture under a different timestamped object key.
The worker does not delete old objects or enable bucket-native versioning.

Deploy one designated worker. It can request a snapshot from any reachable voter;
rqlite forwards the request to its leader. The configured endpoint must remain
reachable. This worker has no election or destination failover mechanism.

## Prepare the keys and destination

1. On a separate recovery machine, generate an age identity in private storage:

   ```sh
   umask 077
   age-keygen -o registry-recovery.key
   age-keygen -y registry-recovery.key > recipients.txt
   ```

   Keep `registry-recovery.key` outside the backup host and S3 credentials. Keep a
   recoverable copy in independently controlled storage. Copy only `recipients.txt`
   to the backup worker. Losing the identity makes its backups unrecoverable.

2. Provision an existing S3-compatible bucket and credentials scoped to its backup
   prefix. The worker needs upload and object-inspection access; it does not need
   deletion access. Recovery operators need separate read access. Configure
   retention or bucket versioning separately if required by your storage policy.

3. Create a dedicated rqlite account with `backup` permission and provision a client
   certificate trusted by the voters. Keep that account separate from the public
   API account. The HTTP endpoint must use hostname-verified TLS and client
   authentication. Do not copy the CA signing key to this worker.

## Configure and start one worker

Copy `backup.example.json` to a private configuration directory as `backup.json`.
Copy `curl.example.conf` and `rclone.example.conf` to a separate private secrets
directory as `curl.conf` and `rclone.conf`. Replace all example values. Place
`recipients.txt`, `rqlite-ca.crt`, `client.crt` and `client.key` in that secrets
directory. The curl file holds the database username/password and TLS file paths;
the rclone file holds the S3 endpoint, region and credentials. Use an HTTPS S3
endpoint. These files are operator configuration and must not be committed.

Both directories and their files must be accessible to container UID/GID 10001.
Keep secret files private, for example owned by that UID with mode 0600 in a
0700 directory. The container mounts both directories read-only. Its persistent
status volume is separate from its temporary ciphertext storage.

Add these paths to an operator inventory file outside the checkout:

```dotenv
REGISTRY_BACKUP_CONFIG_DIRECTORY=/etc/baander-registry/backup-config
REGISTRY_BACKUP_SECRETS_DIRECTORY=/etc/baander-registry/backup-secrets
```

Validate the configuration, build the image and explicitly enable the service:

```sh
docker compose --env-file /etc/baander-registry/inventory.env \
  -f relay/docker/backup.compose.yml --profile backup config --quiet
docker compose --env-file /etc/baander-registry/inventory.env \
  -f relay/docker/backup.compose.yml --profile backup build
docker compose --env-file /etc/baander-registry/inventory.env \
  -f relay/docker/backup.compose.yml --profile backup up -d
```

The service is independent of voter startup. Do not enable a copy on every voter.
Its resource limits are additional to the API and voter limits; the existing
one-CPU/512-MiB whole-host target has not been qualified with backups enabled.

## Check progress and failures

The entry point accepts `once`, `run` and `health`, each with `--config PATH`.
`run` starts immediately, permits only one attempt at a time and skips missed
schedule ticks. The default attempt deadline is 240 seconds; it must remain below
the fixed 300-second interval. The default ciphertext limit is 256 MiB. Increase
the temporary storage and memory allocations together if changing that limit.

The worker rejects failed transport, a nonbinary response, an incomplete transfer,
a rqlite stream-error trailer or failed encryption before upload. Failed uploads
leave the previous successful status intact. Temporary ciphertext is removed after
each attempt. An interrupted upload may have reached object storage even when no
success was recorded locally; the next attempt uses a new key.

Read health independently from the recurring process:

```sh
docker compose --env-file /etc/baander-registry/inventory.env \
  -f relay/docker/backup.compose.yml --profile backup exec -T registry-backup \
  python3 /usr/local/bin/registry-backup health \
  --config /etc/baander-registry-backup/backup.json
```

The command prints JSON containing the latest attempt and last successful upload.
Success records include capture start, upload completion, object key and ciphertext
SHA-256. `object_key` includes the bucket and prefix but excludes the rclone remote
name. Health exits nonzero before the first success, after a failed attempt, or
when the capture age exceeds the interval plus attempt deadline: 540 seconds with
the defaults. Upload completion does not reset capture age. Monitor the container's
availability as well as its health; a stopped container cannot run its own probe.

That threshold reports operational freshness. It does not establish a five-minute
recovery point objective. Upload failures, endpoint outages and recovery timing
still require destination-specific qualification.

## Recover a chosen snapshot

Use a separate recovery machine with age, rclone, Python and the private recovery
identity. Select the exact bucket/prefix/object key from the saved status or a
reviewed destination listing. Use a new private directory so recovery cannot
overwrite an existing database:

```sh
umask 077
recovery_dir=$(mktemp -d)
rclone --config /private/recovery-rclone.conf copyto \
  backup:BUCKET/PREFIX/CHOSEN.sqlite.age "$recovery_dir/snapshot.sqlite.age" \
  --immutable --no-check-certificate=false
age --decrypt --identity /private/registry-recovery.key \
  --output "$recovery_dir/snapshot.sqlite" "$recovery_dir/snapshot.sqlite.age"
```

Stop if either command fails. A failed decryption may leave a partial output file;
remove it and do not load it. If an independently saved status record is available,
compare its ciphertext SHA-256 before decryption. Age verifies ciphertext integrity
with the recovery identity, but encryption alone does not identify who uploaded an
object; destination write permissions remain part of the trust boundary.

After successful decryption, check SQLite integrity:

```sh
python3 - "$recovery_dir/snapshot.sqlite" <<'PY'
import sqlite3
import sys
from pathlib import Path
with sqlite3.connect(Path(sys.argv[1]).resolve().as_uri() + '?mode=ro', uri=True) as db:
    if db.execute('PRAGMA integrity_check').fetchall() != [('ok',)]:
        raise SystemExit('Recovered SQLite integrity check failed')
PY
```

Provision separate fresh voter storage before using the authenticated
[`/db/load` API](https://rqlite.io/docs/guides/backup/#restoring-a-backup).
Keep the public Registry API stopped until the load succeeds and the restored
rows and schema are checked. Use recovery credentials with load authority; the
recurring worker's backup-only credentials are insufficient. Do not load over an
existing cluster or delete existing volumes as part of these instructions.

Real destination access, a five-minute recovery point objective, a 30-minute
recovery time objective and whole-host capacity remain unqualified. The local
contract exercises encryption, upload, download, decryption and fresh-cluster
restore with disposable credentials and storage.
