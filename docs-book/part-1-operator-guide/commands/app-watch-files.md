# app:watch-files

Print inotify events for explicitly watched directories. The command reports events;
it does not scan libraries or dispatch ingestion. See
[Library management](../library-management.md) for catalog discovery and import.

## Quick start

Run from the installed-instance host shell with the `dc` function from
[Getting started](../getting-started.md#command-context). Paths name locations
inside the application container. The examples use the source-installation media
root; substitute your configured paths and ensure each directory exists first.

Watch a single directory:

```bash
dc exec app php bin/console app:watch-files --path /media/library/music
```

Watch multiple directories:

```bash
dc exec app php bin/console app:watch-files --path /media/library/music --path /media/library/movies
```

## Options

| Option | Default | Description |
|--------|---------|-------------|
| `--path`, `-p` | None | Directory to watch. Can be specified multiple times. |
| `--timeout`, `-t` | `5000` | Read timeout in milliseconds. Controls how often the watcher checks for events. |

## Details

The command runs continuously until interrupted (Ctrl+C). It prints filesystem events to stdout:

```
[CREATE] song.mp3
[MODIFY] cover.jpg
[CLOSE_WRITE] song.mp3
[DELETE] track.mp3
```

Event types you'll see:

| Type | Meaning |
|------|---------|
| `CREATE` | File or directory was created |
| `DELETE` | File or directory was deleted |
| `MODIFY` | File content was modified |
| `MOVE` | File or directory was moved |
| `CLOSE_WRITE` | File was opened for writing and then closed (common after file copy/download) |

Directory events are marked with `[DIR]`. Output uses the event name, not its
absolute path. Watches cover only the specified directories; subdirectories are
not added recursively.

At least one `--path` is required in practice. Without a path, the command prints
a notice about watching the current directory, but registers no watch and fails
with `No directories are being watched. Call watch() first.`

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Watcher stopped normally after SIGINT or SIGTERM |
| 1 | No directory was registered, a directory is missing, or watcher setup failed |

## Tips

- Use a longer timeout (`--timeout 10000`) on busy filesystems to reduce CPU usage.
- Use this command for filesystem diagnostics. It does not replace a library scan.
- Pipe the output to a log file if you want to keep a record of filesystem activity.
