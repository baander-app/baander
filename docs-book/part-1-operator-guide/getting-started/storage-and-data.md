# Storage and Data

A database backup cannot recover a missing music file, and a copy of converted
video cannot recover accounts or library membership: Baander keeps those things
in different places. Before maintenance, identify each store and the processes
that can still write to it.

This chapter describes the data to account for. Use [Backup and Recovery](../maintenance/backup-and-recovery.md)
for capture and recovery prerequisites, and [Source Installation](installation.md)
for the source-installation volume layout.

## What must be preserved

**Authoritative data** records choices or state that another store cannot simply
recreate. **Derived data** can be produced again only while its required sources,
configuration and execution path remain available. **Transient state** describes
current activity or short-lived credentials; losing it can still interrupt work
or change security behavior.

| Store | Writers and contents | Preservation meaning |
|---|---|---|
| Main PostgreSQL database, selected by `DATABASE_URL` | Web, console commands and background processes write accounts, credentials/token records, libraries, memberships, catalog, settings and job history. Background processes also write scheduling, outbox, failed-message and execution/ownership records. | Authoritative application and coordination state. Restoring catalog records alone does not restore the instance. |
| Redis | Web and background processes write caches, PHP sessions, authentication replay/nonce state, rate limits and queued deliveries. Consumers update stream delivery state. | Mixed derived, transient and pending-work state. A flush can lose queued work and security/session state. It is not a general cache repair. |
| Source media | The operator supplies originals. Authorized media-file deletion can remove them where the application has writable access. Scanners, ingestion and encoders read them. | Authoritative bytes. Catalog entries and converted output are not backups of originals. |
| Application `storage/` | Media services store uploaded or extracted images and generated image derivatives; web and background tasks use this storage. | Mixed stored assets and derived output. Preserve assets whose original upload or provider content may no longer be available. Do not discard the whole directory as a cache. |
| `CONVERT_STORAGE_PATH` | Worker-owned media execution produces video output and audio renditions; web reads delivery files. Quality learning and encoder-capability data also use this root. | Mostly derived output, with learning/configuration observations worth retaining. Regeneration needs source media and working execution. |
| Application `var/transcode_state/` | A shared path required by the worker media topology. | File state does not grant execution authority. PostgreSQL runtime records govern ownership; copying these files cannot resume an old encoder. |
| Configuration, keys and deployment records | Operators maintain environment files, OAuth keys, signing and provider secrets, worker manifests and credentials. | Preserve privately with the versions and identities needed to interpret the data. Key rotation has separate effects described in [Security](../maintenance/security.md). |
| Worker private IPC volume | The admitted control and execution units exchange their private runtime communication here. | Live pair state. Keep it private to that pair; do not restore it as permission to start replacement execution. |
| Optional registry datastore and secrets | The separate registry writes registration/discovery state through rqlite; its deployment also has independent certificates and backup encryption keys. | Separate authoritative service data. Main PostgreSQL backups do not cover it; see [Registry](../administration/registry.md). |

Job monitor history and the `failed_messages` failure transport live in
PostgreSQL; pending asynchronous stream deliveries live in Redis. A job-monitor
row describes processing; it is not a replacement copy of every message waiting
in the stream. Keep the two apart when investigating missing work or planning a
recovery.

The outbox, scheduler and worker ownership tables are also operational data.
Restoring them can bring back old descriptions of work or ownership, and restored
records alone cannot establish which processes are still running. Recovery needs
the old deployment's state resolved before replacement work is admitted; see
[Background Work](../workers/background-work.md).

## Shared media paths

The source-installation recipe uses the following named volumes. These are the
recipe's names, not a promise that every installation uses them. Check the
actual Compose configuration and worker manifest when identifying your data.

| Recipe volume | Application path | Purpose |
|---|---|---|
| `postgres_data` | PostgreSQL's configured data directory | Main database files |
| `baander-source` | `/media`, with `MEDIA_STORAGE_PATH=/media/library` | Original library files |
| `baander-storage` | `/var/www/html/storage` | Application media assets and derivatives |
| `baander-conversions` | `/storage/conversions`, selected by `CONVERT_STORAGE_PATH` | Converted media and quality-learning state |
| `baander-transcode-state` | `/var/www/html/var/transcode_state` | Shared media file state |

Web, scanners, catalog ingestion and encoders need consistent absolute paths.
Seeing a source file inside `app` does not prove that a worker can read it.
The admitted worker topology uses shared named volumes and read-only source
media. The setup recipe also mounts source media read-only into web, so deleting
source files requires separately authorized writable access there. Derived storage
needs write permission for the image's runtime UID and GID.

The worker manifest calls writable media mounts `derived`. That admission role
sets access and topology; it does not mean that every file beneath the mount is
disposable. Application storage can hold the only retained copy of an upload,
and the conversion root also holds learned quality state.

The repository Compose file does not declare a persistent Redis volume. Do not
assume that its queued deliveries survive container replacement, or that Redis
data is part of the PostgreSQL volume. A plan to preserve the queue must account
for the Redis deployment actually in use; see [Backup and Recovery](../maintenance/backup-and-recovery.md).

## Before changing storage

Record the database and Redis endpoints, volume identities, mount targets,
source paths and deployed code/image versions. Keep secrets out of shared
reports. Include the web processes, console jobs and worker pair when identifying
writers; stopping web alone does not stop background writes.

A consistent restore must reconcile database state, queued work, files and
credentials; copying each live store on its own does not produce such a
checkpoint. Converted output may be regenerated while its inputs remain
available, but that does not guarantee that every pending task can be replayed
or that an old execution can be resumed.

Follow [Backup and Recovery](../maintenance/backup-and-recovery.md) before replacing a store,
and [Upgrading](../maintenance/upgrading.md) before changing its schema or deployed code.
Registry capture and restore have their own credentials, destinations and
qualification limits; keep those procedures separate from application recovery.
