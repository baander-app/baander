#!/usr/bin/env python3
# SPDX-License-Identifier: Apache-2.0
"""Failure controls for the bounded full-disk contract; never fill a real mount."""
import errno
import io
import os
import shutil
import socket
import subprocess
import sys
from pathlib import Path
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import patch

import run_storage_contract as storage
from cluster_support import Cluster


class StorageTest(unittest.TestCase):
    def test_unsafe_roots_allocate_nothing(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            sentinel = root / "sentinel"
            sentinel.write_text("keep")
            link = root / "link"
            link.symlink_to(root, target_is_directory=True)
            for candidate in (root / "missing", root, link):
                with self.subTest(candidate=candidate), self.assertRaises((ValueError, OSError)):
                    storage.BoundedStorage(candidate)
            self.assertEqual(sentinel.read_text(), "keep")
            self.assertEqual(sorted(p.name for p in root.iterdir()), ["link", "sentinel"])

    def test_ordinary_and_oversized_mounts_are_rejected(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            with self.assertRaises(ValueError):
                storage.BoundedStorage(root)
            mount = f"1 0 0:1 / {root} rw - tmpfs tmpfs rw\n"
            with patch.object(storage.Path, "read_text", return_value=mount), patch.object(
                storage.os, "statvfs", return_value=SimpleNamespace(f_blocks=129, f_frsize=1024*1024, f_bfree=129, f_bavail=129)
            ), self.assertRaises(ValueError):
                storage.BoundedStorage(root)
            self.assertEqual(list(root.iterdir()), [])

    def test_nonempty_dedicated_mount_is_rejected_before_ownership(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            sentinel = root / "sentinel"
            sentinel.write_text("keep")
            mount = f"1 0 0:1 / {root} rw - tmpfs tmpfs rw\n"
            real_stat = Path.stat
            def distinct_device(path, *args, **kwargs):
                values = list(real_stat(path, *args, **kwargs))
                if path == root:
                    values[2] += 100
                return os.stat_result(values)
            with patch.object(storage.Path, "read_text", return_value=mount), patch.object(
                storage, "usage", return_value={"capacity_bytes": 4096}
            ), patch.object(Path, "stat", distinct_device):
                with self.assertRaisesRegex(ValueError, "initially be empty"):
                    storage.BoundedStorage(root)
            self.assertEqual(list(root.iterdir()), [sentinel])
            self.assertEqual(sentinel.read_text(), "keep")

    def test_owned_child_cleanup_preserves_sibling(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            with patch.object(storage, "validate_root", return_value={"capacity_bytes": 4096}):
                owned = storage.BoundedStorage(root)
            sibling = root / "sentinel"
            sibling.write_text("keep")
            (owned.directory / "partial").write_text("partial setup")
            owned.close()
            self.assertEqual(list(root.iterdir()), [sibling])
            self.assertEqual(sibling.read_text(), "keep")

    def test_fill_closes_descriptor_on_unexpected_failure(self):
        with tempfile.TemporaryDirectory() as directory:
            with patch.object(storage, "validate_root", return_value={}):
                owned = storage.BoundedStorage(Path(directory))
            handle = io.BytesIO()
            with patch("builtins.open", return_value=handle), patch.object(
                handle, "write", side_effect=OSError(errno.EIO, "injected failure")
            ), self.assertRaises(OSError):
                owned.fill()
            self.assertTrue(handle.closed)
            owned.close()

    def test_only_real_enospc_qualifies(self):
        with tempfile.TemporaryDirectory() as directory:
            with patch.object(storage, "validate_root", return_value={}):
                owned = storage.BoundedStorage(Path(directory))
            handle = io.BytesIO()
            with patch("builtins.open", return_value=handle), patch.object(
                handle, "write", side_effect=OSError(errno.ENOSPC, "full")
            ), patch.object(storage, "usage", return_value={}):
                evidence = owned.fill()
            self.assertEqual(evidence["filler_errno"], errno.ENOSPC)
            self.assertTrue(handle.closed)
            owned.close()

    def test_pressure_bounds_and_missing_evidence_fail(self):
        class Api:
            def request(self, *_args):
                return 200, {"data": {"registered": True, "publicId": "unused", "revision": 1,
                                      "updatedAtMs": 1, "lastHeartbeatMs": 1}}, {}
        with patch.object(storage, "MAX_REQUESTS", 2), patch.object(storage, "acknowledge") as ack:
            with self.assertRaisesRegex(AssertionError, "ceiling"):
                storage.pressure(Api(), {}, lambda *_: None)
            self.assertEqual(ack.call_count, 2)
        with patch.object(storage.time, "monotonic", side_effect=[0, 121]):
            with self.assertRaisesRegex(AssertionError, "ceiling"):
                storage.pressure(Api(), {}, lambda *_: None)
        with self.assertRaisesRegex(AssertionError, "storage diagnostic"):
            storage.require_storage_error("election timeout; connection refused")
        self.assertIn("no space left on device", "\n".join(storage.require_storage_error(
            "[raft] write failed: no space left on device\n")))

    def test_acknowledgement_derives_full_row_without_reads_or_credentials(self):
        submitted = storage.pressure_registration(3)
        body = {"data": {"registered": True, "publicId": submitted["publicId"], "revision": 1,
                         "updatedAtMs": 123, "lastHeartbeatMs": 123}}
        ledger = {}
        storage.acknowledge(ledger, submitted, body)
        row = ledger[submitted["publicId"]]
        self.assertEqual((row["created_ms"], row["updated_ms"], row["last_seen_ms"]), (123,123,123))
        self.assertEqual(row["revision"], 1)
        self.assertNotIn(submitted["apiKey"], str(ledger))
        changed = dict(row, credential_digest="wrong")
        with patch.object(storage, "local_row", return_value=changed), self.assertRaisesRegex(
            AssertionError, "credential_digest"
        ):
            storage.verify_rows(None, [0], ledger)

    def test_cleanup_retains_assertion_and_attempts_remaining_resources(self):
        calls = []
        class Resource:
            def __init__(self, name, failure=False):
                self.name, self.failure = name, failure
            def close(self):
                calls.append(self.name)
                if self.failure:
                    raise OSError("cleanup failure")
        original = AssertionError("original failure")
        with self.assertRaises(BaseExceptionGroup) as caught:
            with storage.cleanup_scope([Resource("api", True), Resource("voters"), Resource("storage")]):
                raise original
        self.assertEqual(calls, ["api", "voters", "storage"])
        self.assertIs(caught.exception.exceptions[0], original)

    def test_fill_failure_reaps_children_releases_listener_and_preserves_logs(self):
        listener = socket.socket()
        listener.bind(("127.0.0.1", 0))
        listener.listen()
        number = listener.getsockname()[1]
        child = subprocess.Popen([sys.executable, "-c", "import time; time.sleep(60)"])
        artifacts = []
        calls = []
        native_mkdtemp = tempfile.mkdtemp
        def track_directory(*args, **kwargs):
            result = native_mkdtemp(*args, **kwargs)
            if kwargs.get("prefix", "").startswith("baander-registry-storage-evidence-"):
                artifacts.append(Path(result))
            return result
        class FakeCluster:
            def __init__(self, directory, *_args, **_kwargs):
                self.directory = directory
                self.http_ports = [1, 2, 3]
                (directory / "node-0.log").write_text("retained storage diagnostic")
            def start(self):
                return 0
            def close(self):
                calls.append("cluster")
                listener.close()
        class FakeApi:
            def __init__(self, *_args):
                self.process = child
            def request(self, _method, _target, body):
                return 200, {"data": {"registered": True, "publicId": body["publicId"],
                    "revision": 1, "updatedAtMs": 123, "lastHeartbeatMs": 123}}, {}
            def close(self):
                calls.append("api")
                child.terminate()
                child.wait(timeout=3)
        try:
            with tempfile.TemporaryDirectory() as directory:
                root = Path(directory)
                with patch.object(storage, "validate_root", return_value={}), patch.object(
                    storage, "Cluster", FakeCluster
                ), patch.object(storage, "Api", FakeApi), patch.object(
                    storage, "wait_recovered"
                ), patch.object(storage, "usage", return_value={}), patch.object(
                    storage.BoundedStorage, "fill", side_effect=OSError(errno.EIO, "injected fill failure")
                ), patch.object(storage.tempfile, "mkdtemp", side_effect=track_directory):
                    with self.assertRaisesRegex(OSError, "injected fill failure"):
                        storage.run(SimpleNamespace(storage_root=root, rqlited="unused", server="unused"))
                self.assertEqual(list(root.iterdir()), [])
            self.assertEqual(calls, ["api", "cluster"])
            self.assertIsNotNone(child.poll())
            with socket.socket() as replacement:
                replacement.bind(("127.0.0.1", number))
            self.assertEqual((artifacts[0] / "node-0.log").read_text(), "retained storage diagnostic")
            self.assertNotIn("apiKey", (artifacts[0] / "ledger.json").read_text())
        finally:
            listener.close()
            if child.poll() is None:
                child.kill()
            child.wait(timeout=3)
            for directory in artifacts:
                shutil.rmtree(directory)

    def test_artifact_creation_failure_removes_owned_child(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            native_mkdtemp = tempfile.mkdtemp
            def fail_artifacts(*args, **kwargs):
                if kwargs.get("prefix", "").startswith("baander-registry-storage-evidence-"):
                    raise OSError(errno.EIO, "artifact directory failure")
                return native_mkdtemp(*args, **kwargs)
            with patch.object(storage, "validate_root", return_value={}), patch.object(
                storage.tempfile, "mkdtemp", side_effect=fail_artifacts
            ), self.assertRaisesRegex(OSError, "artifact directory failure"):
                storage.run(SimpleNamespace(storage_root=root, rqlited="unused", server="unused"))
            self.assertEqual(list(root.iterdir()), [])

    def test_unavailable_envelope_rejects_credentials_and_success(self):
        storage.require_unavailable(503, {"error": "Authoritative database result unavailable."},
                                    {"Retry-After": "1"})
        for status, body, headers in (
            (503, {"error": "Authoritative registry unavailable.", "apiKey": "secret"}, {"Retry-After": "1"}),
            (503, {"error": "raw database internals"}, {"Retry-After": "1"}),
            (503, {"error": "Authoritative registry unavailable."}, {}),
            (200, {"data": {"registered": True}}, {}),
        ):
            with self.subTest(status=status, body=body), self.assertRaises(AssertionError):
                storage.require_unavailable(status, body, headers)
        self.assertEqual(storage.require_storage_error("SQLITE_FULL: raw SQL credential=secret"),
                         ["voter SQLite: SQLITE_FULL (database or disk is full)"])

    def test_cluster_default_and_override_data_paths(self):
        cluster = Cluster.__new__(Cluster)
        cluster.directory = Path("/fixture")
        cluster.data_root = cluster.directory
        self.assertEqual(cluster.data_directory(2), Path("/fixture/data-2"))
        cluster.data_root = Path("/bounded/private")
        self.assertEqual(cluster.data_directory(2), Path("/bounded/private/data-2"))


if __name__ == "__main__":
    unittest.main()
