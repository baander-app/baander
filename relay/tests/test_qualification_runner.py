#!/usr/bin/env python3
# SPDX-License-Identifier: Apache-2.0
"""Bounded orchestration checks with disposable command stubs, without native builds."""
import os
from pathlib import Path
import signal
import shutil
import subprocess
import sys
import tempfile
import time
import unittest

import run_cluster_contract as cluster_contract


ROOT = Path(__file__).resolve().parents[2]


class QualificationTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix="baander-qualification-test-")
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.bin = self.root / "bin"
        self.bin.mkdir()
        self.calls = self.root / "calls"
        self.output = self.root / "output"
        stub = self.bin / "stub"
        stub.write_text("#!" + sys.executable + "\n" + r'''
import os, pathlib, signal, sys, tempfile, time
name = pathlib.Path(sys.argv[0]).name
args = sys.argv[1:]
with open(os.environ['STUB_CALLS'], 'a') as calls:
    calls.write(name + ' ' + ' '.join(args) + '\n')
if name == 'curl':
    if os.environ.get('STUB_FAIL') == 'download':
        print('injected download failure', flush=True)
        sys.exit(19)
    pathlib.Path(args[args.index('-o') + 1]).touch()
elif name == 'tar' and 'openssl.tar.gz' in ' '.join(args):
    target = pathlib.Path(args[args.index('-C') + 1]) / 'openssl-3.5.3'
    target.mkdir()
    configure = target / 'Configure'
    configure.write_text('#!/bin/sh\nexit 0\n')
    configure.chmod(0o700)
elif name == 'cmake' and '--build' in args:
    build = pathlib.Path(args[1])
    build.mkdir(exist_ok=True)
    fuzz = build / 'registry_parser_fuzz'
    fuzz.write_text('#!' + sys.executable + '\n' + """import os, pathlib, sys
print('parser output', flush=True)
prefix = next(arg.split('=', 1)[1] for arg in sys.argv if arg.startswith('-artifact_prefix='))
if os.environ.get('STUB_FAIL') == 'fuzz':
    pathlib.Path(prefix, 'crash-input').write_bytes(b'input')
    sys.exit(29)
""")
    fuzz.chmod(0o700)
elif name == 'clang++':
    target = pathlib.Path(args[args.index('-o') + 1])
    target.write_text('#!/bin/sh\necho "AddressSanitizer: heap-use-after-free"\nexit 1\n')
    target.chmod(0o700)
elif name == 'python3':
    if 'run_cluster_contract.py' in args[0] or 'run_restore_contract.py' in args[0]:
        if '--diagnostics' in args:
            directory = pathlib.Path(args[args.index('--diagnostics') + 1])
            directory.mkdir(parents=True, exist_ok=True)
            (directory / 'node-0.log').write_text('process diagnostics\n')
        if os.environ.get('STUB_FAIL') == 'cluster' and 'run_cluster_contract.py' in args[0]:
            print('injected cluster failure', flush=True)
            sys.exit(23)
        if os.environ.get('STUB_FAIL') in ('terminate', 'stubborn') and 'run_cluster_contract.py' in args[0]:
            if os.environ.get('STUB_FAIL') == 'stubborn':
                signal.signal(signal.SIGTERM, signal.SIG_IGN)
            storage = tempfile.mkdtemp(prefix='qualification-child-')
            pathlib.Path(os.environ['STUB_STORAGE']).write_text(storage)
            pathlib.Path(os.environ['STUB_CHILD']).write_text(str(os.getpid()))
            time.sleep(60)
        print('PASS: ' + args[0], flush=True)
''')
        stub.chmod(0o700)
        for name in ("curl", "tar", "sha256sum", "cmake", "ctest", "python3", "make", "clang", "clang++"):
            (self.bin / name).symlink_to(stub)
        self.env = dict(os.environ, PATH=str(self.bin) + os.pathsep + os.environ["PATH"],
                        CC="clang", CXX="clang++", STUB_CALLS=str(self.calls),
                        STUB_CHILD=str(self.root / "child"), STUB_STORAGE=str(self.root / "storage"),
                        REGISTRY_QUALIFICATION_OUTPUT=str(self.output))
        self.env.pop("REGISTRY_CLUSTER_NODES", None)
        self.env.pop("REGISTRY_FUZZ_RUNS", None)
        self.env.pop("REGISTRY_FUZZ_SECONDS", None)

    def run_recipe(self, **settings):
        return subprocess.run(["bash", str(ROOT / "scripts/test-registry.sh"), "fuzz"],
                              env=dict(self.env, **settings), capture_output=True, text=True, timeout=15)

    def assert_temporary_work_removed(self):
        download = next(line for line in self.calls.read_text().splitlines() if line.startswith("curl "))
        work = Path(download.split(" -o ", 1)[1]).parent
        self.assertFalse(work.exists(), "qualification temporary storage survived")

    def test_prerequisite_failure_retains_diagnostics(self):
        result = self.run_recipe(STUB_FAIL="download")
        self.assertEqual(result.returncode, 19, result.stdout + result.stderr)
        self.assertIn("exit_status=19", (self.output / "metadata.env").read_text())
        self.assertIn("injected download failure", (self.output / "console.log").read_text())
        self.assert_temporary_work_removed()

    def test_output_remains_opt_in(self):
        result = self.run_recipe(REGISTRY_QUALIFICATION_OUTPUT="")
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertFalse(self.output.exists())
        self.assertNotIn("--diagnostics", self.calls.read_text())
        self.assert_temporary_work_removed()

    def test_scenario_diagnostics_exclude_generated_secrets_and_storage(self):
        source = self.root / "scenario"
        source.mkdir()
        for name in ("node-0.log", "pinned.log", "ca.key", "auth.json", "backup.sqlite"):
            (source / name).write_text(name)
        database = source / "node-0"
        database.mkdir()
        (database / "raft.db").write_text("database")
        cluster_contract.export_diagnostics(source, self.output)
        self.assertEqual(sorted(path.name for path in self.output.iterdir()),
                         ["node-0.log", "pinned.log"])

    def test_five_voters_and_metadata(self):
        result = self.run_recipe(REGISTRY_CLUSTER_NODES="5", REGISTRY_FUZZ_RUNS="7", REGISTRY_FUZZ_SECONDS="2")
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        calls = self.calls.read_text().splitlines()
        for runner in ("run_cluster_contract.py", "run_restore_contract.py"):
            self.assertIn("--nodes 5", next(line for line in calls if runner in line))
        metadata = (self.output / "metadata.env").read_text()
        for field in ("cluster_nodes=5", "fuzz_runs=7", "fuzz_seconds=2", "exit_status=0", "revision="):
            self.assertIn(field, metadata)
        self.assertIn("qualification passed", (self.output / "console.log").read_text())
        self.assertTrue((self.output / "cluster/node-0.log").is_file())
        self.assertTrue((self.output / "restore/node-0.log").is_file())
        self.assertFalse(any(self.output.rglob("*.key")))
        self.assertFalse(any(self.output.rglob("*.sqlite")))
        self.assert_temporary_work_removed()

    def test_default_three_voters(self):
        result = self.run_recipe()
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        for line in self.calls.read_text().splitlines():
            if "run_cluster_contract.py" in line or "run_restore_contract.py" in line:
                self.assertIn("--nodes 3", line)

    def test_invalid_voters_fail_before_work(self):
        result = self.run_recipe(REGISTRY_CLUSTER_NODES="4")
        self.assertEqual(result.returncode, 2)
        self.assertFalse(self.calls.exists())

    def test_nonempty_output_fails_before_work(self):
        self.output.mkdir()
        (self.output / "keep").write_text("existing")
        result = self.run_recipe()
        self.assertEqual(result.returncode, 2)
        self.assertFalse(self.calls.exists())
        self.assertEqual((self.output / "keep").read_text(), "existing")

    def test_contract_failure_retains_status_and_stops_later_gates(self):
        result = self.run_recipe(STUB_FAIL="cluster")
        self.assertEqual(result.returncode, 23, result.stdout + result.stderr)
        self.assertIn("exit_status=23", (self.output / "metadata.env").read_text())
        self.assertIn("injected cluster failure", (self.output / "console.log").read_text())
        self.assertNotIn("run_restore_contract.py", self.calls.read_text())
        self.assertNotIn("qualification passed", result.stdout)
        self.assert_temporary_work_removed()

    def test_fuzzer_failure_retains_reproducer(self):
        result = self.run_recipe(STUB_FAIL="fuzz")
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual((self.output / "fuzz-artifacts/crash-input").read_bytes(), b"input")
        self.assertIn("parser output", (self.output / "fuzz.log").read_text())
        self.assertNotIn("negative control passed", result.stdout)

    def test_termination_reaps_child_and_reports_status(self):
        self.check_termination("terminate")

    def test_stubborn_child_is_killed_before_temporary_cleanup(self):
        self.check_termination("stubborn")

    def check_termination(self, behavior):
        process = subprocess.Popen(["bash", str(ROOT / "scripts/test-registry.sh"), "fuzz"],
                                   env=dict(self.env, STUB_FAIL=behavior), stdout=subprocess.DEVNULL,
                                   stderr=subprocess.DEVNULL)
        def stop_recipe():
            if process.poll() is None:
                process.terminate()
                try:
                    process.wait(timeout=8)
                except subprocess.TimeoutExpired:
                    process.kill()
            process.wait()

        self.addCleanup(stop_recipe)
        child_file = self.root / "child"
        until = time.monotonic() + 10
        while not child_file.exists() and process.poll() is None and time.monotonic() < until:
            time.sleep(0.02)
        self.assertTrue(child_file.exists(), "controlled child did not start")
        child = int(child_file.read_text())
        storage = Path((self.root / "storage").read_text())
        self.addCleanup(shutil.rmtree, storage, ignore_errors=True)
        process.send_signal(signal.SIGTERM)
        self.assertEqual(process.wait(timeout=8), 143)
        with self.assertRaises(ProcessLookupError):
            os.kill(child, 0)
        self.assertIn("exit_status=143", (self.output / "metadata.env").read_text())
        self.assertTrue((self.output / "cluster/node-0.log").is_file())
        self.assert_temporary_work_removed()
        self.assertFalse(storage.exists(), "terminated child's temporary storage survived")


if __name__ == "__main__":
    unittest.main()
