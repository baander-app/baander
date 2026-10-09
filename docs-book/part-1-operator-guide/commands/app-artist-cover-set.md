# app:artist:cover:set

Set or replace an artist's cover image from an image file inside the web container. The command does what `POST /api/artists/{publicId}/cover` does when an admin uploads an artist image, through the same use case and with the same limits.

## Quick start

Copy the image into the container, then set it as the cover:

```bash
docker compose cp artist.jpg app:/tmp/artist.jpg
make exec cmd="php bin/console app:artist:cover:set V1StGXR8_Z5jdHi6B-myT /tmp/artist.jpg"
```

Print the result as JSON, for a script:

```bash
make exec cmd="php bin/console app:artist:cover:set V1StGXR8_Z5jdHi6B-myT /tmp/artist.jpg --json"
```

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `public-id` | Yes | The artist's public ID, as the API and the web app show it |
| `path` | Yes | A JPEG, PNG or WebP file of at most 10 MB, as a path inside the web container |

## Options

| Option | Description |
|--------|-------------|
| `--json` | Print only the result, as the admin API's `data` payload, in JSON |

## Details

The command reads the file's content to decide its type; the file name and extension do not matter. A file that is missing, unreadable, larger than 10 MB, or not a JPEG, PNG or WebP image is rejected, and nothing changes.

The file is copied into image storage; the original stays where it is. Each cover gets its own storage path, so a new cover never overwrites the one it replaces. The artist and its new image are saved together. Only after that save does the command delete the old cover's record, its file and its derived WebP files. If the save fails, the new file is removed and the old cover stays.

With `--json` the command prints the new image as the API's `data` object, with `publicId`, `url`, `size` in bytes, `width` and `height`.

To remove a cover, use [app:artist:cover:remove](app-artist-cover-remove.md). [app:album:cover:set](app-album-cover-set.md) sets an album cover the same way.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Cover set |
| 1 | No artist has the public ID, or another error occurred; the message says why |
| 2 | The public ID is malformed, or the file was rejected; the message says why |
