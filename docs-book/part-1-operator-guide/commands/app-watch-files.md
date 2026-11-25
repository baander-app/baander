# app:watch-files

Print inotify events for the directories you name, or for the current directory. The
command reports events; it does not scan libraries or dispatch ingestion. See
[Library management](../media/library-management.md) for catalog discovery and import.

## Quick start

Run from the installed-instance host shell with the `dc` function from
[Installation](../getting-started/installation.md#command-context). Paths name locations
inside the application container. The examples use the installation's media
root; substitute your configured paths and ensure each directory exists first.

Watch a single directory:

```bash
dc exec web php bin/console app:watch-files --path /storage/media/music
```

Watch multiple directories:

```bash
dc exec web php bin/console app:watch-files --path /storage/media/music --path /storage/media/movies
```

## Options

| Option | Default | Description |
|--------|---------|-------------|
| `--path`, `-p` | The current directory | Directory to watch. Can be specified multiple times. |
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
absolute path. Each watch covers only its own directory; subdirectories are not
added recursively.

Without `--path`, the command prints `No paths specified. Watching current directory.`
and watches the directory it runs in. Inside the application container that is the
application directory (`APP_HOME`), not a media directory, so name the media
directories with `--path`.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Watcher stopped normally after SIGINT or SIGTERM |
| 1 | A directory is missing, the current directory cannot be read, or watcher setup failed |

## Tips

- Use a longer timeout (`--timeout 10000`) on busy filesystems to reduce CPU usage.
- Use this command for filesystem diagnostics. It does not replace a library scan.
- Pipe the output to a log file if you want to keep a record of filesystem activity.
