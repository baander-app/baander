# Part I — Operator's Guide

Use this manual to understand, install and maintain a Baander instance. Start
with the chapters or reading path for a new installation, or choose a task,
symptom or component below when returning to an existing one.

Installed-instance commands use the `dc` function defined in
[Source Installation](getting-started/installation.md), on the installation host from its checkout.
Worker-controller commands run separately on the trusted host. Repository Make
targets select development instances; see
[Which installation a command targets](getting-started/understanding-baander.md#which-installation-a-command-targets)
before carrying a command between environments.

## Chapters

| Chapter | Coverage |
| --- | --- |
| [Getting Started](getting-started/README.md) | Component overview, deployment topology, storage requirements, host sizing, and source installation |
| [Configuration](configuration/README.md) | Deployment capability and size, media slots, PgBouncer pooler, environment variables, authentication limits, and live server/user settings |
| [Workers and Background Work](workers/README.md) | Control/execution worker pair, queue and outbox flow, version 9 manifest, credentials, and host lifecycle controller |
| [Media and Playback](media/README.md) | Library management, file watching, external metadata/lyrics APIs, video/audio transcoding, and watch parties |
| [Administration](administration/README.md) | User accounts, roles, passkeys, OAuth clients, notifications, and the optional server-discovery registry |
| [Monitoring and Troubleshooting](operations/README.md) | Health probes, Prometheus metrics, Swoole stats, job and transport monitoring, logs, and symptom-led diagnostics |
| [Maintenance and Security](maintenance/README.md) | Backups, isolated restore verification, upgrading, secret rotation, breach recovery, and host hardening |
| [CLI Commands](commands/README.md) | Complete reference for every `app:*` and `debug:*` console command |

## Reading path

1. **Understand the instance.** [Understanding Baander](getting-started/understanding-baander.md) introduces the journey from files to playback. [Deployment Topology](getting-started/deployment-topology.md) shows the components in one diagram and what PostgreSQL and Redis hold. [Storage and Data](getting-started/storage-and-data.md) identifies the stores, their writers and what must be preserved.
2. **Set it up.** Check [System Requirements](getting-started/system-requirements.md) to choose a capability and size, follow [Source Installation](getting-started/installation.md), then use [Configuration](configuration/README.md) for settings, [Worker Deployment](workers/worker-deployment.md) for the worker manifest, credentials and host controller, and [Background Work](workers/background-work.md) for queue flow and operating limits.
3. **Run it day to day.** Use [Library Management](media/library-management.md), [User Management](administration/user-management.md), [External APIs](media/external-apis.md), [Transcoding](media/transcoding.md), [Notifications](administration/notifications.md) and [Watch Parties](media/watch-parties.md) for the work your instance supports.
4. **Observe and diagnose.** [Monitoring and Troubleshooting](operations/README.md) connects [Health Checks, Metrics, and Logs](operations/health-and-metrics.md) and [Job and Transport Monitoring](operations/job-monitoring.md) to stage-by-stage evidence. [Troubleshooting](operations/troubleshooting.md) starts from symptoms and connects them to the responsible stage.
5. **Maintain and recover.** Read [Backup and Recovery](maintenance/backup-and-recovery.md) before [Upgrading](maintenance/upgrading.md) or a recovery action. [Security](maintenance/security.md) covers credential changes and account/session effects. The optional [Registry](administration/registry.md) has separate storage and recovery requirements.

## Tasks

| I need to… | Start here |
| --- | --- |
| See how the pieces fit together | [Deployment Topology](getting-started/deployment-topology.md) |
| Size a host or choose a deployment capability | [System Requirements](getting-started/system-requirements.md) and [Deployment Capability, Size and Runtime](configuration/deployment-profiles.md) |
| Install an instance and scan its first library | [Source Installation](getting-started/installation.md) |
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

| Component or detail | Explanation and operating reference |
| --- | --- |
| Web, proxy and real-time connections | [Deployment Topology](getting-started/deployment-topology.md), [Health Checks, Metrics, and Logs](operations/health-and-metrics.md) |
| Control/execution pair, queues, scheduler and outbox | [Background Work](workers/background-work.md) |
| Worker manifest, credentials and host controller | [Worker Deployment](workers/worker-deployment.md) |
| PostgreSQL, Redis, source media and generated output | [Storage and Data](getting-started/storage-and-data.md) |
| Native encoding and delivery files | [Transcoding](media/transcoding.md) |
| Optional registration/discovery service | [Registry](administration/registry.md) |
| Command arguments, options and outcomes | [CLI Reference](commands/README.md) |
