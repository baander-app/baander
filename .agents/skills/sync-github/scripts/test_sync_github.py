#!/usr/bin/env python3
"""Exercise snapshot publication against temporary local bare Git repositories."""

import hashlib
import importlib.util
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

sys.dont_write_bytecode = True
HELPER = Path(__file__).with_name("sync_github.py").resolve()
SPEC = importlib.util.spec_from_file_location("sync_github", HELPER)
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)


class SnapshotTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix="baander-sync-github-test-")
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.repo = self.root / "source"
        self.remote = self.root / "mirror.git"
        self.repo.mkdir()
        self.environment = dict(os.environ, GIT_OPTIONAL_LOCKS="0", GIT_CONFIG_NOSYSTEM="1")
        # Tests use only disposable configuration, never a user's global hooks
        # or destination rewrites. The helper itself honors operator configuration.
        self.environment["GIT_CONFIG_GLOBAL"] = os.devnull
        for name in ("GIT_DIR", "GIT_WORK_TREE", "GIT_INDEX_FILE", "GIT_COMMON_DIR"):
            self.environment.pop(name, None)
        self.git(self.repo, "init", "-q")
        self.git(self.repo, "config", "user.email", "test@baander.app")
        self.git(self.repo, "config", "user.name", "Snapshot Skill Test")
        (self.repo / "tracked").write_text("committed\n")
        self.git(self.repo, "add", "tracked")
        self.git(self.repo, "commit", "-qm", "source commit")
        self.git(self.root, "init", "--bare", "-q", str(self.remote))
        self.git(self.repo, "remote", "add", "mirror", str(self.remote))

    def git(self, cwd, *arguments):
        return subprocess.check_output(
            ["git", *arguments], cwd=cwd, env=self.environment,
            text=True, stderr=subprocess.PIPE,
        ).strip()

    def invoke(self, *arguments, success=True, environment=None):
        # Normal callers pin the inspected source. Individual tests can supply
        # an older pin or invoke the raw CLI to verify a missing pin is rejected.
        if "--apply" in arguments and "--expected-source" not in arguments:
            arguments = (*arguments, "--expected-source", self.git(self.repo, "rev-parse", "HEAD"))
        result = subprocess.run(
            [sys.executable, str(HELPER), "--remote", "mirror", "--expected-url",
             str(self.remote), *arguments],
            cwd=self.repo, env=environment or self.environment,
            text=True, capture_output=True, timeout=15,
        )
        if success:
            self.assertEqual(result.returncode, 0, result.stderr)
            return json.loads(result.stdout)
        self.assertNotEqual(result.returncode, 0, result.stdout)
        return result.stderr

    @staticmethod
    def file_hashes(directory):
        return {
            str(path.relative_to(directory)): hashlib.sha256(path.read_bytes()).hexdigest()
            for path in directory.rglob("*") if path.is_file()
        }

    def dirty_source(self):
        (self.repo / "tracked").write_text("unstaged change\n")
        (self.repo / "staged").write_text("staged private data\n")
        self.git(self.repo, "add", "staged")
        (self.repo / "untracked").write_text("untracked private data\n")

    def local_state(self):
        return {
            "refs": self.git(self.repo, "show-ref"),
            "head": (self.repo / ".git/HEAD").read_bytes(),
            "index": (self.repo / ".git/index").read_bytes(),
            "status": self.git(self.repo, "status", "--porcelain"),
            "work": {p.name: p.read_bytes() for p in self.repo.iterdir() if p.is_file()},
        }

    def test_dry_run_changes_no_source_or_remote_files(self):
        self.dirty_source()
        before_source = self.file_hashes(self.repo)
        before_remote = self.file_hashes(self.remote)
        result = self.invoke("--dry-run")
        self.assertEqual(result["observed_sha"], "absent")
        self.assertTrue(result["dirty_work_excluded"])
        self.assertEqual(before_source, self.file_hashes(self.repo))
        self.assertEqual(before_remote, self.file_hashes(self.remote))

    def test_initial_publish_exports_only_committed_tree_preserving_local_state(self):
        self.dirty_source()
        before = self.local_state()
        result = self.invoke("--apply", "--expected-sha", "absent")
        self.assertEqual(self.git(self.remote, "rev-list", "--count", "master"), "1")
        self.assertEqual(self.git(self.remote, "rev-parse", "master^{tree}"), result["tree"])
        self.assertEqual(self.git(self.remote, "show", "master:tracked"), "committed")
        self.assertEqual(self.git(self.remote, "ls-tree", "--name-only", "master"), "tracked")
        self.assertEqual(before, self.local_state())

    def test_replacement_publish_remains_a_single_root_commit(self):
        first = self.invoke("--apply", "--expected-sha", "absent")["snapshot_commit"]
        (self.repo / "tracked").write_text("second committed tree\n")
        self.git(self.repo, "add", "tracked")
        self.git(self.repo, "commit", "-qm", "next source commit")
        self.dirty_source()
        before = self.local_state()
        result = self.invoke("--apply", "--expected-sha", first)
        self.assertNotEqual(first, result["snapshot_commit"])
        self.assertEqual(self.git(self.remote, "rev-list", "--count", "master"), "1")
        self.assertEqual(self.git(self.remote, "show", "master:tracked"), "second committed tree")
        self.assertEqual(before, self.local_state())

    def test_destination_mismatch_is_rejected_without_local_writes(self):
        before = self.file_hashes(self.repo)
        self.assertIn("push URL", self.invoke("--expected-url", str(self.root / "wrong.git"), success=False))
        self.assertEqual(before, self.file_hashes(self.repo))

    def test_multiple_push_urls_are_rejected(self):
        self.git(self.repo, "remote", "set-url", "--add", "--push", "mirror", str(self.remote))
        self.git(self.repo, "remote", "set-url", "--add", "--push", "mirror", str(self.root / "other.git"))
        self.assertIn("push URL", self.invoke("--dry-run", success=False))
        self.assertEqual(self.git(self.remote, "for-each-ref"), "")

    def test_stale_expected_sha_is_rejected(self):
        published = self.invoke("--apply", "--expected-sha", "absent")["snapshot_commit"]
        before = self.file_hashes(self.repo)
        self.assertIn("changed", self.invoke("--apply", "--expected-sha", "absent", success=False))
        self.assertEqual(self.git(self.remote, "rev-parse", "master"), published)
        self.assertEqual(before, self.file_hashes(self.repo))

    def test_changed_head_between_preview_and_apply_is_rejected_without_writes(self):
        preview = self.invoke("--dry-run")
        (self.repo / "tracked").write_text("new unreviewed source\n")
        self.git(self.repo, "add", "tracked")
        self.git(self.repo, "commit", "-qm", "unreviewed source commit")
        self.dirty_source()
        before_source = self.file_hashes(self.repo)
        before_remote = self.file_hashes(self.remote)
        error = self.invoke(
            "--apply", "--expected-sha", preview["observed_sha"],
            "--expected-source", preview["source_commit"], success=False,
        )
        self.assertIn("Source HEAD changed", error)
        self.assertEqual(before_source, self.file_hashes(self.repo))
        self.assertEqual(before_remote, self.file_hashes(self.remote))

    def test_apply_requires_explicit_source_pin_without_writes(self):
        before_source = self.file_hashes(self.repo)
        before_remote = self.file_hashes(self.remote)
        result = subprocess.run(
            [sys.executable, str(HELPER), "--remote", "mirror", "--expected-url",
             str(self.remote), "--apply", "--expected-sha", "absent"],
            cwd=self.repo, env=self.environment, text=True, capture_output=True, timeout=15,
        )
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("requires the previewed --expected-source", result.stderr)
        self.assertEqual(before_source, self.file_hashes(self.repo))
        self.assertEqual(before_remote, self.file_hashes(self.remote))

    def test_concurrent_remote_update_is_rejected_by_exact_lease(self):
        expected = self.invoke("--apply", "--expected-sha", "absent")["snapshot_commit"]
        # Import a different existing commit into the disposable remote, then
        # move its ref after inspection but immediately before the real push.
        self.git(self.remote, "fetch", "-q", str(self.repo), "HEAD")
        race_sha = self.git(self.repo, "rev-parse", "HEAD")
        shim = self.root / "shim"
        shim.mkdir()
        real_git = shutil.which("git", path=self.environment.get("PATH"))
        wrapper = shim / "git"
        wrapper.write_text(
            "#!" + sys.executable + "\nimport os, subprocess, sys\n"
            "args = sys.argv[1:]\n"
            "if 'push' in args:\n"
            "    subprocess.run([" + repr(real_git) + ", '--git-dir', " + repr(str(self.remote))
            + ", 'update-ref', 'refs/heads/master', " + repr(race_sha) + "], check=True)\n"
            "os.execv(" + repr(real_git) + ", [" + repr(real_git) + ", *args])\n"
        )
        wrapper.chmod(0o755)
        env = dict(self.environment, PATH=str(shim) + os.pathsep + self.environment.get("PATH", ""))
        before = self.local_state()
        self.invoke("--apply", "--expected-sha", expected, success=False, environment=env)
        self.assertEqual(self.git(self.remote, "rev-parse", "master"), race_sha)
        self.assertEqual(before, self.local_state())

    def test_configured_pre_push_rejection_is_honored(self):
        hook = self.repo / ".git/hooks/pre-push"
        hook.write_text("#!/bin/sh\nprintf 'validation rejected\\n' >&2\nexit 1\n")
        hook.chmod(0o755)
        before = self.local_state()
        error = self.invoke("--apply", "--expected-sha", "absent", success=False)
        self.assertIn("validation rejected", error)
        self.assertEqual(self.git(self.remote, "for-each-ref"), "")
        self.assertEqual(before, self.local_state())

    def test_inspection_timeout_stops_operation(self):
        with patch.object(MODULE.subprocess, "run", side_effect=subprocess.TimeoutExpired("git", 60)) as run:
            with self.assertRaisesRegex(MODULE.SyncError, "inspection timed out"):
                MODULE.git("ls-remote", "unused")
            self.assertEqual(run.call_args.kwargs["timeout"], 60)
            self.assertEqual(run.call_count, 1)

    def test_push_timeout_reports_uncertain_outcome_without_retry(self):
        with patch.object(MODULE.subprocess, "run", side_effect=subprocess.TimeoutExpired("git", 60)) as run:
            with self.assertRaisesRegex(MODULE.SyncError, "outcome is uncertain"):
                MODULE.git("push", "unused")
            self.assertEqual(run.call_args.kwargs["timeout"], 60)
            self.assertEqual(run.call_count, 1)


if __name__ == "__main__":
    unittest.main(verbosity=2)
