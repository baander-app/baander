# Part I — Operator's Guide

For a new Baander instance, follow the reading path below. For an existing
instance, use the task and symptom tables to find the procedure you need.

Commands for an installation use the `dc` function that
[Installation](getting-started/installation.md#command-context) defines, and run
on the installation host from its checkout. The deployment preset starts, stops
and reports the installation from the same host. Repository Make targets select
development instances, not an installation; see
[Which installation a command targets](getting-started/understanding-baander.md#which-installation-a-command-targets)
before carrying a command from one environment to another.

## Chapters

| Chapter | Coverage |
| --- | --- |
| [Getting Started](getting-started/README.md) | Component overview, deployment topology, storage requirements, host sizing, and installation through the deployment preset |
| [Configuration](configuration/README.md) | Deployment capability and size, media slots, PgBouncer pooler, environment variables, authentication limits, and live server/user settings |
| [Workers and Background Work](workers/README.md) | Control/execution worker pair, queue and outbox flow, version 9 manifest, credentials, and host lifecycle controller |
| [Media and Playback](media/README.md) | Library management, file watching, external metadata/lyrics APIs, video/audio transcoding, and watch parties |
| [Administration](administration/README.md) | User accounts, roles, passkeys, OAuth clients, notifications, and the optional server-discovery registry |
| [Monitoring and Troubleshooting](operations/README.md) | Health probes, Prometheus metrics, Swoole stats, job and transport monitoring, logs, and symptom-led diagnostics |
| [Maintenance and Security](maintenance/README.md) | Backups, isolated restore verification, upgrading, secret rotation, breach recovery, and host hardening |
| [CLI Commands](commands/README.md) | Complete reference for every `app:*` and `debug:*` console command |

## Reading path

1. **Understand what you will run.** Read [Understanding Baander](getting-started/understanding-baander.md)
   for the path from files to playback, then [Deployment Topology](getting-started/deployment-topology.md)
   for the components and [Storage and Data](getting-started/storage-and-data.md)
   for what must be preserved.
2. **Install it.** Check [System Requirements](getting-started/system-requirements.md),
   then follow [Installation](getting-started/installation.md).
3. **Operate it.** Choose a task below. [Monitoring and Troubleshooting](operations/README.md)
   explains where to look when a step fails.
4. **Prepare for changes.** Read [Backup and Recovery](maintenance/backup-and-recovery.md)
   before upgrading or recovering an instance. The optional
   [Registry](administration/registry.md) has separate storage and recovery requirements.

## Tasks

| I need to… | Start here |
| --- | --- |
| See how the pieces fit together | [Deployment Topology](getting-started/deployment-topology.md) |
| Size a host or choose a deployment capability | [System Requirements](getting-started/system-requirements.md) and [Deployment Capability, Size and Runtime](configuration/deployment-profiles.md) |
| Install an instance and scan its first library | [Installation](getting-started/installation.md) |
| Change an environment variable or server setting | [Environment Variables](configuration/environment-variables.md) and [Server and User Settings](configuration/server-settings.md) |
| Configure OAuth lifetimes, rate limits or passkeys | [Authentication and Rate Limiting](configuration/authentication-and-limits.md) |
| Add media, scan again or inspect file-watcher limits | [Library Management](media/library-management.md) |
| Add users or change access, passkeys and OAuth clients | [User Management](administration/user-management.md) |
| Configure metadata, artwork or lyrics enrichment | [External APIs](media/external-apis.md) |
| Understand original playback or converted audio/video | [Transcoding](media/transcoding.md) |
| Configure email, push or webhooks | [Notifications](administration/notifications.md) |
| Use synchronized playback through the supported API | [Watch Parties](media/watch-parties.md) |
| Provision, configure or run the worker pair from a host manifest | [Worker Deployment](workers/worker-deployment.md) |
| Inspect, replace or recover background workers | [Background Work](workers/background-work.md) |
| Preserve data or assess a restore | [Backup and Recovery](maintenance/backup-and-recovery.md) |
| Update code and schema | [Upgrading](maintenance/upgrading.md) |
| Rotate secrets or respond to exposed credentials | [Security](maintenance/security.md) |
| Operate the separate registry | [Registry](administration/registry.md) |

## Symptoms

| What I see | Start here |
| --- | --- |
| Web responds, but queued work does not progress | [Observe background work](workers/background-work.md#observe-background-work), then [Job Monitoring](operations/job-monitoring.md#job-monitoring) |
| Media has not appeared after a scan | [Media not appearing after scan](operations/troubleshooting.md#media-not-appearing-after-scan) |
| Conversion fails or playback cannot obtain output | [Transcoding failures](operations/troubleshooting.md#transcoding-failures) |
| A job failed, or I need its retry/cancellation evidence | [Retry and cancel](operations/job-monitoring.md#retry-and-cancel) and [Failed messages](operations/job-monitoring.md#failed-messages) |
| Redis, database, permissions or login checks fail | [Common Issues](operations/troubleshooting.md#common-issues) |
| Email, push or webhooks do not arrive | [Notifications](administration/notifications.md) |
| A worker operation has an uncertain outcome | [Stop, replace or recover workers](workers/background-work.md#stop-replace-or-recover-workers) |
| Registry lookup or readiness fails | [Registry readiness and failures](administration/registry.md#readiness-and-failures) |

## Components and reference

[Deployment Topology](getting-started/deployment-topology.md) is the component map.
For command arguments, options and outcomes, use the [CLI Reference](commands/README.md).
The chapter indexes above link to each subsystem's operating reference.
