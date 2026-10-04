#!/usr/bin/env python3
# SPDX-License-Identifier: Apache-2.0
"""Disposable loopback SQL-contract check; not cluster/TLS/capacity acceptance."""
import argparse
import json
import pathlib
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.request

parser = argparse.ArgumentParser()
parser.add_argument('--rqlited', required=True)
parser.add_argument('--fixture', required=True)
args = parser.parse_args()
operations = json.loads(subprocess.check_output([args.fixture], text=True))

def port():
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        return sock.getsockname()[1]

with tempfile.TemporaryDirectory(prefix='baander-registry-contract-') as directory:
    http_port, raft_port = port(), port()
    with open(pathlib.Path(directory) / 'rqlite.log', 'w') as log:
        process = subprocess.Popen([args.rqlited, '-node-id', 'fixture', '-http-addr', f'127.0.0.1:{http_port}',
                                    '-raft-addr', f'127.0.0.1:{raft_port}', str(pathlib.Path(directory) / 'data')],
                                   stdout=log, stderr=log)
        base = f'http://127.0.0.1:{http_port}'
        try:
            deadline = time.monotonic() + 15
            while True:
                if process.poll() is not None:
                    raise RuntimeError('Disposable rqlite exited before readiness')
                try:
                    with urllib.request.urlopen(base + '/readyz', timeout=1) as response:
                        if response.status == 200:
                            break
                except (urllib.error.URLError, TimeoutError):
                    pass
                if time.monotonic() >= deadline:
                    raise RuntimeError('Disposable rqlite did not become ready')
                time.sleep(0.05)
            for operation in operations:
                request = urllib.request.Request(base + operation['target'], data=json.dumps(operation['statements']).encode(),
                                                 headers={'Content-Type': 'application/json'}, method='POST')
                with urllib.request.urlopen(request, timeout=5) as response:
                    result = json.load(response)
                assert 'error' not in result, operation['name']
                assert len(result['results']) == len(operation['statements']), operation['name']
                assert all('error' not in item for item in result['results']), operation['name']
                if operation['kind'] != 'maintenance':
                    assert isinstance(result['results'][-1].get('types'), dict), 'Missing authoritative query column types'
                decoded = json.loads(subprocess.check_output([args.fixture, 'decode'], input=json.dumps({**operation, 'response': result}), text=True))
                expected_status = {'conflict': 403, 'offline': 404, 'schema-invalid': 503}.get(operation['kind'], 200)
                assert decoded['status'] == expected_status, f"Core decoder rejected native result: {operation['name']}"
                if operation['kind'] in ('register', 'lookup'):
                    assert decoded['body']['data']['revision'] == operation['revision'], operation['name']
                if operation['kind'] == 'offline':
                    assert 'body' not in decoded, 'Offline response must not return stale server metadata'
                if operation['kind'] == 'conflict':
                    assert not result['results'][-1].get('rows'), operation['name']
                elif operation['kind'] in ('register', 'lookup'):
                    rows = result['results'][-1]['rows']
                    assert len(rows) == 1, operation['name']
                    assert rows[0]['revision'] == operation['revision'], operation['name']
                    if operation['name'] == 'owned metadata update':
                        assert rows[0]['last_seen_ms'] == 2000, 'Clock reversal moved last seen backwards'
                        assert rows[0]['updated_ms'] == 2000, 'Clock reversal moved updated timestamp backwards'
                    if operation['kind'] == 'lookup':
                        assert 'credential_digest' not in rows[0], 'Lookup returned credential digest'
                print('PASS:', operation['name'])
        finally:
            process.terminate()
            try:
                process.wait(timeout=10)
            except subprocess.TimeoutExpired:
                process.kill()
                process.wait(timeout=5)
