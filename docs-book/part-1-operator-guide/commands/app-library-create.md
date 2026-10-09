# app:library:create

Register a new media library. This tells Baander where to find media files on disk and what type of content to expect. The command runs the same use case as adding a library in the admin panel (`POST /api/libraries` in the API), with the same validation and the same slug conflict.

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
| `--filesystem-type` | `local` | Filesystem backend. Currently only `local` is supported. |
| `--slug`, `-s` | Generated from the name | URL-friendly identifier. Omit it to generate one from the name. |
| `--sort-order` | `0` | Sort order for display (lower numbers appear first) |
| `--json` | — | Print only the created library in JSON, as the API's `data` returns it |

## Details

The library path must be accessible from inside the app container. If you're using Docker, make sure the directory is mounted as a volume. Check it first with [app:library:validate-path](app-library-validate-path.md).

The slug is used in URLs and API endpoints. If you don't provide one, it's generated from the name (lowercased, with runs of other characters replaced by hyphens). If another library already has the slug, the command creates nothing and fails; provide `--slug` in that case.

A library created in the admin panel grants its creating admin access, so that admin receives the notification when a scan of the library completes. The command has no user to grant: nobody receives the library's scan-completed notifications, and users who are not admins do not see it. Admins see and manage every library either way.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Library created |
| 1 | Another library has the slug, or another error occurred; the message says why |
| 2 | Invalid input, such as an unknown type, a relative path, a malformed slug, a blank name or a sort order that is not a whole number |

## Tips

- After creating a library, run [app:library:scan](app-library-scan.md) to index its contents.
- You can create multiple libraries of the same type (e.g., separate music collections).
