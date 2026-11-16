# Understanding Baander

When a library scan finishes, the browser may still be waiting for its songs or
videos. Finding files, adding them to the catalog and preparing playback happen
in separate stages, each owned by a component. If you know which component owns
the stage that stalled, you can choose the right check before restarting anything.

Read this chapter first, then [Storage and Data](storage-and-data.md) and
[Source Installation](installation.md). If the instance is already installed, use
[Monitoring](../operations/README.md) to find the evidence for the stage you are checking.

## The components

The components, their dependencies and what PostgreSQL and Redis each hold are
drawn in [Deployment Topology](deployment-topology.md). This chapter follows the
journey from files to playback and explains what a health result tells you.

Here, a background worker means a process doing queued, scheduled or media work.
Swoole also calls its HTTP-serving processes workers. Reloading those web workers
and replacing a background deployment are different operations. A web restart
does not restart independently owned encoders. Use the lifecycle procedure in
[Background Work](../workers/background-work.md) when the background deployment needs attention.

## From files to playback

A library points at media under a configured path. A scan discovers the files
there and records its results per directory. The application keeps events in
the PostgreSQL **outbox**, so an event can be delivered after its transaction
commits. The background outbox relay publishes that work, and
consumers process the messages that follow. Catalog ingestion reads the
discovered files and creates catalog records. A scan summary therefore describes
discovery; it does not prove that all ingestion or enrichment has finished.

The implemented catalog ingestion paths handle music and movies. A library
created with another accepted type does not get a complete ingestion workflow
from that type. Follow
[Library Management](../media/library-management.md) for supported types, scan execution
and the checks for media that has not appeared.

Optional providers can enrich catalog entries with additional metadata, artwork
or lyrics. Their credentials and availability affect those tasks; they do not
replace access to the source files. See [External APIs](../media/external-apis.md).

Playback then needs the catalog entry and readable media. Original delivery reads
the source file. Converted audio or video also needs the worker execution that
produces it and shared output storage: the execution unit owns native encoding,
and the web application serves the output once it is available. See
[Transcoding](../media/transcoding.md) for the playback paths and their failure signals.

## What a health result tells you

Web readiness checks whether PostgreSQL and Redis are available. The
background-worker and memory results are reported, but they do not decide the web
container's health, so a ready web application can coexist with stalled
background work. Likewise, a recent worker heartbeat shows that a worker was seen
recently, not that an outbox item was relayed or that an encoder produced a
playable segment.

Check the signal that belongs to the task: discovery results for scanning,
catalog entries for ingestion, delivery/failure records for queued work, and
output plus execution state for conversion. [Monitoring](../operations/README.md) explains
these signals and their limits. A registry has separate health and recovery
conditions; the application's health response does not cover it.

## Which installation a command targets

The installed-instance procedures use the `dc` shell function defined in
[Source Installation](installation.md). Run those commands on the installation
host, from that installation's checkout, in the shell where `dc` is defined.
The function supplies its private Compose environment file. An application
console command runs inside `app`; a host-controller command runs on the trusted
host with its own manifest and credential paths. Keep those contexts separate.

Repository Make targets manage local development instances. They normally select
the current worktree's preview; `INSTANCE=persistent` selects the persistent
**development** instance. Neither convention selects an independently installed
operator instance. Use the [Development Environment](../../part-2-developer-guide/getting-started/development-environment.md)
guide when working on those instances.

The source-installation recipe adapts a development-oriented Compose stack and
requires explicit network, volume, credential and worker preparation. Following it
does not qualify the result for every hosting arrangement. Work through the
checkpoints in [Source Installation](installation.md) before treating a new
installation as ready for use.
