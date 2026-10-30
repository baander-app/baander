# Part I — Operator's Guide

Use this manual to understand, install and maintain a Baander instance. Start
with the reading path for a new installation, or choose a task, symptom or
component below when returning to an existing one.

Installed-instance commands use the `dc` function defined in
[Getting Started](getting-started.md), on the installation host from its checkout.
Worker-controller commands run separately on the trusted host. Repository Make
targets select development instances; see
[Which installation a command targets](understanding-baander.md#which-installation-a-command-targets)
before carrying a command between environments.

## Reading path

1. **Understand the instance.** [Understanding Baander](understanding-baander.md) introduces the components and the journey from files to playback. [Storage and Data](storage-and-data.md) identifies the stores, their writers and what must be preserved.
2. **Set it up.** Follow [Getting Started](getting-started.md), then use [Configuration](configuration.md) for settings and [Background Work](background-work.md) for the separate worker deployment and its operating limits.
3. **Run it day to day.** Use [Library Management](library-management.md), [User Management](user-management.md), [External APIs](external-apis.md), [Transcoding](transcoding.md), [Notifications](notifications.md) and [Watch Parties](watch-parties.md) for the work your instance supports.
4. **Observe and diagnose.** [Monitoring](monitoring.md) explains health, job and transport evidence. [Troubleshooting](troubleshooting.md) starts from symptoms and connects them to the responsible stage.
5. **Maintain and recover.** Read [Backup and Recovery](backup-and-recovery.md) before [Upgrading](upgrading.md) or a recovery action. [Security](security.md) covers credential changes and account/session effects. The optional [Registry](registry.md) has separate storage and recovery requirements.

## Tasks

| I need to… | Start here |
| --- | --- |
| Install an instance and scan its first library | [Getting Started](getting-started.md) |
| Change an environment variable or server setting | [Configuration](configuration.md) |
| Add media, scan again or inspect file-watcher limits | [Library Management](library-management.md) |
| Add users or change access, passkeys and OAuth clients | [User Management](user-management.md) |
| Configure metadata, artwork or lyrics enrichment | [External APIs](external-apis.md) |
| Understand original playback or converted audio/video | [Transcoding](transcoding.md) |
| Configure email, push or webhooks | [Notifications](notifications.md) |
| Use synchronized playback through the supported API | [Watch Parties](watch-parties.md) |
| Inspect, replace or recover background workers | [Background Work](background-work.md) |
| Preserve data or assess a restore | [Backup and Recovery](backup-and-recovery.md) |
| Update code and schema | [Upgrading](upgrading.md) |
| Rotate secrets or respond to exposed credentials | [Security](security.md) |
| Operate the separate registry | [Registry](registry.md) |

## Symptoms

| What I see | Start here |
| --- | --- |
| Web responds, but queued work does not progress | [Observe background work](background-work.md#observe-background-work), then [Job Monitoring](monitoring.md#job-monitoring) |
| Media has not appeared after a scan | [Media not appearing after scan](troubleshooting.md#media-not-appearing-after-scan) |
| Conversion fails or playback cannot obtain output | [Transcoding failures](troubleshooting.md#transcoding-failures) |
| A job failed, or I need its retry/cancellation evidence | [Retry and cancel](monitoring.md#retry-and-cancel) and [Failed messages](monitoring.md#failed-messages) |
| Redis, database, permissions or login checks fail | [Common Issues](troubleshooting.md#common-issues) |
| Email, push or webhooks do not arrive | [Notifications](notifications.md) |
| A worker operation has an uncertain outcome | [Stop, replace or recover workers](background-work.md#stop-replace-or-recover-workers) |
| Registry lookup or readiness fails | [Registry readiness and failures](registry.md#readiness-and-failures) |

## Components and reference

| Component or detail | Explanation and operating reference |
| --- | --- |
| Web, proxy and real-time connections | [The components](understanding-baander.md#the-components), [Monitoring](monitoring.md) |
| Control/execution pair, queues, scheduler and outbox | [Background Work](background-work.md) |
| PostgreSQL, Redis, source media and generated output | [Storage and Data](storage-and-data.md) |
| Native encoding and delivery files | [Transcoding](transcoding.md) |
| Optional registration/discovery service | [Registry](registry.md) |
| Command arguments, options and outcomes | [CLI Reference](commands/README.md) |
