from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from dev_environment.build import SourceUnavailable, snapshot_worktree, checkout_revision
from dev_environment.model import WorkflowError
from dev_environment.process import Runner


class SnapshotTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.repo = self.root / 'repo'
        self.repo.mkdir()
        self.git('init', '-b', 'master')
        self.git('config', 'user.email', 'dev@baander.app')
        self.git('config', 'user.name', 'Development fixture')
        (self.repo / 'src').mkdir()
        (self.repo / 'src/code.py').write_text('original')
        (self.repo / '.env').write_text('APP_SECRET=template')
        (self.repo / '.gitignore').write_text('.env.local\nvar/\n')
        self.git('add', '.')
        self.git('commit', '-m', 'fixture')

    def git(self, *args):
        return subprocess.run(['git', '-C', str(self.repo), *args], check=True,
                              capture_output=True, text=True).stdout.strip()

    def test_preview_captures_edits_without_host_secrets_or_generated_state(self):
        (self.repo / 'src/code.py').write_text('edited')
        (self.repo / 'src/new.py').write_text('new')
        (self.repo / '.env').write_text('APP_SECRET=actual-secret')
        (self.repo / '.env.local').write_text('private')
        (self.repo / 'var').mkdir()
        (self.repo / 'var/state').write_text('runtime')
        target = self.root / 'snapshot'
        first = snapshot_worktree(Runner(), self.repo, target)
        self.assertEqual('edited', (target / 'src/code.py').read_text())
        self.assertTrue((target / 'src/new.py').exists())
        self.assertEqual('APP_SECRET=template', (target / '.env').read_text())
        self.assertFalse((target / '.env.local').exists())
        self.assertFalse((target / 'var').exists())
        self.assertFalse((target / '.git').exists())
        self.assertEqual(first, snapshot_worktree(Runner(), self.repo, self.root / 'again'))
        (self.repo / 'src/code.py').write_text('newer')
        self.assertNotEqual(first, snapshot_worktree(Runner(), self.repo, self.root / 'newer'))

    def test_external_symlink_is_not_followed(self):
        (self.repo / 'src/escape').symlink_to(self.root)
        with self.assertRaises(WorkflowError):
            snapshot_worktree(Runner(), self.repo, self.root / 'snapshot')

    def test_managed_checkout_uses_remote_master_not_dirty_caller(self):
        revision = self.git('rev-parse', 'HEAD')
        (self.repo / 'src/code.py').write_text('uncommitted')
        managed, resolved = checkout_revision(Runner(), str(self.repo), self.root / 'managed', self.root / 'release')
        self.assertEqual(revision, resolved)
        self.assertEqual('original', (managed / 'src/code.py').read_text())
        self.assertEqual('uncommitted', (self.repo / 'src/code.py').read_text())


    def test_failed_partial_clone_is_removed_and_same_revision_can_be_retried(self):
        class InterruptedClone(Runner):
            def run(inner, arguments, **kwargs):
                if arguments[:2] == ['git', 'clone']:
                    partial = Path(arguments[-1])
                    partial.mkdir()
                    (partial / 'partial-state').write_text('incomplete transfer')
                    raise WorkflowError('transport interrupted')
                return super().run(arguments, **kwargs)
        managed = self.root / 'managed'
        with self.assertRaises(SourceUnavailable):
            checkout_revision(InterruptedClone(), str(self.repo), managed, self.root / 'release')
        self.assertFalse(managed.exists())
        self.assertEqual([], list(self.root.glob('.clone-*')))
        _, revision = checkout_revision(Runner(), str(self.repo), managed, self.root / 'release')
        self.assertEqual(self.git('rev-parse', 'HEAD'), revision)
        self.assertEqual('original', (self.root / 'release/src/code.py').read_text())

    def test_transient_fetch_preserves_managed_checkout_and_allows_retry(self):
        managed = self.root / 'managed'
        checkout_revision(Runner(), str(self.repo), managed, self.root / 'first')
        class InterruptedFetch(Runner):
            def run(inner, arguments, **kwargs):
                if arguments[:2] == ['git', 'fetch']:
                    raise WorkflowError('transport interrupted')
                return super().run(arguments, **kwargs)
        destination = self.root / 'release'
        with self.assertRaises(SourceUnavailable):
            checkout_revision(InterruptedFetch(), str(self.repo), managed, destination)
        self.assertTrue((managed / '.git').is_dir())
        self.assertFalse(destination.exists())
        _, revision = checkout_revision(Runner(), str(self.repo), managed, destination)
        self.assertEqual(self.git('rev-parse', 'HEAD'), revision)

    def test_wrong_managed_remote_stays_a_deterministic_error(self):
        managed = self.root / 'managed'
        checkout_revision(Runner(), str(self.repo), managed, self.root / 'first')
        with self.assertRaises(WorkflowError) as failure:
            checkout_revision(Runner(), str(self.root / 'other'), managed, self.root / 'release')
        self.assertNotIsInstance(failure.exception, SourceUnavailable)
        self.assertIn('different remote', str(failure.exception))


if __name__ == '__main__':
    unittest.main()
