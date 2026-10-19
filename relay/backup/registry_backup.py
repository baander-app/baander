#!/usr/bin/env python3
# SPDX-License-Identifier: Apache-2.0
"""Capture encrypted Registry snapshots and publish independently checked status."""
import argparse
import configparser
import datetime
import hashlib
import json
import math
import os
from pathlib import Path
import selectors
import signal
import subprocess
import sys
import tempfile
import time
import urllib.parse
import uuid

INTERVAL_SECONDS = 300
HEADER_LIMIT = 65536
SQLITE_PREFIX = b'SQLite format 3\x00'


class BackupError(Exception):
    pass


def utc_now():
    return datetime.datetime.now(datetime.timezone.utc).isoformat()


def load_config(path):
    config = json.loads(Path(path).read_text())
    for name in ('rqlite_url', 'curl_config', 'recipients_file', 'rclone_config',
                 'destination', 'spool_dir', 'status_file'):
        if not isinstance(config.get(name), str) or not config[name]:
            raise BackupError('missing configuration: ' + name)
    url = urllib.parse.urlsplit(config['rqlite_url'])
    if url.scheme != 'https' or not url.hostname or url.username or url.password or url.query or url.fragment:
        raise BackupError('rqlite_url must be an HTTPS base URL without credentials')
    config.setdefault('attempt_timeout_seconds', 240)
    config.setdefault('max_ciphertext_bytes', 268435456)
    timeout = config['attempt_timeout_seconds']
    if isinstance(timeout, bool) or not isinstance(timeout, (int, float)) or not math.isfinite(timeout) or not 0 < timeout < INTERVAL_SECONDS:
        raise BackupError('attempt timeout must be positive and below 300 seconds')
    size = config['max_ciphertext_bytes']
    if isinstance(size, bool) or not isinstance(size, int) or size < 1:
        raise BackupError('ciphertext limit must be a positive integer')
    if ':' not in config['destination'] or config['destination'].startswith(':'):
        raise BackupError('destination must name a configured rclone remote')
    remote = config['destination'].split(':', 1)[0]
    storage = configparser.ConfigParser(interpolation=None)
    storage.read(config['rclone_config'])
    if not storage.has_section(remote) or storage.get(remote, 'type', fallback='') != 's3':
        raise BackupError('destination must use an S3 remote')
    endpoint = storage.get(remote, 'endpoint', fallback='')
    if endpoint and urllib.parse.urlsplit(endpoint).scheme != 'https':
        raise BackupError('S3 endpoint must use HTTPS')
    return config


def validate_headers(headers):
    # curl retains HTTP/1.1 trailers in dump-header output. CONNECT and interim
    # response blocks may precede the actual response.
    blocks = headers.decode('iso-8859-1').replace('\r\n', '\n').split('\n\n')
    responses = [block for block in blocks if block.startswith('HTTP/')]
    if not responses or responses[-1].split('\n', 1)[0].split()[1:2] != ['200']:
        raise BackupError('snapshot HTTP status was not 200')
    fields = responses[-1].split('\n')[1:]
    if not any(line.lower().split(';', 1)[0].strip() == 'content-type: application/octet-stream' for line in fields):
        raise BackupError('snapshot content type was not binary')
    for line in headers.decode('iso-8859-1').splitlines():
        key, separator, value = line.partition(':')
        if separator and key.lower() == 'x-stream-error' and value.strip():
            raise BackupError('snapshot stream reported an error')


def stop_children(children):
    for child in children:
        if child.poll() is None:
            try:
                os.killpg(child.pid, signal.SIGKILL)
            except ProcessLookupError:
                pass
    for child in children:
        child.wait()


def launch(arguments, **kwargs):
    return subprocess.Popen(arguments, stderr=subprocess.DEVNULL,
                            start_new_session=True, **kwargs)


