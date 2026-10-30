# Backup and Recovery

Recovering Baander means bringing its database, queued work, media and credentials
back to a compatible point. A database export is useful, but it does not include
source files or Redis deliveries. This chapter describes what to capture and how
to assess a recovery before replacing any live data.

The repository does not provide a coordinated application backup/restore command
or a qualified recovery-time guarantee. The procedure below is a maintenance
checkpoint and recovery checklist. Test the complete recovery with your storage
and credentials in isolation before relying on it. The separate registry has an
[encrypted snapshot runbook](registry.md#backups-and-recovery).

## Choose the recovery set

Start with the [storage inventory](storage-and-data.md). Record the actual volume
identities and endpoints, not just the recipe names.

| Capture | Why it belongs in the set |
|---|---|
| Main PostgreSQL database | Accounts, OAuth state, libraries, catalog, settings, outbox, scheduler state, failed messages, job history and execution/deployment journals |
| Redis state and its persistence configuration | Pending deliveries and consumer state coexist with sessions, caches and security state; losing the stream can lose work already handed off by the outbox |
| Source media and application storage | Original media and uploaded assets may have no other recoverable copy |
| Conversion storage and shared media state | Useful retained output and learning state; regeneration still needs sources and compatible runtime records |
| Configuration and secrets | Environment files, OAuth keys, signing secrets, provider credentials, database/Redis credentials and TLS material |
| Code and deployment inventory | Exact revisions/images, migration history, worker manifests, boot identities, host credentials and storage mapping |

Keep secret material in private recovery storage, separate from public reports.
Record which captures belong together and when writers stopped. Hashes can detect
accidental file changes; they do not establish that the stores describe the same
point in the application's work.

## Capture a maintenance checkpoint

Use the installed `dc` context from [Getting Started](getting-started.md), on the
installation host in its checkout. These steps interrupt new work.

1. **Quiesce writers.** Prevent new browser/API writes, stop independent console
   jobs and drain web. Retire the worker pair through its matching
   [host controller](background-work.md#stop-replace-or-recover-workers). Confirm
   both units have retired; an expired lease or an unreachable host is insufficient.
   Do not capture a supposed offline checkpoint while an encoder or consumer can
   still write to it.
2. **Preserve the database.** Export the main database using credentials with
   access to all its application objects. The source-installation database service
   has PostgreSQL client tools. In a private, newly created host directory, an
   example export is:

   ```bash
   umask 077
   backup_dir=$(mktemp -d)
   dc exec -T database sh -ec 'pg_dump --format=custom --username="$POSTGRES_USER" --dbname="$POSTGRES_DB"' > "$backup_dir/application.dump"
   ```

   Check the command's exit status and stderr. A failed command can leave a partial
   file; do not accept it as a backup. This exports one database, not cluster roles
   or tablespaces. Preserve any required role definitions separately. PostgreSQL's
   [pg_dump reference](https://www.postgresql.org/docs/18/app-pgdump.html) explains
   the format and scope.
3. **Preserve queues and files.** Capture Redis using the persistence/backup method
   configured for that deployment, and copy or snapshot the identified media and
   application volumes while their writers remain stopped. The repository Compose
   file declares no durable Redis volume. If you have not established a recoverable
   Redis capture, stop here: do not recreate Redis or describe the set as preserving
   pending work. Never copy a live PostgreSQL data directory as a substitute for
   a database-aware backup.
4. **Retain the matching configuration.** Copy the private configuration and
   deployment inventory into protected recovery storage. Include the retired
   worker pair's records so restoration does not introduce ambiguous old ownership.
   Keep this set outside the volumes it is intended to recover.
5. **Verify before resuming.** Check archive readability and file inventories,
   then exercise a separate restore as below. Archive readability alone does not
   prove application recovery. To resume the original installation, follow the
   [upgrade start checks](upgrading.md#standard-update), including a fresh worker
   boot after retirement.

The capture is not complete if a required store or secret is missing. Record that
limit explicitly. Do not compensate by flushing queues or deleting failed jobs.

## Restore in isolation first

Prepare fresh, separate storage with the same required database extensions and
compatible code. Keep it unreachable from ordinary clients and external delivery
services. Do not point an experimental worker at the live database, Redis or media
volumes.

1. **Identify the recovery point.** Choose a matching database, queue, file and
   configuration set. Determine what work or user changes happened after it.
   A restore may replay external side effects; notifications already delivered
   cannot be recalled by restoring a database.
2. **Restore the database and files.** Restore the custom archive into the fresh
   database with `pg_restore --exit-on-error`, using the target's explicit connection
   settings. Provision required roles/extensions first and stop on any restore
   error. Consult the [pg_restore reference](https://www.postgresql.org/docs/18/app-pgrestore.html)
   for target selection. Restore files at the paths the recovered catalog expects.
   Restore Redis only according to the captured deployment's verified procedure.
3. **Resolve execution ownership.** Keep background execution stopped until old
   hosts and both old units are accounted for. Restored leases, runtime rows or
   IPC files do not prove that an old process is gone. Use the controller and
   manifest matching those records; stop if creation or retirement remains uncertain.
4. **Check the recovered application.** Validate configuration and schema
   compatibility, sign in, inspect libraries and catalog records, and check source
   access. Start a fresh admitted worker pair only after ownership is resolved.
   Observe readiness, pending/failed work and a representative playback. Account
   for missing files and jobs before declaring the recovery usable.
5. **Decide the cutover separately.** Record losses and failed checks. Replacing an
   existing target requires that target owner's approval and a preserved copy of
   its current state. Keep the isolated recovery separate until those decisions
   are made.

No general live merge of two application databases or queue histories is provided.
A successful isolated restore establishes only the exercised recovery set and
environment. It does not establish an unmeasured recovery point or time objective.

## Partial failures

- **Derived output is missing:** check sources and permissions first. The audio
  path can rebuild an evicted completed rendition; other media recovery follows
  its execution state. Do not delete runtime rows to force work. See
  [Transcoding](transcoding.md).
- **Redis data is lost:** PostgreSQL still contains job history and outbox data,
  but those are not a complete copy of acknowledged stream deliveries. Inventory
  missing work and use supported task-specific retries. Do not promise that a
  rescan recovers every message.
- **Credentials are lost or compromised:** restoration and rotation have different
  effects. Follow [Security](security.md) before bringing old credentials back.
- **The registry is lost:** use its separate snapshot and fresh-cluster recovery
  procedure in [Registry](registry.md). Application PostgreSQL is not its datastore.

For code/schema compatibility during a rollback, see [Upgrading](upgrading.md#rollback).
