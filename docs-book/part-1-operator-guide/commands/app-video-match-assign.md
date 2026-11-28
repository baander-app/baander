# app:video:match:assign

Assign a video file to one or more episodes, or classify it as an extra. Use the file placement public ID from the review queue.

## Quick start

```bash
make exec cmd="php bin/console app:video:match:assign <file-public-id> --episodes=<episode-public-id>"
make exec cmd="php bin/console app:video:match:assign <file-public-id> --extra-kind=trailer --force --json"
```

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `public-id` | Yes | File placement public ID |

## Options

| Option | Description |
|--------|-------------|
| `--episodes` | Comma-separated public IDs of active episodes in the file's series |
| `--extra-kind` | Extra kind, used instead of episode IDs |
| `--force` | Assign without asking, required without a terminal |
| `--json` | Print the file public ID, episode public IDs and extra kind |

## Details

`PUT /api/admin/video-matches/files/{publicId}/assignment` accepts `episodePublicIds` or `extraKind`. Supply exactly one assignment type. Episodes must belong to the same series as the file. The assignment becomes a manual choice and survives ordinary rescans. Assigning an extra removes its episode links. Playback marks follow the placement when an episode assignment changes.

## API and output

The command runs with full authority. Its admin API counterpart requires a first-party administrator. `--json` writes the API response's `data` object to stdout. Errors and confirmation prompts go to stderr.

The [command](../../../src/Catalog/Interface/Console/VideoMatchAssignCommand.php) and [controller](../../../src/Catalog/Interface/Controller/VideoMatchController.php) use the same [video match administration service](../../../src/Catalog/Application/Service/VideoMatch/VideoMatchAdminService.php).

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Operation completed |
| 1 | Target missing, conflicting state, provider unavailable, or confirmation declined |
| 2 | Invalid input, or a change needs confirmation without a terminal |
