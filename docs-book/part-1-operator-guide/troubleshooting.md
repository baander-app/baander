# Troubleshooting

Common issues, diagnostic commands, and log locations for Baander operators.

## Diagnostic Commands

Start here. These commands help you understand the current state of the system.

```bash
# Check PHP and Symfony versions
make info

# Verify all components are healthy (database, Redis, FFmpeg, Swoole)
make exec cmd="php bin/console app:health:check"

# Validate configuration for common mistakes
make exec cmd="php bin/console app:config:validate"
```

See the full documentation for these commands:

- [app:health:check](commands/app-health-check.md)
- [app:config:validate](commands/app-config-validate.md)

## Log Locations

### Makefile shortcuts

| Command | Description |
|---------|-------------|
| `make logs` | Follow app container logs (Ctrl+C to exit) |
| `make logs-nginx` | Follow Nginx container logs |
| `make hl-logs` | Last 1 hour of app logs, highlighted (requires [hl](https://github.com/pamburus/hl)) |
| `make hl-logs-f` | Follow app logs, highlighted |
| `make hl-logs-nginx` | Last 1 hour of Nginx logs, highlighted |
| `make hl-logs-all` | Last 1 hour of all containers, highlighted |
| `make hl-logs-error` | Last 1 hour of app error-level logs only |
| `make hl-logs-warn` | Last 1 hour of app warnings and above |

### Container names

| Container | Name |
|-----------|------|
| Application (Swoole) | `baander-app` |
| Reverse proxy | `baander-nginx` |
| Database | `baander-postgres` |
| Cache / sessions / jobs | `baander-redis` |
| Profiler (dev only) | `baander-profiler` |

### Docker commands

```bash
# Follow logs for a specific container
docker logs -f baander-app

# Last 100 lines
docker logs --tail 100 baander-app

# Logs since a specific time
docker logs --since 2026-04-21T10:00:00 baander-app

# All containers at once
docker compose logs --tail 50

# Check if all containers are running
docker compose ps
```

## Common Issues

### Redis unreachable

**Symptoms:** Sessions fail, rate limiting stops working, messenger jobs do not process, cache misses on every request.

**Cause:** The `REDIS_PASSWORD` in `.env` does not match the password configured in `docker-compose.yml` for the Redis container.

**Fix:**

1. Verify the password in `.env`:
   ```bash
   grep REDIS_PASSWORD .env
   ```

2. Verify the password in `docker-compose.yml` (check the Redis service `command` and `environment` sections):
   ```bash
   grep -A2 'requirepass\|REDIS_PASSWORD' docker-compose.yml
   ```

3. Ensure both match, then restart:
   ```bash
   make restart
   ```

4. Confirm Redis is reachable from the app container:
   ```bash
   make exec cmd="php bin/console app:health:check"
   ```

5. Verify the internal Docker network exists:
   ```bash
   docker network ls | grep baander-backtier
   ```

   If missing, recreate it by running `make down && make start`.

### Transcoding failures

**Symptoms:** Video playback fails, transcode jobs stuck in pending or failed state, missing quality tiers.

**Fix:**

1. Verify FFmpeg is available in the container:
   ```bash
   make exec cmd="ffmpeg -version"
   ```

   If FFmpeg is not found, build the FFmpeg image first:
   ```bash
   make build-ffmpeg
   make build
   ```

2. Check the job monitor for failed transcode jobs:
   ```bash
   make exec cmd="php bin/console app:health:check"
   ```

3. Review app logs for transcode errors:
   ```bash
   make hl-logs-error
   ```

4. If the CPU process pool is failing, check for memory issues or worker crashes in the logs:
   ```bash
   docker logs --tail 200 baander-app | grep -i "process\|pool\|transcode"
   ```

### Permission errors

**Symptoms:** File upload failures, media files not accessible, "permission denied" errors in logs.

**Cause:** File ownership mismatch between the host user and the container process.

**Fix:**

1. Check volume mount permissions inside the container:
   ```bash
   make exec cmd="ls -la /storage"
   ```

2. The Makefile passes `HOST_UID` and `HOST_GID` to the container. Verify these match the host user:
   ```bash
   id -u
   id -g
   ```

3. If ownership is wrong, fix it on the host (adjust paths as needed):
   ```bash
   sudo chown -R $(id -u):$(id -g) ./storage ./config
   ```

4. Restart the app to pick up corrected ownership:
   ```bash
   make restart
   ```

### Swoole crashes

**Symptoms:** The application becomes unresponsive, returns 502 errors, or the container restarts repeatedly.

**Fix:**

1. Check container restart count and status:
   ```bash
   docker compose ps
   docker inspect baander-app --format='{{.RestartCount}}'
   ```

2. Check logs for crash details:
   ```bash
   docker logs --tail 500 baander-app
   ```

3. If workers are running out of memory, increase the memory limit or reduce `SWOOLE_WORKER_NUM` in your Swoole configuration.

4. If crashes happen under load, check the Swoole configuration in `config/packages/swoole.yaml` and consider reducing worker count or enabling coroutine hooks more selectively.

### Database connection refused

**Symptoms:** All page loads fail, "connection refused" or "could not connect to server" errors.

**Fix:**

1. Verify the database container is running:
   ```bash
   docker compose ps
   ```

   If it is not running:
   ```bash
   make start
   ```

2. Check `DATABASE_URL` in `.env`:
   ```bash
   grep DATABASE_URL .env
   ```

   Ensure the host is `database` (the Docker service name, not `localhost`):
   ```env
   DATABASE_URL="postgresql://baander:your_password@database:5432/baander?serverVersion=18&charset=utf8"
   ```

3. Test connectivity from the app container:
   ```bash
   make exec cmd="php bin/console dbal:run-sql 'SELECT 1'"
   ```

4. Check PostgreSQL logs if the connection is refused:
   ```bash
   docker logs --tail 100 baander-postgres
   ```

### OAuth token issues

**Symptoms:** Users cannot authenticate, access tokens rejected, refresh token flow fails.

**Fix:**

1. Verify `APP_SECRET` is set to a strong value (not the default):
   ```bash
   grep APP_SECRET .env
   ```

2. Check that OAuth encryption keys exist and are readable:
   ```bash
   make exec cmd="ls -la config/secrets/oauth/"
   ```

3. If keys were rotated recently, ensure the encryption key in `.env` matches the new key pair. See [security.md](security.md#rotating-oauth-20-keys) for the rotation procedure.

4. If tokens are failing after a deployment, the encryption key may have changed between workers. Ensure `APP_ENV=prod` and that the encryption key is consistent across all workers.

5. Rotate keys if you suspect compromise:
   ```bash
   make exec cmd="php bin/console app:auth:rotate-secrets"
   ```

### Media not appearing after scan

**Symptoms:** Library scan completes but songs, movies, or other media do not show up in the catalog.

**Fix:**

1. Verify the library path is accessible inside the container:
   ```bash
   make exec cmd="ls -la /path/to/your/media"
   ```

   Replace `/path/to/your/media` with the library path configured in your library settings. If the path does not exist or is empty inside the container, the volume mount in `docker-compose.yml` is incorrect.

2. Re-run the scan:
   ```bash
   make exec cmd="php bin/console app:library:scan"
   ```

3. Check scan logs for errors:
   ```bash
   make hl-logs-error | grep -i "scan\|library"
   ```

4. If metadata enrichment is failing (covers, artist info), check that external API keys are configured in `.env` and that the container has outbound internet access:
   ```bash
   make exec cmd="curl -s -o /dev/null -w '%{http_code}' https://api.discogs.com"
   ```

## Getting Help

If you encounter an issue not covered here:

1. Run the diagnostic commands above and collect the relevant logs.
2. Search existing issues on [GitHub](https://github.com/martinjuul/baander/issues).
3. Open a new issue with the diagnostic output, logs, and steps to reproduce.

## See Also

- [monitoring.md](monitoring.md) -- ongoing observability and alerting
- [security.md](security.md) -- secret rotation and breach recovery
- [app:health:check](commands/app-health-check.md) -- component health verification
- [app:config:validate](commands/app-config-validate.md) -- configuration audit
