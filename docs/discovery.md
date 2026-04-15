# Local Network Discovery

## Overview

Bånder supports automatic server discovery on the local network. When you open the Electron desktop client, it can automatically find Bånder servers running on your network without requiring manual configuration of the server URL.

## How It Works

### Server Side

The discovery service runs as a Swoole Process alongside the main HTTP server:

- **Protocol**: UDP
- **Port**: 41234 (configurable via `DISCOVERY_PORT`)
- **Message Format**: Plain text `BAANDER_DISCOVER`
- **Response Format**: JSON with server information

When the server receives a discovery request, it responds with:
```json
{
  "name": "Bånder",
  "url": "https://baander.test",
  "version": "1.0.0",
  "api_version": "v1",
  "timestamp": "2026-02-06T01:00:00+00:00"
}
```

### Client Side

The Electron client sends UDP broadcast discovery packets and listens for responses:

1. Broadcasts to `255.255.255.255:41234` for network-wide discovery
2. Sends to detected local hosts for development environments:
   - `127.0.0.1` (localhost)
   - `192.168.64.2` (Colima VM)
   - `192.168.65.2` (Docker Desktop VM)
   - All local network interface IPs
3. Collects responses for 5 seconds
4. Displays discovered servers in the configuration window

## Configuration

### Server Configuration (`.env`)

```env
# Enable/disable discovery
DISCOVERY_ENABLED=true

# UDP port for discovery
DISCOVERY_PORT=41234

# Server name broadcast in responses
DISCOVERY_SERVER_NAME="Bånder"

# Application version
APP_VERSION=1.0.0
```

### Docker Configuration

The discovery port must be exposed in `docker-compose.yml`:

```yaml
services:
  app:
    ports:
      - "41234:41234/udp"
```

## Local Development

### Colima (macOS/Linux)

Colima runs Docker in a VM at `192.168.64.2`. The Electron client automatically detects and includes this IP in discovery requests.

### Docker Desktop (macOS/Windows)

Docker Desktop runs containers in a VM at `192.168.65.2`. The Electron client automatically includes this IP.

### Native Docker (Linux)

On Linux, Docker runs natively and containers share the host network namespace. Localhost (`127.0.0.1`) discovery works automatically.

## Usage

### For Users

1. Install and start the Bånder server
2. Open the Electron desktop client
3. If no server is configured, the configuration window appears
4. Click "🔍 Discover Servers"
5. Select your server from the list
6. Click "Save" to connect

### For Developers

The discovery service starts automatically with Octane:

```bash
make restart-app
```

Check if it's running:
```bash
tail -f storage/logs/baander-$(date +%Y-%m-%d).log
```

Test from inside the container:
```bash
docker exec -it baander-app bash
php -r '$fp = stream_socket_client("udp://127.0.0.1:41234"); stream_socket_sendto($fp, "BAANDER_DISCOVER"); fclose($fp);'
```

## Troubleshooting

### "No servers found" in Electron

1. **Check if discovery is enabled**:
   ```bash
   make exec cmd="php artisan tinker --execute=\"echo config('discovery.enabled')\""
   ```

2. **Check if the port is exposed**:
   ```bash
   docker ps | grep baander-app
   # Should show: 0.0.0.0:41234->41234/udp
   ```

3. **Check if the service is running**:
   ```bash
   tail storage/logs/baander-$(date +%Y-%m-%d).log | grep -i discovery
   ```

4. **Test UDP connectivity** (from host):
   ```bash
   echo "BAANDER_DISCOVER" | nc -u localhost 41234
   # Then check logs for "Discovery request received"
   ```

### Docker Networking Issues

If discovery doesn't work with Docker:

1. **Colima**: Ensure Colima is running (`colima status`)
2. **Docker Desktop**: Ensure Docker Desktop is running
3. **Port conflicts**: Ensure port 41234 isn't used by another service

### Firewall Issues

The discovery service uses UDP port 41234. Ensure your firewall allows:
- Outbound UDP packets from Electron to port 41234
- Inbound UDP packets to port 41234

## Security Considerations

- Discovery responses contain only public information (server name, URL, version)
- No authentication required for discovery (by design)
- Discovery works only on the local network (UDP broadcasts don't cross routers)
- For production, consider:
  - Rate limiting discovery requests
  - Adding an optional authentication token
  - Limiting discovery to specific network interfaces

## Production Deployment

For production deployments:

1. **Disable discovery** if not needed:
   ```env
   DISCOVERY_ENABLED=false
   ```

2. **Limit to specific interfaces** (modify `DiscoveryService.php`):
   ```php
   $socket = stream_socket_server("udp://192.168.1.10:$port", ...);
   ```

3. **Add rate limiting** to prevent abuse:
   - Track discovery requests by IP
   - Limit to N requests per minute per IP

4. **Use a firewall** to restrict access to port 41234