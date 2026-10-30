# Registry

The registry is a separately operated service that lets a client find a registered
server by its public identity. It has its own API, datastore, credentials, health
checks and backups. Running the Baander web application and its background workers
does not start this service.

Baander's PHP Discovery feature stores local server and pairing records in the
application database. It does not call this standalone registry. You do not need
to deploy the registry to follow the ordinary application installation. Use this
chapter when you are responsible for the registry itself.

## What runs

The public registry API is a C++20 TLS service backed by rqlite, which replicates
its SQLite data between voting members. The deployment inventory defines five
voters; authoritative operations require a majority of three. Additional API-only
hosts do not become voters. Database HTTP and Raft traffic stay on the private
network and use authenticated TLS.

A registration supplies a public identity, HTTPS server URL, name, version and
private client credential. A first claim also requires an operator-issued
enrollment capability bound to that identity and credential. Later heartbeats
prove ownership with the credential; they update the registered metadata and
revision. A lookup treats a server as offline after ten minutes without a heartbeat,
but its identity reservation remains. Keep owner credentials private.

See the [service contract](../../relay/README.md#contract) for request validation,
enrollment and response details. Enrollment issuance is an operator task; exposing
the enrollment signing key would grant authority to create new registrations.

## Provision and operate the registry

Use the [private inventory and container recipe](../../relay/README.md#container-packaging-and-private-inventory).
Its Compose files and `/etc/baander-registry/inventory.env` are separate from the
application's `dc` context. Follow its certificate, UID/GID, port and volume
requirements before starting services. The public API must not proxy rqlite
administration endpoints.

Voter startup modes have distinct purposes:

| Mode | Required state | Result |
|---|---|---|
| `bootstrap` | Reviewed first creation with empty storage and all five expected identities | Creates the intended cluster; it does not shrink the voter count to fit reachable hosts |
| `join-new` | An explicitly provisioned empty member joining an existing cluster | Joins using the reviewed membership and identity |
| `restart` | Existing native state and its matching recorded voter identity | Reopens that member without bootstrapping a replacement cluster |

After successful creation or joining, switch that inventory to `restart` and
recreate the container with the same storage. Inspect membership before and after
changes. Upgrade one voter at a time while quorum remains available. Do not reuse
legacy `relay_data` or local SQLite volumes as native rqlite storage, and do not
use `docker compose down -v` against the operator inventory.

## Readiness and failures

| Signal | What it establishes | What it does not establish |
|---|---|---|
| Registry `GET /health` | The public API can answer its liveness request | Database quorum, backup freshness or authoritative lookup availability |
| Registry `GET /ready` | An authoritative schema/column read succeeded | Regional latency, spare capacity or recoverability from a backup |
| Registration/lookup `503` | The request could not obtain the required result, including unavailable quorum or bounded capacity | That an identity is absent; do not treat it as a `404` |
| Backup health | The recurring worker's capture/upload status and freshness | A destination-qualified recovery point or recovery time |

These are registry endpoints, independent of the application endpoints with the
same names. Probe with the configured TLS hostname and trusted CA. Do not disable
certificate verification to make a health check pass.

If the API is alive but unready, inspect API logs, database reachability, TLS and
credentials, then voter membership and available quorum. Preserve state when a
member fails to restart. The startup guard deliberately refuses mismatched or
incomplete storage instead of repairing it silently.

The repository provides no general quorum-loss repair procedure. It rejects
`raft/peers.json` recovery files. Do not reduce the expected voters, delete state
or start a new cluster over the old storage to clear a readiness failure. Resolve
the incident through a reviewed offline recovery plan.

## Backups and recovery

The optional [encrypted backup worker](../../relay/backup/README.md) captures an
authenticated snapshot immediately and every 300 seconds. Deploy one designated
worker. It encrypts with age and uploads completed ciphertext to an S3-compatible
destination; it does not elect a replacement worker or manage bucket retention.

Keep the private recovery identity outside the backup host and its upload
credentials. The worker receives only encryption recipients. Recovery needs
separate destination read access and database load authority. Losing the recovery
identity makes the encrypted snapshots unrecoverable.

Follow the runbook to inspect the last successful capture and chosen object key.
Its default freshness threshold is 540 seconds from capture start, not upload
completion. A recent upload does not demonstrate a five-minute recovery point.

Recover a chosen snapshot on a separate recovery machine, verify decryption and
SQLite integrity, and load it into a fresh cluster with separate storage. Keep
the public API stopped until restored schema and rows are checked. The detailed
[recovery procedure](../../relay/backup/README.md#recover-a-chosen-snapshot) does
not authorize overwriting an existing cluster.

Local disposable contracts cover backup and fresh-cluster restore. The actual
destination, five-minute recovery point, 30-minute recovery time, regional
deployment and whole-host resource capacity remain unqualified. Review the
[release acceptance limits](../../relay/README.md#release-acceptance) before
relying on those targets.
