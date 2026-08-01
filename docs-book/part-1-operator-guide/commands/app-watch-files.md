# app:watch-files

Watch directories for filesystem changes using inotify. Useful for triggering automated actions when files are added, modified, or removed.

## Quick start

Watch a single directory:

```bash
make exec cmd="php bin/console app:watch-files --path /data/music"
```

Watch multiple directories:

```bash
make exec cmd="php bin/console app:watch-files --path /data/music --path /data/movies"
```

## Options

| Option | Default | Description |
|--------|---------|-------------|
| `--path`, `-p` | Current directory | Directory to watch. Can be specified multiple times. |
| `--timeout`, `-t` | `5000` | Read timeout in milliseconds. Controls how often the watcher checks for events. |

## Details

The command runs continuously until interrupted (Ctrl+C). It prints filesystem events to stdout:

```
[CREATE] /data/music/New Album/song.mp3
[MODIFY] /data/music/New Album/cover.jpg
[CLOSE_WRITE] /data/music/New Album/song.mp3
[DELETE] /data/music/Old Album/track.mp3
```

Event types you'll see:

| Type | Meaning |
|------|---------|
| `CREATE` | File or directory was created |
| `DELETE` | File or directory was deleted |
| `MODIFY` | File content was modified |
| `MOVE` | File or directory was moved |
| `CLOSE_WRITE` | File was opened for writing and then closed (common after file copy/download) |

Directory events are marked with `[DIR]`.

If no paths are specified, the command watches the current working directory and prints a notice.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Watcher stopped normally (interrupted by user) |

## Tips

- Use a longer timeout (`--timeout 10000`) on busy filesystems to reduce CPU usage.
- This command is designed for development and debugging. For production file watching, the Swoole-based file watcher runs automatically.
- Pipe the output to a log file if you want to keep a record of filesystem activity.
