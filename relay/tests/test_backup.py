#!/usr/bin/env python3
# SPDX-License-Identifier: Apache-2.0
"""Coordinator boundary tests; CLI fixtures do not test encryption or S3 itself."""
import contextlib
import datetime
import importlib.util
import io
import json
import os
from pathlib import Path
import signal
import subprocess
import sys
import tempfile
import time
import unittest
from unittest.mock import patch

MODULE = Path(__file__).resolve().parents[1] / 'backup/registry_backup.py'
spec = importlib.util.spec_from_file_location('registry_backup', MODULE)
backup = importlib.util.module_from_spec(spec)
spec.loader.exec_module(backup)

FIXTURE = r'''#!/usr/bin/env python3
import json, os, pathlib, sys, time
name = pathlib.Path(sys.argv[0]).name
mode = os.environ.get('BACKUP_FIXTURE_MODE', '')
root = pathlib.Path(os.environ['BACKUP_FIXTURE_ROOT'])
with (root / 'calls').open('a') as output:
    output.write(json.dumps([name, os.getpid(), sys.argv[1:]]) + '\n')
if name == 'curl':
    header_path = sys.argv[sys.argv.index('--dump-header') + 1]
    headers = b'HTTP/1.1 200 OK\r\nContent-Type: application/octet-stream\r\nTrailer: X-STREAM-ERROR\r\n\r\n'
    if mode == 'auth': headers = headers.replace(b'200 OK', b'401 Unauthorized')
    if mode == 'redirect': headers = headers.replace(b'200 OK', b'301 Redirect')
    if mode == 'content': headers = headers.replace(b'application/octet-stream', b'text/plain')
    if mode == 'stream': headers += b'X-STREAM-ERROR: sqlite failed\r\n'
    if mode == 'headers': headers += b'X-Long: ' + b'x' * 70000 + b'\r\n'
    with open(header_path, 'wb') as output:
        output.write(headers)
    if mode == 'hang': time.sleep(30)
    sys.stdout.buffer.write((b'not a database!!' if mode == 'prefix' else b'SQLite format 3\0') + b'x' * 100)
    if mode in ('tls', 'incomplete'): sys.exit(18)
elif name == 'age':
    data = sys.stdin.buffer.read()
    if mode == 'encrypt': sys.exit(1)
    sys.stdout.buffer.write(b'controlled-encryption-fixture\n' + data)
elif name == 'rclone':
    if mode == 'upload': sys.exit(1)
    if mode == 'upload-hang': time.sleep(30)
    index = sys.argv.index('copyto')
    source, destination = sys.argv[index + 1:index + 3]
    target = root / 'objects' / destination.split('/')[-1]
    target.write_bytes(pathlib.Path(source).read_bytes())
'''


