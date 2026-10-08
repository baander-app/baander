# app:scheduler:commands

List the commands a scheduled job can run, with their parameters. These are the commands the **Create Job** dialog on the admin **Scheduler** page offers, as `GET /api/admin/scheduler/jobs/commands` returns them in the admin API.

## Quick start

```bash
make exec cmd="php bin/console app:scheduler:commands"
```

## Options

| Option | Description |
|--------|-------------|
| `--json` | Print the catalog as the admin API returns it, as a JSON object with a `messenger` and a `console` map, on stdout |

## Details

The command prints a table with one row per schedulable command:

| Column | Content |
|--------|---------|
| Type | `messenger` for a message dispatched to the workers, `console` for a console command |
| Command | The value to pass to `--command` of [app:scheduler:create](app-scheduler-create.md); a class name for a messenger command |
| Description | What the command does |
| Parameters | One line per parameter with its type and whether it is required, or `-` when it takes none |

The `--json` form also carries each parameter's description, default and, where the command declares them, its format, example and allowed values.

The command is read-only.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Catalog printed |