def capture(config, output, deadline):
    children = []
    header_read, header_write = os.pipe()
    try:
        curl = launch([
            'curl', '--disable', '--config', config['curl_config'], '--http1.1',
            '--no-insecure', '--no-location', '--proto', '=https', '--proto-redir', '=https',
            '--silent', '--show-error', '--fail', '--max-time',
            str(config['attempt_timeout_seconds']), '--dump-header', f'/dev/fd/{header_write}',
            config['rqlite_url'].rstrip('/') + '/db/backup?fmt=binary',
        ], stdout=subprocess.PIPE, pass_fds=(header_write,))
        children.append(curl)
        os.close(header_write)
        header_write = None
        age = launch(['age', '--encrypt', '--recipients-file', config['recipients_file']],
                     stdin=subprocess.PIPE, stdout=subprocess.PIPE)
        children.append(age)
        with os.fdopen(header_read, 'rb', buffering=0) as header_pipe, selectors.DefaultSelector() as selector:
            header_read = None
            for stream, kind in ((curl.stdout, 'plain'), (header_pipe, 'header'), (age.stdout, 'cipher')):
                os.set_blocking(stream.fileno(), False)
                selector.register(stream, selectors.EVENT_READ, kind)
            os.set_blocking(age.stdin.fileno(), False)
            headers = bytearray()
            prefix = bytearray()
            pending = bytearray()
            plain_done = False
            cipher_size = 0
            digest = hashlib.sha256()
            while selector.get_map():
                remaining = deadline - time.monotonic()
                if remaining <= 0:
                    raise BackupError('attempt deadline exceeded')
                for key, _ in selector.select(min(remaining, 0.1)):
                    stream, kind = key.fileobj, key.data
                    if kind == 'input':
                        try:
                            written = os.write(stream.fileno(), pending)
                        except BrokenPipeError:
                            raise BackupError('encryption failed') from None
                        del pending[:written]
                        if not pending:
                            selector.unregister(stream)
                            if plain_done:
                                stream.close()
                        if len(pending) < 65536 and not plain_done and curl.stdout not in [entry.fileobj for entry in selector.get_map().values()]:
                            selector.register(curl.stdout, selectors.EVENT_READ, 'plain')
                        continue
                    data = os.read(stream.fileno(), 65536)
                    if not data:
                        selector.unregister(stream)
                        if kind == 'plain':
                            plain_done = True
                            if not pending:
                                age.stdin.close()
                        continue
                    if kind == 'header':
                        headers.extend(data)
                        if len(headers) > HEADER_LIMIT:
                            raise BackupError('snapshot headers exceed limit')
                    elif kind == 'cipher':
                        cipher_size += len(data)
                        if cipher_size > config['max_ciphertext_bytes']:
                            raise BackupError('ciphertext exceeds limit')
                        output.write(data)
                        digest.update(data)
                    else:
                        prefix.extend(data[:max(0, len(SQLITE_PREFIX) - len(prefix))])
                        pending.extend(data)
                        if len(pending) >= 65536:
                            selector.unregister(curl.stdout)
                        if age.stdin not in [entry.fileobj for entry in selector.get_map().values()]:
                            selector.register(age.stdin, selectors.EVENT_WRITE, 'input')
            for child in children:
                try:
                    result = child.wait(timeout=max(0.001, deadline - time.monotonic()))
                except subprocess.TimeoutExpired:
                    raise BackupError('attempt deadline exceeded') from None
                if result:
                    raise BackupError('snapshot transport or encryption failed')
            validate_headers(headers)
            if prefix != SQLITE_PREFIX or cipher_size == 0:
                raise BackupError('snapshot or ciphertext is empty or invalid')
            return digest.hexdigest()
    finally:
        stop_children(children)
        for child in children:
            for stream in (child.stdin, child.stdout):
                if stream:
                    stream.close()
        if header_read is not None:
            os.close(header_read)
        if header_write is not None:
            os.close(header_write)


def read_status(config):
    try:
        return json.loads(Path(config['status_file']).read_text())
    except FileNotFoundError:
        return {}


def write_status(config, status):
    target = Path(config['status_file'])
    with tempfile.NamedTemporaryFile(mode='w', dir=target.parent, prefix='.backup-status-', delete=False) as output:
        temporary = Path(output.name)
        try:
            json.dump(status, output)
            output.write('\n')
            output.flush()
            os.fsync(output.fileno())
            os.replace(temporary, target)
        finally:
            temporary.unlink(missing_ok=True)


