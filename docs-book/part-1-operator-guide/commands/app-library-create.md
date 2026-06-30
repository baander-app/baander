# app:library:create

Register a new media library. This tells Baander where to find media files on disk and what type of content to expect.

## Quick start

```bash
make exec cmd="php bin/console app:library:create 'My Music' /data/music music"
```

With a custom slug:

```bash
make exec cmd="php bin/console app:library:create 'My Music' /data/music music --slug my-music-collection"
```

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `name` | Yes | Human-readable library name |
| `path` | Yes | Absolute path to the media directory on disk |
| `type` | Yes | Library type: `music`, `podcast`, `audiobook`, `movie`, or `tv_show` |

## Options

| Option | Default | Description |
|--------|---------|-------------|
| `--slug`, `-s` | Auto-generated from name | URL-friendly identifier. Omit to generate from the name automatically. |
| `--sort-order` | `0` | Sort order for display (lower numbers appear first) |

## Details

The library path must be accessible from inside the app container. If you're using Docker, make sure the directory is mounted as a volume.

The slug is used in URLs and API endpoints. If you don't provide one, it's generated from the name (lowercased, spaces replaced with hyphens, special characters removed). If the auto-generated slug conflicts with an existing library, the command will return an error — provide `--slug` manually in that case.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Library created successfully |
| 1 | Invalid input — check the error message for details |

## Tips

- After creating a library, run `app:library:scan` to index its contents.
- You can create multiple libraries of the same type (e.g., separate music collections).
- The path must be an absolute path inside the container filesystem.
