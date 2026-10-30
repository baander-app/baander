# Understanding Baander

When a library scan finishes, the browser may still be waiting for its songs or
videos. Finding files, adding them to the catalog and preparing playback happen
in separate stages. Knowing which component owns each stage helps you choose the
right check before restarting anything.

Read this chapter first, then [Storage and Data](storage-and-data.md) and
[Getting Started](getting-started.md). If the instance is already installed, use
[Monitoring](monitoring.md) to find the evidence for the stage you are checking.

## The components

Baander serves a browser interface and an API from its web application. In the
source-installation recipe, nginx receives browser traffic and forwards requests
to the `app` service, whose Swoole server also maintains real-time connections.
PostgreSQL and Redis are shared dependencies. Background execution has its own
deployment, separate from the web service.

| Component | What it owns | What to check when work stops |
|---|---|---|
| nginx and the web application | HTTP requests, authentication, browser/API responses, real-time connections and delivery of available media | Web logs, readiness and the affected request; see [Monitoring](monitoring.md) |
| PostgreSQL | Accounts, libraries, catalog records, settings, job history, failed messages and durable execution/coordination state | Database availability, then the relevant task's stored status |
| Redis | Asynchronous message streams, caches, PHP sessions, rate limits and short-lived authentication checks | Connectivity and the relevant stream or subsystem; see [Configuration](configuration.md) |
| Worker deployment pair | Background consumption, scheduled work, outbox relay and media execution | Both units and actual work progress; see [Background Work](background-work.md) |
| Source and application storage | Original media, stored images and generated playback files | Shared paths, permissions and the expected files; see [Storage and Data](storage-and-data.md) |
| Optional registry | Server registration and discovery state in a separate service and datastore | Registry-specific signals; see [Registry](registry.md) |

The **worker deployment pair** consists of control and execution containers
admitted together under one deployment identity. Control holds the deployment
lease and coordinates work. Execution owns native processes, including encoders.
Each unit has its own resource limits; a private IPC volume connects them.
The host controller manages this pair from the trusted installation host.
Starting Compose's web service does not create the pair.

Here, a background worker means a process doing queued, scheduled or media work.
Swoole also calls its HTTP-serving processes workers. Reloading those web workers
and replacing a background deployment are different operations. A web restart
does not restart independently owned encoders. Use the lifecycle procedure in
[Background Work](background-work.md) when the background deployment needs attention.

## From files to playback

A library identifies media at a configured path. A scan discovers files and
records directory results. The application retains events in the PostgreSQL
**outbox** so the event can be delivered after the transaction commits. The
background outbox relay publishes that work, and consumers process subsequent
messages. Catalog ingestion reads the discovered files and creates catalog
records. A scan summary therefore describes discovery; it does not prove that
all ingestion or enrichment has finished.

The implemented catalog ingestion paths handle music and movies. Other accepted
library type values do not establish a complete ingestion workflow. Follow
[Library Management](library-management.md) for supported types, scan execution
and the checks for media that has not appeared.

Optional providers can enrich catalog entries with additional metadata, artwork
or lyrics. Their credentials and availability affect those tasks; they are not a
substitute for access to source files. See [External APIs](external-apis.md).

Playback then needs the catalog entry and readable media. Original delivery uses
the source file; converted audio or video also needs the relevant worker execution
and shared output storage. The web application serves available output while the
execution unit owns native encoding. See [Transcoding](transcoding.md) for the
playback paths and their failure signals.

## What a health result tells you

Web readiness checks PostgreSQL and Redis availability. Background-worker and
memory results are reported, but do not decide the web container's health.
A ready web application can therefore coexist with stalled background work.
Likewise, a recent worker heartbeat establishes a recent observation, not that
an outbox item was relayed or an encoder produced a playable segment.

Check the signal that belongs to the task: discovery results for scanning,
catalog entries for ingestion, delivery/failure records for queued work, and
output plus execution state for conversion. [Monitoring](monitoring.md) explains
these signals and their limits. A registry has separate health and recovery
conditions; the application's health response does not cover it.

## Which installation a command targets

The installed-instance procedures use the `dc` shell function defined in
[Getting Started](getting-started.md). Run those commands on the installation
host, from that installation's checkout, in the shell where `dc` is defined.
The function supplies its private Compose environment file. An application
console command runs inside `app`; a host-controller command runs on the trusted
host with its own manifest and credential paths. Keep those contexts separate.

Repository Make targets manage local development instances. They normally select
the current worktree's preview; `INSTANCE=persistent` selects the persistent
**development** instance. Neither convention selects an independently installed
operator instance. Use the [Development Environment](../part-2-developer-guide/development-environment.md)
guide when working on those instances.

The source-installation recipe adapts a development-oriented Compose stack; it
requires explicit network, volume, credential and worker preparation. It does
not establish qualification for every hosting arrangement. Follow the stated
checkpoints in [Getting Started](getting-started.md) before treating a new
installation as ready for use.