def attempt(config):
    started = utc_now()
    deadline = time.monotonic() + config['attempt_timeout_seconds']
    status = read_status(config)
    status['latest_attempt'] = {'capture_started_at': started, 'outcome': 'running'}
    write_status(config, status)
    try:
        with tempfile.TemporaryDirectory(prefix='registry-backup-', dir=config['spool_dir']) as directory:
            ciphertext = Path(directory) / 'snapshot.sqlite.age'
            with ciphertext.open('wb') as output:
                digest = capture(config, output, deadline)
            key = datetime.datetime.fromisoformat(started).strftime('%Y%m%dT%H%M%S.%fZ') + '-' + uuid.uuid4().hex + '.sqlite.age'
            destination = config['destination'].rstrip('/') + '/' + key
            if time.monotonic() >= deadline:
                raise BackupError('attempt deadline exceeded')
            upload = launch(['rclone', '--config', config['rclone_config'], '--no-check-certificate=false', 'copyto',
                             str(ciphertext), destination, '--immutable', '--retries', '1',
                             '--low-level-retries', '1', '--quiet'], stdout=subprocess.DEVNULL)
            try:
                try:
                    result = upload.wait(timeout=max(0.001, deadline - time.monotonic()))
                except subprocess.TimeoutExpired:
                    raise BackupError('attempt deadline exceeded') from None
                if result:
                    raise BackupError('upload failed')
                if time.monotonic() >= deadline:
                    raise BackupError('attempt deadline exceeded')
            finally:
                stop_children([upload])
            status['last_success'] = {'capture_started_at': started, 'upload_completed_at': utc_now(),
                                      'object_key': destination.split(':', 1)[1], 'sha256': digest}
        status['latest_attempt'].update(outcome='success', completed_at=utc_now())
        write_status(config, status)
        return True
    except (BackupError, OSError, KeyboardInterrupt) as error:
        # Child stderr and configuration values can contain credentials. Publish
        # only coordinator-owned failure text.
        if isinstance(error, KeyboardInterrupt):
            message = 'backup cancelled'
        else:
            message = str(error) if isinstance(error, BackupError) else 'backup tool or filesystem failed'
        status['latest_attempt'].update(outcome='failed', completed_at=utc_now(), error=message)
        write_status(config, status)
        print(message, file=sys.stderr)
        if isinstance(error, KeyboardInterrupt):
            raise
        return False


def healthy(config, status, now=None):
    success = status.get('last_success')
    if not success or status.get('latest_attempt', {}).get('outcome') == 'failed':
        return False
    captured = datetime.datetime.fromisoformat(success['capture_started_at']).timestamp()
    age = (time.time() if now is None else now) - captured
    return 0 <= age <= INTERVAL_SECONDS + config['attempt_timeout_seconds']


def recurring(config, operation=attempt, monotonic=time.monotonic, sleep=time.sleep):
    next_tick = monotonic()
    while True:
        operation(config)
        next_tick += INTERVAL_SECONDS
        now = monotonic()
        if next_tick <= now:
            missed = int((now - next_tick) // INTERVAL_SECONDS) + 1
            print(f'missed {missed} backup schedule tick(s)', file=sys.stderr)
            next_tick += missed * INTERVAL_SECONDS
        sleep(max(0, next_tick - monotonic()))


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('command', choices=('once', 'run', 'health'))
    parser.add_argument('--config', required=True)
    args = parser.parse_args()
    os.umask(0o077)
    def shutdown(_signum, _frame):
        raise KeyboardInterrupt
    signal.signal(signal.SIGTERM, shutdown)
    try:
        config = load_config(args.config)
        if args.command == 'health':
            status = read_status(config)
            print(json.dumps(status))
            return 0 if healthy(config, status) else 1
        if args.command == 'once':
            return 0 if attempt(config) else 1
        recurring(config)
    except KeyboardInterrupt:
        return 130
    except (BackupError, OSError, ValueError, KeyError):
        print('backup configuration or status is invalid', file=sys.stderr)
        return 1


if __name__ == '__main__':
    sys.exit(main())
