import os
from pathlib import Path
import subprocess
import tempfile
import unittest


class BrowserCheckpointCleanupTest(unittest.TestCase):
    def run_cleanup(self, scenario):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            artifacts = root / "artifacts"
            artifacts.mkdir()
            marker = artifacts / "browser-container"
            marker.write_text("baander-browser-cleanup-test\n")
            docker = root / "docker"
            docker.write_text("""#!/usr/bin/env python3
import os
from pathlib import Path
import sys

scenario = os.environ['CLEANUP_TEST_SCENARIO']
removed = Path(os.environ['CLEANUP_TEST_REMOVED'])
args = sys.argv[1:]
if args[:2] == ['container', 'ls']:
    if scenario == 'daemon-failure':
        sys.exit(1)
    if scenario != 'never-created' and not removed.exists():
        print('baander-browser-cleanup-test')
elif args[:2] == ['rm', '-f']:
    if scenario in ['daemon-failure', 'survivor']:
        sys.exit(1)
    removed.touch()
elif args[0] == 'cp':
    sys.exit(1 if scenario == 'copy-failure' else 0)
else:
    sys.exit(99)
""")
            docker.chmod(0o755)
            environment = os.environ | {
                "PATH": str(root) + os.pathsep + os.environ["PATH"],
                "CLEANUP_TEST_SCENARIO": scenario,
                "CLEANUP_TEST_REMOVED": str(root / "removed"),
            }
            script = Path(__file__).resolve().parents[1] / "test-two-command-browser-checkpoint.sh"
            result = subprocess.run(
                ["bash", str(script), "cleanup", str(artifacts), ""],
                env=environment, capture_output=True, text=True, timeout=10,
            )
            return result.returncode, marker.exists()

    def test_verified_removal_succeeds(self):
        self.assertEqual((0, False), self.run_cleanup("removed"))

    def test_container_never_created_is_verified_absent(self):
        self.assertEqual((0, False), self.run_cleanup("never-created"))

    def test_daemon_failure_cannot_prove_absence(self):
        self.assertEqual((1, True), self.run_cleanup("daemon-failure"))

    def test_surviving_container_fails_cleanup(self):
        self.assertEqual((1, True), self.run_cleanup("survivor"))

    def test_missing_evidence_fails_even_after_removal(self):
        self.assertEqual((1, False), self.run_cleanup("copy-failure"))
