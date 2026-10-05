#!/usr/bin/env python3
# SPDX-License-Identifier: Apache-2.0
"""Failure injection for disposable cluster harness resource ownership."""
import json
from pathlib import Path
import socket
import subprocess
import sys
import tempfile
import time
from types import SimpleNamespace
import unittest
from unittest.mock import patch

import run_cluster_contract as contract


class CleanupTest(unittest.TestCase):
    def test_all_resources_are_attempted_and_cleanup_errors_are_preserved(self):
        calls = []

        class Resource:
            def __init__(self, name, failure=None):
                self.name = name
                self.failure = failure

            def close(self):
                calls.append(self.name)
                if self.failure:
                    raise self.failure

        first = RuntimeError("API cleanup failure")
        second = RuntimeError("observer cleanup failure")
        with self.assertRaises(ExceptionGroup) as caught:
            contract.close_resources(
                [
                    Resource("api-1", first),
                    Resource("api-2"),
                    Resource("observer", second),
                    Resource("cluster"),
                ]
            )
        self.assertEqual(calls, ["api-1", "api-2", "observer", "cluster"])
        self.assertEqual(caught.exception.exceptions, (first, second))

    def test_failed_readiness_reaps_unregistered_api(self):
        with tempfile.TemporaryDirectory(prefix="baander-registry-cleanup-") as directory:
            root = Path(directory)
            executable = root / "listener.py"
            executable.write_text(
                "#!" + sys.executable + "\n"
                "import json, socket, sys, time\n"
                "config = json.load(open(sys.argv[2]))\n"
                "listener = socket.socket()\n"
                "listener.bind(('127.0.0.1', config['api']['port']))\n"
                "listener.listen()\n"
                "time.sleep(60)\n"
            )
            executable.chmod(0o700)
            cluster = SimpleNamespace(
                directory=root,
                api_cert=root / "unused.crt",
                api_key=root / "unused.key",
                client_cert=root / "unused-client.crt",
                client_key=root / "unused-client.key",
                enrollment_key_file=root / "unused-enrollment.key",
            )
            children = []
            native_popen = subprocess.Popen

            def spawn(*args, **kwargs):
                child = native_popen(*args, **kwargs)
                children.append(child)
                return child

            def fail_ready(api):
                until = time.monotonic() + 3
                while time.monotonic() < until:
                    try:
                        with socket.create_connection(("127.0.0.1", api.port), timeout=0.1):
                            raise AssertionError("injected readiness failure")
                    except OSError:
                        time.sleep(0.01)
                raise AssertionError("fixture listener did not start")

            try:
                with patch.object(contract.subprocess, "Popen", side_effect=spawn), patch.object(
                    contract.Api, "wait_ready", fail_ready
                ):
                    with self.assertRaisesRegex(AssertionError, "injected readiness failure"):
                        contract.Api(cluster, str(executable), "failed", [(0, 4001)])
                self.assertIsNotNone(children[0].poll(), "Failed constructor leaked a subprocess")
                number = json.loads((root / "failed.json").read_text())["api"]["port"]
                with socket.socket() as replacement:
                    replacement.bind(("127.0.0.1", number))
            finally:
                for child in children:
                    if child.poll() is None:
                        child.kill()
                    child.wait(timeout=3)

    def test_teardown_continues_after_observer_failure(self):
        listener = socket.socket()
        listener.bind(("127.0.0.1", 0))
        listener.listen()
        number = listener.getsockname()[1]
        child = subprocess.Popen([sys.executable, "-c", "import time; time.sleep(60)"])
        calls = []

        class FakeCluster:
            def __init__(self, *_):
                self.logs = []

            def start(self):
                return 0

            def close(self):
                calls.append("cluster")
                child.terminate()
                child.wait(timeout=3)

        class FakeObserver:
            def __init__(self, *_):
                self.port = number

            def close(self):
                calls.append("observer")
                listener.close()
                raise RuntimeError("injected observer cleanup failure")

        try:
            with (
                patch.object(contract, "Cluster", FakeCluster),
                patch.object(contract, "Observer", FakeObserver),
                patch.object(contract, "Api", side_effect=AssertionError("injected setup failure")),
                patch.object(
                    sys,
                    "argv",
                    ["cluster", "--nodes", "3", "--server", "unused", "--rqlited", "unused"],
                ),
            ):
                with self.assertRaises(Exception):
                    contract.main()
            self.assertEqual(calls, ["observer", "cluster"])
            self.assertIsNotNone(child.poll(), "Failed teardown leaked a voter subprocess")
            with socket.socket() as replacement:
                replacement.bind(("127.0.0.1", number))
        finally:
            listener.close()
            if child.poll() is None:
                child.kill()
            child.wait(timeout=3)


if __name__ == "__main__":
    unittest.main()
