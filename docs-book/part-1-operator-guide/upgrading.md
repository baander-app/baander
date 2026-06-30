# Upgrading

## Standard Update

Run these commands in order every time you pull changes:

```bash
git pull
make build
make start
make composer-install
make migrate
```

1. **`git pull`** fetches the latest code.
2. **`make build`** rebuilds the Docker images if the Dockerfile or installed packages changed.
3. **`make start`** recreates containers with the new images.
4. **`make composer-install`** updates PHP dependencies inside the app container.
5. **`make migrate`** runs pending database migrations.

Check the commit log for notable changes between your current version and the one you are upgrading to:

```bash
git log --oneline <current-tag>..HEAD
```

## Database Migrations

`make migrate` runs all pending migrations on both the main database and the test database. Always run it after pulling new code, even if you do not expect schema changes -- migrations may contain data transformations or index additions that are not obvious from the file list alone.

If you only need to migrate the main database (for example, on a production server without a test database), use `make migrate-no-test` instead.

## When to Rebuild Docker Images

`make build` is necessary when any of the following have changed:

- The `Dockerfile` or `.dockerignore`
- PHP extensions (added or removed in the Dockerfile)
- System packages in the Dockerfile (e.g., `apt-get install`)
- The base image tag

`make build` uses Docker layer caching, so it is safe to run on every update. It only rebuilds layers that have actually changed.

## Docker Compose Changes

When `docker-compose.yml` changes (new services, new volume mounts, network changes, environment variable additions), a simple `make restart` is not enough -- Docker Compose needs to recreate the affected containers from scratch.

```bash
make down && make start
```

This stops all containers, removes their network configuration, and starts them fresh with the updated compose file.

## FFmpeg Updates

When the FFmpeg version changes (the current build targets FFmpeg 8.0), rebuild the FFmpeg image first, then rebuild the app image:

```bash
make build-ffmpeg
make build
make start
```

The app image copies the static FFmpeg binary from the FFmpeg image during build, so the FFmpeg image must be up to date before the app image is built.

## Version Compatibility

Baander does not have a formal breaking change policy yet. The current approach is migration-first: always run migrations after pulling, and check the commit log for any notable changes between versions.

When environment variables are added or changed between versions, see [Configuration](configuration.md) for the current list and descriptions.

## Rollback

To revert to a previous version:

1. Check out the previous tag or commit:

   ```bash
   git checkout <previous-tag>
   ```

2. Rebuild and restart:

   ```bash
   make build
   make start
   make composer-install
   ```

Database migrations are forward-only. If the newer version added migrations, those tables or columns will still exist in your database. Doctrine will ignore them, but they take up space. If you need a clean rollback, inspect the migration files from the newer version and write manual `ALTER TABLE` statements to drop or revert the changes. Always back up your database before running manual schema changes.

## Tracking Changes

Baander does not have a formal release notes page yet. To see what changed between versions:

```bash
# View commits since a specific tag
git log --oneline <current-tag>..HEAD

# View recent commits
git log --oneline -20

# View changes to a specific area
git log --oneline -- src/Transcode/
```

Check the commit log for notable changes before upgrading — particularly look for new environment variables in `config/` or schema changes in `migrations/`.

## See Also

- [CLI Reference](commands/README.md) -- all available console commands
- [Configuration](configuration.md) -- environment variables that may change between versions
