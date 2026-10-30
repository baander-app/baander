# Baander Documentation

Baander is a self-hosted media library server. Choose the operator manual to run
an instance, or the developer guide to work on its code.

## Documentation Book

- **[Part I — Operator's Guide](part-1-operator-guide/README.md)** — Understand the components, install an instance, manage media and users, monitor work, and prepare for maintenance and recovery.
- **[Part II — Developer's Guide](part-2-developer-guide/README.md)** — Understand the architecture, write code and contribute.

## Quick Links

- [Understanding Baander](part-1-operator-guide/understanding-baander.md) — how web, workers, storage and the optional registry fit together
- [Getting Started](part-1-operator-guide/getting-started.md) — source installation, worker deployment and your first library
- [Configuration](part-1-operator-guide/configuration.md) — environment variables and runtime settings
- [Monitoring](part-1-operator-guide/monitoring.md) and [Troubleshooting](part-1-operator-guide/troubleshooting.md) — find evidence for a stalled or failing task
- [Backup and Recovery](part-1-operator-guide/backup-and-recovery.md) — preserve the application data and assess recovery limits
- [CLI Reference](part-1-operator-guide/commands/README.md) — console commands by area

## Other Resources

- [AGENTS.md](../AGENTS.md) — Agent instructions and pointers to canonical rules and repository skills.
- OpenAPI spec — available at `/api/doc` in dev mode, export via `app:export-openapi-spec`
