#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
python3 - <<'PY'
from pathlib import Path
import socket
import sys
import tempfile
sys.path.insert(0, str(Path.cwd() / 'scripts'))
from dev_environment.host import Host
from dev_environment.model import StateStore, WorkflowError
from dev_environment.process import Runner
try:
    with tempfile.TemporaryDirectory(prefix='baander-host-check-') as directory:
        host = Host(StateStore(Path(directory)), Runner())
        evidence = host.inspect()
        Runner().run(['systemctl', '--user', 'show-environment'])
        for name in ('baander.test', 'host-check.baander.test'):
            addresses = {record[4][0] for record in socket.getaddrinfo(name, 443)}
            if addresses != {'127.0.0.1'}:
                raise WorkflowError(name + ' must resolve only to loopback for this development workflow.')
        print('PASS: host DNS ownership, wildcard loopback resolution, port availability, mkcert and user systemd prerequisites')
        print('This read-only check does not establish application readiness or certificate trust.')
except (WorkflowError, OSError) as error:
    print('HOST PREREQUISITE BLOCKED: ' + str(error), file=sys.stderr)
    raise SystemExit(78)
PY
