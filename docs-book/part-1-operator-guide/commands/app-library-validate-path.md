# app:library:validate-path

Check that a directory can serve as a media library before you create one. The command runs the same check as the path field of the admin panel's library form (`POST /api/libraries/validate-path` in the API).

## Quick start

```bash
make exec cmd="php bin/console app:library:validate-path /data/music"
```

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `path` | Yes | Absolute path inside the container |

## Options

| Option | Default | Description |
|--------|---------|-------------|
| `--json` | — | Print the result as the API's `data` object, in JSON, and nothing else |

## Details

The command prints whether the path is valid, the path it resolves to, whether it exists and is readable, and the reason when it is not valid. A path is valid when it is absolute, has no `..` segment, and names an existing, readable directory.

The check runs inside the container, so it sees the container's mounts, not the host's directories.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | The path can serve as a library |
| 1 | The path cannot serve as a library; the output says why |
| 2 | The path is blank |