class BackupContract(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='registry-backup-unit-')
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        for directory in ('bin', 'spool', 'objects'):
            (self.root / directory).mkdir()
        for name in ('curl', 'age', 'rclone'):
            tool = self.root / 'bin' / name
            tool.write_text(FIXTURE)
            tool.chmod(0o700)
        self.config = {
            'rqlite_url': 'https://registry.baander.app:4001',
            'curl_config': str(self.root / 'curl.conf'),
            'recipients_file': str(self.root / 'recipients'),
            'rclone_config': str(self.root / 'rclone.conf'),
            'destination': 'backup:registry/snapshots',
            'spool_dir': str(self.root / 'spool'),
            'status_file': str(self.root / 'status.json'),
            'attempt_timeout_seconds': 2,
            'max_ciphertext_bytes': 4096,
        }
        (self.root / 'rclone.conf').write_text('[backup]\ntype=s3\nendpoint=https://storage.baander.app\n')
        (self.root / 'curl.conf').write_text('user = "private-user:private-password"\n')
        self.environment = patch.dict(os.environ, {
            'PATH': str(self.root / 'bin') + ':' + os.environ['PATH'],
            'BACKUP_FIXTURE_ROOT': str(self.root), 'BACKUP_FIXTURE_MODE': '',
        })
        self.environment.start()
        self.addCleanup(self.environment.stop)

    def run_attempt(self, mode=''):
        with patch.dict(os.environ, {'BACKUP_FIXTURE_MODE': mode}), contextlib.redirect_stderr(io.StringIO()):
            return backup.attempt(self.config)

    def calls(self):
        return [json.loads(line) for line in (self.root / 'calls').read_text().splitlines()]

    def test_success_preserves_history_and_records_capture_age(self):
        self.assertTrue(self.run_attempt())
        first = backup.read_status(self.config)['last_success']
        self.assertTrue(self.run_attempt())
        status = backup.read_status(self.config)
        self.assertEqual(2, len(list((self.root / 'objects').iterdir())))
        self.assertNotEqual(first['object_key'], status['last_success']['object_key'])
        self.assertLessEqual(first['capture_started_at'], first['upload_completed_at'])
        self.assertEqual(64, len(first['sha256']))
        self.assertEqual([], list((self.root / 'spool').iterdir()))
        self.assertNotIn('private-password', json.dumps(self.calls()))
        self.assertNotIn('private-password', json.dumps(status))
        self.assertIn('--immutable', self.calls()[-1][2])

    def test_capture_failures_never_upload_or_refresh_success(self):
        self.assertTrue(self.run_attempt())
        previous = backup.read_status(self.config)['last_success']
        for mode in ('auth', 'redirect', 'content', 'stream', 'tls', 'incomplete', 'encrypt', 'prefix', 'headers'):
            with self.subTest(mode=mode):
                (self.root / 'calls').unlink()
                self.assertFalse(self.run_attempt(mode))
                self.assertNotIn('rclone', [call[0] for call in self.calls()])
                self.assertEqual(previous, backup.read_status(self.config)['last_success'])
                self.assertEqual('failed', backup.read_status(self.config)['latest_attempt']['outcome'])
                self.assertEqual([], list((self.root / 'spool').iterdir()))

    def test_upload_failure_preserves_success(self):
        self.assertTrue(self.run_attempt())
        previous = backup.read_status(self.config)['last_success']
        self.assertFalse(self.run_attempt('upload'))
        self.assertEqual(previous, backup.read_status(self.config)['last_success'])
        self.assertEqual([], list((self.root / 'spool').iterdir()))

    def test_size_bound_prevents_upload(self):
        self.config['max_ciphertext_bytes'] = 20
        self.assertFalse(self.run_attempt())
        self.assertNotIn('rclone', [call[0] for call in self.calls()])
        self.assertEqual([], list((self.root / 'spool').iterdir()))

    def test_deadlines_reap_real_capture_and_upload_children(self):
        self.config['attempt_timeout_seconds'] = 0.2
        for mode in ('hang', 'upload-hang'):
            with self.subTest(mode=mode):
                started = time.monotonic()
                self.assertFalse(self.run_attempt(mode))
                self.assertLess(time.monotonic() - started, 1.5)
                for _, pid, _ in self.calls():
                    with self.assertRaises(ProcessLookupError):
                        os.kill(pid, 0)
                self.assertEqual([], list((self.root / 'spool').iterdir()))

    def test_shutdown_reaps_children_and_cleans_spool(self):
        path = self.root / 'config.json'
        path.write_text(json.dumps(self.config))
        with patch.dict(os.environ, {'BACKUP_FIXTURE_MODE': 'hang'}):
            worker = subprocess.Popen([sys.executable, str(MODULE), 'once', '--config', str(path)],
                                      stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        try:
            deadline = time.monotonic() + 3
            while time.monotonic() < deadline:
                if (self.root / 'calls').exists() and len(self.calls()) >= 2:
                    break
                time.sleep(0.01)
            else:
                self.fail('worker did not start capture children')
            worker.send_signal(signal.SIGTERM)
            self.assertEqual(130, worker.wait(timeout=2))
            self.assertEqual('failed', backup.read_status(self.config)['latest_attempt']['outcome'])
            for _, pid, _ in self.calls():
                with self.assertRaises(ProcessLookupError):
                    os.kill(pid, 0)
            self.assertEqual([], list((self.root / 'spool').iterdir()))
        finally:
            if worker.poll() is None:
                worker.kill()
                worker.wait()

    def test_health_uses_capture_time_and_latest_failure(self):
        self.assertFalse(backup.healthy(self.config, {}))
        captured = datetime.datetime.now(datetime.timezone.utc).timestamp()
        status = {'last_success': {'capture_started_at': datetime.datetime.fromtimestamp(captured, datetime.timezone.utc).isoformat(),
                                  'upload_completed_at': datetime.datetime.fromtimestamp(captured + 200, datetime.timezone.utc).isoformat()}}
        self.assertTrue(backup.healthy(self.config, status, captured + 302))
        self.assertFalse(backup.healthy(self.config, status, captured + 303))
        status['latest_attempt'] = {'outcome': 'failed'}
        self.assertFalse(backup.healthy(self.config, status, captured + 1))

    def test_schedule_runs_immediately_and_skips_missed_ticks(self):
        clock = [0]
        starts = []
        def operation(_config):
            starts.append(clock[0])
            clock[0] += 1 if len(starts) == 1 else 650
            if len(starts) == 3:
                raise StopIteration
        def sleep(duration):
            clock[0] += duration
        with self.assertRaises(StopIteration), contextlib.redirect_stderr(io.StringIO()):
            backup.recurring(self.config, operation, lambda: clock[0], sleep)
        self.assertEqual([0, 300, 1200], starts)

    def test_configuration_rejects_insecure_destinations(self):
        path = self.root / 'config.json'
        path.write_text(json.dumps(self.config))
        self.assertEqual(self.config, backup.load_config(path))
        (self.root / 'rclone.conf').write_text('[backup]\ntype=s3\nendpoint=http://storage.baander.app\n')
        with self.assertRaises(backup.BackupError):
            backup.load_config(path)

if __name__ == '__main__':
    unittest.main()
