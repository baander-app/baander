# Baander Documentation

Baander is a self-hosted media library server. Choose the operator manual to run
an instance, or the developer guide to work on its code.

## Documentation Book

- **[Part I — Operator's Guide](part-1-operator-guide/README.md)** — Understand the components, install an instance, manage media and users, monitor work, and prepare for maintenance and recovery.
- **[Part II — Developer's Guide](part-2-developer-guide/README.md)** — Understand the architecture, write code and contribute.

## Quick Links

- [Deployment Topology](part-1-operator-guide/getting-started/deployment-topology.md) — how web, workers, storage and the optional registry fit together
- [Understanding Baander](part-1-operator-guide/getting-started/understanding-baander.md) — the journey from files to playback and what a health result tells you
- [System Requirements](part-1-operator-guide/getting-started/system-requirements.md) — host software, deployment profiles and measured memory
- [Source Installation](part-1-operator-guide/getting-started/installation.md) — source installation, worker deployment and your first library
- [Configuration](part-1-operator-guide/configuration/README.md) — deployment profiles, environment variables and runtime settings
- [Monitoring and Troubleshooting](part-1-operator-guide/operations/README.md) — find evidence for a stalled or failing task
- [Backup and Recovery](part-1-operator-guide/maintenance/backup-and-recovery.md) — preserve the application data and assess recovery limits
- [CLI Reference](part-1-operator-guide/commands/README.md) — console commands by area

## Other Resources

- [AGENTS.md](../AGENTS.md) — Agent instructions and pointers to canonical rules and repository skills.
- OpenAPI spec — available at `/api/doc` in dev mode, export via `app:export-openapi-spec`
