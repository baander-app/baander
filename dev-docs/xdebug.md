# Xdebug

Xdebug is configured in the Docker image. See [phpstorm.md](phpstorm.md) for PhpStorm interpreter and path mapping setup.

## Configuration

Xdebug settings are in `docker/xdebug-main.ini` (Linux/WSL) and `docker/xdebug-osx.ini` (macOS). Rebuild after changes: `make build`.

### Listen mode (default)

Debug all requests automatically:

```ini
xdebug.start_with_request = yes
```

### Trigger mode

Only debug when the browser extension sends the IDE key:

```ini
xdebug.start_with_request = no
```

Install "Xdebug Helper" for Firefox or the equivalent for your browser. Set IDE key to `PHPSTORM`.

## PhpStorm Setup

1. `Settings → PHP → Debug` — set Xdebug port to `10000`
2. `Settings → PHP → Servers` — add server `baander`, host `localhost`, port `80`
3. Path mapping: project root → `/var/www/html`
4. Start listening for debug connections (`Run → Start Listening`)

## Debugging Different Targets

**HTTP requests**: Set breakpoints, enable "Start Listening", make the request.

**API clients (Postman, etc.)**: In trigger mode, append `?XDEBUG_SESSION_START=PHPSTORM` to the URL. In listen mode, nothing extra needed.

**Console commands**: Uncomment `xdebug.client_host=172.17.0.1` in `docker/xdebug-main.ini` and rebuild. The IP is typically the Docker bridge gateway.

## Further Reading

- [Debugging PHP with Xdebug, Docker, and PhpStorm](https://thecodingmachine.io/configuring-xdebug-phpstorm-docker)
- [Xdebug documentation](https://xdebug.org/docs/)
