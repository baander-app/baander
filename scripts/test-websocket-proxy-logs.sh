#!/usr/bin/env bash
# Check nginx proxy logging and development HTTPS origin forwarding.
# Build the fixture using the production nginx base:
# docker build -f docker/nginx/Dockerfile.test -t baander-websocket-proxy-test:local docker/nginx
set -euo pipefail
if [ "${BAANDER_WS_PROXY_TIMEOUT_ACTIVE:-0}" != 1 ]; then
    exec env BAANDER_WS_PROXY_TIMEOUT_ACTIVE=1 timeout 180s bash "$0" "$@"
fi
cd "$(dirname "$0")/.."
image="${BAANDER_TEST_PROXY_IMAGE:-baander-websocket-proxy-test:local}"
container="baander-ws-proxy-$(date +%s)-$$-${RANDOM}"
started_at=$SECONDS
cleanup() {
    local status=$?
    if ! timeout 15s docker rm -f "$container" >/dev/null 2>&1; then
        echo 'Could not remove the disposable proxy container.' >&2
        status=1
    fi
    exit "$status"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

# No host mounts, published ports or external network access are required.
timeout 20s docker run -d --pull=never --name "$container" --network none --user 0:0 \
    --memory 256m --cpus 1 --pids-limit 96 --entrypoint sleep "$image" 180 >/dev/null
timeout 10s docker exec "$container" mkdir -p /tmp/ws-proxy
tar -cf - docker/prod/nginx.conf docker/dev/nginx.conf docker/dev/nginx.conf.template |
    timeout 10s docker exec -i "$container" tar -xf - -C /tmp/ws-proxy

timeout 120s docker exec -i "$container" sh -s <<'SHELL'
set -eu
for executable in nginx curl openssl python3; do
    command -v "$executable" >/dev/null
done
nginx -v
umask 077
mkdir -p /etc/nginx/certificates /var/log/nginx
openssl req -x509 -newkey rsa:2048 -nodes -days 1 \
    -subj /CN=proxy.baander.app -addext subjectAltName=DNS:proxy.baander.app \
    -keyout /etc/nginx/certificates/key.pem -out /etc/nginx/certificates/cert.pem \
    >/tmp/ws-proxy/certificate.log 2>&1

python3 - <<'PY'
from pathlib import Path

root = Path('/tmp/ws-proxy')
for name, source in [('prod', 'docker/prod/nginx.conf'),
                     ('dev', 'docker/dev/nginx.conf'),
                     ('template', 'docker/dev/nginx.conf.template')]:
    content = (root / source).read_text()
    assert content.count('server app:9501;') == 1
    content = content.replace('server app:9501;', 'server 127.0.0.1:9501;')
    content = content.replace('${SERVER_NAME}', 'proxy.baander.app')
    (root / (name + '.conf')).write_text(content)
(root / 'nginx.conf').write_text('''worker_processes 1;
pid /tmp/ws-proxy/nginx.pid;
error_log /tmp/ws-proxy/global-error.log;
events { worker_connections 64; }
http { include /tmp/ws-proxy/selected.conf; }
''')
PY

# Replace image stdout/stderr symlinks only inside this disposable filesystem.
rm -f /var/log/nginx/access.log /var/log/nginx/error.log
for variant in prod dev template; do
    cp "/tmp/ws-proxy/$variant.conf" /tmp/ws-proxy/selected.conf
    : >/var/log/nginx/access.log
    : >/var/log/nginx/error.log
    : >/tmp/ws-proxy/global-error.log
    nginx -t -c /tmp/ws-proxy/nginx.conf >/tmp/ws-proxy/validation.log 2>&1 || {
        cat /tmp/ws-proxy/validation.log >&2
        exit 1
    }
    nginx -c /tmp/ws-proxy/nginx.conf
    if [ "$variant" = prod ]; then
        scheme=http
        port=80
    else
        scheme=https
        port=443
    fi
    # A separate ordinary-route failure proves error logging remains operational.
    control=$(curl --silent --show-error --max-time 5 --noproxy '*' \
        --resolve "proxy.baander.app:$port:127.0.0.1" \
        --cacert /etc/nginx/certificates/cert.pem \
        -o /tmp/ws-proxy/control.body -w '%{http_code}' \
        "$scheme://proxy.baander.app/api/proxy-observability-control")
    [ "$control" = 502 ] || { echo "$variant: control did not reach the refused upstream" >&2; exit 1; }
    ticket=$(openssl rand -hex 32)
    duplicate=$(openssl rand -hex 32)
    encoded=$(python3 -c 'import sys; value = sys.argv[1]; print("%%%02X" % ord(value[0]) + value[1:])' "$ticket")
    referrer=proxy-referrer-secret-canary
    agent=proxy-user-agent-secret-canary
    for query in "ticket=$ticket" "ticket=$ticket&ticket=$duplicate" "%74ick%65t=$ticket" "ticket=$encoded"; do
        status=$(curl --silent --show-error --max-time 5 --noproxy '*' \
            --resolve "proxy.baander.app:$port:127.0.0.1" \
            --cacert /etc/nginx/certificates/cert.pem \
            -H 'Origin: https://baander.app' -H 'Upgrade: websocket' -H 'Connection: Upgrade' \
            -H 'Sec-WebSocket-Version: 13' -H 'Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==' \
            -H "Referer: https://baander.app/player?ticket=$referrer" -H "User-Agent: $agent" \
            -o /tmp/ws-proxy/upgrade.body -w '%{http_code}' \
            "$scheme://proxy.baander.app/api/ws?$query")
        [ "$status" = 502 ] || { echo "$variant: upgrade did not return 502" >&2; exit 1; }
        if [ "$variant" != prod ]; then
            redirect=$(curl --silent --show-error --max-time 5 --noproxy '*' \
                --resolve proxy.baander.app:80:127.0.0.1 \
                -H "Referer: https://baander.app/player?ticket=$referrer" \
                -o /tmp/ws-proxy/redirect.body -w '%{http_code}' \
                "http://proxy.baander.app/api/ws?$query")
            [ "$redirect" = 307 ] || { echo "$variant: HTTP redirect did not return 307" >&2; exit 1; }
        fi
    done
    nginx -c /tmp/ws-proxy/nginx.conf -s quit
    attempts=0
    while [ -e /tmp/ws-proxy/nginx.pid ]; do
        attempts=$((attempts + 1))
        [ "$attempts" -le 50 ] || { echo 'nginx did not stop within five seconds' >&2; exit 1; }
        sleep 0.1
    done
    python3 - "$variant" "$ticket" "$duplicate" "$encoded" "$referrer" "$agent" <<'PY'
from pathlib import Path
import re
import sys

variant, ticket, duplicate, encoded, referrer, agent = sys.argv[1:]
access = Path('/var/log/nginx/access.log').read_text()
errors = Path('/var/log/nginx/error.log').read_text()
global_errors = Path('/tmp/ws-proxy/global-error.log').read_text()
for secret in (ticket, duplicate, encoded, referrer, agent):
    assert secret not in access + errors + global_errors, 'A request credential reached proxy logs'
assert len(re.findall(r'"GET /api/ws HTTP/1\.1" 502 ', access)) == 4, 'Missing safe upgrade failure access records'
if variant != 'prod':
    assert len(re.findall(r'"GET /api/ws HTTP/1\.1" 307 ', access)) == 4, 'Missing safe redirect access records'
assert '/api/proxy-observability-control' in errors, 'Ordinary-route upstream diagnostics disappeared'
assert '/api/ws' not in errors + global_errors, 'Upgrade errors retained a raw request URI'
assert '?' not in access, 'A query string reached the access log'
print(f'PASS: {variant}: nginx -t, four query forms return upstream 502 with safe logs; ordinary-route diagnostics retained')
PY
done

# Echo the headers that reach the app through the actual development template.
cat >/tmp/ws-proxy/echo-upstream.py <<'PY'
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
import json


class EchoHeaders(BaseHTTPRequestHandler):
    def do_GET(self):
        body = json.dumps({'path': self.path, 'headers': dict(self.headers)}).encode()
        self.send_response(200)
        self.send_header('Content-Type', 'application/json')
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def log_message(self, *_args):
        pass


server = ThreadingHTTPServer(('127.0.0.1', 9501), EchoHeaders)
Path('/tmp/ws-proxy/echo-ready').touch()
server.serve_forever()
PY
python3 /tmp/ws-proxy/echo-upstream.py >/tmp/ws-proxy/echo.log 2>&1 &
echo_pid=$!
trap 'kill "$echo_pid" 2>/dev/null || true' EXIT
attempts=0
while [ ! -e /tmp/ws-proxy/echo-ready ]; do
    attempts=$((attempts + 1))
    [ "$attempts" -le 50 ] || { cat /tmp/ws-proxy/echo.log >&2; echo 'Echo upstream did not start' >&2; exit 1; }
    sleep 0.1
done
cp /tmp/ws-proxy/template.conf /tmp/ws-proxy/selected.conf
nginx -t -c /tmp/ws-proxy/nginx.conf >/tmp/ws-proxy/validation.log 2>&1 || {
    cat /tmp/ws-proxy/validation.log >&2
    exit 1
}
nginx -c /tmp/ws-proxy/nginx.conf
for authority in proxy.baander.app:18443 proxy.baander.app; do
    for route in / /api/ws /api/stream/track; do
        curl --silent --show-error --fail --max-time 5 --noproxy '*' \
            --resolve proxy.baander.app:443:127.0.0.1 \
            --cacert /etc/nginx/certificates/cert.pem \
            -H "Host: $authority" \
            -H 'X-Forwarded-Host: attacker.baander.app:9443' \
            -H 'X-Forwarded-Proto: http' -H 'X-Forwarded-Port: 9443' \
            -o /tmp/ws-proxy/echo-response.json "https://proxy.baander.app$route"
        python3 - "$authority" "$route" <<'PY'
from pathlib import Path
import json
import sys

authority, route = sys.argv[1:]
response = json.loads(Path('/tmp/ws-proxy/echo-response.json').read_text())
headers = {name.lower(): value for name, value in response['headers'].items()}
assert response['path'] == route, response
for name in ('host', 'x-forwarded-host'):
    assert headers.get(name) == authority, f'{route}: {name} lost public authority: {headers}'
assert headers.get('x-forwarded-proto') == 'https', f'{route}: spoofed scheme reached app: {headers}'
assert 'x-forwarded-port' not in headers, f'{route}: spoofed port reached app: {headers}'
print(f'PASS: template: {route} preserves HTTPS authority {authority} and replaces spoofed origin headers')
PY
    done
done
nginx -c /tmp/ws-proxy/nginx.conf -s quit
SHELL
echo "Proxy log qualification completed in $((SECONDS - started_at)) seconds."
